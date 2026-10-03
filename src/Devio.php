<?php

namespace Dekor\Devio;

use Closure;
use Dekor\Devio\Support\PharUpdate;
use Phar;
use Throwable;

/**
 * The command registry and entry point of your script:
 *
 *   Devio::command(name: 'up', description: 'start the dev stack', handler: fn (Args $args) => Docker::compose('up', '-d', ...$args))
 *       ->command(name: 'deploy', description: 'deploy HEAD', handler: function (Args $args) { ... });
 *
 *   Devio::run();
 */
final class Devio
{
    public const VERSION = '1.0.0';

    /** @var array<string, Command> */
    private static array $commands = [];

    /** @var Closure[] */
    private static array $failureHandlers = [];

    private function __construct()
    {
    }

    /** The handler gets the arguments after the command name; it may return an exit code. */
    public static function command(string $name, Closure $handler, string $description = ''): Command
    {
        return self::$commands[$name] = new Command($name, $handler, $description);
    }

    /** Called when a (non-plain) command fails: fn (Throwable $e, string $command). */
    public static function onFailure(Closure $handler): self
    {
        self::$failureHandlers[] = $handler;

        return new self();
    }

    /** Runs the command named in argv and exits with its code. Commands run from the script's directory. */
    public static function run(?array $argv = null): never
    {
        $script = $_SERVER['SCRIPT_FILENAME'] ?? null;
        if ($script !== null && ($dir = dirname(realpath($script) ?: $script)) !== '') {
            chdir($dir);
        }

        exit(self::handle($argv ?? $_SERVER['argv'] ?? []));
    }

    /** @internal run() without the exit, returns the exit code. */
    public static function handle(array $argv): int
    {
        $script = basename((string) (array_shift($argv) ?? 'devio'));
        Console::verbose((bool) getenv('DEVIO_VERBOSE'));

        // from the phar only: a Composer install updates with Composer
        if (class_exists(Phar::class) && ($phar = Phar::running(false)) !== '' && ! isset(self::$commands['devio-update-phar'])) {
            self::command('devio-update-phar', fn () => PharUpdate::run($phar), 'update devio.phar to the latest on GitHub');
        }

        while ($argv && str_starts_with($argv[0], '-')) {
            $option = array_shift($argv);
            switch ($option) {
                case '-v':
                case '--verbose':
                    Console::verbose();
                    break;
                case '--no-color':
                    Console::colors(false);
                    break;
                case '--version':
                    echo 'devio ' . self::VERSION . "\n";

                    return 0;
                case '-h':
                case '--help':
                    return self::help($script, 0);
                default:
                    Console::error("unknown option $option");

                    return self::help($script, 1);
            }
        }

        if (! $argv) {
            return self::help($script, 0);
        }

        $name = array_shift($argv);
        if (! isset(self::$commands[$name])) {
            Console::error("unknown command $name");

            return self::help($script, 1);
        }

        return self::$commands[$name]->execute(new Args($argv));
    }

    /** @internal */
    public static function failed(Throwable $e, string $command): void
    {
        foreach (self::$failureHandlers as $handler) {
            try {
                $handler($e, $command);
            } catch (Throwable $handlerError) {
                Console::warn('onFailure handler failed: ' . $handlerError->getMessage());
            }
        }
    }

    private static function help(string $script, int $code): int
    {
        $width = max([8, ...array_map('strlen', array_keys(self::$commands))]) + 3;

        echo "Usage: $script [-v] <command> [args]\n\nCommands:\n";
        foreach (self::$commands as $name => $command) {
            echo '  ' . str_pad($name, $width) . $command->description() . "\n";
        }
        echo "\nOptions:\n";
        echo '  ' . str_pad('-v', $width) . "verbose: also log the commands devio runs to look at things\n";
        echo '  ' . str_pad('--no-color', $width) . "no colors (NO_COLOR=1 works too)\n";

        return $code;
    }
}
