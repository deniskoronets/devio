<?php

namespace Dekor\Devio\Channels;

use Dekor\Devio\Level;
use Dekor\Devio\Support\HttpClient;
use RuntimeException;

/**
 * A bot message to a chat: create a bot with @BotFather (the token), add it to the chat, and take the chat id
 * from https://api.telegram.org/bot<token>/getUpdates after writing something there.
 */
final class Telegram implements Channel
{
    private const MAX_LENGTH = 4096;

    public function __construct(
        private readonly string $token,
        private readonly string|int $chatId,
    ) {
    }

    public function send(Level $level, string $message): void
    {
        [$status, $body] = HttpClient::postJson("https://api.telegram.org/bot{$this->token}/sendMessage", [
            'chat_id' => $this->chatId,
            'text' => self::cut($level->emoji() . ' ' . $message),
            'disable_web_page_preview' => true,
        ]);

        if ($status !== 200) {
            throw new RuntimeException("telegram answered $status: " . (json_decode($body, true)['description'] ?? $body));
        }
    }

    /** Cuts by characters, not bytes, so a multibyte character is never split. */
    private static function cut(string $text): string
    {
        return preg_match('/^.{0,' . self::MAX_LENGTH . '}/su', $text, $match) ? $match[0] : $text;
    }
}
