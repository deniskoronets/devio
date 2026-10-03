<?php

namespace Dekor\Devio;

/** .env files, locally or on a host. */
final class Env
{
    /** KEY => value from a .env file (all, or only $keys); [] if there is no such file. */
    public static function read(string $file, string ...$keys): array
    {
        $result = Shell::capture('cat', $file);
        if ($result->exitCode === 1) {
            return [];
        }

        return self::parse($result->throw()->output, ...$keys);
    }

    /**
     * Puts a .env file's values into the environment: getenv(), $_ENV and the commands devio runs here see them.
     * Variables already set win (a real env var over the file), unless $override. A missing file is skipped.
     * Relative paths are relative to where ./dev was called from until Devio::run(), so use __DIR__.
     */
    public static function load(string $file, bool $override = false): void
    {
        foreach (self::read($file) as $key => $value) {
            if (! $override && getenv($key) !== false) {
                continue;
            }
            putenv("$key=$value");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }

    /** Parses .env syntax: comments, `export`, single/double quotes, inline # comments. */
    public static function parse(string $content, string ...$keys): array
    {
        $env = [];
        foreach (preg_split('/\R/', $content) as $line) {
            if (! preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*(.*?)\s*$/', $line, $match)) {
                continue;
            }
            [, $key, $value] = $match;

            if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"/', $value, $quoted)) {
                $value = strtr($quoted[1], ['\\n' => "\n", '\\"' => '"', '\\\\' => '\\']);
            } elseif (preg_match("/^'([^']*)'/", $value, $quoted)) {
                $value = $quoted[1];
            } else {
                $value = trim(preg_replace('/\s+#.*$/', '', $value));
            }

            $env[$key] = $value;
        }

        return $keys ? array_intersect_key($env, array_flip($keys)) : $env;
    }
}
