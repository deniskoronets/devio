<?php

namespace Dekor\Devio\Tests;

use Dekor\Devio\Console;
use Dekor\Devio\ProcessFailed;
use Dekor\Devio\Shell;

final class ShellTest extends TestCase
{
    public function test_output_lines_and_succeeds(): void
    {
        $this->assertSame('hello world', Shell::output('echo', '  hello world  '));
        $this->assertSame(['a', 'b'], Shell::lines('printf', "a\n\nb\n"));
        $this->assertTrue(Shell::succeeds('true'));
        $this->assertFalse(Shell::succeeds('false'));
        $this->assertFalse(Shell::succeeds('devio-no-such-binary'));
    }

    public function test_arguments_are_passed_without_a_shell(): void
    {
        $this->assertSame('$HOME; rm -rf / `x` \'q\'', Shell::output('printf', '%s', '$HOME; rm -rf / `x` \'q\''));
    }

    public function test_capture_keeps_stdout_stderr_and_exit_code(): void
    {
        $result = Shell::capture('sh', '-c', 'echo out; echo err >&2; exit 3');

        $this->assertSame(3, $result->exitCode);
        $this->assertSame("out\n", $result->output);
        $this->assertSame("err\n", $result->errorOutput);
        $this->assertFalse($result->successful());
    }

    public function test_failed_output_throws_with_the_error_output(): void
    {
        try {
            Shell::output('sh', '-c', 'echo broken >&2; exit 4');
            $this->fail('no exception');
        } catch (ProcessFailed $e) {
            $this->assertSame(4, $e->exitCode);
            $this->assertStringContainsString('failed with exit code 4', $e->getMessage());
            $this->assertStringContainsString('broken', $e->getMessage());
        }
    }

    public function test_run_logs_the_command_and_its_status(): void
    {
        Shell::run('true');

        try {
            Shell::run('sh', '-c', 'exit 2');
        } catch (ProcessFailed $e) {
            $this->assertSame(2, $e->exitCode);
        }

        $this->assertMatchesRegularExpression('/\$ true\n  ✔ \d+\.\ds\n/', $this->console());
        $this->assertMatchesRegularExpression("/\\$ sh -c 'exit 2'\n  ✖ exit 2 /", $this->console());
    }

    public function test_pipe_fails_when_any_part_fails(): void
    {
        $this->expectException(ProcessFailed::class);

        Shell::pipe('false | cat');
    }

    public function test_in_and_env(): void
    {
        mkdir("$this->tmp/sub");

        $this->assertSame(realpath("$this->tmp/sub"), Shell::in("$this->tmp/sub", fn () => Shell::output('pwd')));
        $this->assertSame('a b', Shell::env(['DEVIO_X' => 'a b'], fn () => Shell::output('sh', '-c', 'echo $DEVIO_X')));
        $this->assertSame('', Shell::output('sh', '-c', 'echo $DEVIO_X'), 'env is only for the closure');
    }

    public function test_captured_commands_are_logged_only_when_verbose(): void
    {
        Shell::output('echo', 'quiet');
        $this->assertSame('', $this->console());

        Console::verbose();
        Shell::output('echo', 'loud');
        $this->assertMatchesRegularExpression('/\$ echo loud ✔ /', $this->console());
    }

    public function test_masked_values_are_not_logged(): void
    {
        Console::mask('s3cret');

        Shell::run('true', 'token=s3cret');

        $this->assertStringContainsString('$ true token=••••', $this->console());
        $this->assertStringNotContainsString('s3cret', $this->console());
        Console::mask(); // keeps it for later tests, harmless
    }
}
