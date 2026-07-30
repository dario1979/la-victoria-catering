<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessImportBatch;
use App\Models\ImportBatch;
use App\Models\User;
use App\Support\IdempotentAction;
use App\Support\Imports\ImportDefinition;
use App\Support\Imports\ImportRollback;
use App\Support\Imports\ImportValidator;
use App\Support\Imports\SpreadsheetReader;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class ImportController extends Controller
{
    public function index(Request $request, TenantContext $tenant): JsonResponse
    {
        $this->authorize($tenant);
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50])],
            'status' => ['sometimes', 'string', 'max:32'],
            'type' => ['sometimes', Rule::in(ImportDefinition::TYPES)],
        ]);
        $query = ImportBatch::query()
            ->where('organization_id', $tenant->organization->id)
            ->where('branch_id', $tenant->branch->id)
            ->when($validated['status'] ?? null, fn ($builder, $status) => $builder->where('status', $status))
            ->when($validated['type'] ?? null, fn ($builder, $type) => $builder->where('type', $type))
            ->latest('id');
        $page = $query->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function definitions(ImportDefinition $definitions, TenantContext $tenant): JsonResponse
    {
        $this->authorize($tenant);

        return response()->json(['data' => collect(ImportDefinition::TYPES)
            ->mapWithKeys(fn (string $type): array => [$type => Arr::except($definitions->for($type), ['rules'])])
            ->all()]);
    }

    public function store(
        Request $request,
        TenantContext $tenant,
        SpreadsheetReader $reader,
        ImportDefinition $definitions,
        IdempotentAction $idempotency,
    ): JsonResponse {
        $this->authorize($tenant);
        abort_unless($request->hasHeader('Idempotency-Key'), 400, 'Idempotency-Key header is required.');
        $validated = $request->validate([
            'type' => ['required', Rule::in(ImportDefinition::TYPES)],
            'file' => [
                'required',
                'file',
                'max:'.(int) ceil(config('imports.max_bytes') / 1024),
                'mimetypes:text/plain,text/csv,application/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip',
            ],
        ]);
        $file = $validated['file'];
        $extension = mb_strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['csv', 'xlsx'], true)) {
            throw ValidationException::withMessages(['file' => ['Solo se admiten archivos CSV o XLSX.']]);
        }
        $sha = hash_file('sha256', $file->getRealPath());
        [$body, $status, $replayed] = $idempotency->run(
            "organizations.{$tenant->organization->id}.branches.{$tenant->branch->id}.imports.upload",
            (string) $request->header('Idempotency-Key'),
            ['type' => $validated['type'], 'sha256' => $sha, 'size' => $file->getSize()],
            function () use ($file, $extension, $validated, $reader, $definitions, $tenant, $request, $sha): array {
                $sheet = $reader->read($file->getRealPath(), $extension);
                $definition = $definitions->for($validated['type']);
                $mapping = [];
                foreach ($definition['fields'] as $field) {
                    if (in_array($field, $sheet['headers'], true)) {
                        $mapping[$field] = $field;
                    }
                }
                $uuid = (string) Str::uuid();
                $path = $file->storeAs(
                    "imports/{$tenant->organization->id}/{$tenant->branch->id}",
                    "{$uuid}.{$extension}",
                    config('imports.disk'),
                );
                abort_if($path === false, 500, 'No se pudo almacenar temporalmente el archivo.');
                $batch = ImportBatch::create([
                    'uuid' => $uuid,
                    'organization_id' => $tenant->organization->id,
                    'branch_id' => $tenant->branch->id,
                    'created_by' => $request->user()->id,
                    'type' => $validated['type'],
                    'status' => 'analyzed',
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'extension' => $extension,
                    'storage_path' => $path,
                    'file_sha256' => $sha,
                    'file_size' => $file->getSize(),
                    'headers' => $sheet['headers'],
                    'mapping' => $mapping,
                    'total_rows' => count($sheet['rows']),
                ]);
                $this->audit('import.uploaded', $batch, $request, $tenant, ['rows' => count($sheet['rows'])]);

                return [[
                    'data' => $batch,
                    'definition' => Arr::except($definition, ['rules']),
                ], 201];
            },
        );

        return response()->json($body, $status)
            ->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    public function show(ImportBatch $importBatch, TenantContext $tenant): JsonResponse
    {
        $this->authorize($tenant);
        $this->assertTenant($importBatch, $tenant);

        return response()->json(['data' => $importBatch]);
    }

    public function dryRun(
        ImportBatch $importBatch,
        Request $request,
        TenantContext $tenant,
        ImportValidator $validator,
    ): JsonResponse {
        $this->authorize($tenant);
        $this->assertTenant($importBatch, $tenant);
        abort_unless(in_array($importBatch->status, ['analyzed', 'invalid', 'validated'], true), 409, 'El lote ya fue confirmado.');
        abort_if($importBatch->file_purged_at !== null, 409, 'El archivo temporal venció; cree un nuevo lote.');
        $mapping = $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string', 'max:255'],
        ])['mapping'];
        $path = Storage::disk(config('imports.disk'))->path($importBatch->storage_path);
        abort_unless(hash_file('sha256', $path) === $importBatch->file_sha256, 409, 'El archivo temporal cambió desde el análisis.');
        $result = $validator->validate(
            $path,
            $importBatch->extension,
            $importBatch->type,
            array_filter($mapping, fn ($value): bool => $value !== null && $value !== ''),
            $tenant->organization->id,
            $tenant->branch->id,
        );
        $errorRows = count(array_unique(array_column($result['errors'], 'row')));
        $importBatch->update([
            'status' => $result['errors'] === [] ? 'validated' : 'invalid',
            'mapping' => $mapping,
            'preview' => $result['preview'],
            'errors' => $result['errors'],
            'valid_rows' => count($result['rows']) - $errorRows,
            'error_rows' => $errorRows,
            'validated_at' => now(),
        ]);
        $this->audit('import.dry_run', $importBatch, $request, $tenant, [
            'valid_rows' => $importBatch->valid_rows,
            'error_rows' => $importBatch->error_rows,
        ]);

        return response()->json(['data' => $importBatch->fresh()]);
    }

    public function confirm(
        ImportBatch $importBatch,
        Request $request,
        TenantContext $tenant,
        IdempotentAction $idempotency,
    ): JsonResponse {
        $this->authorize($tenant);
        $this->assertTenant($importBatch, $tenant);
        abort_unless($request->hasHeader('Idempotency-Key'), 400, 'Idempotency-Key header is required.');
        [$body, $status, $replayed] = $idempotency->run(
            "organizations.{$tenant->organization->id}.imports.{$importBatch->id}.confirm",
            (string) $request->header('Idempotency-Key'),
            ['batch' => $importBatch->uuid, 'sha256' => $importBatch->file_sha256],
            function () use ($importBatch, $request, $tenant): array {
                $locked = ImportBatch::query()->lockForUpdate()->findOrFail($importBatch->id);
                abort_unless($locked->status === 'validated' && $locked->error_rows === 0, 409, 'Se requiere un dry-run válido y sin errores.');
                $locked->update([
                    'status' => 'queued',
                    'confirmed_at' => now(),
                    'idempotency_key' => (string) $request->header('Idempotency-Key'),
                ]);
                ProcessImportBatch::dispatch($locked->id)->afterCommit();
                $this->audit('import.confirmed', $locked, $request, $tenant);

                return [['data' => $locked->fresh()], 202];
            },
        );

        return response()->json($body, $status)
            ->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    public function errors(ImportBatch $importBatch, TenantContext $tenant): Response
    {
        $this->authorize($tenant);
        $this->assertTenant($importBatch, $tenant);
        $errors = $importBatch->errors ?? [];
        $stream = fopen('php://temp', 'w+b');
        abort_if($stream === false, 500, 'No se pudo preparar el informe.');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['row', 'field', 'message']);
        foreach ($errors as $error) {
            fputcsv($stream, array_map($this->safeSpreadsheetCell(...), [
                (string) $error['row'],
                (string) $error['field'],
                (string) $error['message'],
            ]));
        }
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return response($contents, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"import-errors-{$importBatch->uuid}.csv\"",
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function rollback(
        ImportBatch $importBatch,
        Request $request,
        TenantContext $tenant,
        IdempotentAction $idempotency,
        ImportRollback $rollback,
    ): JsonResponse {
        $this->authorize($tenant);
        $this->assertTenant($importBatch, $tenant);
        abort_unless($request->hasHeader('Idempotency-Key'), 400, 'Idempotency-Key header is required.');
        [$body, $status, $replayed] = $idempotency->run(
            "organizations.{$tenant->organization->id}.imports.{$importBatch->id}.rollback",
            (string) $request->header('Idempotency-Key'),
            ['batch' => $importBatch->uuid],
            function () use ($importBatch, $request, $tenant, $rollback): array {
                $locked = ImportBatch::query()->lockForUpdate()->findOrFail($importBatch->id);
                $result = $rollback->execute($locked);
                $locked->update(['status' => 'rolled_back', 'rolled_back_at' => now()]);
                $this->audit('import.rolled_back', $locked, $request, $tenant, $result);

                return [['data' => $locked->fresh(), 'result' => $result], 200];
            },
        );

        return response()->json($body, $status)
            ->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    public function template(
        string $type,
        ImportDefinition $definitions,
        TenantContext $tenant,
    ): Response {
        $this->authorize($tenant);
        abort_unless(in_array($type, ImportDefinition::TYPES, true), 404);
        $definition = $definitions->for($type);
        $stream = fopen('php://temp', 'w+b');
        abort_if($stream === false, 500, 'No se pudo preparar la plantilla.');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $definition['fields']);
        fputcsv($stream, array_map($this->safeSpreadsheetCell(...), $definition['example']));
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return response($contents, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"plantilla-{$type}.csv\"",
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function authorize(TenantContext $tenant): void
    {
        abort_unless($tenant->can('owner', 'admin'), 403);
    }

    private function assertTenant(ImportBatch $batch, TenantContext $tenant): void
    {
        abort_unless(
            $batch->organization_id === $tenant->organization->id
                && $batch->branch_id === $tenant->branch->id,
            404,
        );
    }

    /** @param array<string, mixed> $context */
    private function audit(
        string $event,
        ImportBatch $batch,
        Request $request,
        TenantContext $tenant,
        array $context = [],
    ): void {
        DB::table('audit_logs')->insert([
            'event' => $event,
            'subject_type' => ImportBatch::class,
            'subject_id' => $batch->id,
            'actor_type' => User::class,
            'actor_id' => $request->user()->id,
            'context' => json_encode([
                'organization_id' => $tenant->organization->id,
                'branch_id' => $tenant->branch->id,
                'type' => $batch->type,
                ...$context,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function safeSpreadsheetCell(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'{$value}";
        }

        return $value;
    }
}
