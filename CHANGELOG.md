# Changelog

All notable changes to the Fullmetrix PrestaShop connector are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/), and the
project adheres to semantic versioning where practical.

## 1.5.5

### Fixed

- Fixes an infinite redirect loop on customer login. The shutdown handlers that
  send tracking events, webhooks and checkout consents call
  `fastcgi_finish_request()` to release the client before any HTTP call. PHP runs
  shutdown functions before object destructors, and PrestaShop only persists its
  cookie from `Cookie::__destruct()`, where `write()` is a no-op once
  `headers_sent()` is true. On login, `Context::updateCustomer()` writes the
  cookie and *then* adds `session_id` / `session_token` through
  `registerSession()`, so those keys were still pending when the response was
  detached and never reached the browser. `Cookie::isSessionAlive()` then failed
  on the next request and the customer was sent back to the login page. The
  response is now detached only after the pending cookie has been written.
  Affects every PrestaShop from 1.7.6 to 9.x: `Cookie::registerSession()` landed
  in 1.7.6 and `Customer::isLogged()` has required `isSessionAlive()` ever since.
  1.7.4 and 1.7.5 are not affected. The regression was introduced in connector
  1.5.0, which added `fastcgi_finish_request()`; only PHP-FPM and LiteSpeed
  storefronts are hit, and only when the visitor already carries the tracker
  cookies that make a hook queue an event during the login request.

## 1.5.4

### Added

- Adds tax-inclusive displayed, regular and sale prices to products and combinations.
- Adds tax-inclusive pre-discount prices and tax amounts to order lines.
- Sends product and combination updates when combinations or specific prices change.

## 1.5.3

### Added

- Adds a `shop` object to streamed and webhook payloads for orders, refunds,
  customers, products, product variations, categories and coupons. The payload
  includes the PrestaShop shop id, shop group id, names, public URL and active
  status where available.
- Adds `customer_groups` to customer payloads and order payloads, including the
  default group id/name plus all assigned group ids/names.
- Adds coupon restriction metadata for customer groups and shops so future
  Fullmetrix features can map PrestaShop B2B and multi-shop rules without a
  historical resync.

### Notes

- This release intentionally keeps the new PrestaShop metadata in the raw sync
  JSON. It does not yet expose shop or customer-group fields in Fullmetrix
  dashboards, segmentation or filters.
- Native PrestaShop carts remain out of scope. Cart analytics continue to rely
  on Fullmetrix universal tracking.

## 1.5.0

A stability release focused on guaranteeing that the connector cannot crash
or noticeably slow down the storefront, regardless of the hosting setup
(PHP-FPM, Apache `mod_php`, CGI, LiteSpeed). Behaviourally compatible with
1.4.x — no configuration changes required.

### Storefront safety

- Every frontend hook is now wrapped in a top-level `try/catch (\Throwable)`.
  Swallowed exceptions are recorded in the admin **Logs** tab through a new
  `FullmetrixLogger::logException()` helper instead of failing silently.
  Hooks affected: `displayHeader`, `displayFooter`, `actionCartSave`,
  `actionAuthentication`, `actionValidateOrder`, `actionOrderStatusUpdate`,
  `actionCustomerAccountUpdate`, `actionObjectCustomerUpdateAfter`,
  `actionProductUpdate`, `actionProductAdd`, `actionUpdateQuantity`,
  `actionObjectCartRuleUpdateAfter`, `actionOrderSlipAdd`,
  `actionCategoryUpdate`.
- A central `FullmetrixConnector::isActive()` guard early-returns from every
  action hook when the plugin is disconnected. A disconnected plugin now
  performs zero work on storefront requests.
- `hookActionCartSave` is now suppressed during order validation
  (`$cart->orderExists()` check), so checkout no longer emits a spurious
  post-validation `cart_updated` event.
- `hookActionCartSave` calls `$cart->getOrderTotal(...)` inside a guarded
  block and falls back to summing line totals when the cart has no carrier
  or address (the typical guest-visitor case).
- `maybeRebuildCart` validates the HMAC signature, then walks each step in
  its own `try/catch` (item delete, item add, coupon apply). If headers
  have already been sent by another module, the function gracefully returns
  instead of attempting an invalid redirect.
- Every per-item branch in `hookActionCartSave` (`getImageLink`,
  `getProductLink`, `nbProducts`, `getCartRules`, `buildCartRecoveryUrl`)
  is individually guarded so that a single broken product cannot prevent
  the rest of the cart snapshot from being captured.

### Non-blocking HTTP

- All outbound HTTP traffic now goes through cURL with explicit millisecond
  timeouts (`CURLOPT_CONNECTTIMEOUT_MS`, `CURLOPT_TIMEOUT_MS`) and
  `CURLOPT_NOSIGNAL=1`, so a DNS hang or slow upstream cannot freeze a
  worker. The previous `file_get_contents` + `stream_context_create` path
  has been removed.
- Timeouts adapt to the SAPI:
  - **PHP-FPM** (response already flushed via `fastcgi_finish_request`):
    1.5–3s — comfortable margin, hidden from the customer.
  - **Apache `mod_php` / CGI** (response not yet sent): 200–800ms — strict
    cap, capped at the request level even when the upstream is slow.
- `FullmetrixWebhookSender::finishResponse()` centralises the call to
  `fastcgi_finish_request()` + `ignore_user_abort(true)`. Calling it is
  idempotent across shutdown handlers via a shared static flag — the
  function is now invoked at most once per request.
- The connector class no longer registers a fresh shutdown handler for
  every checkout consent. A static `$pendingConsents` queue is filled from
  `forwardCheckoutConsent`, and a single `flushPendingConsents()` shutdown
  drains it. Bulk-validation scripts that process several orders in one
  request now send one batch of consent POSTs instead of N serialised
  ones.
- The plugin config endpoint (`/api/plugin/config`) is fetched at most
  once per request. A static memoisation flag prevents the header and
  footer hooks from triggering the same cURL twice in a single page
  render. The on-disk cache TTL remains 30 minutes and falls back to the
  stale entry if the upstream is unreachable, without writing to the
  database on failure.

### Tracking events

- `FullmetrixTrackingSender::enqueueEvent()` deduplicates `cart_updated`
  events within a single request. PrestaShop fires `actionCartSave` on
  every quantity change, carrier change, and automatic cart-rule
  application, which could previously enqueue 5–10 identical snapshots of
  the same cart. Only the latest snapshot is now kept.
- Cookie values (`fm_vid`, `fm_sid`, `fm_cid`) are validated with a strict
  regex and length cap instead of `pSQL()`, so names containing
  apostrophes (e.g. `O'Brien`) are no longer SQL-escaped on their way out
  to the analytics backend.
- Visitor / session identifiers are accepted only if they match
  `^[a-zA-Z0-9_\-]{1,64}$`. The raw `fm_cid` cookie payload is hard-capped
  at 8 KB; each string field within it is capped at 255 characters.
- `getCurrentUrl()` no longer reads attacker-controllable `$_SERVER['HTTP_HOST']`.
  It uses `Tools::getShopDomainSsl()` + `Tools::usingSecureMode()` through a
  shared `FullmetrixConnector::buildPublicUrl()` helper.

### Webhooks

- The webhook queue captures the current shop id (`Context::getContext()->shop->id`)
  per entry instead of relying on the shop id that was current when the
  module was first instantiated. In multi-shop installations this prevents
  a webhook from being emitted with the wrong shop scope when context
  switches within a request. Stream exporters are instantiated once per
  shop id seen in the queue.
- The redundant `register_shutdown_function` call inside `enqueue()` was
  removed; `init()` registers it once. Combined with the queue's natural
  deduplication, this eliminates a narrow race that could send the same
  webhook twice.
- The webhook flush handler runs entirely inside a guarded loop. A failure
  while formatting one entity is logged via `FullmetrixLogger::logException`
  but does not interrupt the remaining entities.
- The checkout-consent POST is now signed with the same HMAC scheme as the
  other plugin endpoints (`X-Fullmetrix-Connection-Code`,
  `X-Fullmetrix-Signature`, `X-Fullmetrix-Timestamp`). Forward-compatible:
  servers that ignore the headers continue to honour the `key` field in
  the body.

### Performance

- `FullmetrixConnector::getConfig()` memoises `Configuration::get()` reads
  for the duration of a request. Frequently-read keys
  (`FULLMETRIX_CONNECTION_CODE`, `FULLMETRIX_CONNECTION_SECRET`,
  `FULLMETRIX_REGISTERED`) are now read from the database at most once
  per request.
- `FullmetrixConnector::clearConfigCache()` is invoked after admin
  Connect / Disconnect actions so subsequent reads in the same request
  see the new state immediately.
- `FULLMETRIX_PLUGIN_CONFIG` is now cleared on disconnect and on uninstall
  so reconnecting against a different Fullmetrix account does not serve a
  stale `checkoutConsent` block for up to 30 minutes.

### Defensive checks

- All `curl_init()` calls now check the return value; the plugin gracefully
  no-ops if libcurl is broken or disabled on the host.
- All `curl_setopt_array` blocks set `CURLOPT_FOLLOWLOCATION = false`.
- Hosts without `curl_init` defined are detected and the relevant
  outbound paths short-circuit cleanly.

### Admin

- The Logs tab now correctly initialises `$rawLogs` before iterating —
  fixes a fatal `TypeError` on PHP 8 (`foreach over null`).
- The "Clear Logs" button now actually clears the log entries
  (`FullmetrixLogger::clear()`); previously it only rendered the
  confirmation banner without resetting the underlying configuration row.
- `FullmetrixLogger::logException($context, $e)` records a structured
  entry (`type=sync_error`, `message=hook_exception`, `details={context, error, file:line}`)
  whenever a top-level catch block swallows a `\Throwable`.

### Compatibility

- `ps_versions_compliancy.min` was bumped from `1.7.0.0` to `1.7.4.0`. The
  codebase relies on language features (`\Throwable`, `random_bytes`) that
  require PHP 7.1+, which PrestaShop 1.7.4.0 enforces. Stores running PS
  1.7.0–1.7.3 with PHP 5.6 will no longer accept the install rather than
  crash on a parse error after install.
- All four version markers (`FULLMETRIX_VERSION`, `$this->version`,
  `config.xml`, `manifest.json`) are kept in sync by `scripts/build-plugins.sh`.
- The PrestaShop validator's findings have been addressed:
  - PHPDoc type ordering (`null` placed last) per `phpdoc_types_order`.
  - Blank line before `return` per `blank_line_before_statement` for the
    new methods.
  - Removed inline HTML output from `maybeRebuildCart` (PHP code no longer
    contains `echo` of HTML).
  - Removed the `litespeed_finish_request` fallback that the validator's
    static analyser flagged as an unknown symbol.
  - Tightened isset checks where the array shape already guarantees the key.

### Files added

- `CHANGELOG.md` (this file).

### Files modified

- `fullmetrixconnector.php`
- `classes/FullmetrixWebhookSender.php`
- `classes/FullmetrixTrackingSender.php`
- `classes/FullmetrixLogger.php` (added `logException`)
- `config.xml`
- `manifest.json`

### Known limitations

- Native PrestaShop multi-shop (several shops sharing one install) is
  technically supported (each webhook now carries its own `shop_id`), but
  the connector is designed primarily for a one-install / one-shop setup.
  Multiple PrestaShop installations are independent and each requires its
  own connection code, which is the standard pattern.
- Apache `mod_php` users may observe a small additional latency on order
  validation (≤ 800ms in the worst case where the analytics endpoint is
  unreachable) because `fastcgi_finish_request` is not available outside
  PHP-FPM. On PHP-FPM no measurable latency is added.
- Product features (`product_feature`) and suppliers (`supplier`) are not
  yet extracted by the connector. They are planned for a future release.
- Stream exporters do not currently filter all queries by `id_shop`. In
  native multi-shop installations this can mix data from sibling shops in
  the initial sync. Webhook deltas are correctly scoped.

## 1.4.x and earlier

Earlier releases focused on feature parity with the WooCommerce connector
(initial sync, paginated and streaming exporters, signed webhooks for
orders, customers, products, categories, coupons, refunds, gift coupons
with combination attribute support, cart recovery via signed URL).
