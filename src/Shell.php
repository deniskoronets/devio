<?php

namespace Dekor\Devio;

use Closure;
use Dekor\Devio\Support\Context;

/**
 * Runs commands. Locally by default; inside Ssh::to('name')->run(fn) everything runs on that host.
 * Commands are argv arrays, never parsed by a shell (except pipe()), so arguments need no escaping.
 */
final class Shell
{
    /** @var Context[] */
    private static array $contexts = [];

    /** Runs attached to the terminal (output streams, prompts work); throws ProcessFailed if it fails. */
    public static function run(string ...$command): void
    {
        self::stream($command);
    }

    /** Runs a shell pipeline with bash; fails if any part of it fails. */
    public static function pipe(string $pipeline): void
    {
        self::stream(['bash', '-o', 'pipefail', '-c', $pipeline], $pipeline);
    }

    /** stdout of the command, trimmed; throws ProcessFailed if it fails. */
    public static function output(string ...$command): string
    {
        return trim(self::process($command)->throw()->output);
    }

    /** Non-empty lines of stdout; throws ProcessFailed if it fails. */
    public static function lines(string ...$command): array
    {
        return array_values(array_filter(explode("\n", self::output(...$command)), fn ($line) => trim($line) !== ''));
    }

    /** Runs silently, tells whether it succeeded. Never throws. */
    public static function succeeds(string ...$command): bool
    {
        return self::process($command)->successful();
    }

    /** Runs silently, returns exit code, stdout and stderr. Never throws. */
    public static function capture(string ...$command): Result
    {
        return self::process($command);
    }

    /** Runs $fn with commands in $dir (relative to the current dir, or the host dir on a host). */
    public static function in(string $dir, Closure $fn): mixed
    {
        return self::within(self::context()->in($dir), $fn);
    }

    /** Runs $fn with extra env vars for its commands. */
    public static function env(array $vars, Closure $fn): mixed
    {
        return self::within(self::context()->with($vars), $fn);
    }

    /** Inside a host closure: runs $fn locally. */
    public static function local(Closure $fn): mixed
    {
        return self::within(new Context(), $fn);
    }

    /** @internal */
    public static function within(Context $context, Closure $fn): mixed
    {
        self::$contexts[] = $context;

        try {
            return $fn();
        } finally {
            array_pop(self::$contexts);
        }
    }

    /** @internal */
    public static function context(): Context
    {
        return end(self::$contexts) ?: new Context();
    }

    /** @internal Runs attached to the terminal, logged as $label. */
    public static function stream(array $command, ?string $label = null): void
    {
        $context = self::context();
        $label = self::label($command, $label, $context);
        $interactive = stream_isatty(STDIN) && stream_isatty(STDOUT);
        [$argv, $cwd, $env] = self::prepare($command, $context, $interactive);

        $start = Console::commandStarted($label, $context->host());
        // descriptors not listed are inherited; passing STDOUT itself would rewind it when it is a file
        $process = @proc_open($argv, [], $pipes, $cwd, $env);
        $code = $process === false ? 127 : proc_close($process);
        Console::commandFinished($start, $code);

        if ($code !== 0) {
            throw new ProcessFailed($label, $code, $context->host());
        }
    }

    /** @internal Runs with output captured; $input is fed to stdin. */
    public static function process(array $command, ?string $label = null, ?string $input = null): Result
    {
        $context = self::context();
        $label = self::label($command, $label, $context);
        [$argv, $cwd, $env] = self::prepare($command, $context, false);

        $start = microtime(true);
        $stderr = tmpfile(); // a file, not a pipe: no deadlock when both stdout and stderr are large
        $stdin = $input === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'];
        $process = @proc_open($argv, [$stdin, ['pipe', 'w'], $stderr], $pipes, $cwd, $env);

        if ($process === false) {
            $result = new Result(127, '', "cannot run {$command[0]}", $label, $context->host());
        } else {
            if ($input !== null) {
                fwrite($pipes[0], $input);
                fclose($pipes[0]);
            }
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $code = proc_close($process);
            rewind($stderr);
            $result = new Result($code, $output, stream_get_contents($stderr), $label, $context->host());
        }
        fclose($stderr);

        Console::captured($result, $start);

        return $result;
    }

    /** @return array{0: array, 1: ?string, 2: ?array} argv, cwd, env */
    private static function prepare(array $command, Context $context, bool $interactive): array
    {
        while ($context->wrap !== null) {
            [$command, $context] = ($context->wrap)($command, $context, $interactive);
        }

        if ($context->connection === null) {
            return [$command, $context->dir, $context->env ? [...getenv(), ...$context->env] : null];
        }

        $line = implode(' ', array_map('escapeshellarg', $command));
        if ($context->env) {
            $line = 'env ' . self::assignments($context->env, 'escapeshellarg') . ' ' . $line;
        }
        if ($context->dir !== null) {
            $line = 'cd ' . escapeshellarg($context->dir) . ' && ' . $line;
        }

        return [$context->connection->sshCommand($line, $interactive), null, null];
    }

    private static function label(array $command, ?string $label, Context $context): string
    {
        $label ??= implode(' ', array_map([self::class, 'quote'], $command));

        return $context->env ? self::assignments($context->env, [self::class, 'quote']) . ' ' . $label : $label;
    }

    private static function assignments(array $env, callable $quote): string
    {
        return implode(' ', array_map(fn ($key, $value) => $key . '=' . $quote($value), array_keys($env), $env));
    }

    /** Quotes for display only where needed, so logged commands stay readable. */
    private static function quote(string $arg): string
    {
        return preg_match('~^[\w./:=@%+,{}-]+$~', $arg) ? $arg : escapeshellarg($arg);
    }
}
