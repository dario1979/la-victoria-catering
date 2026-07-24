<?php

namespace App\Support;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class IdempotentAction
{
    public function run(string $scope, string $key, array $input, Closure $action): array
    {
        $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));

        try {
            return Cache::lock('idempotency:'.hash('sha256', "{$scope}:{$key}"), 120)
                ->block(15, fn () => $this->execute($scope, $key, $hash, $action));
        } catch (LockTimeoutException) {
            throw new HttpException(503, 'The same operation is still being processed. Retry with the same Idempotency-Key.');
        }
    }

    private function execute(string $scope, string $key, string $hash, Closure $action): array
    {
        $validationFailure = null;

        $result = DB::transaction(function () use ($scope, $key, $hash, $action, &$validationFailure): array {
            $saved = IdempotencyKey::query()->where(compact('scope', 'key'))->lockForUpdate()->first();
            if ($saved) {
                if (! hash_equals($saved->request_hash, $hash)) {
                    throw ValidationException::withMessages(['Idempotency-Key' => ['Key was already used with another request.']]);
                }

                return [$saved->response_body, $saved->response_status, true];
            }
            try {
                [$body, $status] = $action();
            } catch (ValidationException $exception) {
                $validationFailure = $exception;

                return [[], 422, false];
            }
            IdempotencyKey::create([
                'scope' => $scope, 'key' => $key, 'request_hash' => $hash,
                'response_status' => $status, 'response_body' => $body,
            ]);

            return [$body, $status, false];
        });

        if ($validationFailure) {
            throw $validationFailure;
        }

        return $result;
    }
}
