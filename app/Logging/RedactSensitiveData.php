<?php

namespace App\Logging;

use App\Support\SensitiveDataRedactor;
use Illuminate\Log\Logger;
use Monolog\LogRecord;

final class RedactSensitiveData
{
    public function __construct(private readonly SensitiveDataRedactor $redactor) {}

    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(fn (LogRecord $record): LogRecord => $record->with(
            message: $this->redactor->redactString($record->message),
            context: $this->redactor->redact($record->context),
            extra: $this->redactor->redact($record->extra),
        ));
    }
}
