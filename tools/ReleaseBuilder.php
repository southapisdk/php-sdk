<?php

declare(strict_types=1);

namespace SouthApi\Build;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

final class ReleaseBuilder
{
    /** @return list<string> */
    public static function files(string $root): array
    {
        $files = ['LICENSE', 'README.md', 'composer.json'];
        foreach (['src', 'examples'] as $directory) {
            if (is_link($root . '/' . $directory)) {
                throw new RuntimeException('Symbolic links are forbidden in release inputs.');
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->isLink()) {
                    throw new RuntimeException('Symbolic links are forbidden in release inputs.');
                }
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        sort($files);
        return $files;
    }

    /** @return list<string> */
    public static function build(string $root, string $destination): array
    {
        $temporary = tempnam(dirname($destination), '.southapi-build-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot allocate release staging file.');
        }
        try {
            $files = self::writeArchive($root, $temporary);
            if (!rename($temporary, $destination)) {
                throw new RuntimeException('Cannot finalize release archive.');
            }
            return $files;
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @return list<string> */
    private static function writeArchive(string $root, string $destination): array
    {
        $files = self::files($root);
        $zip = new ZipArchive();
        if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create release archive.');
        }
        try {
            foreach ($files as $name) {
                $path = $root . '/' . $name;
                if (is_link($path) || !is_file($path)) {
                    throw new RuntimeException('Release input is not a regular file.');
                }
                $content = file_get_contents($path);
                if ($content === false) {
                    throw new RuntimeException('Cannot read release input.');
                }
                if ($name === 'composer.json') {
                    $manifest = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
                    if (!is_array($manifest)) {
                        throw new RuntimeException('Invalid Composer manifest.');
                    }
                    unset($manifest['scripts'], $manifest['require-dev'], $manifest['autoload-dev'], $manifest['archive']);
                    if (isset($manifest['repositories'])) {
                        throw new RuntimeException('Custom repositories are forbidden in release manifest.');
                    }
                    $manifest = array_intersect_key($manifest, array_flip(['name', 'description', 'type', 'license', 'require', 'autoload']));
                    $manifest['config'] = ['allow-plugins' => false];
                    $content = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                }
                LeakScanner::check($name, $content);
                if (!$zip->addFromString($name, $content)
                    || !$zip->setMtimeName($name, 315532800)
                    || !$zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16)) {
                    throw new RuntimeException('Cannot write release entry.');
                }
            }
        } finally {
            $zip->close();
        }
        self::inspect($destination, $files);
        return $files;
    }

    /**
     * @param list<string>|null $expected
     * @return list<string>
     */
    public static function inspect(string $archive, ?array $expected = null): array
    {
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new RuntimeException('Cannot open release archive.');
        }
        $names = [];
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (!is_string($name) || str_contains($name, '..') || str_contains($name, '\\')
                    || !preg_match('~\A(?:composer\.json|README\.md|LICENSE|(?:src|examples)/[A-Za-z0-9_/]+\.php)\z~', $name)) {
                    throw new RuntimeException('Unexpected release archive path.');
                }
                $opsys = 0;
                $attributes = 0;
                if (!$zip->getExternalAttributesIndex($i, $opsys, $attributes) || (($attributes >> 16) & 0170000) === 0120000) {
                    throw new RuntimeException('Invalid release entry attributes.');
                }
                $content = $zip->getFromIndex($i);
                if ($content === false || in_array($name, $names, true)) {
                    throw new RuntimeException('Unreadable or duplicate release entry.');
                }
                LeakScanner::check($name, $content);
                if ($name === 'composer.json') {
                    $manifest = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
                    if (!is_array($manifest) || isset($manifest['scripts']) || isset($manifest['repositories'])
                        || isset($manifest['require-dev']) || isset($manifest['autoload-dev'])) {
                        throw new RuntimeException('Unsafe release Composer manifest.');
                    }
                }
                $names[] = $name;
            }
        } finally {
            $zip->close();
        }
        sort($names);
        if ($expected !== null && $expected !== $names) {
            throw new RuntimeException('Release archive manifest mismatch.');
        }
        return $names;
    }
}
