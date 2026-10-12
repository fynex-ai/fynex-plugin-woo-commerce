# Fynex for WooCommerce

Take card payments in WooCommerce through Fynex hosted checkout. Card details are
entered on Fynex and never touch the store.

- Product page: <https://fynex.ai/integrations/woocommerce/>
- WordPress.org readme (features, FAQ, data disclosure): [`readme.txt`](readme.txt)
- Changes: [`changelog.txt`](changelog.txt)

## What you get

- **Hosted checkout** for the Checkout block, the classic checkout and the pay-for-order page.
- **Orders kept in sync** by signed webhooks, with a fallback that asks Fynex directly when the
  customer returns and every five minutes for an hour, so a lost webhook cannot leave a paid
  order pending.
- **Amount checks**: an order is marked paid only for the amount and currency it was created with.
- **Refunds from the order screen**, recorded at once and confirmed against Fynex.
- **Demo and live keys** (`sk_test_…` / `sk_live_…`), with the saved key's mode shown in the settings.
- **HPOS** with or without compatibility mode, **translations**, and a **debug log**.

## Requirements

- WordPress 6.9+ (tested up to 7.1)
- WooCommerce 10.9+ (tested up to 11.2)
- PHP 7.4+
- A Fynex seller account and API key, and a store served over HTTPS

## Install

1. Download `fynex-for-woocommerce.zip` from the latest release.
2. **Plugins → Add New → Upload Plugin**, choose the zip, install and activate.
3. **WooCommerce → Settings → Payments → Fynex**: paste the API key and save. The plugin
   registers `https://your-store.example/wp-json/fynex/v1/webhook` and stores the signing
   secret Fynex returns.
4. Enable Fynex and run a checkout with a test key (see [docs/TESTING.md](docs/TESTING.md)).
5. To go live, replace the test key with a live key and save again. If Fynex has restricted your account's
   return addresses, ask Fynex support to add the store's domain; by default any address is accepted.

## Documentation

- [WooCommerce integration on fynex.ai](https://fynex.ai/integrations/woocommerce/): what the plugin does for your store
- [Hosted checkout guide](https://docs.fynex.ai/payments-api/v2/docs#tag/hosted-checkout): the API flow the plugin uses
- [Webhooks guide](https://docs.fynex.ai/payments-api/v2/docs#tag/webhooks): signing, retries and the 200 contract
- [Captures & refunds](https://docs.fynex.ai/payments-api/v2/docs#tag/captures-refunds): how refunds are confirmed
- [Going live](https://docs.fynex.ai/payments-api/v2/docs#tag/going-live): the go-live checklist and live keys

## How a payment moves

1. The customer chooses Fynex and places the order. The plugin creates a hosted checkout
   session (`POST /checkout`) with a payment reference `wc-<site hash>-<order ID>-<attempt>`
   and sends the customer to Fynex. The basket is kept until the payment completes.
2. The customer pays on Fynex and returns to the order-received page. If the webhook has not
   arrived yet, the plugin reads the payment (`GET /payments/{ref}`) before the page renders.
3. Fynex sends `PaymentCompleted`. The plugin verifies the signature, checks the amount and
   currency against the attempt, and marks the order paid, or failed for a failed or cancelled
   attempt. A background check repeats the status read every five minutes for an hour in case
   every webhook delivery was lost.
4. A cancelled customer lands on the pay-for-order page and can try again; each new attempt
   gets a new payment reference.

## Refunds

Refunds made from the order screen are sent to Fynex (`POST /payments/{ref}/refund`). Once Fynex
accepts one, WooCommerce records it immediately and tags it with the Fynex refund ID. The
`PaymentRefunded` webhook, or a status read, confirms it. If Fynex later reports the refund as
failed, the order is flagged and a note names the WooCommerce refund to delete before you try
again. It is not deleted automatically, because that would not undo a restock or a status
change WooCommerce already made.

## Privacy

The checkout request carries the order amount, currency, country code, payment reference, a
description ("Order #123") and the store URLs the customer returns to. It does not carry the
customer's name, email, phone or address, and card data never passes through the store.
Webhooks are inbound, signed, and carry payment and refund status only. Details are in
[`readme.txt`](readme.txt) under *External services*; Fynex's
[privacy policy](https://fynex.ai/legal/privacy-policy/).

## Development

```sh
./bin/test-docker                       # PHP syntax + standalone load, in Docker
composer install
vendor/bin/phpcs                        # WooCommerce coding standards, PHP 7.4+ compatibility
./bin/install-test-env 7.1.3 11.2.0     # WordPress + WooCommerce for the suite (needs MySQL)
vendor/bin/phpunit                      # FYNEX_TEST_HPOS=1 runs it on HPOS with sync off
./bin/package                           # dist/fynex-for-woocommerce.zip, runtime files only
```

Point a test store at a non-production API with `define( 'FYNEX_WC_API_BASE', 'https://…/payments-api/v1' );`
in `wp-config.php`. Releasing: [docs/RELEASING.md](docs/RELEASING.md).
