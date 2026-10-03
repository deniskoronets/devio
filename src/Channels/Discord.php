<?php

namespace Dekor\Devio\Channels;

use Dekor\Devio\Level;
use Dekor\Devio\Support\HttpClient;
use RuntimeException;

/** A message through a Discord webhook (channel settings → Integrations → Webhooks). */
final class Discord implements Channel
{
    private const MAX_LENGTH = 2000;

    public function __construct(private readonly string $webhookUrl)
    {
    }

    public function send(Level $level, string $message): void
    {
        [$status, $body] = HttpClient::postJson($this->webhookUrl, [
            'content' => self::cut($level->emoji() . ' ' . $message),
        ]);

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("discord answered $status: $body");
        }
    }

    /** Cuts by characters, not bytes, so a multibyte character is never split. */
    private static function cut(string $text): string
    {
        return preg_match('/^.{0,' . self::MAX_LENGTH . '}/su', $text, $match) ? $match[0] : $text;
    }
}
