<?php

namespace Dekor\Devio\Tests;

use Dekor\Devio\Channels\Channel;
use Dekor\Devio\Level;
use Dekor\Devio\Notifier;
use InvalidArgumentException;
use RuntimeException;

final class NotifierTest extends TestCase
{
    public function test_levels_and_channel_filters(): void
    {
        $all = $this->channel();
        $errors = $this->channel();
        Notifier::addChannel('all', $all);
        Notifier::addChannel('errors', $errors, levels: ['error', Level::Warning]);

        Notifier::success('deployed');
        Notifier::notify('error', 'broken');
        Notifier::notify(Level::Info, 'only all', channels: ['all']);

        $this->assertSame(['success: deployed', 'error: broken', 'info: only all'], $all->sent);
        $this->assertSame(['error: broken'], $errors->sent);
    }

    public function test_a_failing_channel_only_warns(): void
    {
        $ok = $this->channel();
        Notifier::addChannel('broken', new class implements Channel {
            public function send(Level $level, string $message): void
            {
                throw new RuntimeException('telegram answered 401: Unauthorized');
            }
        });
        Notifier::addChannel('ok', $ok);

        Notifier::error('x');

        $this->assertSame(['error: x'], $ok->sent);
        $this->assertStringContainsString('▲ broken notification failed: telegram answered 401', $this->console());
    }

    public function test_unknown_level(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Notifier::notify('fatal', 'x');
    }

    private function channel(): Channel
    {
        return new class implements Channel {
            public array $sent = [];

            public function send(Level $level, string $message): void
            {
                $this->sent[] = "{$level->value}: $message";
            }
        };
    }
}
