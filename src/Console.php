<?php

namespace Dekor\Devio;

use Closure;
use Throwable;

/**
 * The log: steps, tasks, the commands devio runs and how they ended, how long they took.
 * It goes to stderr, so a command's own stdout stays clean for pipes and files.
 *
 *   ● build 3f2a1c9e0b12
 *     $ docker build --tag app:3f2a1c9e0b12 .
 *     ...docker's own output...
 *     ✔ 41.8s
 *
 *   ✔ deploy finished in 1m 12s
 */
final class Console
{
    private const COLORS = ['bold' => '1', 'red' => '31', 'green' => '32', 'yellow' => '33', 'blue' => '34', 'magenta' => '35', 'cyan' => '36', 'gray' => '90'];

    /** @var resource|null */
    private static $stream = null;

    private static ?bool $colors = null;

    private static bool $verbose = false;

    private static bool $plain = false;

    private static int $depth = 0;

    private static bool $written = false;

    /** @var string[] */
    private static array $secrets = [];

    // ---- logging ----------------------------------------------------------------------------------

    /** A heading for what comes next: ● message */
    public static function step(string $message): void
    {
        if (self::$depth === 0 && self::$written) {
            self::write('');
        }
        self::line(self::paint('cyan', '●') . ' ' . self::paint('bold', $message));
    }

    /** A step that reports how it ended and how long it took; what runs inside is indented. */
    public static function task(string $title, Closure $fn): mixed
    {
        self::step($title);
        $start = microtime(true);
        $ok = false;
        self::$depth++;

        try {
            $result = $fn();
            $ok = true;

            return $result;
        } finally {
            self::$depth--;
            self::line(($ok ? self::paint('green', "✔ $title") : self::paint('red', "✖ $title")) . ' ' . self::paint('gray', self::duration($start)));
        }
    }

    public static function info(string $message): void
    {
        self::line('  ' . $message);
    }

    public static function success(string $message): void
    {
        self::line(self::paint('green', '✔') . ' ' . $message);
    }

    public static function warn(string $message): void
    {
        self::line(self::paint('yellow', '▲ ' . $message));
    }

    public static function error(string $message): void
    {
        $lines = explode("\n", $message);
        self::line(self::paint('red', '✖ ' . array_shift($lines)));
        foreach ($lines as $line) {
            self::line('  ' . self::paint('gray', $line));
        }
    }

    /** Stops the command: devio prints the message and exits 1. */
    public static function fail(string $message): never
    {
        throw new Failed($message);
    }

    // ---- input ------------------------------------------------------------------------------------

    /** Yes/no question; without a terminal (CI, pipes) the default is the answer. */
    public static function confirm(string $question, bool $default = false): bool
    {
        if (! stream_isatty(STDIN)) {
            return $default;
        }
        $answer = strtolower(trim(self::prompt($question . ($default ? ' [Y/n]' : ' [y/N]'))));

        return $answer === '' ? $default : in_array($answer, ['y', 'yes'], true);
    }

    public static function ask(string $question, ?string $default = null): string
    {
        $answer = stream_isatty(STDIN) ? trim(self::prompt($question . ($default !== null ? " [$default]" : ''))) : '';

        return $answer !== '' ? $answer : ($default ?? '');
    }

    // ---- settings ---------------------------------------------------------------------------------

    /** Hides these values wherever they would be printed. */
    public static function mask(string ...$secrets): void
    {
        self::$secrets = array_values(array_unique([...self::$secrets, ...array_filter($secrets, fn ($s) => $s !== '')]));
    }

    /** Also logs the commands devio runs only to look at things (git rev-parse, docker image inspect, ...). */
    public static function verbose(bool $verbose = true): void
    {
        self::$verbose = $verbose;
    }

    public static function colors(bool $colors): void
    {
        self::$colors = $colors;
    }

    /** @internal Where the log goes (stderr by default). */
    public static function to($stream): void
    {
        self::$stream = $stream;
    }

    /** @internal No devio lines of its own while a plain command runs, only what the command prints. */
    public static function plain(bool $plain): void
    {
        self::$plain = $plain;
    }

    // ---- hooks for Shell and Devio ----------------------------------------------------------------

    /** @internal */
    public static function commandStarted(string $command, ?string $host): float
    {
        if (! self::$plain) {
            $prompt = ($host !== null ? self::paint('magenta', $host) . ' ' : '') . self::paint('gray', '$');
            self::line('  ' . $prompt . ' ' . self::fit($command, strlen($host ?? '') + 6));
        }

        return microtime(true);
    }

    /** @internal */
    public static function commandFinished(float $start, int $code): void
    {
        if (! self::$plain) {
            $status = $code === 0 ? self::paint('green', '✔') : self::paint('red', "✖ exit $code");
            self::line('  ' . $status . ' ' . self::paint('gray', self::duration($start)));
        }
    }

    /** @internal Captured commands are logged in verbose mode only, as one line once they end. */
    public static function captured(Result $result, float $start): void
    {
        if (self::$verbose && ! self::$plain) {
            $prompt = ($result->host !== null ? self::paint('magenta', $result->host) . ' ' : '') . self::paint('gray', '$');
            $status = $result->successful() ? self::paint('green', '✔') : self::paint('red', "✖ exit $result->exitCode");
            self::line('  ' . $prompt . ' ' . self::paint('gray', $result->command) . ' ' . $status . ' ' . self::paint('gray', self::duration($start)));
        }
    }

    /** @internal */
    public static function finished(string $command, float $start, bool $ok): void
    {
        self::write('');
        self::line($ok
            ? self::paint('green', "✔ $command") . ' ' . self::paint('gray', 'finished in ' . self::duration($start))
            : self::paint('red', "✖ $command") . ' ' . self::paint('gray', 'failed after ' . self::duration($start)));
    }

    /** @internal */
    public static function exception(Throwable $e): void
    {
        if ($e instanceof ProcessFailed || $e instanceof Failed) {
            self::error($e->getMessage());

            return;
        }

        self::error(get_class($e) . ': ' . $e->getMessage() . (self::$verbose ? "\n" . $e->getTraceAsString() : ''));
        if (! self::$verbose) {
            self::line('  ' . self::paint('gray', "at {$e->getFile()}:{$e->getLine()}, -v for the trace"));
        }
    }

    // ---- output -----------------------------------------------------------------------------------

    private static function line(string $text): void
    {
        self::write(str_repeat('  ', self::$depth) . $text);
    }

    private static function write(string $text): void
    {
        if (self::$secrets) {
            $text = str_replace(self::$secrets, '••••', $text);
        }
        fwrite(self::$stream ?? STDERR, $text . "\n");
        self::$written = true;
    }

    private static function prompt(string $question): string
    {
        fwrite(self::$stream ?? STDERR, str_repeat('  ', self::$depth) . self::paint('cyan', '?') . " $question ");

        return fgets(STDIN) ?: '';
    }

    private static function paint(string $color, string $text): string
    {
        self::$colors ??= getenv('FORCE_COLOR') !== false
            || (getenv('NO_COLOR') === false && self::$stream === null && stream_isatty(STDERR));

        return self::$colors ? "\033[" . self::COLORS[$color] . 'm' . $text . "\033[0m" : $text;
    }

    /** Long commands (docker build ...) are cut to one terminal line, unless verbose. */
    private static function fit(string $command, int $used): string
    {
        $width = self::$colors && ! self::$verbose ? self::width() - self::$depth * 2 - $used : 0;

        return $width > 20 && strlen($command) > $width ? substr($command, 0, $width - 1) . '…' : $command;
    }

    private static function width(): int
    {
        static $width = null;
        if ($width === null) {
            $width = (int) getenv('COLUMNS');
            if ($width <= 0 && ($process = @proc_open(['stty', 'size'], [['file', '/dev/tty', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w']], $pipes))) {
                $size = stream_get_contents($pipes[1]);
                proc_close($process);
                $width = (int) (explode(' ', trim($size))[1] ?? 0);
            }
            $width = $width > 0 ? $width : 120;
        }

        return $width;
    }

    private static function duration(float $start): string
    {
        $seconds = microtime(true) - $start;

        return $seconds < 60
            ? sprintf('%.1fs', $seconds)
            : sprintf('%dm %02ds', intdiv((int) $seconds, 60), (int) $seconds % 60);
    }
}
