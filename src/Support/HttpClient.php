<?php

namespace Dekor\Devio\Support;

use Dekor\Devio\Devio;

/** @internal A minimal HTTP client on PHP streams, so devio needs no extensions. */
final class HttpClient
{
    /** @return array{0: int, 1: string} status (0 if nothing answers) and body */
    public static function request(string $method, string $url, ?string $body = null, array $headers = [], int $timeout = 10): array
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'timeout' => $timeout,
            'ignore_errors' => true, // 4xx/5xx still give a response
            'follow_location' => 1,
            'max_redirects' => 5,
            'user_agent' => 'devio/' . Devio::VERSION,
        ]]);

        $previousTimeout = ini_set('default_socket_timeout', (string) $timeout); // the connect timeout
        $stream = @fopen($url, 'r', false, $context);
        ini_set('default_socket_timeout', (string) $previousTimeout);

        if ($stream === false) {
            return [0, ''];
        }
        $response = (string) stream_get_contents($stream);
        $headers = stream_get_meta_data($stream)['wrapper_data'] ?? [];
        fclose($stream);

        // with redirects every response's headers are listed, the last status line is the final one
        $status = 0;
        foreach ($headers as $header) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $match)) {
                $status = (int) $match[1];
            }
        }

        return [$status, $response];
    }

    /** @return array{0: int, 1: string} */
    public static function postJson(string $url, array $data, int $timeout = 10): array
    {
        return self::request('POST', $url, json_encode($data, JSON_UNESCAPED_UNICODE), ['Content-Type: application/json'], $timeout);
    }
}
