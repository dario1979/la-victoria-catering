<?php

namespace App\Support;

use Stringable;
use Throwable;

final class SensitiveDataRedactor
{
    public const REDACTED = '[REDACTED]';

    private const SENSITIVE_KEY_PARTS = [
        'authorization',
        'password',
        'passwd',
        'secret',
        'token',
        'cookie',
        'signature',
        'certificate',
        'privatekey',
        'publickey',
        'vapid',
        'cardnumber',
        'cardholder',
        'securitycode',
        'cvv',
        'cvc',
        'pan',
        'fiscalpayload',
        'fiscalrequest',
        'fiscalresponse',
        'taxid',
    ];

    public function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->sensitiveKey($key)) {
            return self::REDACTED;
        }
        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $nestedKey => $nestedValue) {
                $redacted[$nestedKey] = $this->redact($nestedValue, (string) $nestedKey);
            }

            return $redacted;
        }
        if ($value instanceof Throwable) {
            return [
                'type' => $value::class,
                'message' => $this->redactString($value->getMessage()),
            ];
        }
        if ($value instanceof Stringable) {
            return $this->redactString((string) $value);
        }
        if (is_string($value)) {
            return $this->redactString($value);
        }
        if (is_object($value)) {
            return ['type' => $value::class];
        }

        return $value;
    }

    public function redactString(string $value): string
    {
        $value = preg_replace(
            '/\b(Bearer|Basic)\s+[A-Za-z0-9+\/=._~-]+/i',
            '$1 '.self::REDACTED,
            $value,
        ) ?? $value;
        $value = preg_replace(
            '/\b(password|passwd|token|secret|signature|cookie|authorization|vapid(?:_key)?|webhook_secret|certificate)\b(\s*[=:]\s*)(?:"[^"]*"|\'[^\']*\'|[^\s,;]+)/i',
            '$1$2'.self::REDACTED,
            $value,
        ) ?? $value;

        return preg_replace('/(?<!\d)(?:\d[ -]*?){13,19}(?!\d)/', self::REDACTED, $value) ?? $value;
    }

    private function sensitiveKey(string $key): bool
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));

        return array_any(
            self::SENSITIVE_KEY_PARTS,
            fn (string $part): bool => str_contains($normalized, $part),
        );
    }
}
