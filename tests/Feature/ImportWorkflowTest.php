<?php

namespace Tests\Feature;

use App\Jobs\ProcessImportBatch;
use App\Models\Branch;
use App\Models\ImportBatch;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Support\Imports\ImportExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

final class ImportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    private User $admin;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->organization = Organization::create(['name' => 'Imports A']);
        $this->branch = Branch::create([
            'organization_id' => $this->organization->id,
            'name' => 'Centro',
            'active' => true,
        ]);
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->organization, ['role' => 'admin']);
        $this->admin->branches()->attach($this->branch);
        $this->headers = [
            'X-Organization-ID' => (string) $this->organization->id,
            'X-Branch-ID' => (string) $this->branch->id,
        ];
        $this->actingAs($this->admin)->withHeaders($this->headers);
    }

    public function test_csv_dry_run_confirmation_and_repeated_job_are_safe(): void
    {
        Queue::fake();
        $batchId = $this->upload(
            'customers',
            "name,tax_id,email,credit_limit,active\nCliente Importado,30710000999,cliente@example.test,1200.50,true\n",
        );

        $this->postJson("/api/v1/imports/{$batchId}/dry-run", [
            'mapping' => [
                'name' => 'name',
                'tax_id' => 'tax_id',
                'email' => 'email',
                'credit_limit' => 'credit_limit',
                'active' => 'active',
            ],
        ])->assertOk()
            ->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.valid_rows', 1)
            ->assertJsonPath('data.error_rows', 0);

        $confirmHeaders = [...$this->headers, 'Idempotency-Key' => 'confirm-customers-1'];
        $this->withHeaders($confirmHeaders)->postJson("/api/v1/imports/{$batchId}/confirm")
            ->assertStatus(202)->assertHeader('Idempotency-Replayed', 'false');
        $this->withHeaders($confirmHeaders)->postJson("/api/v1/imports/{$batchId}/confirm")
            ->assertStatus(202)->assertHeader('Idempotency-Replayed', 'true');
        Queue::assertPushed(ProcessImportBatch::class, 1);

        $job = new ProcessImportBatch($batchId);
        $job->handle(app(ImportExecutor::class));
        $job->handle(app(ImportExecutor::class));

        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseHas('customers', [
            'organization_id' => $this->organization->id,
            'name' => 'Cliente Importado',
            'credit_limit' => '1200.50',
        ]);
        $this->assertDatabaseHas('import_batches', ['id' => $batchId, 'status' => 'completed']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'import.completed', 'subject_id' => $batchId]);
    }

    public function test_dry_run_reports_formulas_duplicates_missing_columns_and_exports_safe_errors(): void
    {
        $batchId = $this->upload(
            'customers',
            "source_name,email\n=1+1,bad-email\nCliente repetido,bad-email\nCliente repetido,ok@example.test\n",
        );
        $this->postJson("/api/v1/imports/{$batchId}/dry-run", [
            'mapping' => ['name' => 'source_name', 'email' => 'email'],
        ])->assertOk()
            ->assertJsonPath('data.status', 'invalid')
            ->assertJsonPath('data.error_rows', 3)
            ->assertJsonFragment(['message' => 'Las fórmulas no están permitidas.'])
            ->assertJsonFragment(['message' => 'La clave natural está duplicada dentro del archivo.']);

        $download = $this->get("/api/v1/imports/{$batchId}/errors");
        $download->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringNotContainsString('HYPERLINK', (string) $download->getContent());

        $this->postJson("/api/v1/imports/{$batchId}/dry-run", [
            'mapping' => ['email' => 'email'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['mapping.name']);
    }

    public function test_xlsx_is_supported_and_cross_tenant_batches_are_hidden(): void
    {
        $batchId = $this->upload(
            'locations',
            $this->xlsx([['name', 'active'], ['Depósito XLSX', 'true']]),
            'pilot.xlsx',
        );
        $this->postJson("/api/v1/imports/{$batchId}/dry-run", [
            'mapping' => ['name' => 'name', 'active' => 'active'],
        ])->assertOk()->assertJsonPath('data.status', 'validated');

        $otherOrganization = Organization::create(['name' => 'Imports B']);
        $otherBranch = Branch::create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Otra',
            'active' => true,
        ]);
        $otherUser = User::factory()->create();
        $otherUser->organizations()->attach($otherOrganization, ['role' => 'admin']);
        $otherUser->branches()->attach($otherBranch);

        $this->actingAs($otherUser)->withHeaders([
            'X-Organization-ID' => (string) $otherOrganization->id,
            'X-Branch-ID' => (string) $otherBranch->id,
        ])->getJson("/api/v1/imports/{$batchId}")->assertNotFound();
    }

    public function test_row_limit_and_permissions_are_enforced(): void
    {
        config(['imports.max_rows' => 2]);
        $this->withHeaders(['Accept' => 'application/json', 'Idempotency-Key' => 'upload-too-many'])->post('/api/v1/imports', [
            'type' => 'locations',
            'file' => UploadedFile::fake()->createWithContent(
                'too-many.csv',
                "name,active\nA,true\nB,true\nC,true\n",
            ),
        ])->assertUnprocessable();

        $viewer = User::factory()->create();
        $viewer->organizations()->attach($this->organization, ['role' => 'viewer']);
        $viewer->branches()->attach($this->branch);
        $this->actingAs($viewer)->withHeaders($this->headers)
            ->getJson('/api/v1/imports/definitions')->assertForbidden();
    }

    public function test_initial_stock_uses_immutable_movements_and_safe_compensating_rollback(): void
    {
        Product::create([
            'organization_id' => $this->organization->id,
            'name' => 'Harina base',
            'type' => 'ingredient',
            'unit' => 'kg',
            'minimum_stock' => '0.000',
            'price' => '0.00',
            'active' => true,
        ]);
        Location::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'name' => 'Depósito',
            'active' => true,
        ]);
        $batchId = $this->upload(
            'initial_stock',
            "product_name,location_name,lot_code,quantity,unit,manufactured_on,expires_on\n"
                ."Harina base,Depósito,INICIAL-01,25.500,kg,2026-07-01,2026-12-31\n",
        );
        $mapping = [
            'product_name' => 'product_name',
            'location_name' => 'location_name',
            'lot_code' => 'lot_code',
            'quantity' => 'quantity',
            'unit' => 'unit',
            'manufactured_on' => 'manufactured_on',
            'expires_on' => 'expires_on',
        ];
        $this->postJson("/api/v1/imports/{$batchId}/dry-run", ['mapping' => $mapping])
            ->assertOk()->assertJsonPath('data.status', 'validated');
        Queue::fake();
        $headers = [...$this->headers, 'Idempotency-Key' => 'confirm-stock-1'];
        $this->withHeaders($headers)->postJson("/api/v1/imports/{$batchId}/confirm")->assertStatus(202);
        (new ProcessImportBatch($batchId))->handle(app(ImportExecutor::class));

        $this->assertDatabaseHas('inventory_lots', ['code' => 'INICIAL-01', 'quantity' => '25.500']);
        $this->assertDatabaseHas('stock_movements', ['reason' => 'initial_import', 'quantity' => '25.500']);

        $rollbackHeaders = [...$this->headers, 'Idempotency-Key' => 'rollback-stock-1'];
        $this->withHeaders($rollbackHeaders)->postJson("/api/v1/imports/{$batchId}/rollback")
            ->assertOk()->assertJsonPath('result.compensated_lots', 1);
        $this->withHeaders($rollbackHeaders)->postJson("/api/v1/imports/{$batchId}/rollback")
            ->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertDatabaseHas('inventory_lots', ['code' => 'INICIAL-01', 'quantity' => '0.000', 'status' => 'depleted']);
        $this->assertDatabaseHas('stock_movements', ['reason' => 'initial_import_rollback', 'quantity' => '-25.500']);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_expired_files_are_pruned_without_deleting_audit_metadata(): void
    {
        $batchId = $this->upload('locations', "name,active\nTemporal,true\n");
        $batch = ImportBatch::findOrFail($batchId);
        $batch->update(['created_at' => now()->subHours(3)]);
        Storage::disk('local')->assertExists($batch->storage_path);

        $this->artisan('bakery:imports-prune', ['--hours' => 1])
            ->expectsOutput('Archivos temporales purgados: 1.')
            ->assertSuccessful();

        Storage::disk('local')->assertMissing($batch->storage_path);
        $this->assertDatabaseHas('import_batches', ['id' => $batchId, 'status' => 'analyzed']);
        $this->assertNotNull($batch->fresh()->file_purged_at);
    }

    public function test_upload_is_idempotent_and_rejects_key_reuse_with_different_file(): void
    {
        $headers = [...$this->headers, 'Idempotency-Key' => 'upload-locations-once'];
        $payload = fn (string $name): array => [
            'type' => 'locations',
            'file' => UploadedFile::fake()->createWithContent('locations.csv', "name,active\n{$name},true\n"),
        ];

        $this->withHeaders($headers)->post('/api/v1/imports', $payload('Depósito único'))
            ->assertCreated()->assertHeader('Idempotency-Replayed', 'false');
        $this->withHeaders($headers)->post('/api/v1/imports', $payload('Depósito único'))
            ->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertDatabaseCount('import_batches', 1);

        $this->withHeaders([...$headers, 'Accept' => 'application/json'])
            ->post('/api/v1/imports', $payload('Otro depósito'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['Idempotency-Key']);
    }

    public function test_definitions_and_templates_publish_headers_examples_and_allowed_values(): void
    {
        $this->getJson('/api/v1/imports/definitions')
            ->assertOk()
            ->assertJsonPath('data.products.required.0', 'name')
            ->assertJsonPath('data.products.allowed.type.0', 'raw_material')
            ->assertJsonMissingPath('data.products.rules');

        $template = $this->get('/api/v1/import-templates/products');
        $template->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $contents = (string) $template->getContent();
        $this->assertStringContainsString('name,type,unit,minimum_stock,price,active', $contents);
        $this->assertStringContainsString('"Harina piloto",raw_material,kg,20.000,0.00,true', $contents);
    }

    private function upload(string $type, string $contents, string $name = 'pilot.csv'): int
    {
        $response = $this->withHeader('Idempotency-Key', 'upload-'.Str::uuid())->post('/api/v1/imports', [
            'type' => $type,
            'file' => UploadedFile::fake()->createWithContent($name, $contents),
        ]);
        $response->assertCreated()
            ->assertJsonPath('data.type', $type)
            ->assertJsonPath('data.status', 'analyzed');

        return (int) $response->json('data.id');
    }

    /** @param list<list<string>> $rows */
    private function xlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'import-test-xlsx-');
        $this->assertNotFalse($path);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::OVERWRITE) === true);
        $sheet = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $rowIndex => $row) {
            $number = $rowIndex + 1;
            $sheet .= "<row r=\"{$number}\">";
            foreach ($row as $columnIndex => $value) {
                $column = chr(65 + $columnIndex);
                $escaped = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $sheet .= "<c r=\"{$column}{$number}\" t=\"inlineStr\"><is><t>{$escaped}</t></is></c>";
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Datos" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
        $contents = file_get_contents($path);
        unlink($path);
        $this->assertIsString($contents);

        return $contents;
    }
}
