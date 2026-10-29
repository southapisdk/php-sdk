<?php

declare(strict_types=1);

use SouthApi\Build\LeakScanner;

require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__);
$walk = static function (string $directory) use (&$walk): void {
    foreach (new DirectoryIterator($directory) as $entry) {
        if ($entry->isDot() || in_array($entry->getFilename(), ['vendor', 'dist', '.git', '.phpunit.cache', '.phpstan-cache', 'CONTRACT-NOTES.md'], true)) {
            continue;
        }
        if ($entry->isLink()) {
            throw new RuntimeException('Symbolic links are forbidden in SDK sources.');
        }
        if ($entry->isDir()) {
            $walk($entry->getPathname());
        } else {
            $content = file_get_contents($entry->getPathname());
            if ($content === false) {
                throw new RuntimeException('Cannot read source file.');
            }
            LeakScanner::check($entry->getFilename(), $content);
        }
    }
};
$walk($root);
echo "SDK source leakage scan passed.\n";
