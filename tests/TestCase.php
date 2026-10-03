<?php

namespace Dekor\Devio\Tests;

use Dekor\Devio\Console;
use Dekor\Devio\Devio;
use Dekor\Devio\Notifier;
use Dekor\Devio\Ssh;
use ReflectionClass;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    /** @var resource */
    protected $log;

    protected string $tmp;

    protected function setUp(): void
    {
        foreach ([Devio::class => ['commands', 'failureHandlers'], Notifier::class => ['channels'], Ssh::class => ['hosts', 'connections']] as $class => $properties) {
            foreach ($properties as $property) {
                (new ReflectionClass($class))->setStaticPropertyValue($property, []);
            }
        }

        $this->log = fopen('php://memory', 'w+');
        Console::to($this->log);
        Console::colors(false);
        Console::verbose(false);

        $this->tmp = sys_get_temp_dir() . '/devio-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);

        // the fake ssh, scp and docker from tests/bin come first
        putenv('PATH=' . __DIR__ . '/bin:' . getenv('PATH'));
        putenv("FAKE_SSH_LOG=$this->tmp/ssh.log");
        putenv("FAKE_DOCKER_LOG=$this->tmp/docker.log");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmp));
        putenv('PATH=' . str_replace(__DIR__ . '/bin:', '', getenv('PATH')));
    }

    protected function console(): string
    {
        rewind($this->log);

        return stream_get_contents($this->log);
    }

    protected function fakeLog(string $name): array
    {
        $file = "$this->tmp/$name.log";

        return is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [];
    }
}
