# Fynex for WooCommerce

Official Fynex hosted-checkout payment gateway for WooCommerce.

## What it does

- Redirects customers to Fynex hosted checkout; card data never reaches WordPress.
- Keeps WooCommerce order state in sync using signed Fynex webhooks.
- Supports Classic and Checkout Block payment flows.
- Supports High-Performance Order Storage (HPOS).

## Requirements

- WordPress 6.9+
- PHP 7.4+
- WooCommerce 10.9+ (tested through 11.2)
- A Fynex seller API token and an HTTPS-accessible store URL.

## Install

1. Download `fynex-for-woocommerce.zip` from the release.
2. In WordPress, open **Plugins → Add New → Upload Plugin**, choose the zip, install, and activate it.
3. Open **WooCommerce → Settings → Payments → Fynex → Manage**.
4. Paste the Fynex seller API token and save. The plugin registers
   `https://your-store.example/wp-json/fynex/v1/webhook` and stores the signing
   secret returned by Fynex.
5. Enable Fynex and run a demo checkout.

The plugin always calls `https://api.fynex.ai`; demo/live behavior is selected
by the Fynex token, not by a plugin environment switch.

## Important operational notes

- Fynex validates return URLs against the seller's allowed return hosts. Add the
  store's HTTPS host to that Fynex configuration before going live.
- The webhook receiver accepts a delivery only when its HMAC-SHA256 signature
  and five-minute timestamp window are valid. It returns **exactly HTTP 200**
  for accepted or duplicate events.
- If the callback already exists in Fynex but this installation lacks its
  one-time signing secret, paste the original secret or rotate/reconnect the
  callback in Fynex before enabling the gateway.
- Full and partial refunds submitted from WooCommerce may remain pending until
  Fynex confirms them. The plugin creates the WooCommerce refund only after that
  confirmation; pending refunds do not automatically restock inventory.

## Scope of v0.1

Included: hosted checkout redirect, Classic/Blocks checkout, signed payment
webhooks with per-attempt matching and validation, and reconciled full/partial refunds.

Not included: manual capture, saved cards, subscriptions, marketplace split
payments, custom update service, or WordPress.org distribution.

## Development

```sh
./bin/test-docker
./bin/package
```

`bin/test-docker` runs PHP syntax checks and webhook-verifier tests inside the
PHP 8.3 Docker image. `bin/package` emits `dist/fynex-for-woocommerce.zip` with
only runtime files.
