<?php

namespace Dekor\Devio\Channels;

use Dekor\Devio\Level;
use Dekor\Devio\Support\HttpClient;
use RuntimeException;

/** A message through a Slack incoming webhook (https://api.slack.com/messaging/webhooks). */
final class Slack implements Channel
{
    public function __construct(private readonly string $webhookUrl)
    {
    }

    public function send(Level $level, string $message): void
    {
        [$status, $body] = HttpClient::postJson($this->webhookUrl, ['text' => $level->emoji() . ' ' . $message]);

        if ($status !== 200) {
            throw new RuntimeException("slack answered $status: $body");
        }
    }
}
