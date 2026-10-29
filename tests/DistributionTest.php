<?php

declare(strict_types=1);

namespace SouthApi\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SouthApi\Build\{LeakScanner, Process, ReleaseBuilder};
use ZipArchive;

final class DistributionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/southapi-release-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory . '/src');
        mkdir($this->directory . '/examples');
        file_put_contents($this->directory . '/LICENSE', 'Proprietary');
        file_put_contents($this->directory . '/README.md', 'Synthetic package');
        file_put_contents($this->directory . '/composer.json', '{"name":"southapi/test","scripts":{"test":"private"},"require-dev":{"test/tool":"*"}}');
        file_put_contents($this->directory . '/src/Client.php', '<?php // synthetic');
    }

    protected function tearDown(): void
    {
        Process::removeDirectory($this->directory);
    }

    public function testArchiveAllowlistAndDeterminism(): void
    {
        foreach (['.env', 'debug.log', 'dump.sql', 'CONTRACT-NOTES.md', 'private.txt'] as $filename) {
            file_put_contents($this->directory . '/' . $filename, 'PRIVATE_MARKER');
        }
        $first = $this->directory . '/one.zip';
        $second = $this->directory . '/two.zip';
        $files = ReleaseBuilder::build($this->directory, $first);
        ReleaseBuilder::build($this->directory, $second);
        self::assertSame(['LICENSE', 'README.md', 'composer.json', 'src/Client.php'], $files);
        self::assertSame(hash_file('sha256', $first), hash_file('sha256', $second));
        $zip = new ZipArchive();
        self::assertTrue($zip->open($first));
        $manifest = $zip->getFromName('composer.json');
        self::assertIsString($manifest);
        self::assertStringNotContainsString('require-dev', $manifest);
        self::assertStringNotContainsString('scripts', $manifest);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $content = $zip->getFromIndex($i);
            self::assertIsString($content);
            self::assertStringNotContainsString('PRIVATE_MARKER', $content);
        }
        $zip->close();
    }

    public function testSymlinkRejected(): void
    {
        symlink($this->directory . '/README.md', $this->directory . '/src/Linked.php');
        $this->expectException(RuntimeException::class);
        ReleaseBuilder::build($this->directory, $this->directory . '/release.zip');
    }

    public function testContaminatedSourceRejected(): void
    {
        file_put_contents($this->directory . '/src/Client.php', '-----BEGIN ' . 'PRIVATE KEY-----');
        $this->expectException(RuntimeException::class);
        ReleaseBuilder::build($this->directory, $this->directory . '/release.zip');
    }

    public function testContaminatedArchiveRejected(): void
    {
        $archive = $this->directory . '/contaminated.zip';
        $zip = new ZipArchive();
        $zip->open($archive, ZipArchive::CREATE);
        $zip->addFromString('README.md', '-----BEGIN ' . 'PRIVATE KEY-----');
        $zip->close();
        $this->expectException(RuntimeException::class);
        ReleaseBuilder::inspect($archive);
    }

    public function testTraversalRejected(): void
    {
        $archive = $this->directory . '/traversal.zip';
        $zip = new ZipArchive();
        $zip->open($archive, ZipArchive::CREATE);
        $zip->addFromString('../README.md', 'synthetic');
        $zip->close();
        $this->expectException(RuntimeException::class);
        ReleaseBuilder::inspect($archive);
    }

    public function testFilenameScanRejectsEnvironment(): void
    {
        $this->expectException(RuntimeException::class);
        LeakScanner::check('.env', 'synthetic');
    }
}
