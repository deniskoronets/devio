<?php

namespace Dekor\Devio;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The arguments after the command name: `./dev art migrate --force` gives ['migrate', '--force'].
 * Spreads into calls: Docker::compose('exec', 'app', 'php', 'artisan', ...$args).
 */
final class Args implements IteratorAggregate, Countable
{
    /** @var string[] */
    private readonly array $args;

    public function __construct(array $args = [])
    {
        $this->args = array_values(array_map('strval', $args));
    }

    /** @return string[] */
    public function all(): array
    {
        return $this->args;
    }

    public function get(int $index, ?string $default = null): ?string
    {
        return $this->args[$index] ?? $default;
    }

    /** has('--force') */
    public function has(string $flag): bool
    {
        return in_array($flag, $this->args, true);
    }

    /** option('tag') reads --tag=abc */
    public function option(string $name, ?string $default = null): ?string
    {
        foreach ($this->args as $arg) {
            if (str_starts_with($arg, "--$name=")) {
                return substr($arg, strlen($name) + 3);
            }
        }

        return $default;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->args);
    }

    public function count(): int
    {
        return count($this->args);
    }
}
