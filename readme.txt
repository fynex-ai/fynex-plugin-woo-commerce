=== Fynex for WooCommerce ===
Contributors: fynex
Tags: woocommerce, payments, payment gateway, hosted checkout, credit card
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Take card payments in WooCommerce through Fynex hosted checkout. Card details are entered on Fynex and never touch your store.

== Description ==

Fynex for WooCommerce adds Fynex as a payment method at checkout. The customer pays on a page hosted by Fynex, then comes back to your store, and the order is updated from Fynex's signed notifications.

= What you get =

* **Hosted checkout.** Card details are entered on Fynex, so they never reach WordPress or your server.
* **Classic and block checkout.** Works with the Checkout block and the classic `[woocommerce_checkout]` shortcode, and with the pay-for-order page.
* **Orders kept in sync.** Signed webhooks mark orders paid or failed. If a webhook is lost, the plugin asks Fynex directly when the customer returns and in the background, so a paid order does not stay pending.
* **Amount checks.** An order is marked paid only when Fynex reports the amount and currency the order was created with.
* **Refunds from the order screen.** Full and partial refunds are sent to Fynex and recorded straight away; Fynex's confirmation is matched to them.
* **Demo and live keys.** A test key (`sk_test_…`) takes demo payments and a live key (`sk_live_…`) real ones. The settings screen says which key is saved, and demo checkouts say "Test mode".
* **High-Performance Order Storage (HPOS)** supported, with or without compatibility mode.
* **Debug log** under WooCommerce → Status → Logs.

You need a Fynex seller account and an API key from the Fynex dashboard. Your store must be served over HTTPS so Fynex can deliver payment notifications.

= Documentation =

* [WooCommerce integration on fynex.ai](https://fynex.ai/integrations/woocommerce/): what the plugin does for your store
* [Hosted checkout guide](https://docs.fynex.ai/payments-api/v2/docs#tag/hosted-checkout): the API flow the plugin uses
* [Webhooks guide](https://docs.fynex.ai/payments-api/v2/docs#tag/webhooks): signing, retries and delivery
* [Captures & refunds](https://docs.fynex.ai/payments-api/v2/docs#tag/captures-refunds): how refunds are confirmed
* [Going live](https://docs.fynex.ai/payments-api/v2/docs#tag/going-live): the go-live checklist and live keys

== External services ==

This plugin connects to the Fynex Payments API (`https://api.fynex.ai`), operated by Fynex, to take payments. It contacts Fynex only after you enter a Fynex API key, and only for the actions below.

* **When a customer places an order with Fynex**, it creates a hosted checkout session. It sends the order amount and currency, a country code (the billing country, or the store's base country), a payment reference made of the order ID and a short hash of the store address, a description ("Order #123"), and the two addresses on your store the customer returns to. It does **not** send the customer's name, email address, phone number or postal address. The customer then enters their card details on the Fynex-hosted page; card data never passes through your store or this plugin.
* **When a customer returns, and periodically until the payment is final**, it reads that payment's status.
* **When you save the settings**, it registers or checks your store's webhook address so Fynex can send payment and refund updates.
* **When you refund an order from WooCommerce**, it sends the payment reference, the refund amount, and checks the refund's status until Fynex confirms it.

Fynex sends payment and refund updates to your store's webhook address (`/wp-json/fynex/v1/webhook`). Each delivery is signed and carries the payment status, amount and currency; it brings no new customer data into your store.

Fynex [Terms of Service](https://fynex.ai/legal/terms/) · [Privacy Policy](https://fynex.ai/legal/privacy-policy/)

== Installation ==

1. In WordPress, go to **Plugins → Add New**, search for "Fynex for WooCommerce" (or upload the zip), then install and activate it. WooCommerce must be active.
2. Go to **WooCommerce → Settings → Payments → Fynex**.
3. Paste your Fynex API key and save. The plugin registers your store's webhook address with Fynex and stores the signing secret it receives.
4. Tick **Enable Fynex payments** and save.
5. To go live, replace the test key with a live key from the Fynex dashboard and save again.

== Frequently Asked Questions ==

= How do I test without taking real money? =

Use a test key (`sk_test_…`) from the Fynex dashboard. The settings screen shows "Saved key: demo", checkouts say "Test mode", and you pay with Fynex's [test cards](https://docs.fynex.ai/payments-api/v2/docs#tag/test-cards).

= Why does saving the settings say Fynex needs an HTTPS address? =

Fynex only delivers payment notifications to a public HTTPS address. Serve the store over HTTPS and save again. A local development site on `http://localhost` cannot receive them.

= The settings say the signing secret is missing. What do I do? =

Fynex shows a webhook's signing secret only once, when it is created. If your store's webhook already exists in Fynex but its secret is not stored here (for example after reinstalling), saving the settings replaces the webhook with a new one and stores the new secret.

= How do refunds work? =

Refund from the order screen as usual. Once Fynex accepts the refund, WooCommerce records it straight away and Fynex's confirmation is matched to it, usually within minutes. If Fynex later reports the refund as failed, the order is flagged and a note names the WooCommerce refund to delete before you try again.

= An order was paid but is still pending. =

The plugin asks Fynex for the payment status when the customer comes back to your store and then every five minutes for an hour, so this resolves itself even if a notification was lost. If it does not, turn on **Debug log** in the settings and check WooCommerce → Status → Logs → fynex-for-woocommerce.

= Does the plugin store card data? =

No. Card details are entered on the Fynex-hosted page. Your store keeps only the payment reference, status, amounts and refund references in the order.

== Screenshots ==

1. Fynex in the Checkout block.
2. Fynex settings: API key with its demo or live mode, and the debug log option.
3. An order paid with Fynex and partly refunded from the order screen.

== Changelog ==

= 0.1.0 =
* Initial preview: hosted checkout gateway for classic and block checkout, HPOS, signed webhooks, refunds.

See changelog.txt for the full history.
