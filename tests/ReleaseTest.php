<?php

namespace Dekor\Devio\Tests;

use Dekor\Devio\Failed;
use Dekor\Devio\Release;
use Dekor\Devio\Shell;
use Dekor\Devio\Ssh;

/** A project in a temp git repo, deployed to a temp dir through the fake ssh, scp and docker. */
final class ReleaseTest extends TestCase
{
    private string $cwd;

    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cwd = getcwd();
        mkdir("$this->tmp/project");
        mkdir("$this->tmp/server");
        file_put_contents("$this->tmp/project/compose.yaml", "services: {}\n");
        $this->config([
            'app' => ['build' => ['context' => '.', 'target' => 'prod'], 'image' => 'app-php:${TAG}'],
            'worker' => ['build' => ['context' => '.', 'target' => 'prod'], 'image' => 'app-php:${TAG}'],
            'nginx' => ['build' => ['context' => '.', 'target' => 'nginx'], 'image' => 'app-nginx:${TAG}'],
            'mysql' => ['image' => 'mysql:8'],
        ]);
        chdir("$this->tmp/project");
        exec('git init -q');
        $this->tag = $this->commit();

        Ssh::addHost('prod', '10.0.0.1', dir: "$this->tmp/server");
        putenv("FAKE_DOCKER_STATE=$this->tmp/images");
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        putenv('FAKE_DOCKER_STATE');
        putenv('FAKE_COMPOSE_CONFIG');

        parent::tearDown();
    }

    public function test_deploy_builds_head_uploads_and_starts_it(): void
    {
        $this->assertSame($this->tag, $this->release()->deploy());

        $docker = $this->docker();
        $this->assertStringContainsString("DOCKER_DEFAULT_PLATFORM=linux/arm64 TAG=$this->tag compose -f compose.yaml -f compose.prod.yaml build", $docker);
        $this->assertStringContainsString("save app-php:$this->tag app-nginx:$this->tag\n", $docker);
        $this->assertStringContainsString("TAG=$this->tag compose -f compose.yaml -f compose.prod.yaml up -d --remove-orphans", $docker);

        $this->assertSame(["app-php:$this->tag", "app-nginx:$this->tag"], $this->images('remote'));
        $this->assertSame([], $this->images('local'));
        $this->assertFileExists("$this->tmp/server/compose.prod.yaml");
        $this->assertSame([$this->tag], $this->release()->history());
    }

    public function test_a_build_the_host_has_is_not_built_again(): void
    {
        $this->release()->deploy();
        $this->release()->deploy();

        $this->assertSame(1, substr_count($this->docker(), 'compose.prod.yaml build'));
        $this->assertStringContainsString("$this->tag is already on prod", $this->console());
    }

    public function test_the_host_keeps_the_last_releases(): void
    {
        $this->release()->keep(2)->deploy();
        $second = $this->commit();
        $this->release()->keep(2)->deploy();
        $third = $this->commit();
        $this->release()->keep(2)->deploy();

        $this->assertSame([$third, $second], $this->release()->history());
        $this->assertSame(["app-php:$second", "app-nginx:$second", "app-php:$third", "app-nginx:$third"], $this->images('remote'));
    }

    public function test_rollback_starts_the_previous_release_with_its_compose_files(): void
    {
        $this->release()->deploy();
        $second = $this->commit("services: { worker: {} }\n");
        $this->release()->deploy();

        $this->assertSame($this->tag, $this->release()->rollback());

        $this->assertStringContainsString("TAG=$this->tag compose -f compose.yaml -f compose.prod.yaml up -d", $this->docker());
        $this->assertSame([$this->tag, $second], $this->release()->history());
        $this->assertSame("services: {}\n", file_get_contents("$this->tmp/server/compose.prod.yaml"));
    }

    public function test_rollback_to_a_commit_the_host_does_not_have(): void
    {
        $this->release()->deploy();
        $second = $this->commit();

        $this->expectException(Failed::class);
        $this->expectExceptionMessage("$second is not on prod");

        $this->release()->rollback($second);
    }

    public function test_rollback_to_an_unknown_commit(): void
    {
        $this->expectException(Failed::class);
        $this->expectExceptionMessage('nope is not a commit in this repository');

        $this->release()->rollback('nope');
    }

    public function test_built_images_must_be_tagged_with_the_release(): void
    {
        $this->config(['app' => ['build' => ['context' => '.'], 'image' => 'app-php:latest']]);

        $this->expectException(Failed::class);
        $this->expectExceptionMessage('service app is built, so its image needs the release tag, e.g. image: app:${TAG:?}');

        $this->release()->deploy();
    }

    public function test_before_start_runs_on_the_host_before_up(): void
    {
        $got = null;
        $this->release()->beforeStart('app', function (string $tag) use (&$got) {
            $got = $tag;
            Shell::run('php', 'artisan', 'migrate', '--force');
        })->deploy();

        $this->assertSame($this->tag, $got);
        $this->assertMatchesRegularExpression(
            "/TAG=$this->tag compose -f compose.yaml -f compose.prod.yaml run --rm -T app php artisan migrate --force\n.*compose .+ up -d/",
            $this->docker(),
        );
        $this->assertStringContainsString('prod/app $ php artisan migrate --force', $this->console());
    }

    public function test_compose_runs_against_the_running_release(): void
    {
        $this->release()->deploy();
        $this->release()->compose('logs', 'worker');

        $this->assertStringContainsString("TAG=$this->tag compose -f compose.yaml -f compose.prod.yaml logs worker", $this->docker());
    }

    public function test_platform(): void
    {
        $this->release()->platform('linux/amd64')->deploy();

        $this->assertStringContainsString('DOCKER_DEFAULT_PLATFORM=linux/amd64 TAG=', $this->docker());
        $this->assertStringNotContainsString('version', $this->docker());
    }

    public function test_uncommitted_changes_stop_the_deploy(): void
    {
        file_put_contents("$this->tmp/project/new.txt", 'x');

        $this->expectException(Failed::class);
        $this->expectExceptionMessage('uncommitted changes');

        $this->release()->deploy();
    }

    private function release(): Release
    {
        return Release::to('prod')->composeFiles('compose.yaml', 'compose.prod.yaml');
    }

    /** What the fake `docker compose config` answers, one image per line as the fake build reads it. */
    private function config(array $services): void
    {
        file_put_contents("$this->tmp/compose.json", json_encode(['services' => $services], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        putenv("FAKE_COMPOSE_CONFIG=$this->tmp/compose.json");
    }

    /** Commits compose.prod.yaml with $content (or a new comment), returns the short sha. */
    private function commit(?string $content = null): string
    {
        $file = "$this->tmp/project/compose.prod.yaml";
        file_put_contents($file, $content ?? (is_file($file) ? file_get_contents($file) . "#\n" : "services: {}\n"));
        exec('git add -A && git -c user.name=t -c user.email=t@t commit -qm release');

        return trim(shell_exec('git rev-parse --short=12 HEAD'));
    }

    private function images(string $where): array
    {
        $file = "$this->tmp/images" . ($where === 'remote' ? '.remote' : '');

        return is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [];
    }

    private function docker(): string
    {
        return implode("\n", $this->fakeLog('docker'));
    }
}
