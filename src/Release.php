<?php

namespace Dekor\Devio;

use Closure;

/**
 * Deploys Docker images without a registry: builds them here from the committed HEAD with docker compose,
 * tagged with its sha, copies them to the host, runs your hooks (migrations) and starts them. The host keeps
 * the last few releases, so a rollback only starts older images again.
 *
 *   $prod = Release::to('prod')
 *       ->composeFiles('compose.yaml', 'compose.prod.yaml')
 *       ->beforeStart('app', fn () => Shell::run('php', 'artisan', 'migrate', '--force'));
 *
 *   $prod->deploy();     // returns the tag
 *   $prod->rollback();   // to the release before the running one
 *
 * The images are those of the services with a build section, and each must be tagged with ${TAG}:
 *
 *   app:
 *     image: app-php:${TAG:?}
 *     build: { context: ., dockerfile: docker/Dockerfile, target: prod }
 *     pull_policy: never
 */
final class Release
{
    /** Deployed tags, newest first, in the host dir. */
    private const HISTORY = '.releases';

    /** @var string[] */
    private array $composeFiles = ['compose.yaml'];

    private ?string $platform = null;

    private int $keep = 3;

    /** @var array{0: string, 1: Closure}[] */
    private array $beforeStart = [];

    private function __construct(private readonly string $host)
    {
    }

    /** A release on a host added with Ssh::addHost(). */
    public static function to(string $host): self
    {
        return new self($host);
    }

    /** Paths in the commit; every start writes them, as committed, to the same paths in the host dir. */
    public function composeFiles(string ...$files): self
    {
        $this->composeFiles = $files;

        return $this;
    }

    /** Builds for this platform, e.g. linux/amd64. By default the host's, as its Docker reports it. */
    public function platform(string $platform): self
    {
        $this->platform = $platform;

        return $this;
    }

    /** How many releases the host keeps, the running one included; older images are removed. */
    public function keep(int $releases): self
    {
        $this->keep = max(1, $releases);

        return $this;
    }

    /**
     * Runs $fn before a release starts, also on rollback, with every Shell call inside it in a new container of
     * $service from that release, on the host: fn (string $tag). If it fails, the running containers stay as they are.
     */
    public function beforeStart(string $service, Closure $fn): self
    {
        $this->beforeStart[] = [$service, $fn];

        return $this;
    }

    /** Builds HEAD (unless the host has it already), uploads it and starts it. Returns the tag. */
    public function deploy(): string
    {
        return Shell::local(function () {
            Git::isClean() || Console::fail('uncommitted changes: deploy builds exactly HEAD, commit first');

            $tag = Git::sha();
            Console::step("deploy $tag to $this->host");
            $root = Git::archive(); // the commit, not the working tree: no local .env, vendor, node_modules
            $images = $this->images($root, $tag);
            $server = $this->server();

            if ($server->run(fn () => Docker::hasImages(...$images))) {
                Console::info("$tag is already on $this->host");
            } else {
                Console::task("build $tag", fn () => $this->build($root, $tag));
                Console::task("upload $tag", fn () => Docker::transferImages($server, ...$images));
                Docker::removeImages(...$images); // the host has them now
            }

            $this->start($tag, $root, $images);

            return $tag;
        });
    }

    /** Starts $tag again, by default the release before the running one. The host must still have it. */
    public function rollback(?string $tag = null): string
    {
        return Shell::local(function () use ($tag) {
            $tag ??= $this->history()[1] ?? Console::fail("no previous release on $this->host");
            Shell::succeeds('git', 'cat-file', '-e', "$tag^{commit}") || Console::fail("$tag is not a commit in this repository");

            Console::step("roll back $this->host to $tag");
            $root = Git::archive(ref: $tag);
            $this->start($tag, $root, $this->images($root, $tag));

            return $tag;
        });
    }

    /** Tags deployed on the host, newest first: [0] is the running one. */
    public function history(): array
    {
        return array_values(array_filter(explode("\n", $this->server()->read(self::HISTORY) ?? '')));
    }

    /** The running release, null before the first deploy. */
    public function current(): ?string
    {
        return $this->history()[0] ?? null;
    }

    /** docker compose on the host, against the running release: ->compose('logs', '-f', 'worker'). */
    public function compose(string ...$args): void
    {
        $tag = $this->current() ?? Console::fail("nothing deployed on $this->host yet");

        $this->server()->run(fn () => $this->project($tag)->run(...$args));
    }

    /** The images of the services that are built, from the compose files in $root. */
    private function images(string $root, string $tag): array
    {
        foreach ($this->composeFiles as $file) {
            is_file("$root/$file") || Console::fail("no $file in commit $tag (compose files are deployed as committed)");
        }
        $config = json_decode(Shell::in($root, fn () => $this->project($tag)->output('config', '--format', 'json')), true);

        $images = [];
        foreach ($config['services'] ?? [] as $name => $service) {
            if (! isset($service['build'])) {
                continue;
            }
            $image = $service['image'] ?? null;
            if ($image === null || ! str_ends_with($image, ":$tag")) {
                Console::fail("service $name is built, so its image needs the release tag, e.g. image: $name:\${TAG:?}");
            }
            $images[] = $image;
        }

        return array_values(array_unique($images)) ?: Console::fail('no service with a build section in ' . implode(', ', $this->composeFiles));
    }

    private function build(string $root, string $tag): void
    {
        $platform = $this->platform
            ?? $this->server()->run(fn () => Shell::output('docker', 'version', '--format', '{{.Server.Os}}/{{.Server.Arch}}'));

        Shell::in($root, fn () => Shell::env(
            ['DOCKER_DEFAULT_PLATFORM' => $platform],
            fn () => $this->project($tag)->run('build'),
        ));
    }

    private function start(string $tag, string $root, array $images): void
    {
        $server = $this->server();
        $server->run(fn () => Docker::hasImages(...$images)) || Console::fail("$tag is not on $this->host");

        $server->run(function (SshConnection $server) use ($tag, $root, $images) {
            Console::task("start $tag", function () use ($server, $tag, $root) {
                // the compose files of that commit, so a rollback also gets the services of its time
                foreach ($this->composeFiles as $file) {
                    dirname($file) === '.' || Shell::capture('mkdir', '-p', dirname($file))->throw();
                    $server->write($file, file_get_contents("$root/$file"));
                }
                foreach ($this->beforeStart as [$service, $hook]) {
                    $this->project($tag)->container($service, fn () => $hook($tag));
                }
                $this->project($tag)->run('up', '-d', '--remove-orphans');
            });

            $releases = array_slice(array_values(array_unique([$tag, ...$this->history()])), 0, $this->keep);
            $server->write(self::HISTORY, implode("\n", $releases) . "\n");

            foreach ($images as $image) {
                $repository = substr($image, 0, -strlen(":$tag"));
                $kept = array_map(fn ($release) => "$repository:$release", $releases);
                Docker::removeImages(...array_diff(Docker::images($repository), $kept));
            }
            Docker::pruneImages();
        });
    }

    private function project(string $tag): Compose
    {
        return Compose::files(...$this->composeFiles)->env(['TAG' => $tag]);
    }

    private function server(): SshConnection
    {
        return Ssh::to($this->host);
    }
}
