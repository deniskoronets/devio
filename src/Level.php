<?php

namespace Dekor\Devio;

enum Level: string
{
    case Success = 'success';
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';

    public function emoji(): string
    {
        return match ($this) {
            self::Success => '✅',
            self::Info => 'ℹ️',
            self::Warning => '⚠️',
            self::Error => '🚨',
        };
    }
}
