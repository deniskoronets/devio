<?php

namespace Dekor\Devio;

use Dekor\Devio\Channels\Channel;
use InvalidArgumentException;
use Throwable;
use ValueError;

/**
 *   Notifier::addChannel('telegram', new Telegram($token, $chatId));
 *   Notifier::addChannel('slack', new Slack($webhookUrl), levels: ['error']);
 *   Notifier::success('deployed 3f2a1c9e0b12');
 *
 * A channel that fails to send only logs a warning: a notification never fails a deploy.
 */
final class Notifier
{
    /** @var array<string, array{0: Channel, 1: Level[]}> */
    private static array $channels = [];

    /** $levels: the levels this channel gets (Level or 'success', 'info', 'warning', 'error'); [] = all. */
    public static function addChannel(string $name, Channel $channel, array $levels = []): void
    {
        self::$channels[$name] = [$channel, array_map([self::class, 'level'], $levels)];
    }

    /** Sends to every channel that takes $level, or only to the named $channels. */
    public static function notify(Level|string $level, string $message, ?array $channels = null): void
    {
        $level = self::level($level);

        foreach (self::$channels as $name => [$channel, $levels]) {
            if (($channels !== null && ! in_array($name, $channels, true)) || ($levels && ! in_array($level, $levels, true))) {
                continue;
            }

            try {
                $channel->send($level, $message);
            } catch (Throwable $e) {
                Console::warn("$name notification failed: {$e->getMessage()}");
            }
        }
    }

    public static function success(string $message): void
    {
        self::notify(Level::Success, $message);
    }

    public static function info(string $message): void
    {
        self::notify(Level::Info, $message);
    }

    public static function warning(string $message): void
    {
        self::notify(Level::Warning, $message);
    }

    public static function error(string $message): void
    {
        self::notify(Level::Error, $message);
    }

    private static function level(Level|string $level): Level
    {
        try {
            return $level instanceof Level ? $level : Level::from($level);
        } catch (ValueError) {
            throw new InvalidArgumentException("unknown level '$level', use success, info, warning or error");
        }
    }
}
