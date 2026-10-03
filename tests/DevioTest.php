<?php

namespace Dekor\Devio\Tests;

use Dekor\Devio\Args;
use Dekor\Devio\Console;
use Dekor\Devio\Devio;
use Dekor\Devio\Shell;
use RuntimeException;

final class DevioTest extends TestCase
{
    public function test_dispatches_with_args_and_chains(): void
    {
        $got = null;
        Devio::command('one', function (Args $args) use (&$got) {
            $got = $args->all();
        }, 'first')->command('two', fn () => 7);

        $this->assertSame(0, Devio::handle(['dev', 'one', 'a', '--b']));
        $this->assertSame(['a', '--b'], $got);
        $this->assertSame(7, Devio::handle(['dev', 'two']));
    }

    public function test_reports_how_the_command_ended(): void
    {
        Devio::command('ok', fn () => Console::step('working'));

        Devio::handle(['dev', 'ok']);

        $this->assertMatchesRegularExpression('/● working\n\n✔ ok finished in \d+\.\ds\n/', $this->console());
    }

    public function test_a_failing_process_sets_the_exit_code_and_calls_on_failure(): void
    {
        $failures = [];
        Devio::onFailure(function ($e, $command) use (&$failures) {
            $failures[] = $command;
        })->command('broken', fn () => Shell::run('sh', '-c', 'exit 5'));

        $this->assertSame(5, Devio::handle(['dev', 'broken']));
        $this->assertSame(['broken'], $failures);
        $this->assertStringContainsString("✖ `sh -c 'exit 5'` failed with exit code 5", $this->console());
        $this->assertMatchesRegularExpression('/✖ broken failed after /', $this->console());
    }

    public function test_fail_and_unexpected_exceptions_exit_1(): void
    {
        Devio::command('fails', fn () => Console::fail('nope'));
        Devio::command('throws', fn () => throw new RuntimeException('boom'));

        $this->assertSame(1, Devio::handle(['dev', 'fails']));
        $this->assertSame(1, Devio::handle(['dev', 'throws']));
        $this->assertStringContainsString('✖ nope', $this->console());
        $this->assertStringContainsString('✖ RuntimeException: boom', $this->console());
    }

    public function test_plain_commands_print_nothing_of_their_own(): void
    {
        $called = false;
        Devio::onFailure(function () use (&$called) {
            $called = true;
        });
        Devio::command('art', fn (Args $args) => Shell::run('sh', '-c', ...$args))->plain();

        $this->assertSame(0, Devio::handle(['dev', 'art', 'true']));
        $this->assertSame(3, Devio::handle(['dev', 'art', 'exit 3']));
        $this->assertSame('', $this->console());
        $this->assertFalse($called);
    }

    public function test_help_and_unknown_commands(): void
    {
        Devio::command('up', fn () => null, 'start the stack');

        ob_start();
        $code = Devio::handle(['dev']);
        $help = ob_get_clean();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Usage: dev [-v] <command> [args]', $help);
        $this->assertMatchesRegularExpression('/  up +start the stack/', $help);

        ob_start();
        $this->assertSame(1, Devio::handle(['dev', 'nope']));
        ob_end_clean();
        $this->assertStringContainsString('✖ unknown command nope', $this->console());
    }

    public function test_verbose_flag(): void
    {
        Devio::command('look', fn () => Shell::output('echo', 'hi'));

        Devio::handle(['dev', '-v', 'look']);

        $this->assertStringContainsString('$ echo hi ✔', $this->console());
    }

    public function test_args(): void
    {
        $args = new Args(['migrate', '--force', '--tag=abc']);

        $this->assertSame('migrate', $args->get(0));
        $this->assertNull($args->get(5));
        $this->assertTrue($args->has('--force'));
        $this->assertSame('abc', $args->option('tag'));
        $this->assertSame('x', $args->option('nope', 'x'));
        $this->assertCount(3, $args);
        $this->assertSame(['migrate', '--force', '--tag=abc'], [...$args]);
    }

    public function test_task_reports_its_end_and_indents(): void
    {
        Console::task('outer', fn () => Console::info('inside'));

        try {
            Console::task('bad', fn () => Console::fail('x'));
        } catch (\Throwable) {
        }

        $this->assertMatchesRegularExpression('/● outer\n    inside\n✔ outer \d/', $this->console());
        $this->assertMatchesRegularExpression('/✖ bad \d/', $this->console());
    }
}
