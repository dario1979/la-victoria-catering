<?php

namespace App\Support;

use Illuminate\Support\Str;

final class CorrelationId
{
    public const HEADER = 'X-Request-ID';

    public static function resolve(mixed $candidate): string
    {
        if (is_string($candidate) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $candidate) === 1) {
            return $candidate;
        }

        return (string) Str::uuid();
    }
}
