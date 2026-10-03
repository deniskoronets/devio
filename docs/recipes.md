# Recipes

- [The script](#the-script)
- [Dev stack shortcuts](#dev-stack-shortcuts)
- [Deploy Docker images without a registry](#deploy-docker-images-without-a-registry)
- [Rollback](#rollback)
- [Compose on the server: `./dev prod logs -f worker`](#compose-on-the-server)
- [Telegram](#telegram) · [Slack, Discord](#slack-discord) · [Alert on every failure](#alert-on-every-failure) · [Your own channel](#your-own-channel)
- [Secrets](#secrets)
- [Ask before touching prod](#ask-before-touching-prod)
- [Several servers](#several-servers)
- [Run something locally from inside a host closure](#run-something-locally-from-inside-a-host-closure)
- [CI and logs to a file](#ci-and-logs-to-a-file)

## The script

A file named `dev` in the project root, executable (`chmod +x dev`):

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/devio.phar'; // or __DIR__ . '/vendor/autoload.php'

use Dekor\Devio\{Args, Devio, Docker};

Devio::command(name: 'up', description: 'start the dev stack', handler: fn (Args $args) => Docker::compose('up', '-d', ...$args));

Devio::run();
```

`./dev` lists the commands. Commands run from the script's directory, wherever you call it from.

## Dev stack shortcuts

Thin wrappers are `plain()`: they print only the wrapped command's output and exit with its code.

```php
Devio::command(name: 'up', description: 'start the dev stack', handler: fn (Args $args) => Docker::compose('up', '-d', ...$args))
    ->command(name: 'down', description: 'stop it', handler: fn (Args $args) => Docker::compose('down', ...$args))
    ->command(name: 'build', description: 'rebuild the images', handler: fn (Args $args) => Docker::compose('build', ...$args))
    ->command(name: 'logs', description: 'follow logs: ./dev logs worker', handler: fn (Args $args) => Docker::compose('logs', '-f', '--tail=100', ...$args))->plain()
    ->command(name: 'art', description: 'artisan', handler: fn (Args $args) => Docker::compose('exec', 'app', 'php', 'artisan', ...$args))->plain()
    ->command(name: 'composer', description: 'composer', handler: fn (Args $args) => Docker::compose('exec', 'app', 'composer', ...$args))->plain()
    ->command(name: 'npm', description: 'npm', handler: fn (Args $args) => Docker::compose('run', '--rm', 'node', 'npm', ...$args))->plain()
    ->command(name: 'test', description: 'tests', handler: fn (Args $args) => Docker::compose('exec', 'app', 'php', 'artisan', 'test', ...$args))->plain()
    ->command(name: 'sh', description: 'shell in the app container', handler: fn () => Docker::compose('exec', 'app', 'bash'))->plain();
```

`./dev art make:migration create_posts_table`, `./dev test --filter=Billing`, `./dev logs worker`.

## Deploy Docker images without a registry

`Release` builds the images here from the commit itself with `docker compose build`, tags them with its sha,
copies them to the server over SSH, migrates and starts them. The server needs only Docker and its `.env`, and
keeps the last 3 releases for rollback.

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/devio.phar';

use Dekor\Devio\{Args, Devio, Http, Notifier, Release, Shell, Ssh};

Ssh::addHost('prod', '203.0.113.10', 'deploy', '~/.ssh/id_ed25519', dir: '/opt/app');

$prod = Release::to('prod')
    ->composeFiles('compose.yaml', 'compose.prod.yaml')
    ->beforeStart('app', fn () => Shell::run('php', 'artisan', 'migrate', '--force'));

Devio::command(name: 'deploy', description: 'build HEAD, upload it, migrate, start, check the site', handler: function () use ($prod) {
    $tag = $prod->deploy();
    Http::expect('https://example.com');
    Notifier::success("deployed $tag");
});

Devio::run();
```

The images come from the compose files: every service with a `build` section is built, and **its `image` must
be tagged with `${TAG}`**, which `Release` sets to the commit's sha. Without it each deploy would overwrite the
same image and there would be nothing to roll back to, so the deploy stops with
`service app is built, so its image needs the release tag`. `pull_policy: never` keeps compose from looking for
the images in a registry:

```yaml
services:
  app:
    image: app-php:${TAG:?}
    build: { context: ., dockerfile: docker/Dockerfile, target: prod }
    pull_policy: never
  worker:
    image: app-php:${TAG:?}      # same image, no build section: built once, for app
    command: php artisan queue:work
    pull_policy: never
  nginx:
    image: app-nginx:${TAG:?}
    build: { context: ., dockerfile: docker/Dockerfile, target: nginx }
    pull_policy: never
```

`${TAG:?}` makes compose refuse to run without a tag, instead of looking for `app-php:` with an empty one.

```
$ ./dev deploy
● deploy 3f2a1c9e0b12 to prod
  $ git archive 'HEAD' | tar -x -C '/tmp/tmp.Xq3'
  ✔ 0.2s

● build 3f2a1c9e0b12
    $ DOCKER_DEFAULT_PLATFORM=linux/amd64 TAG=3f2a1c9e0b12 docker compose -f compose.yaml -f compose.prod.yaml build
    ...docker's output...
    ✔ 1m 4s
✔ build 3f2a1c9e0b12 1m 4s

● upload 3f2a1c9e0b12
    $ docker save app-php:3f2a1c9e0b12 app-nginx:3f2a1c9e0b12 | gzip | ssh prod docker load
    Loaded image: app-php:3f2a1c9e0b12
    Loaded image: app-nginx:3f2a1c9e0b12
    ✔ 22.7s
✔ upload 3f2a1c9e0b12 22.7s

● start 3f2a1c9e0b12
    prod/app $ php artisan migrate --force
    ...
    ✔ 3.4s
    prod $ TAG=3f2a1c9e0b12 docker compose -f compose.yaml -f compose.prod.yaml up -d --remove-orphans
    ...
    ✔ 5.6s
✔ start 3f2a1c9e0b12 9.1s
✔ https://example.com answers 200

✔ deploy finished in 1m 37s
```

What `deploy()` does:

1. Stops on uncommitted changes: it deploys exactly `HEAD`, tagged with its short sha (`app-php:3f2a1c9e0b12`).
2. Exports the commit with `git archive`: no local `.env`, `vendor` or `node_modules` get in. If the server
   already has its images (a redeploy), skips to 5.
3. `docker compose build` in the export, for the server's platform as its Docker reports it, so `linux/amd64`
   also from an Apple Silicon Mac; `->platform('linux/amd64')` saves asking.
4. `docker save | gzip | ssh docker load`, then removes the local copies.
5. Writes the compose files, as committed, to the server and runs the `beforeStart` hooks: every `Shell` call in
   the closure runs in a new container of that service from the new images (`docker compose run --rm app ...`),
   while the old release keeps serving. Then `docker compose up -d --remove-orphans` with `TAG=<sha>`.
6. Records the tag in `.releases` on the server and removes the images of releases older than the last 3
   (`->keep(5)` for more).

The compose files are paths in the commit, from the repository root, and are deployed as committed. Build args,
secrets and the rest go in the `build` section as usual.

If a hook fails, `up` doesn't run and the running containers stay untouched. Migrations run before the new
containers start, so the old code briefly runs on the new schema: keep migrations backward compatible (add first,
drop in a later release).

Why not migrate in the image's entrypoint? It would run in every container of the image (`app`, `worker`, ...) at
once and on every restart, and when it fails, the new containers have already replaced the old ones. A build can't
migrate at all: it has no database, and the same image goes to every server.

## Rollback

With `$prod` from the deploy recipe:

```php
Devio::command(name: 'rollback', description: 'start an older release again: ./dev rollback [sha] (default: the previous one)', handler: function (Args $args) use ($prod) {
    $tag = $prod->rollback($args->get(0));
    Http::expect('https://example.com');
    Notifier::warning("rolled back to $tag");
});

Devio::command(name: 'releases', description: 'releases on the server, newest first', handler: function () use ($prod) {
    echo implode("\n", $prod->history()), "\n";
});
```

A rollback starts the older images with the compose files of their commit. Nothing is built, so it takes seconds.
The `beforeStart` hooks run again (for `artisan migrate` that's a no-op: the old code has no newer migrations),
and migrations are not undone. The release you left is now the previous one, so a second `./dev rollback` goes
back to it.

## Compose on the server

`./dev prod ps`, `./dev prod logs -f worker`, `./dev prod exec app php artisan tinker`, against the running
release. With a terminal, the remote side gets one too, so tinker and Ctrl+C work.

```php
Devio::command(name: 'prod', description: 'docker compose on the server: ./dev prod logs -f worker', handler: fn (Args $args) => $prod->compose(...$args))->plain();
```

And a shell there: `->command(name: 'ssh', description: 'shell on the server', handler: fn () => Ssh::to('prod')->shell())->plain()`.

## Telegram

1. Message [@BotFather](https://t.me/BotFather): `/newbot`, which gives you the token.
2. Add the bot to your group (or message it directly), write something there, then open
   `https://api.telegram.org/bot<token>/getUpdates` and take `chat.id` (group ids are negative).

```php
use Dekor\Devio\Channels\Telegram;

Notifier::addChannel('telegram', new Telegram($token, '-1001234567890'));

Notifier::success("deployed $tag");   // ✅ deployed 3f2a1c9e0b12
```

Don't put the token in the script, which is committed: see [Secrets](#secrets).

## Slack, Discord

```php
use Dekor\Devio\Channels\{Discord, Slack};

Notifier::addChannel('slack', new Slack('https://hooks.slack.com/services/...'));
Notifier::addChannel('discord', new Discord('https://discord.com/api/webhooks/...'), levels: ['error']);
```

## Alert on every failure

```php
Devio::onFailure(function (Throwable $e, string $command) {
    Notifier::error("$command failed: " . strtok($e->getMessage(), "\n"));
});
```

Plain commands (`./dev art`, `./dev test`) don't trigger it, so failing tests don't page anyone.

## Your own channel

```php
use Dekor\Devio\Channels\Channel;
use Dekor\Devio\Level;

final class Log implements Channel
{
    public function send(Level $level, string $message): void
    {
        file_put_contents(__DIR__ . '/deploys.log', date('c') . " {$level->value} $message\n", FILE_APPEND);
    }
}

Notifier::addChannel('log', new Log());
```

## Secrets

Keep them out of the committed script: in your environment, or in a git-ignored file loaded into it with
`Env::load()`. Variables that are already set win, so in CI the pipeline's secrets are used and the file isn't
needed.

```php
Env::load(__DIR__ . '/.env.deploy');   // TG_TOKEN=..., TG_CHAT=...

if (getenv('TG_TOKEN')) {
    Notifier::addChannel('telegram', new Telegram(getenv('TG_TOKEN'), getenv('TG_CHAT')));
    Console::mask(getenv('TG_TOKEN'));   // never printed, also not in -v logs
}
```

`Env::read()` gives you the values as an array instead, without touching the environment.

On the server, `Env::read()` inside a host closure reads the server's file, for values that commands need:

```php
$url = Ssh::to('prod')->run(fn () => Env::read('.env', 'APP_URL'))['APP_URL'];
```

## Ask before touching prod

```php
Console::confirm('Deploy to prod?') || Console::fail('cancelled');
```

Without a terminal (CI) the answer is the default (`false` here), so pass `true` as default if CI should go ahead.

## Several servers

```php
Ssh::addHost('web1', '203.0.113.10', 'deploy', dir: '/opt/app');
Ssh::addHost('web2', '203.0.113.11', 'deploy', dir: '/opt/app');

foreach (['web1', 'web2'] as $name) {
    Console::task("deploy $name", fn () => Ssh::to($name)->run(fn () => Docker::compose('up', '-d')));
}
```

## Run something locally from inside a host closure

```php
Ssh::to('prod')->run(function (SshConnection $prod) {
    $dump = '/tmp/db.sql.gz';
    Shell::run('sh', '-c', "docker compose exec -T mysql mysqldump app | gzip > $dump");   // on prod
    $prod->download($dump, 'backup.sql.gz');                                               // to here
    Shell::local(fn () => Shell::run('ls', '-lh', 'backup.sql.gz'));                       // here
});
```

## CI and logs to a file

Without a terminal there are no colors and no prompts (`confirm()` returns its default), and long commands
aren't cut. The log goes to stderr and command output to stdout, so keep both:

```bash
./dev deploy > deploy.log 2>&1
```
