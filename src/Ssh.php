<?php

namespace Dekor\Devio;

/**
 *   Ssh::addHost('prod', '1.2.3.4', 'deploy', '~/.ssh/id_ed25519', dir: '/opt/app');
 *   Ssh::to('prod')->run(fn () => Docker::compose('ps'));
 */
final class Ssh
{
    /** @var array<string, Host> */
    private static array $hosts = [];

    /** @var array<string, SshConnection> */
    private static array $connections = [];

    /**
     * $host is an IP, a hostname or an alias from ~/.ssh/config. What is left null comes from ~/.ssh/config.
     * $dir is where commands run on that host and where relative paths point.
     */
    public static function addHost(
        string $name,
        string $host,
        ?string $user = null,
        ?string $sshKey = null,
        ?int $port = null,
        ?string $dir = null,
    ): void {
        self::$hosts[$name] = new Host($name, $host, $user, $sshKey, $port, $dir);
        unset(self::$connections[$name]);
    }

    public static function to(string $name): SshConnection
    {
        $host = self::$hosts[$name] ?? throw new Failed("unknown host '$name', add it with Ssh::addHost()");

        return self::$connections[$name] ??= new SshConnection($host);
    }
}
