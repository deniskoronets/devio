<?php

namespace Dekor\Devio\Tests;

use Dekor\Devio\Compose;
use Dekor\Devio\Docker;
use Dekor\Devio\Shell;

/** Runs against tests/bin/docker, which logs what it was called with. */
final class DockerTest extends TestCase
{
    public function test_compose(): void
    {
        Docker::compose('up', '-d');
        Compose::files('compose.yaml', 'compose.prod.yaml')->env(['TAG' => 'abc'])->project('app')->run('ps');

        $this->assertSame([
            'compose up -d',
            'TAG=abc compose -f compose.yaml -f compose.prod.yaml -p app ps',
        ], $this->fakeLog('docker'));
        $this->assertStringContainsString('$ TAG=abc docker compose -f compose.yaml', $this->console());
    }

    public function test_build(): void
    {
        Docker::build('ctx', 'app:1', target: 'prod', args: ['A' => 'x y'], file: 'Dockerfile', platform: 'linux/amd64', options: ['--no-cache']);

        $this->assertSame(['build --tag app:1 --target prod --file Dockerfile --platform linux/amd64 --build-arg A=x y --no-cache ctx'], $this->fakeLog('docker'));
    }

    public function test_images_has_images_remove_images(): void
    {
        $this->assertSame(['app:one', 'app:two'], Docker::images('app'));
        $this->assertTrue(Docker::hasImages('app:one'));
        $this->assertFalse(Docker::hasImages('app:missing'));
        $this->assertFalse(Docker::hasImages());

        Docker::removeImages('app:one', 'app:two');
        $this->assertContains('rmi app:two', $this->fakeLog('docker'));
    }

    public function test_commands_in_a_container(): void
    {
        $app = Compose::files('compose.yaml')->env(['TAG' => 'abc']);
        $app->container('app', function () {
            Shell::run('php', 'artisan', 'migrate');
            Shell::env(['A' => '1'], fn () => Shell::in('/srv', fn () => Shell::output('ls')));
        });

        $this->assertSame([
            'TAG=abc compose -f compose.yaml run --rm -T app php artisan migrate',
            'TAG=abc compose -f compose.yaml run --rm -T -e A=1 -w /srv app ls',
        ], $this->fakeLog('docker'));
        $this->assertStringContainsString('app $ php artisan migrate', $this->console());
    }
}
