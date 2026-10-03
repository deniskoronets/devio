<?php

namespace Dekor\Devio;

use RuntimeException;

/** A command exited non-zero. Devio exits with the same code. */
final class ProcessFailed extends RuntimeException
{
    private const ERROR_LINES = 20;

    public function __construct(
        public readonly string $command,
        public readonly int $exitCode,
        public readonly ?string $host = null,
        public readonly string $errorOutput = '',
    ) {
        $message = "`$command` failed with exit code $exitCode" . ($host !== null ? " on $host" : '');
        if ($host !== null && $exitCode === 255) {
            $message .= ' (ssh could not connect?)';
        }

        $errors = array_filter(explode("\n", trim($errorOutput)), fn ($line) => trim($line) !== '');
        if ($errors) {
            $message .= "\n" . implode("\n", array_slice($errors, -self::ERROR_LINES));
        }

        parent::__construct($message, $exitCode);
    }
}
