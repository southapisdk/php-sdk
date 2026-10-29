<?php

declare(strict_types=1);

namespace SouthApi\Build;

use RuntimeException;

final class Process
{
    /** @param non-empty-list<string> $command */
    public static function run(array $command, string $directory): void
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start consumer verification.');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            // Build-only output; never handles merchant API calls or credentials.
            fwrite(STDERR, ($output === false ? '' : $output) . ($error === false ? '' : $error));
            throw new RuntimeException('Consumer verification command failed.');
        }
    }

    public static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            throw new RuntimeException('Invalid temporary directory.');
        }
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if ($entry->isDir() && !$entry->isLink()) {
                self::removeDirectory($entry->getPathname());
            } elseif (!unlink($entry->getPathname())) {
                throw new RuntimeException('Cannot remove temporary file.');
            }
        }
        if (!rmdir($directory)) {
            throw new RuntimeException('Cannot remove temporary directory.');
        }
    }
}
