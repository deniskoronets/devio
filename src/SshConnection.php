<?php

namespace Dekor\Devio;

use Closure;
use Dekor\Devio\Support\Context;

/**
 * A host to run things on. All calls share one SSH connection (ControlMaster), so many small
 * commands stay fast.
 */
final class SshConnection
{
    public function __construct(public readonly Host $host)
    {
    }

    /**
     * With a closure, every Shell/Docker/Git/Env call inside it runs on this host, in its dir:
     *   $prod->run(fn (SshConnection $prod) => Docker::compose('up', '-d'));
     * With a string, runs that one shell line there: $prod->run('docker ps | grep app').
     */
    public function run(Closure|string $command): mixed
    {
        if (is_string($command)) {
            return $this->run(fn () => Shell::pipe($command));
        }

        return Shell::within(new Context($this, $this->host->dir), fn () => $command($this));
    }

    /** scp to the host; $remote is relative to the host dir. */
    public function upload(string|array $local, string $remote = '.'): void
    {
        $files = (array) $local;
        $label = 'scp ' . implode(' ', $files) . " {$this->host->name}:" . $this->path($remote);

        Shell::local(fn () => Shell::stream(['scp', '-q', ...$this->options('-P'), ...$files, $this->target($remote)], $label));
    }

    public function download(string $remote, string $local): void
    {
        $label = "scp {$this->host->name}:" . $this->path($remote) . " $local";

        Shell::local(fn () => Shell::stream(['scp', '-q', ...$this->options('-P'), $this->target($remote), $local], $label));
    }

    /** File contents, null if there is no such file. */
    public function read(string $path): ?string
    {
        $result = $this->run(fn () => Shell::capture('cat', $path));

        return match (true) {
            $result->successful() => $result->output,
            $result->exitCode === 1 => null,
            default => $result->throw()->output,
        };
    }

    public function write(string $path, string $content): void
    {
        $this->run(fn () => Shell::process(['sh', '-c', 'cat > "$1"', 'sh', $path], "write $path", $content)->throw());
    }

    public function exists(string $path): bool
    {
        return $this->run(fn () => Shell::succeeds('test', '-e', $path));
    }

    /** An interactive login shell on the host, in its dir. */
    public function shell(): void
    {
        $cd = $this->host->dir !== null ? 'cd ' . escapeshellarg($this->host->dir) . ' && ' : '';

        try {
            Shell::local(fn () => Shell::stream($this->sshCommand($cd . 'exec "${SHELL:-sh}" -l', true), "ssh {$this->host->name}"));
        } catch (ProcessFailed $e) {
            if ($e->exitCode === 255) {
                throw $e;
            }
            // otherwise just the exit code of the last thing typed in the shell
        }
    }

    /** @internal argv that runs the $remote shell line on this host */
    public function sshCommand(string $remote, bool $tty = false): array
    {
        return ['ssh', ...$this->options('-p'), $tty ? '-t' : '-T', $this->host->hostname, $remote];
    }

    /** ssh and scp options; they differ only in the port flag. */
    private function options(string $portFlag): array
    {
        $options = [
            '-o', 'ControlMaster=auto',
            '-o', 'ControlPath=~/.ssh/devio-%C',
            '-o', 'ControlPersist=60',
            '-o', 'LogLevel=ERROR',
        ];
        if ($this->host->user !== null) {
            array_push($options, '-o', 'User=' . $this->host->user);
        }
        if ($this->host->port !== null) {
            array_push($options, $portFlag, (string) $this->host->port);
        }
        if ($this->host->sshKey !== null) {
            $key = str_starts_with($this->host->sshKey, '~/') ? getenv('HOME') . substr($this->host->sshKey, 1) : $this->host->sshKey;
            array_push($options, '-i', $key, '-o', 'IdentitiesOnly=yes');
        }

        return $options;
    }

    private function target(string $path): string
    {
        return $this->host->hostname . ':' . $this->path($path);
    }

    private function path(string $path): string
    {
        if ($this->host->dir === null || str_starts_with($path, '/')) {
            return $path;
        }

        return $path === '.' ? $this->host->dir : rtrim($this->host->dir, '/') . '/' . $path;
    }
}
