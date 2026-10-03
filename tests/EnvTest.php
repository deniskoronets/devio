<?php

namespace Dekor\Devio\Tests;

use Dekor\Devio\Env;
use Dekor\Devio\Shell;

final class EnvTest extends TestCase
{
    public function test_parse(): void
    {
        $env = Env::parse(<<<'ENV'
            # comment
            PLAIN=value
            export EXPORTED=yes
            SPACED = around
            DOUBLE="a \"b\" # not a comment\nnext"
            SINGLE='raw $X # too'
            INLINE=value # comment
            EMPTY=
            URL=https://example.com/#anchor
            not a line
            ENV);

        $this->assertSame([
            'PLAIN' => 'value',
            'EXPORTED' => 'yes',
            'SPACED' => 'around',
            'DOUBLE' => "a \"b\" # not a comment\nnext",
            'SINGLE' => 'raw $X # too',
            'INLINE' => 'value',
            'EMPTY' => '',
            'URL' => 'https://example.com/#anchor',
        ], $env);
    }

    public function test_read_only_some_keys_and_missing_files(): void
    {
        file_put_contents("$this->tmp/.env", "A=1\nB=2\n");

        $this->assertSame(['B' => '2'], Env::read("$this->tmp/.env", 'B', 'C'));
        $this->assertSame([], Env::read("$this->tmp/missing"));
    }

    public function test_load_puts_the_values_into_the_environment(): void
    {
        file_put_contents("$this->tmp/.env", "DEVIO_A=from file\nDEVIO_B=from file\n");
        putenv('DEVIO_B=from env');

        try {
            Env::load("$this->tmp/.env");
            Env::load("$this->tmp/missing");

            $this->assertSame('from file', getenv('DEVIO_A'));
            $this->assertSame('from file', $_ENV['DEVIO_A']);
            $this->assertSame('from env', getenv('DEVIO_B'));
            $this->assertSame('from file', Shell::output('sh', '-c', 'echo $DEVIO_A'));

            Env::load("$this->tmp/.env", override: true);
            $this->assertSame('from file', getenv('DEVIO_B'));
        } finally {
            putenv('DEVIO_A');
            putenv('DEVIO_B');
            unset($_ENV['DEVIO_A'], $_ENV['DEVIO_B'], $_SERVER['DEVIO_A'], $_SERVER['DEVIO_B']);
        }
    }
}
