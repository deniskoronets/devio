<?php

namespace Dekor\Devio\Support;

use Closure;
use Dekor\Devio\SshConnection;

/**
 * @internal Where Shell commands run: locally or on a host, in which dir, with which extra env vars.
 * With $wrap they go through another command, e.g. into a container:
 * fn (array $command, Context $self, bool $interactive): array{0: array, 1: Context} gives the wrapped command
 * and the context that one runs in.
 */
final class Context
{
    /** @var array<string, string> */
    public readonly array $env;

    public function __construct(
        public readonly ?SshConnection $connection = null,
        public readonly ?string $dir = null,
        array $env = [],
        public readonly ?Closure $wrap = null,
        private readonly ?string $name = null,
    ) {
        $this->env = array_map('strval', $env);
    }

    public function in(string $dir): self
    {
        $dir = $this->dir === null || str_starts_with($dir, '/') ? $dir : rtrim($this->dir, '/') . '/' . $dir;

        return new self($this->connection, $dir, $this->env, $this->wrap, $this->name);
    }

    public function with(array $env): self
    {
        return new self($this->connection, $this->dir, [...$this->env, ...$env], $this->wrap, $this->name);
    }

    /** Where commands run, for the log: the host, prod/app in a container on it; null locally. */
    public function host(): ?string
    {
        return $this->name ?? $this->connection?->host->name;
    }
}
