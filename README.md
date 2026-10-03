# devio

A library for writing your project's dev and deploy script in PHP: `./dev up`, `./dev art migrate`, `./dev deploy`.
It's made for Docker-based PHP projects and reads like Laravel.

**Docs: [deniskoronets.github.io/devio](https://deniskoronets.github.io/devio/)**

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/devio.phar';

use Dekor\Devio\{Args, Devio, Docker, Env, Http, Notifier, Ssh, SshConnection};
use Dekor\Devio\Channels\Telegram;

Env::load(__DIR__ . '/.env.deploy');   // git-ignored, below

Ssh::addHost('prod', getenv('PROD_HOST'), getenv('PROD_USER'), getenv('PROD_SSH_KEY'), dir: getenv('PROD_DIR'));
Notifier::addChannel('telegram', new Telegram(getenv('TG_TOKEN'), getenv('TG_CHAT')));

Devio::command(name: 'up', description: 'start the dev stack', handler: fn (Args $args) => Docker::compose('up', '-d', ...$args))
    ->command(name: 'art', description: 'artisan', handler: fn (Args $args) => Docker::compose('exec', 'app', 'php', 'artisan', ...$args))->plain()
    ->command(name: 'deploy', description: 'deploy to prod', handler: function () {
        Ssh::to('prod')->run(function (SshConnection $prod) {
            $prod->run('git pull');
            Docker::compose('up', '-d', '--build');      // runs on prod: we are inside its closure
        });
        Http::expect('https://example.com');
        Notifier::success('deployed');
    });

Devio::run();
```

```bash
# .env.deploy
PROD_HOST=203.0.113.10
PROD_USER=deploy
PROD_SSH_KEY=~/.ssh/id_ed25519
PROD_DIR=/opt/app
TG_TOKEN=123456:ABC...
TG_CHAT=-1001234567890
```

```
$ ./dev deploy
  prod $ git pull
Already up to date.
  ✔ 0.4s
  prod $ docker compose up -d --build
...
  ✔ 38.2s
✔ https://example.com answers 200

✔ deploy finished in 39.1s
```

- **Commands** with arguments passed through: `./dev art make:migration create_posts_table`.
- **Shell** runs argv arrays (no escaping needed), streams output, and stops the command on the first failure with its exit code.
- **SSH**: inside `Ssh::to('prod')->run(fn)` every `Shell`, `Docker`, `Git` and `Env` call runs on that host, in its dir, over one shared connection. The server needs nothing but Docker.
- **Docker**: compose, build, and image transfer without a registry (`docker save | ssh docker load`).
- **Releases**: `Release::to('prod')->deploy()` builds HEAD here, tagged with its sha, ships it without a registry, migrates and starts it; the server keeps the last 3, so `rollback()` takes seconds.
- **Logs**: steps, tasks, every command with its host, status and duration. Long `docker build` lines fit the terminal; `-v` also shows the commands devio runs only to look at things.
- **Notifications**: Telegram, Slack, Discord, or your own channel, by level.
- One phar, no dependencies, PHP 8.1+.

## Install

Either download `devio.phar` next to your script:

```bash
wget https://github.com/deniskoronets/devio/raw/main/dist/devio.phar
```

Later, `./dev devio-update-phar` replaces it with the latest one.

and `require __DIR__ . '/devio.phar';`, or use Composer:

```bash
composer require --dev dekor/devio
```

and `require __DIR__ . '/vendor/autoload.php';`. Then make the script executable: `chmod +x dev`.

The phar works before `composer install` has run, and on machines without Composer.

## Docs

Online at [deniskoronets.github.io/devio](https://deniskoronets.github.io/devio/):

- [API reference](docs/api.md)
- [Recipes](docs/recipes.md): dev stack shortcuts, deploying Docker images without a registry, rollback,
  Telegram/Slack alerts, secrets, confirmations

## Development

```bash
composer install   # also turns on the git hooks in .githooks
composer test      # phpunit; ssh, scp and docker are faked by tests/bin
composer build     # dist/devio.phar
```

`dist/devio.phar` is committed, so it can be downloaded straight from `main`. The pre-commit hook rebuilds it from
the staged files whenever a commit touches `src/`, `build/` or `LICENSE`, and adds it to the commit. CI fails if the
committed phar doesn't match the sources.

## Our sponsors

<a href="https://mobicard.com.ua/" title="Mobicard"><img src="https://mobicard.com.ua/favicon.svg" width="32" alt="Mobicard"></a>
<a href="https://busyb.com.ua/" title="BusyB"><img src="https://busyb.com.ua/favicon.svg" width="32" alt="BusyB"></a>
<a href="https://pc-info.com.ua/" title="PC-Info"><img src="https://pc-info.com.ua/favicon.svg" width="32" alt="PC-Info"></a>
<a href="https://linktrust.pro/" title="LinkTrust"><img src="https://linktrust.pro/linktrust.svg" width="32" alt="LinkTrust"></a>

## Author

Created and maintained by **[Denys Koronets](https://github.com/deniskoronets/)**.

## License

Apache 2.0
