# Testing Fynex for WooCommerce

Instructions for reviewers and for anyone checking a release by hand. Demo store
credentials are sent separately and are never stored in this repository.

## What you need

- A WooCommerce store served over **HTTPS** (Fynex delivers webhooks only to public
  HTTPS addresses).
- A Fynex **test key** (`sk_test_…`) from the Fynex dashboard → Integration → API keys.
  A test key takes demo payments; no real money moves.
- Fynex test card numbers: <https://docs.fynex.ai/payments-api/v2/docs#tag/test-cards>.

## Set up

1. Install and activate the plugin (WooCommerce must be active).
2. WooCommerce → Settings → Payments → Fynex: paste the test key and save.
   Expect "Fynex webhook registered and signing secret saved." and
   "Saved key: demo. No real money moves." under the key field.
3. Tick **Enable Fynex payments**, tick **Debug log**, save.

## Happy path

1. Add a product to the cart and open the checkout (block or classic). Fynex is
   listed, with "Test mode: no real payment is taken." in its description.
2. Place the order. The browser goes to the Fynex-hosted page.
3. Pay with a test card that succeeds.
4. You land on the order-received page; the order is **Processing** and the cart is
   empty. The order notes say "Fynex confirmed the payment."
5. On the order screen, click **Refund**, enter part of the amount, click
   **Refund … via Fynex**. The page reloads with the refund line and no error; the
   note says "Fynex accepted a refund …".
6. Within minutes a second note says "Fynex confirmed refund …". There is still
   exactly one refund line.

## Failure and retry

1. Place another order and press **Cancel** (or Back) on the Fynex-hosted page.
2. Pressing Back leaves the basket intact. Cancel lands on the pay-for-order page,
   where Fynex can be chosen again.
3. Pay with a test card that is declined: the order becomes **Failed**. Paying again
   from the pay-for-order page with a good card completes it.

## Storage modes

The release suite runs on High-Performance Order Storage with compatibility mode
(sync) **off**, and on post storage. To check by hand: WooCommerce → Settings →
Advanced → Features → Order data storage → "High-performance order storage", with
"Enable compatibility mode" unticked, then repeat the happy path.

## Logs

WooCommerce → Status → Logs → source `fynex-for-woocommerce`. Rejected webhooks and
API errors are logged even with Debug log off.

## Security notes for reviewers

**The webhook route is open by design.** `POST /wp-json/fynex/v1/webhook` is
registered with `permission_callback => '__return_true'` because Fynex calls it
server to server with no WordPress session. It is authenticated by its signature
instead, before anything else happens:

- `X-Fynex-Signature: sha256=<hex>` is HMAC-SHA256 over `timestamp + "." + raw body`,
  keyed with the signing secret Fynex returned when the webhook was registered.
- `X-Fynex-Timestamp` must be within 300 seconds of the server clock.
- The comparison is constant-time (`hash_equals`), and the body is not parsed until the
  signature verifies (`Fynex_WC_Webhook_Verifier`).
- Each order remembers the last 50 event IDs, so a replayed delivery is a no-op.
- The signing secret and API key are stored as autoload-off options and are never
  logged or printed back into the settings form.

Other notes:

- The only direct database writes are the lease lock in `Fynex_WC_Lock` (an atomic
  compare-and-swap on a single option row that `update_option()` cannot express) and
  the prefix delete in `uninstall.php`. Both are prepared or use `$wpdb->update/delete`.
- No card data reaches the store. The checkout request carries amount, currency,
  country, payment reference, description and return URLs only.
