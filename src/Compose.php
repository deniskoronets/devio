<?php

namespace Dekor\Devio;

use Closure;
use Dekor\Devio\Support\Context;

/**
 * docker compose with chosen files, env and project:
 *   Compose::files('compose.yaml', 'compose.prod.yaml')->env(['TAG' => $sha])->run('up', '-d');
 */
final class Compose
{
    private function __construct(
        private readonly array $files,
        private readonly array $env = [],
        private readonly ?string $project = null,
    ) {
    }

    /** No files: docker's defaults (compose.yaml + compose.override.yaml). */
    public static function files(string ...$files): self
    {
        return new self($files);
    }

    /** Env vars for the compose files' ${...} substitutions. */
    public function env(array $vars): self
    {
        return new self($this->files, [...$this->env, ...$vars], $this->project);
    }

    public function project(string $name): self
    {
        return new self($this->files, $this->env, $name);
    }

    public function run(string ...$args): void
    {
        Shell::env($this->env, fn () => Shell::run(...$this->command($args)));
    }

    /** stdout of the compose command: ->output('ps', '--services'). */
    public function output(string ...$args): string
    {
        return Shell::env($this->env, fn () => Shell::output(...$this->command($args)));
    }

    /**
     * Runs $fn with every Shell call inside it in a new container of $service, removed afterwards
     * (docker compose run --rm). Shell::env() vars go into the container:
     *   $compose->container('app', fn () => Shell::run('php', 'artisan', 'migrate', '--force'));
     */
    public function container(string $service, Closure $fn): mixed
    {
        $outer = Shell::context()->with($this->env);
        $wrap = function (array $command, Context $inner, bool $interactive) use ($service, $outer) {
            $run = $this->command(['run', '--rm']);
            if (! $interactive) {
                $run[] = '-T';
            }
            foreach ($inner->env as $name => $value) {
                array_push($run, '-e', "$name=$value");
            }
            if ($inner->dir !== null) {
                array_push($run, '-w', $inner->dir);
            }

            return [[...$run, $service, ...$command], $outer];
        };
        $name = $outer->host() !== null ? $outer->host() . "/$service" : $service;

        return Shell::within(new Context(wrap: $wrap, name: $name), $fn);
    }

    private function command(array $args): array
    {
        $command = ['docker', 'compose'];
        foreach ($this->files as $file) {
            array_push($command, '-f', $file);
        }
        if ($this->project !== null) {
            array_push($command, '-p', $this->project);
        }

        return [...$command, ...$args];
    }
}
