=== Zinn® Campaigns – Email & SMS Marketing for WooCommerce & More ===
Contributors: zinndigital
Plugin URI: https://zinndigital.com/wordpress-plugins/zinn-campaigns
Author: Neil Lock — CEO, Zinn Digital® Ltd
Author URI: https://zinndigital.com
Tags: email marketing, sms, whatsapp, newsletter, woocommerce
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
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
* **Works with multilingual sites.** The site's languages are read from Tranzly, WPML or Polylang (or the site language) and reported to Zinn® Campaigns.

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
* **Disconnect** (`DELETE /v1/store-connect/connection`, when you press it): a request that revokes the site's token.

== Frequently Asked Questions ==

= Do I need a Zinn® account? =

Yes. Press Connect and you can create one on the way.

= What does Pro add, and what does it cost? =

Pro is a separate add-on plugin, Zinn® Campaigns Pro. It is included free with any paid Zinn® Campaigns plan, with a 30-day free trial when you first connect. This free plugin has no locked features.

= Does a sign-up add someone to my list straight away? =

No. Zinn® Campaigns sends them a confirmation e-mail first, and they join your list only when they click its link.

= Is anything stored on my site? =

Only the connection token, a random install id and short-lived caches. Sign-ups are not stored on your site.

== Changelog ==

= 0.1.0 =
* One-click connect to Zinn® Campaigns, connection status, Refresh and Disconnect.
* Double opt-in sign-up forms: the Zinn® Campaigns sign-up form block and the [zinn_campaigns_form] shortcode.
* Shows whether the connected account includes Zinn® Campaigns Pro.

== Upgrade Notice ==

= 0.1.0 =
First release.
