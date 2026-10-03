<?php

namespace Dekor\Devio;

use Dekor\Devio\Support\HttpClient;

/** Checks a site from this machine (always local, also inside a host closure). Redirects are followed. */
final class Http
{
    /** Status code of a GET, 0 if nothing answers. */
    public static function status(string $url, int $timeout = 10): int
    {
        return HttpClient::request('GET', $url, timeout: $timeout)[0];
    }

    /** Retries until $url answers $status; tells whether it did. */
    public static function waitFor(string $url, int $status = 200, int $attempts = 6, int $delay = 5): bool
    {
        for ($attempt = 1; ; $attempt++) {
            if (self::status($url) === $status) {
                return true;
            }
            if ($attempt >= $attempts) {
                return false;
            }
            sleep($delay);
        }
    }

    /** Like waitFor(), but logs the attempts and fails the command if $url never answers $status. */
    public static function expect(string $url, int $status = 200, int $attempts = 6, int $delay = 5): void
    {
        for ($attempt = 1; ; $attempt++) {
            $actual = self::status($url);
            if ($actual === $status) {
                Console::success("$url answers $status");

                return;
            }

            $answer = $actual === 0 ? 'does not answer' : "answers $actual";
            if ($attempt >= $attempts) {
                Console::fail("$url $answer, expected $status");
            }
            Console::info("$url $answer, retrying in {$delay}s ($attempt/$attempts)");
            sleep($delay);
        }
    }
}
