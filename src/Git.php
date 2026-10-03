<?php

namespace Dekor\Devio;

final class Git
{
    /** Short sha of HEAD. */
    public static function sha(int $length = 12): string
    {
        return Shell::output('git', 'rev-parse', "--short=$length", 'HEAD');
    }

    public static function branch(): string
    {
        return Shell::output('git', 'rev-parse', '--abbrev-ref', 'HEAD');
    }

    /** No uncommitted changes and no untracked files. */
    public static function isClean(): bool
    {
        return Shell::output('git', 'status', '--porcelain') === '';
    }

    /**
     * Exports a commit (the files as committed, nothing ignored or uncommitted) to $dir, e.g. as a clean
     * docker build context. Without $dir it goes to a temp dir, removed when the script ends. Returns the dir.
     */
    public static function archive(?string $dir = null, string $ref = 'HEAD'): string
    {
        if ($dir === null) {
            $dir = Shell::output('mktemp', '-d');
            $context = Shell::context();
            register_shutdown_function(fn () => Shell::within($context, fn () => Shell::succeeds('rm', '-rf', $dir)));
        } else {
            Shell::run('mkdir', '-p', $dir);
        }

        Shell::pipe('git archive ' . escapeshellarg($ref) . ' | tar -x -C ' . escapeshellarg($dir));

        return $dir;
    }
}
