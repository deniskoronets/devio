<?php

namespace Dekor\Devio\Channels;

use Dekor\Devio\Level;

/** Where Notifier sends messages. send() throws when it fails; Notifier turns that into a warning. */
interface Channel
{
    public function send(Level $level, string $message): void;
}
