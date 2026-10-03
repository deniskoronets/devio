<?php

namespace Dekor\Devio\Support;

use Dekor\Devio\Console;
use Dekor\Devio\Devio;

/** @internal `./dev devio-update-phar`: replaces the running devio.phar with the one committed on GitHub. */
final class PharUpdate
{
    public const URL = 'https://github.com/deniskoronets/devio/raw/main/dist/devio.phar';

    public static function run(string $phar, string $url = self::URL): void
    {
        Console::info("downloading $url");
        [$status, $body] = HttpClient::request('GET', $url, timeout: 60);
        $status === 200 || Console::fail("$url answered " . ($status ?: 'nothing'));

        if (! str_contains($body, '__HALT_COMPILER();') || ! preg_match("/const VERSION = '([^']+)'/", $body, $version)) {
            Console::fail("$url is not a devio.phar");
        }
        if ($body === file_get_contents($phar)) {
            Console::success(basename($phar) . " is up to date: devio $version[1]");

            return;
        }

        // a sibling file, so the rename replaces the phar in one step
        $download = "$phar.download";
        file_put_contents($download, $body) !== false || Console::fail("can't write $download");
        chmod($download, fileperms($phar) & 0777);
        rename($download, $phar) || Console::fail("can't replace $phar");

        $versions = $version[1] === Devio::VERSION ? "devio $version[1]" : 'devio ' . Devio::VERSION . " → $version[1]";
        Console::success("updated " . basename($phar) . ": $versions");
    }
}
