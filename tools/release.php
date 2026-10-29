<?php

declare(strict_types=1);

use SouthApi\Build\ReleaseBuilder;

require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__);
$directory = $root . '/dist';
if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
    throw new RuntimeException('Cannot create artifact directory.');
}
$files = ReleaseBuilder::build($root, $directory . '/south-api-sdk.zip');
echo 'Release artifact verified: ' . count($files) . " allowlisted files.\n";
