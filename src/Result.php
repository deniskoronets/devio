<?php

namespace Dekor\Devio;

/** A finished command run with Shell::capture(). */
final class Result
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output,
        public readonly string $errorOutput,
        public readonly string $command,
        public readonly ?string $host = null,
    ) {
    }

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    /** Throws ProcessFailed if the command failed. */
    public function throw(): self
    {
        if (! $this->successful()) {
            throw new ProcessFailed($this->command, $this->exitCode, $this->host, $this->errorOutput);
        }

        return $this;
    }
}
