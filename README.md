# SouthAPI PHP merchant SDK

Standalone PHP 8.2+ client for the current SouthAPI merchant API. Requires Composer and
JSON and cURL support. No framework, database, environment-file loader or installation
hooks are required. The package identity is provisional and its license is
proprietary; external publication requires the owner's release decision.

## Install and configure

Install the reviewed package from the distribution supplied to you, then load
`vendor/autoload.php`. The provisional Composer name is `southapi/merchant-sdk`;
availability in a public registry is not implied.

```php
use SouthApi\Client;
use SouthApi\Security\Credentials;

// Obtain these from your application's secret store, never from browser input.
$client = new Client(new Credentials($merchantId, $paymentKey, $payoutKey));
$result = $client->payments->create([
    'display_total' => '25.00000001',
    'asset_code' => 'USD',
    'merchant_order' => 'your-unique-order-id',
    'callback_link' => 'https://merchant.example/webhooks/southapi',
]);
$content = $result->content(); // sensitive merchant data; do not log indiscriminately
```

A payment-only integration can omit the payout key. Public rates work with
`new Client()` and never send merchant, timestamp or signature headers. The default origin is
`https://api.southapi.com`. An explicit trusted HTTPS origin can be configured
with `SouthApi\Configuration`; user-controlled input must never select it.

## Operations

| Resource | Methods |
|---|---|
| `$client->payments` | `create`, `lookup`, `search`, `markPaid`, `refund`, `qrCode`, `retryCallback`, `retryCallbackV2`, `options` |
| `$client->depositAddresses` | `create`, `qrCode`, `block`, `refundBlocked`, `payments`, `search` |
| `$client->withdrawals` | `create`, `calculate`, `lookup`, `search`, `options`, `priority` |
| `$client->transfers` | `toPersonal`, `toBusiness` |
| `$client->balances` | `get` |
| `$client->transactions` | `search` |
| `$client->rates` | `get`, `all`, `dictionary`, `xml` |
| `$client->settings` | `paymentAssets`, `discounts`, `setDiscount` |

Array parameters use the API's public field names; source PHPDoc describes their
shapes. Amounts must be decimal **strings**, never floats. Values and nullable
response fields are preserved. Unknown response fields are accepted. JSON methods
return `Result`, with the API's `content` available through `content()`; list
methods return `Page`. XML rates return raw text without parsing XML entities.
The SDK validates required identifiers, accepted boolean representations, and
current destination/order syntax before sending. Detailed business decisions
remain server-owned. Destination strings for withdrawals, calculations and
refunds must have 10–128 Unicode letters, numbers, combining marks, underscores
or dashes; punctuation such as `:` and `.` is rejected. Pass memo/tag separately
where the operation supports it. Order IDs follow the same character rule, with
up to 128 characters, or 100 for refund/block/deposit-payment lookup operations.
Boolean parameters accept `true`, `false`, `0`, `1`, `"0"`, `"1"`; `"true"` and
`"false"` are rejected. For invoice creation `fee_deducted` is an integer
percentage, rather than a boolean.

`payments->retryCallback` uses the v1 callback endpoint; `retryCallbackV2` exposes
the existing v2 operation with the same payload and payment key. Invoice and
withdrawal creation accept nullable `simulated_success` / `simulated_failure`
flags using the boolean representations above; they are sent only when supplied.

Balances return `BalanceResult`: `business()` and `personal()` expose wallet
lists. `content()` preserves the actual server envelope, a list containing one
object: `content()[0]['balances']`. Wallet groups absent from an empty server
response are returned as empty lists by the convenience accessors.

```php
$payment = $client->payments->lookup(['merchant_order' => 'your-unique-order-id']);
$tiers = $client->withdrawals->priority('USDT', 'tron');
$transfer = $client->transfers->toPersonal('10.00000001', 'USDT');
$refund = $client->payments->refund([
    'merchant_order' => 'your-unique-order-id',
    'destination' => $customerAddress,
    'fee_deducted' => true,
]);
```

A create response confirms creation, not payment completion. Use the documented
status and `finished` field from lookup/webhooks to decide when payment is final.

## Pagination and retries

```php
$filters = ['created_from' => '2026-01-01 00:00:00'];
$page = $client->payments->search($filters);
$entries = $page->entries();
if ($page->nextCursor !== null && $page->nextCursor !== '') {
    $next = $client->payments->search($filters, $page->nextCursor);
}
```

A page exposes `itemCount`, `hasMore`, `hasPages`, `nextCursor`, `previousCursor`,
and `pageSize`. `hasMore` is derived from a nonempty `nextCursor`. The server's raw
`has_more` flag actually indicates multiple pages and can remain true on the last
page; it is preserved as `hasPages` and in `content()`. Follow `nextCursor` when
requesting the next page. Cursors are opaque query values, including on POST requests. No
operation automatically traverses pages, follows links or retries a request.
A timeout can mean that the server completed the operation. Reconcile its status
before retrying. For documented idempotent creates, reuse the exact same
`merchant_order`; do not assume transfers or refunds are idempotent.

For withdrawals, keep `merchant_order` unique across **all merchants belonging to
the same account owner**, including historical payouts associated with
other merchants of that owner. The current API looks up existing payouts by owner and order ID,
so two different merchants of that owner must not reuse it. Use a stable merchant
prefix in your application's order IDs (for example `shop-a-order-123` and
`shop-b-order-123`). The SDK sends your ID unchanged: it does not prefix it,
generate a replacement, or promise merchant-scoped withdrawal idempotency.
Invoice orders are merchant-scoped; deposit-address reuse also considers asset
and network.

For invoice creation, `refresh_existing` refreshes eligible cancelled API invoices.
It may keep the existing payment address when its underlying order can be reused;
a new address is not guaranteed. Read `destination` and `expires_on` from the
returned result rather than inferring them from the refresh flag.

## Request and webhook authentication

Protected API requests send three headers:

```text
merchant: <merchant UUID>
X-Timestamp: <current Unix time in seconds>
X-Signature: <lowercase hexadecimal HMAC-SHA256>
```

The signature is `hash_hmac('sha256', $timestamp . '.' . $body, $apiKey)`.
JSON is serialized once and the exact bytes are sent. Empty POST parameters are
`{}`; GET bodies are empty and query parameters are excluded from the signature.
A current timestamp is generated for each request. Keep your clock synchronized:
the API accepts an absolute time difference of at most 300 seconds. The API key
itself is never sent. The legacy `sign` header is not sent and there is no
fallback to legacy authentication.

The SDK selects the key per operation: refunds use payout, while
`depositAddresses->refundBlocked` uses payment. Public rates carry no
`merchant`, `X-Timestamp`, or `X-Signature` headers.

Incoming webhooks use the same timestamped HMAC-SHA256 construction:

```php
use SouthApi\Security\WebhookVerifier;

$verifier = new WebhookVerifier(new Credentials(
    paymentKey: $paymentKey,
    payoutKey: $payoutKey,
));
$event = $verifier->verify($rawBody, $timestampHeader, $signatureHeader);
// Durably enqueue the verified event, then return HTTP 200 from your handler.
```

Pass the original body and `X-Timestamp` / `X-Signature` headers. Verification
uses HMAC-SHA256 of `timestamp.body`, constant-time comparison and a 300-second
absolute clock tolerance. Keep the server clock synchronized. Payment/deposit use
the payment key; withdrawal/blocked_address_payment use the payout key. Missing
keys, unsigned events, unknown event kinds, malformed headers, stale signatures
and tampered data are rejected. Default maximum webhook size is 1 MiB.

Only timestamped HMAC-SHA256 webhooks are supported. Body-only signatures are rejected.

The verifier does not send HTTP responses, update orders or deduplicate events.
After verification, durably enqueue work and acknowledge with HTTP **200**.
Process duplicates idempotently using `payment_reference` or
`withdrawal_reference` and `payment_phase`, according to the event contract.
A timestamp window alone does not prevent duplicate processing. Each retry may
carry the current state and a fresh signature. Invalid requests must not be
acknowledged as successfully verified. See the inert functions in `examples/`.

## Errors, secrets, and networking

Catch `SouthApi\Exceptions\SdkException`, or its `ConfigurationException`,
`TransportException`, `ProtocolException`, `WebhookException`, and `ApiException`
subclasses. `ApiException` exposes safe `status` and `errorCode`; server messages,
validation details and raw transport exceptions are deliberately not included.
A transport failure does not determine whether a financial operation completed.

Keys stay in memory. The SDK does not read environment files, persist secrets,
emit telemetry, or log bodies/headers. Common debug output is redacted and
credential-bearing objects cannot be serialized. Success data remains sensitive.
PHP code, custom transports/middleware, reflection, exports and process dumps in
your application can access your memory; redaction is not a sandbox. Configure
`zend.exception_ignore_args=1` in production, keep debug pages off, and prevent
logging of sensitive input/output in your own application. Sensitive parameters
are also annotated for PHP exception traces.

The default cURL adapter bounds response writes during download and blocks internal
transport retries as well as application retries. Default HTTP behavior verifies TLS certificates/hostnames, forbids redirects,
disables debug output, and uses a 5-second connect / 30-second request timeout.
The response limit is 10 MiB, configurable explicitly. Changing the origin or
injecting `SouthApi\Http\Transport` is trusted application configuration; custom
implementations and middleware must preserve transport restrictions and keep data
private. Returned callback/checkout URLs and cursors are never fetched by the SDK.

This SDK follows the current merchant API implementation when it differs from
the supplied historical reference, including pagination and compatibility modes. Offline tests establish
local contract conformance; deployed interoperability must be verified separately.

## Development checks

From the development checkout (these tools and scripts are excluded from the
runtime ZIP):

```sh
composer install
composer check
composer audit
composer release
composer consumer
```

`composer test` runs PHPUnit; `composer analyse` runs PHPStan at level 8 over
source, tests, examples and PHP tools, without a baseline. Tests are offline and
use synthetic data. The release builder requires `ext-zip`, allowlists files,
scans them, and inspects the finished ZIP; the consumer check installs its actual
runtime contents into a temporary directory outside the checkout. Development
files and local integration notes are excluded. The runtime manifest in the ZIP
has development scripts/dependencies removed. Review the manifest and license
before merchant delivery; building an archive does not publish it.
