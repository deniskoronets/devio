<?php

namespace Dekor\Devio;

/** Docker on this machine, or on a host inside Ssh::to('name')->run(fn). */
final class Docker
{
    /** docker compose with the default compose files; Compose::files() for others. */
    public static function compose(string ...$args): void
    {
        Shell::run('docker', 'compose', ...$args);
    }

    /**
     * docker build $context --tag $tag. $args are build args (name => value),
     * $options any other flags: ['--no-cache'], ['--secret', 'id=npm,src=.npmrc'].
     */
    public static function build(
        string $context,
        string $tag,
        ?string $target = null,
        array $args = [],
        ?string $file = null,
        ?string $platform = null,
        array $options = [],
    ): void {
        $command = ['docker', 'build', '--tag', $tag];
        if ($target !== null) {
            array_push($command, '--target', $target);
        }
        if ($file !== null) {
            array_push($command, '--file', $file);
        }
        if ($platform !== null) {
            array_push($command, '--platform', $platform);
        }
        foreach ($args as $name => $value) {
            array_push($command, '--build-arg', "$name=$value");
        }
        array_push($command, ...$options);
        $command[] = $context;

        Shell::run(...$command);
    }

    /** Tagged images of a repository: images('myapp') gives ['myapp:abc', 'myapp:def']. */
    public static function images(string $repository): array
    {
        $images = Shell::lines('docker', 'images', '--format', '{{.Repository}}:{{.Tag}}', $repository);

        return array_values(array_filter($images, fn ($image) => ! str_ends_with($image, ':<none>')));
    }

    /** Whether all these images exist. */
    public static function hasImages(string ...$images): bool
    {
        return $images !== [] && Shell::succeeds('docker', 'image', 'inspect', ...$images);
    }

    /** Removes images; missing or in-use ones are skipped. */
    public static function removeImages(string ...$images): void
    {
        foreach ($images as $image) {
            Shell::succeeds('docker', 'rmi', $image);
        }
    }

    /** Removes dangling images (layers no tag points to anymore). */
    public static function pruneImages(): void
    {
        Shell::succeeds('docker', 'image', 'prune', '-f');
    }

    /** Copies local images to a host without a registry: docker save | gzip | ssh docker load. */
    public static function transferImages(SshConnection $to, string ...$images): void
    {
        Shell::local(function () use ($to, $images) {
            $progress = stream_isatty(STDERR) && Shell::succeeds('sh', '-c', 'command -v pv') ? ' | pv' : '';
            $save = 'docker save ' . implode(' ', array_map('escapeshellarg', $images));
            $load = implode(' ', array_map('escapeshellarg', $to->sshCommand('docker load')));

            Shell::stream(
                ['bash', '-o', 'pipefail', '-c', "$save | gzip$progress | $load"],
                'docker save ' . implode(' ', $images) . " | gzip$progress | ssh {$to->host->name} docker load",
            );
        });
    }
}
