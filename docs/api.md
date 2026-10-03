# API reference

Everything is in the `Dekor\Devio` namespace (channels in `Dekor\Devio\Channels`). All methods are static
except on `SshConnection`, `Compose`, `Release`, `Command`, `Args` and `Result`.

- [Devio](#devio) · [Command](#command) · [Args](#args)
- [Shell](#shell) · [Result](#result) · [ProcessFailed, Failed](#processfailed-failed)
- [Ssh](#ssh) · [SshConnection](#sshconnection)
- [Docker](#docker) · [Compose](#compose) · [Release](#release) · [Git](#git) · [Env](#env) · [Http](#http)
- [Console](#console)
- [Notifier](#notifier) · [Level](#level) · [Channels](#channels)

---

## Devio

The command registry and entry point.

| method | |
|---|---|
| `command(string $name, Closure $handler, string $description = ''): Command` | Registers a command. The handler gets [`Args`](#args) and may return an exit code. The description shows in `./dev` help. |
| `onFailure(Closure $handler): Devio` | Called when a command fails: `fn (Throwable $e, string $command)`. Returns something you can chain `->command()` on. Not called for [plain](#command) commands. |
| `run(?array $argv = null): never` | Runs the command named in argv (default `$_SERVER['argv']`) and exits with its code. Changes to the script's directory first. |
| `VERSION` | constant |

Command line: `./dev [options] <command> [args]`. Options go **before** the command; everything after it goes to the
command untouched (`./dev art migrate -v` gives artisan the `-v`).

| option | |
|---|---|
| `-v`, `--verbose` | also log captured commands (`Shell::output`, `succeeds`, ...). Same as `DEVIO_VERBOSE=1`. |
| `--no-color` | no colors. `NO_COLOR=1` works too; `FORCE_COLOR=1` forces them. |
| `-h`, `--help` | the command list (also with no command) |
| `--version` | |

Built in when devio runs from the phar:

| command | |
|---|---|
| `devio-update-phar` | Replaces your `devio.phar` with the latest one on GitHub (`dist/devio.phar` on `main`), keeping its file mode. Says so if it's up to date already. A command of your own with this name takes its place. With Composer, update with `composer update dekor/devio` instead. |

How a command ends:

| | log | exit code | `onFailure` |
|---|---|---|---|
| returns normally | `✔ deploy finished in 1m 12s` | 0, or what the handler returned | |
| a command fails ([`ProcessFailed`](#processfailed-failed)) | `✖` why, `✖ deploy failed after 14s` | the failed command's exit code | ✔ |
| `Console::fail('...')` | `✖ ...`, `✖ deploy failed after ...` | 1 | ✔ |
| any other exception | `✖ Class: message` (trace with `-v`) | 1 | ✔ |

## Command

Returned by `Devio::command()`.

| method | |
|---|---|
| `command(string $name, Closure $handler, string $description = ''): Command` | Registers the next command (chaining). |
| `plain(): Command` | For thin wrappers (`art`, `composer`, `logs`): devio prints none of its own lines, only the command's output; it still exits with the command's code. A failure doesn't call `onFailure`. |

## Args

What a handler receives: the arguments after the command name. Spreads into calls:
`Docker::compose('exec', 'app', 'php', 'artisan', ...$args)`. Implements `IteratorAggregate`, `Countable`.

| method | |
|---|---|
| `all(): array` | |
| `get(int $index, ?string $default = null): ?string` | |
| `has(string $flag): bool` | `$args->has('--force')` |
| `option(string $name, ?string $default = null): ?string` | `$args->option('tag')` reads `--tag=abc` |

## Shell

Runs commands. A command is an argv: `Shell::run('git', 'commit', '-m', $message)` passes `$message` as one
argument, no quoting needed, nothing is interpreted by a shell. Only `pipe()` takes a shell line.

Commands run locally, on a host inside `Ssh::to('name')->run(fn)` ([SshConnection](#sshconnection)), or in a container inside `$compose->container('app', fn)` ([Compose](#compose)).

| method | output | on failure |
|---|---|---|
| `run(string ...$command): void` | streams to the terminal; stdin too (prompts, tinker) | throws `ProcessFailed` |
| `pipe(string $pipeline): void` | streams; runs `bash -o pipefail -c` | throws if any part fails |
| `output(string ...$command): string` | captured stdout, trimmed | throws, with stderr in the message |
| `lines(string ...$command): array` | non-empty lines of stdout | throws |
| `succeeds(string ...$command): bool` | nothing | returns false |
| `capture(string ...$command): Result` | exit code, stdout, stderr | never throws |

| method | |
|---|---|
| `in(string $dir, Closure $fn): mixed` | runs `$fn` with commands in `$dir`; relative to the current dir, or to the host dir on a host |
| `env(array $vars, Closure $fn): mixed` | runs `$fn` with extra env vars for its commands |
| `local(Closure $fn): mixed` | inside a host closure: runs `$fn` locally |

`run` and `pipe` are logged with their status and duration. The captured ones (`output`, `lines`, `succeeds`, `capture`) are logged only with `-v`.

## Result

From `Shell::capture()`. Properties: `exitCode`, `output`, `errorOutput`, `command`, `host`.

| method | |
|---|---|
| `successful(): bool` | |
| `throw(): Result` | throws `ProcessFailed` if it failed, otherwise returns itself |

## ProcessFailed, Failed

`ProcessFailed` is thrown when a command exits non-zero. Properties: `command`, `exitCode`, `host` (null when local), `errorOutput`.
The message reads like ``` `docker compose up -d` failed with exit code 1 on prod ```, followed by the last 20 lines of stderr when they were captured.
Exit code 255 on a host usually means ssh could not connect.

`Failed` is thrown by `Console::fail()`. Catch either one to react, for example to roll back:

```php
try {
    Http::expect($url);
} catch (Failed $e) {
    Console::warn('site is down, rolling back');
    $rollback();
    throw $e;
}
```

## Ssh

| method | |
|---|---|
| `addHost(string $name, string $host, ?string $user = null, ?string $sshKey = null, ?int $port = null, ?string $dir = null): void` | `$host`: IP, hostname or an alias from `~/.ssh/config`. What is left null comes from `~/.ssh/config`. `$sshKey` may start with `~/`. `$dir`: where commands run and relative paths point. |
| `to(string $name): SshConnection` | |

All calls to a host share one connection (OpenSSH `ControlMaster`, socket in `~/.ssh/devio-*`, kept 60s), so
running many small commands costs one handshake. Host keys are checked as usual: connect once by hand first.

## SshConnection

| method | |
|---|---|
| `run(Closure $fn): mixed` | Every `Shell`, `Docker`, `Compose`, `Git`, `Env` call inside `$fn` runs on this host, in its dir. `$fn` gets the SshConnection. Returns what `$fn` returns. |
| `run(string $command): void` | Runs one shell line there: `$prod->run('docker ps \| grep app')`. |
| `upload(string\|array $local, string $remote = '.'): void` | scp; `$remote` relative to the host dir |
| `download(string $remote, string $local): void` | |
| `read(string $path): ?string` | file contents, null if there is no such file |
| `write(string $path, string $content): void` | |
| `exists(string $path): bool` | |
| `shell(): void` | interactive login shell on the host, in its dir |
| `host` | the [Host](#ssh): `name`, `hostname`, `user`, `sshKey`, `port`, `dir` |

`Http`, `Console`, `Notifier` and `SshConnection` methods always run locally, also inside a host closure.

## Docker

| method | |
|---|---|
| `compose(string ...$args): void` | `docker compose ...` with docker's default files |
| `build(string $context, string $tag, ?string $target = null, array $args = [], ?string $file = null, ?string $platform = null, array $options = []): void` | `$args`: build args `name => value`; `$options`: other flags, `['--no-cache']` |
| `images(string $repository): array` | tagged images: `images('app')` gives `['app:abc', 'app:def']` |
| `hasImages(string ...$images): bool` | all of them exist |
| `removeImages(string ...$images): void` | missing or in-use images are skipped |
| `pruneImages(): void` | removes dangling images |
| `transferImages(SshConnection $to, string ...$images): void` | `docker save \| gzip \| ssh docker load`, no registry. Shows progress if `pv` is installed. Always runs from this machine. |

## Compose

docker compose with chosen files, env and project. Immutable: each method returns a new instance.

```php
$prod = Compose::files('compose.yaml', 'compose.prod.yaml')->env(['TAG' => $sha]);
$prod->run('up', '-d', '--remove-orphans');
```

| method | |
|---|---|
| `files(string ...$files): Compose` | static; no files: docker's defaults |
| `env(array $vars): Compose` | env vars for `${...}` in the compose files |
| `project(string $name): Compose` | `-p` |
| `run(string ...$args): void` | |
| `output(string ...$args): string` | |
| `container(string $service, Closure $fn): mixed` | every `Shell` call inside `$fn` runs in a new container of `$service` (`docker compose run --rm`), here or on the host whose closure it's in; `Shell::env()` vars and `Shell::in()` dirs go into the container. Logged as `prod/app $ ...`. Returns what `$fn` returns. |

## Release

Deploys Docker images without a registry: builds them here from the committed HEAD with
`TAG=<sha> docker compose build`, copies them to the host, runs the hooks and starts them. The images are those
of the services with a `build` section, and each must be tagged with the release: `image: app-php:${TAG:?}`.
The host keeps the last releases in `.releases` (in its dir) for rollback. See the
[deploy recipe](recipes.md#deploy-docker-images-without-a-registry).

```php
$prod = Release::to('prod')
    ->composeFiles('compose.yaml', 'compose.prod.yaml')
    ->beforeStart('app', fn () => Shell::run('php', 'artisan', 'migrate', '--force'));

$prod->deploy();
```

| setup | |
|---|---|
| `to(string $host): Release` | static; a host added with `Ssh::addHost()` |
| `composeFiles(string ...$files): Release` | default `compose.yaml`. Paths in the commit; every start writes them, as committed, to the same paths in the host dir. |
| `platform(string $platform): Release` | e.g. `linux/amd64`; default: the host's, as its Docker reports it |
| `keep(int $releases): Release` | releases the host keeps, the running one included; default 3 |
| `beforeStart(string $service, Closure $fn): Release` | `fn (string $tag)`, before `up`, also on rollback. Every `Shell` call inside runs in a new container of `$service` from that release, on the host (see `Compose::container()`). If it fails, the running containers stay. |

| method | |
|---|---|
| `deploy(): string` | Fails on uncommitted changes. Builds HEAD from `git archive` with `docker compose build` (unless the host has its images), uploads the images, removes the local copies, then starts it: compose files, hooks, `up -d --remove-orphans`, `.releases`, removes older images. Returns the tag. |
| `rollback(?string $tag = null): string` | starts `$tag`, by default the release before the running one, with the compose files of its commit; nothing is built. Returns the tag. |
| `history(): array` | tags on the host, newest first; `[0]` is running |
| `current(): ?string` | the running tag, `null` before the first deploy |
| `compose(string ...$args): void` | `docker compose` on the host against the running release: `->compose('logs', '-f', 'worker')` |

## Git

| method | |
|---|---|
| `sha(int $length = 12): string` | short sha of HEAD |
| `branch(): string` | |
| `isClean(): bool` | no uncommitted changes, no untracked files |
| `archive(?string $dir = null, string $ref = 'HEAD'): string` | exports a commit (exactly the committed files) to `$dir`, or to a temp dir removed when the script ends; returns the dir. A clean docker build context. |

## Env

| method | |
|---|---|
| `read(string $file, string ...$keys): array` | `KEY => value` from a `.env` file, all keys or only `$keys`; `[]` if the file is missing. On a host inside its closure. |
| `load(string $file, bool $override = false): void` | puts the values into the environment: `getenv()`, `$_ENV` and the commands devio runs locally see them. Variables already set win unless `$override`; a missing file is skipped. Use `__DIR__`: until `Devio::run()`, relative paths are relative to where `./dev` was called from. |
| `parse(string $content, string ...$keys): array` | comments, `export`, quotes, inline `#` comments |

## Http

From this machine, redirects followed.

| method | |
|---|---|
| `status(string $url, int $timeout = 10): int` | status of a GET, 0 if nothing answers |
| `waitFor(string $url, int $status = 200, int $attempts = 6, int $delay = 5): bool` | retries until it answers `$status` |
| `expect(string $url, int $status = 200, int $attempts = 6, int $delay = 5): void` | like `waitFor`, logs the attempts and fails the command (`Failed`) if it never answers `$status` |

## Console

The log goes to **stderr**, so a command's stdout stays clean: `./dev prod ps > out.txt`.

```
● build 3f2a1c9e0b12                     step / task heading
  $ docker build --tag app:3f2a… ctx     a command, cut to the terminal width
  ...docker's own output...
  ✔ 41.8s                                its status and duration
● release                                a task: what runs inside is indented
    prod $ docker compose up -d          a command on a host
    ✔ 2.1s
✔ release 2.3s                           how the task ended
✔ https://example.com answers 200

✔ deploy finished in 1m 12s              how the command ended
```

| method | |
|---|---|
| `step(string $message): void` | `● message` |
| `task(string $title, Closure $fn): mixed` | step + indented body + `✔ title 2.3s` or `✖ title 2.3s`; returns what `$fn` returns, rethrows |
| `info(string $message): void` | |
| `success(string $message): void` | `✔ message` |
| `warn(string $message): void` | `▲ message` |
| `error(string $message): void` | `✖ message`, further lines dimmed |
| `fail(string $message): never` | stops the command: throws `Failed` |
| `confirm(string $question, bool $default = false): bool` | without a terminal (CI) the default is the answer |
| `ask(string $question, ?string $default = null): string` | |
| `mask(string ...$secrets): void` | replaced by `••••` wherever devio prints them |
| `verbose(bool $verbose = true): void` | what `-v` does |
| `colors(bool $colors): void` | |

## Notifier

```php
Notifier::addChannel('telegram', new Telegram($token, $chatId));
Notifier::addChannel('slack', new Slack($webhook), levels: ['warning', 'error']);

Notifier::success("deployed $sha");
Notifier::notify('error', 'migrations failed', channels: ['telegram']);
```

| method | |
|---|---|
| `addChannel(string $name, Channel $channel, array $levels = []): void` | `$levels`: `Level`s or `'success'`, `'info'`, `'warning'`, `'error'`; `[]` means all |
| `notify(Level\|string $level, string $message, ?array $channels = null): void` | to every channel taking `$level`, or only to the named ones |
| `success(string $message)`, `info(...)`, `warning(...)`, `error(...)` | |

A channel that fails to send logs `▲ telegram notification failed: ...` and nothing else: a notification never fails a deploy.

## Level

`enum Level: string` with cases `Success`, `Info`, `Warning`, `Error`. `emoji()` gives ✅ ℹ️ ⚠️ 🚨, which channels put in front of the message.

## Channels

| class | |
|---|---|
| `Telegram(string $token, string\|int $chatId)` | bot message; [setup](recipes.md#telegram) |
| `Slack(string $webhookUrl)` | incoming webhook |
| `Discord(string $webhookUrl)` | webhook |

Your own channel implements one method and throws when sending fails:

```php
interface Channel
{
    public function send(Level $level, string $message): void;
}
```
