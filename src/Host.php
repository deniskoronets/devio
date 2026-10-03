<?php

namespace Dekor\Devio;

/** A host added with Ssh::addHost(). */
final class Host
{
    public function __construct(
        public readonly string $name,
        public readonly string $hostname,
        public readonly ?string $user = null,
        public readonly ?string $sshKey = null,
        public readonly ?int $port = null,
        public readonly ?string $dir = null,
    ) {
    }
}
