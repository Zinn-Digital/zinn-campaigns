=== Zinn® Campaigns – Email & SMS Marketing for WooCommerce & More ===
Contributors: zinndigital
Plugin URI: https://zinndigital.com/wordpress-plugins/zinn-campaigns
Author: Neil Lock — CEO, Zinn Digital® Ltd
Author URI: https://zinndigital.com
Tags: email marketing, sms, whatsapp, newsletter, woocommerce
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your site to Zinn® Campaigns for email, SMS and WhatsApp marketing, with double opt-in sign-up forms.

== Description ==

**Zinn® Campaigns sends your email, SMS and WhatsApp campaigns. This plugin connects your WordPress site or WooCommerce store to your Zinn® Campaigns account and puts its sign-up forms on your pages.**

= What it does =

* **Connects in one click.** Press Connect, sign in to Zinn® (or create an account), approve the site, and you come straight back. You never paste a key, and the site's token is kept out of sight.
* **Disconnects from either side.** Press Disconnect here, or remove the site in your Zinn® dashboard.
* **Double opt-in sign-up forms on any page.** Add the "Zinn® Campaigns sign-up form" block (in the Zinn Digital® category of the block inserter) or the `[zinn_campaigns_form id="…"]` shortcode. The form shows your Zinn® Campaigns form's own title, text and consent sentence, with an unticked box the visitor must tick. Zinn® Campaigns then sends one confirmation e-mail, and only people who click it join your list.
* **Spam protection without a cookie or a captcha.** A hidden field and a signed, time-limited form token stop most bots, and forms keep working on cached pages.
* **Shows what your plan includes.** The Zinn® Campaigns screen shows whether your account includes Pro. You get Pro free with any paid Zinn® Campaigns plan; Pro is a separate add-on you download from your Zinn® dashboard.
* **Abandoned-cart, browse and recovery flows for WooCommerce.** The checkout shows an unticked box: "Email me offers and reminders, including about items left in my cart." Only a shopper who ticks it is sent to Zinn® Campaigns for a reminder. A cart left unpaid for an hour is reported once (Zinn® Campaigns then sends the reminders on the schedule you set there), a reminder link brings the shopper back to a refilled cart, and an order ends the reminders.
* **Works with multilingual sites.** The site's languages are read from Tranzly, WPML or Polylang (or the site language) and reported to Zinn® Campaigns.

= Source code =

The complete source is public at https://github.com/Zinn-Digital/zinn-campaigns

== Installation ==

1. Install and activate the plugin.
2. Open Zinn® Campaigns in the WordPress admin menu and press Connect.
3. Approve the site in Zinn®. You come back to WordPress connected.
4. Create a sign-up form in your Zinn® dashboard (Campaigns, Forms), then add the block or the shortcode to a page.

== External services ==

This plugin connects to the Zinn® API at `https://api.zinndigital.com`, run by Zinn Digital® Ltd. Terms: https://zinndigital.com/legal/terms — Privacy: https://zinndigital.com/legal/privacy

It sends nothing until you press Connect. Then:

* **Connect** (your browser opens `https://app.zinndigital.com/connect/store`, once, when you press it): the site address, the address of this plugin's admin page to come back to, your admin language, the plugin's name, a random value that ties the answer to this browser, and a one-way hash of a secret the plugin keeps (PKCE). You sign in and approve there.
* **Token** (`POST /v1/store-connect/token`, once, when you come back): the one-time code from Zinn®, the secret behind the hash, the site address, a random id the plugin made for this install, the plugin, WordPress, WooCommerce and PHP versions, whether the site is a multisite, which supported plugins are active, and the site's languages (at most 40 characters of language codes).
* **Status** (`GET /v1/store-connect/status`, at most every five minutes while you have the Zinn® Campaigns screen open): the plugin and WordPress versions, which supported plugins are active, and the site's languages. The answer is the connection's state.
* **Plan** (`GET /v1/store-connect/campaigns`, at most every ten minutes while the site is connected and the Zinn® Campaigns screen is open): nothing but the site's token. The answer is whether the account includes Pro.
* **Forms** (`GET /v1/store-connect/forms`, at most every ten minutes, when a page with a sign-up form is shown or the screen is open): nothing but the site's token. The answer is your account's sign-up forms.
* **Sign-up** (`POST /v1/store-connect/forms`, only when a visitor types an e-mail address, ticks the consent box and presses the button): the form, the address, the page it was on, the visitor's IP address and browser name. Zinn® Campaigns keeps them as the proof of consent and sends the confirmation e-mail.
* **Store events** (`POST /v1/store-connect/events`, only while the site is connected and WooCommerce is active, sent in the background by Action Scheduler):
  * `order_placed` and `payment_confirmed` when an order is placed or paid: the order id and number, the billing e-mail address and first name, the order total and currency, and the store language. `payment_confirmed` is sent only when the shopper ticked the box. These end any reminder flow for that shopper.
  * `cart_abandoned` when a cart with an e-mail address typed at checkout is left unpaid for an hour (checked every five minutes, carts older than two days are never sent): the e-mail address, first name, a random cart id, the reminder link, the cart total and currency, the store language, and — only when the shopper ticked the box — when they ticked it and the exact wording of the box they ticked, as the proof of consent. A cart without that tick is not sent.
  * `cart_recovered` when a cart that was reported comes back as an order: the same cart fields.
  * `product_viewed` at most once a day per product, only for a shopper who ticked the box: the e-mail address, first name, the product's name, address and price, and the proof of consent.
* **Disconnect** (`DELETE /v1/store-connect/connection`, when you press it): a request that revokes the site's token.

== Frequently Asked Questions ==

= Do I need a Zinn® account? =

Yes. Press Connect and you can create one on the way.

= What does Pro add, and what does it cost? =

Pro is a separate add-on plugin, Zinn® Campaigns Pro. It is included free with any paid Zinn® Campaigns plan, with a 30-day free trial when you first connect. This free plugin has no locked features.

= Does a sign-up add someone to my list straight away? =

No. Zinn® Campaigns sends them a confirmation e-mail first, and they join your list only when they click its link.

= Is anything stored on my site? =

The connection token, a random install id and short-lived caches. With WooCommerce, the plugin also keeps a small table of open checkout carts (e-mail address, first name, cart total, a random cart id and whether the box was ticked) so it can tell when one is abandoned; rows are removed 30 days after their last change and the table is deleted when you uninstall. Sign-ups are not stored on your site.

= Who gets an abandoned-cart reminder? =

Only a shopper who typed their e-mail address at checkout and ticked the box. Zinn® Campaigns sends the reminders, with an unsubscribe link in each, and an order stops them.

== Changelog ==

= 0.2.1 =
* The readme links the plugin's public source repository.

= 0.2.0 =
* WooCommerce: an unticked marketing opt-in box at checkout (classic and block checkout).
* WooCommerce: abandoned carts are detected in the background and reported once to Zinn® Campaigns for consented shoppers, with a reminder link that refills the cart.
* WooCommerce: sends order placed, payment confirmed, cart recovered and product viewed events so Zinn® Campaigns flows start and stop at the right time.

= 0.1.0 =
* One-click connect to Zinn® Campaigns, connection status, Refresh and Disconnect.
* Double opt-in sign-up forms: the Zinn® Campaigns sign-up form block and the [zinn_campaigns_form] shortcode.
* Shows whether the connected account includes Zinn® Campaigns Pro.

== Upgrade Notice ==

= 0.2.0 =
Adds abandoned-cart, browse and recovery events for WooCommerce, with a checkout opt-in box.

= 0.1.0 =
First release.
