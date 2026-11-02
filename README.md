# SouthAPI PHP merchant SDK

Standalone PHP 8.2+ client for the approved Astra Merchant API v1 contract.
All requests use `/api/v1`. This release changes method arguments, payloads,
authentication and response shapes; existing integrations must migrate together
with the API. Old API routes and legacy webhook formats are not supported.

## Install through Composer

Requirements: PHP 8.2+, PHP extensions `curl` and `json`, and Composer.
`unzip` is a separate operating-system utility used to unpack the supplied archive.
The package is proprietary; its Composer name is `southapi/merchant-sdk`.
Public registry availability is not implied.

In your application's directory, extract the supplied ZIP **outside the public
web directory**:

```sh
mkdir -p packages/south-api-sdk
unzip /path/to/south-api-sdk.zip -d packages/south-api-sdk
composer config repositories.southapi path ./packages/south-api-sdk
composer require southapi/merchant-sdk:@dev
```

Keep `packages/south-api-sdk` available during subsequent Composer installs and
deployments. Commit your application's Composer manifest and lock file according
to your deployment policy. To install a replacement archive, replace the package
contents and run `composer update southapi/merchant-sdk`. Test your integration
before deploying contract changes.

Load your application's `vendor/autoload.php`. Supply values from your application's
secret store or server environment; these variable names are examples, not SDK requirements:

```php
require __DIR__ . '/vendor/autoload.php';

use SouthApi\Client;
use SouthApi\Configuration;
use SouthApi\Security\Credentials;

$merchantId = getenv('SOUTHAPI_MERCHANT_ID');
$paymentKey = getenv('SOUTHAPI_PAYMENT_KEY');
$baseUrl = getenv('SOUTHAPI_BASE_URL');
$payoutKey = getenv('SOUTHAPI_PAYOUT_KEY');
if (!$merchantId || !$paymentKey || !$baseUrl) {
    throw new RuntimeException('Missing API configuration.');
}
$client = new Client(
    new Credentials($merchantId, $paymentKey, $payoutKey ?: null),
    new Configuration(baseUrl: $baseUrl),
);
```

`SOUTHAPI_BASE_URL` is the trusted HTTPS origin issued to you, **without `/api/v1`
or another path**. The SDK adds the prefix. Configure variables in your deployment
secret store, service configuration or application's environment loader. The SDK
does not read `.env` automatically. Never place keys in browser code, URLs, version
control or publicly accessible files. Payment-only integrations can omit the payout
key. Public rates do not need credentials. If no configuration is supplied, the
origin defaults to `https://southapi.com`; use your assigned host explicitly.

## Operations

Existing PHP resource names remain, while their HTTP routes use the new names.
`ref` below means the corresponding resource UUID; order means `merchant_order`.
All array bodies use the documented **nested** fields; no flattening or conversion
from previous request shapes takes place.

| Resource | Methods | HTTP resource |
|---|---|---|
| `payments` | `create(body)`, `search(filters)`, `lookup(ref)`, `lookupByOrder(order)`, `qrCode(ref)`, `retryCallback(ref)`, `retryCallbackByTransaction(hash)`, `markPaid(ref)`, `refund(ref, body)`, `options()` | charges, charge-options |
| `depositAddresses` | `create(body)`, `search(filters)`, `lookup(ref)`, `lookupByOrder(order)`, `qrCode(ref)`, `payments(ref, filters)`, `paymentsByOrder(order, filters)`, `block(ref, forceRefund)`, `blockByOrder(order, forceRefund)`, `refundBlocked(ref, destination)`, `refundBlockedByOrder(order, destination)` | receiving-addresses |
| `withdrawals` | `create(body)`, `calculate(body)`, `search(filters)`, `lookup(ref)`, `lookupByOrder(order)`, `cancel(ref)`, `cancelByOrder(order)`, `options()`, `priority(asset, chain)` | cashouts, cashout-options |
| `bills` | `create(body)`, `search(filters)`, `lookup(ref)`, `pdf(ref)`, `deliver(ref, body)`, `retryDelivery(ref, body)`, `disable(ref)`, `extend(ref)` | bills |
| `balances` | `get()` | funds |
| `transactions` | `search(filters)` | ledger |
| `transfers` | `toPersonal(amount, asset)`, `toBusiness(amount, asset)` | fund-transfers |
| `settings` | `paymentAssets()`, `discounts()`, `setDiscount(asset, chain, rate)` | settings/charge-assets, charge-discounts |
| `rates` | `all()`, `get(asset)`, `xml()` | fx-rates |

Lists and lookups use GET, settings replacement uses PUT, creation and actions
use POST. Reference and order path segments are URL-encoded. Refund and mark-paid
require the payment UUID: look up the payment by order first if necessary.
Receiving-address QR is available by UUID only. Cancellation can return 404 when
not available to your account; the SDK does not bypass API permissions.

```php
$payment = $client->payments->create([
    'merchant_order' => 'shop-order-1042',
    'pricing' => ['display_total' => '25.00000001', 'asset_code' => 'USD'],
    'settlement' => ['chain_code' => 'TRON'],
    'checkout' => ['partial_allowed' => false],
    'links' => ['callback_link' => 'https://merchant.example/webhooks/southapi'],
]);
$content = $payment->arrayContent();
$lookup = $client->payments->lookupByOrder('shop-order-1042');

$withdrawal = $client->withdrawals->create([
    'merchant_order' => 'shop-payout-501',
    'amount' => ['display_total' => '10.00', 'asset_code' => 'USDT'],
    'chain' => ['chain_code' => 'TRON', 'destination' => $customerAddress],
    'fee_deducted' => true,
]);
$refund = $client->payments->refund($paymentReference, [
    'destination' => $customerAddress,
    'fee_deducted' => true,
    'refund' => ['display_total' => '5.00'],
]);
```

Amounts and rates are decimal strings, never floats. Flags are PHP booleans.
Payment `pricing.fee_deducted` is an integer percentage (0–100); payout and refund
`fee_deducted` are booleans. Payout orders have 1–100 characters, payment orders
1–128, using letters, numbers, combining marks, underscores or dashes.
Destinations have 10–128 characters with the same character rule; pass memo
separately where supported. Priority values come from `withdrawals->priority()`;
the SDK sends the chosen value unchanged. Quote payloads exclude `merchant_order`,
`links`, `simulation`, `chain.memo` and `amount.source_asset`.
The SDK checks basic input shapes; the API remains responsible for business
validation, account permissions and supported assets.

## Responses, pagination and retries

JSON operations return `Result`. `content()` preserves the API content as an
array or `null`; `arrayContent()` requires an array and throws for null. HTTP 200
and 201 are supported. Nested amounts, dates, nullable fields and unknown response
fields are preserved without numeric or date conversion. Actions without a result
can return `content: null`. Creation is not proof of payment completion: use the
returned phase and `finished` fields.

Balances expose `business()` and `personal()` lists; their raw content is directly
`['business' => [...], 'personal' => [...]]`. Transfers return business/personal
objects. `bills->pdf()` returns the exact PDF bytes, without base64 or a JSON
envelope; the SDK requires an `application/pdf` response media type and does not
choose filenames or write files. Custom transports must pass the server media type
as the third argument (`contentType`) of `SouthApi\Http\Response`. `rates->xml()` returns
raw XML without parsing entities.

```php
$filters = ['created_from' => '2026-11-01', 'page_size' => 20];
$page = $client->payments->search($filters);
$entries = $page->entries();
if ($page->hasMore && $page->nextCursor !== null && $page->nextCursor !== '') {
    $next = $client->payments->search($filters + ['cursor' => $page->nextCursor]);
}
```

`Page` exposes `itemCount`, `pageSize`, `hasMore`, `nextCursor`, `previousCursor`
and `entries()`. `hasMore` is the server's `has_more` flag. Filters and opaque
cursors are query parameters. `page_size` is 1–100 (server default 15); dates are
`YYYY-MM-DD` or ISO 8601. Payment and payout lists additionally accept
`merchant_order`; bills accept `phase`, `recipient`, `query`, `expires_from`,
`expires_to`. No request automatically follows pagination or retries.

A timeout leaves the operation outcome unknown. Reconcile by reference/order
before retrying a financial request. Duplicate orders or invalid state transitions
can return 409 `conflict`. Do not assume a retry will return an existing resource.

## Billing

```php
$bill = $client->bills->create([
    'subject' => 'Consulting',
    'category' => 'amount',
    'pricing' => ['asset_code' => 'USD', 'pre_tax_total' => '100.00',
        'tax_total' => '0', 'fee_deducted' => false],
    'contacts' => ['contact_address' => 'buyer@example.com'],
]);
// Creation content contains billing_record, merchant_details_complete, warning.
$pdfBytes = $client->bills->pdf($invoiceReference);
$delivery = $client->bills->deliver($invoiceReference, [
    'contacts' => ['contact_address' => 'buyer@example.com'],
    'use_merchant_contact' => false,
]);
```

## Request authentication

Protected requests send `X-Merchant`, `X-Timestamp` and `X-Signature`.
The signature is lowercase hexadecimal HMAC-SHA256:

```text
canonical = timestamp + "." + METHOD + "." + path_with_query + "." + raw_body
signature = HMAC-SHA256(canonical, api_key)
```

The method is uppercase; the path starts `/api/v1` and includes the exact encoded
query as sent. GET and bodyless actions use empty bytes. JSON is encoded once and
those same bytes are signed and sent. Requests with bodies use
`Content-Type: application/json; charset=utf-8`. Keep clocks synchronized; the
timestamp tolerance is 300 seconds. Keys are never sent as headers or body fields.

Payment key: payments except refunds, receiving addresses (including refund-blocked),
Billing, balances, ledger and settings. Payout key: payment refunds, cashouts and
fund transfers. Public rates send no credentials. There is no legacy fallback.

## Webhook verification

Webhook signatures use **`timestamp.body`**, without method or path. Pass the
original raw bytes and `X-Timestamp` / `X-Signature` headers:

```php
use SouthApi\Security\WebhookVerifier;
$verifier = new WebhookVerifier(new Credentials(
    paymentKey: $paymentKey,
    payoutKey: $payoutKey,
));
$event = $verifier->verify($rawBody, $timestampHeader, $signatureHeader)->arrayContent();
// $event is ['event_kind' => ..., 'data' => [...]].
// Durably enqueue it, then acknowledge with HTTP 200 in your handler.
```

`payment` and `deposit` use the payment key; `withdrawal` and `blocked_deposit`
use the payout key. The existing webhook key assignment is retained; the approved
contract changes the blocked event name and payload, without defining a new key
assignment. The verifier never tries both keys. It checks the nested envelope,
constant-time signature equality, timestamp tolerance (300 seconds) and body size
(default 1 MiB). Unknown kinds, old flat payloads, missing keys, body-only
signatures and malformed/stale signatures are rejected.

Verification does not update orders, send HTTP responses or deduplicate events.
Use references and phases inside `data` to make processing idempotent. Retries can
carry updated state and fresh signatures. A valid timestamp does not prevent
repeated processing within the time window.

## Errors, secrets and networking

Catch `SouthApi\Exceptions\SdkException`, or its `ConfigurationException`,
`TransportException`, `ProtocolException`, `WebhookException`, `ApiException`
subclasses. `ApiException` exposes safe `status` and `errorCode`, including
`bad_request`, `method_not_allowed`, `conflict`, `rate_limited`. Server messages,
validation details and raw transport exceptions are not exposed in exceptions.

The SDK does not persist secrets, log headers/bodies or emit telemetry. Common
debug output is redacted; credential-bearing objects cannot be serialized.
Success results are sensitive merchant data. Your application, custom transport,
reflection and process dumps can still access memory: redaction is not isolation.
Use `zend.exception_ignore_args=1` in production, disable debug pages and prevent
sensitive application logging.

The default cURL transport verifies TLS certificates and hostnames, forbids
redirects and disables automatic retries and debugging. Defaults: 5-second
connect timeout, 30-second request timeout, 10 MiB response limit. PDF uses the
same limit, configurable through `Configuration`. Origins and custom transports
are trusted application configuration, never user input. Returned URLs and
cursors are not fetched automatically.

## Development checks

In the development checkout (excluded from the runtime ZIP):

```sh
composer install
composer check
composer audit
XDEBUG_MODE=coverage composer infection
composer release
composer consumer
```

PHPUnit tests run offline using synthetic fixtures. PHPStan checks source, tests,
examples and tools at level 8 without a baseline. Release tooling requires
`ext-zip`, scans allowlisted files and inspects the ZIP. The consumer check installs
the archive in a clean directory using runtime dependencies only. Internal notes,
source contracts, tests, tools, credentials and parent project files are excluded.
These checks validate the documented contract; deployed API interoperability must
be checked when the matching server release is available.

`composer infection` runs mutation testing over all `src/` files with the default
mutators, including uncovered code, and counts timeouts as undetected mutations.
It requires PCOV or Xdebug coverage support. Local HTML, JSON and text reports
are written to `.infection/` and excluded from distribution. No minimum mutation
score is configured: completion alone does not mean all mutations were detected.
