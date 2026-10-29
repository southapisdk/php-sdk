<?php

declare(strict_types=1);

namespace SouthApi\Build;

use RuntimeException;

final class LeakScanner
{
    public static function check(string $name, string $content): void
    {
        $patterns = [
            '~-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----~',
            '~\bAKIA[0-9A-Z]{16}\b~',
            '~https?://[^\s/]+:[^\s@]+@~',
            '~https?://(?:gitlab|vault|jenkins)\.[^\s/]+~i',
            '~/(?:var/www|home/[^/]+/\.ssh)/~',
            '~\beyJ[A-Za-z0-9_-]{12,}\.[A-Za-z0-9_-]{12,}\.[A-Za-z0-9_-]{12,}\b~',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                throw new RuntimeException('Prohibited content detected in a package file.');
            }
        }
        if (preg_match('~(?:^|/)(?:\.env[^/]*|auth\.json|id_rsa|[^/]+\.(?:log|sql|dump))$~i', $name)) {
            throw new RuntimeException('Prohibited package filename.');
        }
    }
}
