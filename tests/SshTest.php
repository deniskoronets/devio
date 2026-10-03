<?php

namespace Dekor\Devio\Tests;

use Dekor\Devio\Compose;
use Dekor\Devio\Docker;
use Dekor\Devio\Env;
use Dekor\Devio\Failed;
use Dekor\Devio\ProcessFailed;
use Dekor\Devio\Shell;
use Dekor\Devio\Ssh;
use Dekor\Devio\SshConnection;

/** Runs against tests/bin/ssh and scp, which execute "remote" commands locally. */
final class SshTest extends TestCase
{
    private SshConnection $server;

    protected function setUp(): void
    {
        parent::setUp();

        mkdir("$this->tmp/server");
        Ssh::addHost('prod', '10.0.0.1', 'deploy', '~/.ssh/key', port: 2222, dir: "$this->tmp/server");
        $this->server = Ssh::to('prod');
    }

    public function test_commands_in_the_closure_run_in_the_host_dir(): void
    {
        $this->assertSame(realpath("$this->tmp/server"), $this->server->run(fn () => Shell::output('pwd')));
    }

    public function test_ssh_options(): void
    {
        $this->server->run(fn () => Shell::output('true'));

        $ssh = $this->fakeLog('ssh')[0];
        $this->assertStringContainsString('-o ControlMaster=auto', $ssh);
        $this->assertStringContainsString('-o User=deploy', $ssh);
        $this->assertStringContainsString('-p 2222', $ssh);
        $this->assertStringContainsString('-i ' . getenv('HOME') . '/.ssh/key -o IdentitiesOnly=yes', $ssh);
        $this->assertStringContainsString('-T 10.0.0.1', $ssh);
    }

    public function test_arguments_survive_the_remote_shell(): void
    {
        $tricky = "it's \$HOME; `x` \"q\" & | > *";

        $this->assertSame($tricky, $this->server->run(fn () => Shell::output('printf', '%s', $tricky)));
    }

    public function test_env_and_in_work_remotely(): void
    {
        mkdir("$this->tmp/server/sub");

        $this->assertSame('v 1', $this->server->run(fn () => Shell::env(['X' => 'v 1'], fn () => Shell::output('sh', '-c', 'echo $X'))));
        $this->assertSame(realpath("$this->tmp/server/sub"), $this->server->run(fn () => Shell::in('sub', fn () => Shell::output('pwd'))));
    }

    public function test_remote_commands_are_logged_with_the_host(): void
    {
        $this->server->run(fn () => Shell::run('true'));

        $this->assertStringContainsString('prod $ true', $this->console());
    }

    public function test_failures_name_the_host(): void
    {
        try {
            $this->server->run('exit 3');
            $this->fail('no exception');
        } catch (ProcessFailed $e) {
            $this->assertSame('prod', $e->host);
            $this->assertStringContainsString('failed with exit code 3 on prod', $e->getMessage());
        }
    }

    public function test_local_inside_a_host_closure(): void
    {
        $this->assertSame(getcwd(), $this->server->run(fn () => Shell::local(fn () => Shell::output('pwd'))));
    }

    public function test_files(): void
    {
        $this->assertNull($this->server->read('missing.txt'));
        $this->assertFalse($this->server->exists('a.txt'));

        $this->server->write('a.txt', "one\ntwo\n");

        $this->assertTrue($this->server->exists('a.txt'));
        $this->assertSame("one\ntwo\n", $this->server->read('a.txt'));
        $this->assertSame("one\ntwo\n", file_get_contents("$this->tmp/server/a.txt"));
    }

    public function test_upload_and_download(): void
    {
        file_put_contents("$this->tmp/local.txt", 'x');

        $this->server->upload("$this->tmp/local.txt");
        $this->server->download('local.txt', "$this->tmp/back.txt");

        $this->assertSame('x', file_get_contents("$this->tmp/server/local.txt"));
        $this->assertSame('x', file_get_contents("$this->tmp/back.txt"));
        $this->assertStringContainsString('-P 2222', $this->fakeLog('ssh')[0]);
    }

    public function test_env_read_remotely(): void
    {
        file_put_contents("$this->tmp/server/.env", "APP_URL=https://example.com\nOTHER=1\n");

        $this->assertSame(['APP_URL' => 'https://example.com'], $this->server->run(fn () => Env::read('.env', 'APP_URL')));
        $this->assertSame([], $this->server->run(fn () => Env::read('missing.env')));
    }

    public function test_docker_transfer_images_pipes_into_the_host(): void
    {
        // the fake docker logs `save` and `load`; with the fake ssh both run here
        Docker::transferImages($this->server, 'app:1', 'nginx:1');

        $this->assertSame(['save app:1 nginx:1', 'load'], $this->fakeLog('docker'));
        $this->assertStringContainsString('docker save app:1 nginx:1 | gzip | ssh prod docker load', $this->console());
    }

    public function test_a_container_on_the_host(): void
    {
        $this->server->run(fn () => Compose::files('compose.yaml')->container('app', fn () => Shell::run('php', '-v')));

        $this->assertSame(['compose -f compose.yaml run --rm -T app php -v'], $this->fakeLog('docker'));
        $this->assertStringContainsString("cd '$this->tmp/server' && 'docker' 'compose'", $this->fakeLog('ssh')[0]);
        $this->assertStringContainsString('prod/app $ php -v', $this->console());
    }

    public function test_unknown_host(): void
    {
        $this->expectException(Failed::class);

        Ssh::to('nope');
    }
}
