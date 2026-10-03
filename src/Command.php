<?php

namespace Dekor\Devio;

use Closure;
use Throwable;

/** A command registered with Devio::command(). */
final class Command
{
    private bool $plain = false;

    public function __construct(
        public readonly string $name,
        private readonly Closure $handler,
        private readonly string $description = '',
    ) {
    }

    /** Registers the next command, for chaining. */
    public function command(string $name, Closure $handler, string $description = ''): self
    {
        return Devio::command($name, $handler, $description);
    }

    /**
     * For thin wrappers (art, composer, logs): devio prints nothing of its own, only the command's output,
     * and a failure doesn't trigger Devio::onFailure().
     */
    public function plain(): self
    {
        $this->plain = true;

        return $this;
    }

    public function description(): string
    {
        return $this->description;
    }

    /** @internal Runs the handler and reports how it ended; returns the exit code. */
    public function execute(Args $args): int
    {
        $start = microtime(true);
        Console::plain($this->plain);

        try {
            $result = ($this->handler)($args);
            $code = is_int($result) ? $result : 0;
            if (! $this->plain) {
                Console::finished($this->name, $start, $code === 0);
            }

            return $code;
        } catch (Throwable $e) {
            // a plain command's failing process has already said why
            if (! $this->plain || ! $e instanceof ProcessFailed) {
                Console::exception($e);
            }
            if (! $this->plain) {
                Console::finished($this->name, $start, false);
                Devio::failed($e, $this->name);
            }

            return $e instanceof ProcessFailed && $e->exitCode > 0 ? $e->exitCode : 1;
        } finally {
            Console::plain(false);
        }
    }
}
