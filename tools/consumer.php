<?php

declare(strict_types=1);

use SouthApi\Build\{Process, ReleaseBuilder};

require dirname(__DIR__) . '/vendor/autoload.php';
$archive = dirname(__DIR__) . '/dist/south-api-sdk.zip';
ReleaseBuilder::inspect($archive);
$directory = sys_get_temp_dir() . '/southapi-consumer-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Cannot create consumer directory.');
}
try {
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) {
        throw new RuntimeException('Cannot open consumer archive.');
    }
    try {
        if (!$zip->extractTo($directory)) {
            throw new RuntimeException('Cannot extract consumer archive.');
        }
    } finally {
        $zip->close();
    }
    Process::run(['composer', 'validate', '--strict', '--no-interaction'], $directory);
    Process::run(['composer', 'install', '--no-dev', '--no-plugins', '--no-scripts', '--no-interaction', '--prefer-dist'], $directory);
    $smoke = <<<'PHP'
<?php
require __DIR__ . '/vendor/autoload.php';
if (class_exists('Illuminate\Foundation\Application') || class_exists('PHPUnit\Framework\TestCase')) {
    throw new RuntimeException('Unexpected development/framework dependency.');
}
$transport = new class implements \SouthApi\Http\Transport {
    public array $requests = [];
    public function send(#[\SensitiveParameter] \SouthApi\Http\Request $request): \SouthApi\Http\Response {
        $this->requests[] = $request;
        return new \SouthApi\Http\Response(200, '{"outcome":0,"content":{"display_total":"1.00000001"}}');
    }
};
$client = new \SouthApi\Client(new \SouthApi\Security\Credentials('00000000-0000-4000-8000-000000000000', 'test-payment-key'), transport: $transport);
$result = $client->payments->create(['display_total' => '1.00000001', 'asset_code' => 'USD', 'merchant_order' => 'test-order-1']);
$client->rates->all();
if ($result->content()['display_total'] !== '1.00000001' || count($transport->requests) !== 2 || isset($transport->requests[1]->headers['sign']) || isset($transport->requests[1]->headers['X-Signature']) || isset($transport->requests[1]->headers['X-Timestamp'])) {
    throw new RuntimeException('Consumer behavior mismatch.');
}
if (isset($transport->requests[0]->headers['sign'])
    || !isset($transport->requests[0]->headers['X-Timestamp'], $transport->requests[0]->headers['X-Signature'])
    || !hash_equals(hash_hmac('sha256', $transport->requests[0]->headers['X-Timestamp'] . '.' . $transport->requests[0]->body, 'test-payment-key'), $transport->requests[0]->headers['X-Signature'])) {
    throw new RuntimeException('Consumer authentication mismatch.');
}
$page = new \SouthApi\Responses\Page(['entries'=>[], 'pagination'=>['item_count'=>0, 'has_more'=>true, 'next_cursor'=>null, 'previous_cursor'=>'previous', 'page_size'=>15]]);
if ($page->hasMore || !$page->hasPages) {
    throw new RuntimeException('Consumer pagination mismatch.');
}
$balances = new \SouthApi\Responses\BalanceResult([['balances'=>['business'=>[['available_balance'=>'1.00000001']], 'personal'=>[]]]]);
if ($balances->business()[0]['available_balance'] !== '1.00000001' || $balances->personal() !== []) {
    throw new RuntimeException('Consumer balances mismatch.');
}
$verifier = new \SouthApi\Security\WebhookVerifier(new \SouthApi\Security\Credentials(paymentKey: 'test-payment-key'));
$body = '{"event_kind":"payment","display_total":"1.00000001"}';
$timestamp = (string) time();
$signature = hash_hmac('sha256', $timestamp . '.' . $body, 'test-payment-key');
if ($verifier->verify($body, $timestamp, $signature)->content()['display_total'] !== '1.00000001') {
    throw new RuntimeException('Consumer webhook mismatch.');
}
foreach (['configuration', 'payments', 'pagination', 'webhook'] as $example) {
    require __DIR__ . '/examples/' . $example . '.php';
}
PHP;
    if (file_put_contents($directory . '/smoke.php', $smoke) === false) {
        throw new RuntimeException('Cannot write consumer smoke check.');
    }
    Process::run([PHP_BINARY, '-d', 'zend.exception_ignore_args=1', $directory . '/smoke.php'], $directory);
    echo "Clean consumer installed runtime-only archive and passed offline smoke checks.\n";
} finally {
    Process::removeDirectory($directory);
}
