=== Cube Payment Portal ===
Contributors: destinmincy
Tags: square, payments, invoices, subscriptions, client-portal
Requires at least: 5.0
Tested up to: 6.9
Stable tag: 1.1.7
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A self-service client portal powered by Square — payments, invoices, subscriptions, bookings, loyalty, and gift cards in one plugin.

== Description ==

Cube Payment Portal gives your clients a branded, self-service portal embedded directly in your WordPress site. Connect your Square account once via OAuth and your clients can pay invoices, manage subscriptions, save payment methods, view transaction history, book appointments, and more — without ever leaving your site.

Everything stays in sync with Square automatically. Changes made in the Square Dashboard, through the portal, or from the WordPress admin are reflected everywhere.

= Client Portal =

The portal is a single-page application rendered via the `[spp_client_portal]` shortcode. Once a client logs in they have access to:

* **Dashboard** — Personalised welcome, quick-action buttons, and live summary widgets (outstanding balance, payment methods, recent invoices, subscriptions).
* **Payment Methods** — Save, view, and delete credit/debit cards. Cards are tokenised client-side by the Square Web Payments SDK and never touch your server.
* **Invoices** — View all invoices from Square, filter by status, expand line-item details, and pay outstanding balances with a single click using a saved card or a new card.
* **Subscriptions** — View active, paused, and cancelled plans. Clients can pause, resume, upgrade, downgrade, or cancel directly from the portal.
* **Transactions** — Full payment history with amounts, dates, status, card used, and receipt links.
* **Appointments** — View upcoming and past bookings synced from Square Appointments (requires Bookings feature enabled).
* **Loyalty & Rewards** — Check points balance and redemption options synced from Square Loyalty.
* **Gift Cards** — View linked gift cards, check balances, and see activity history.
* **Profile** — Edit name, email, address, and notification preferences. Country selector included.

= Admin Features =

* **Dashboard** — Revenue charts, recent transactions, customer stats, and outstanding invoice totals.
* **Customer Management** — View and manage all Square customers linked to WordPress accounts. Sync on demand.
* **Invoices** — View all Square invoices, filter by status, and see full line-item details.
* **Subscriptions** — Manage all active subscription plans and subscriber accounts.
* **Catalog & Inventory** — Browse synced Square catalog items and monitor stock levels across locations.
* **Bookings** — View all appointments, filter by service and staff, and manage availability.
* **Disputes** — Track and respond to payment disputes from the WordPress dashboard.
* **Loyalty** — View loyalty programme enrollment and points data for each customer.
* **Gift Cards** — Manage gift card issuance, balances, and activity logs.
* **Feature Toggles** — Enable only the modules your business uses. Unused sections are completely hidden from clients and admins.
* **Webhook Configuration** — Enter your Square webhook signature key and view incoming event logs.
* **Sync Scheduler** — Configure independent sync frequencies (15 min to daily) for customers, invoices, subscriptions, transactions, catalog, orders, and gift card activities.

= Shortcodes =

* `[spp_client_portal]` — Full client portal (login, registration, and dashboard in one).
* `[spp_login_form]` — Standalone login form.
* `[spp_register_form]` — Standalone registration form.
* `[spp_payment_form amount="50.00" description="Service Fee"]` — One-time payment form.
* `[spp_payment_button amount="25.00" text="Pay Now"]` — Minimal payment button.
* `[spp_subscription_plans columns="3"]` — Subscription plan grid.
* `[spp_subscribe_button plan_id="PLAN_ID"]` — Subscribe button for a specific plan.
* `[spp_my_subscriptions]` — Logged-in user's active subscriptions.
* `[spp_my_bookings]` — Logged-in user's upcoming and past bookings.
* `[spp_loyalty_points]` — Loyalty points balance widget.

= Security =

* **PCI Compliance** — Card data is tokenised entirely client-side by the Square Web Payments SDK. Raw card numbers never reach your server.
* **OAuth 2.0** — Authorisation flow with CSRF state-parameter protection. Tokens are encrypted with AES-256-CBC before being stored.
* **Webhook Verification** — HMAC-SHA256 signature verification on every incoming Square event. Verification is enforced whenever a signature key is configured, regardless of environment.
* **Nonce Verification** — Every AJAX handler verifies a WordPress nonce.
* **Capability Checks** — Every admin AJAX action requires `manage_options`. Client actions are gated to the `spp_client` role.
* **Rate Limiting** — Configurable per-IP rate limiting on webhook and public endpoints.
* **Input Sanitization** — All user input is sanitised (`sanitize_text_field`, `absint`, `sanitize_email`, `wp_kses`, etc.).
* **Output Escaping** — All output uses `esc_html`, `esc_attr`, `esc_url`, or `wp_kses_post` throughout.
* **IP Spoofing Protection** — Only `REMOTE_ADDR` is trusted by default. Proxy header trust (`X-Forwarded-For`) is opt-in via the `SPP_TRUSTED_PROXY_IP` constant.

= Privacy & GDPR =

* Integrates with the WordPress personal data exporter and eraser.
* Exports customer profiles, transactions, invoices, subscriptions, bookings, and loyalty data on request.
* Anonymises records on erasure while preserving referential integrity.
* Appends a disclosure section to your site's Privacy Policy page automatically.
* Per-user opt-out controls for appointment and invoice reminder emails.

= Page Template =

The plugin provides a dedicated "Client Portal (Full Width)" page template. It is automatically assigned to portal pages on activation and is compatible with both classic PHP themes and modern block/FSE themes. The template uses the active theme's own header and footer so your site navigation and branding remain consistent, while stripping the theme's content-area width constraints so the portal fills the full viewport.

= WooCommerce Integration =

When WooCommerce is active the plugin registers as a payment gateway. Clients can pay at checkout using a card saved in their portal account, or enter a new card. Orders and downloads are accessible from within the client portal. Refunds initiated from WooCommerce are submitted to Square automatically.

= Requirements =

* WordPress 5.0 or higher
* PHP 7.4 or higher
* HTTPS / SSL certificate (required by Square)
* A Square account with API credentials ([Sign up free](https://squareup.com))
* WooCommerce 5.0+ (optional, for e-commerce gateway features)

== Installation ==

1. Upload the `cube-payment-portal` folder to `/wp-content/plugins/`.
2. Activate the plugin through **Plugins → Installed Plugins**.
3. Navigate to **Cube Payment Portal → Settings → API Configuration**.
4. Enter your Square Application ID and Application Secret.
5. Click **Connect to Square** to complete the OAuth authorisation flow.
6. On the **General** tab, select your default Square location.
7. The plugin automatically creates a Client Portal page, a Portal Login page, and a Portal Registration page. Visit them to confirm everything is working.

**Note:** Deactivating the plugin deletes the auto-created pages. They are recreated fresh when you reactivate.

= Sandbox Testing =

1. Go to **Settings → API Configuration**.
2. Define the constant `SPP_DEVELOPER_MODE` in your `wp-config.php` to reveal sandbox options.
3. Switch the environment to **Sandbox** and enter your sandbox Application ID and access token from the [Square Developer Dashboard](https://developer.squareup.com).
4. Use Square's [test card numbers](https://developer.squareup.com/docs/testing/test-values) to simulate payments without real charges.

= Webhook Setup =

1. Go to **Cube Payment Portal → Settings → Webhooks** and copy your webhook endpoint URL.
2. In the [Square Developer Dashboard](https://developer.squareup.com), open your application and go to **Webhooks → Subscriptions**.
3. Add the endpoint URL and subscribe to the events your business needs.
4. Copy the signature key Square generates and paste it into the plugin's Webhook settings.

== Frequently Asked Questions ==

= Do I need a Square account? =

Yes. You need a Square account (free) and an application created in the [Square Developer Dashboard](https://developer.squareup.com) to obtain your Application ID and Secret.

= Is this plugin PCI compliant? =

Yes. All card data is tokenised client-side using the Square Web Payments SDK. Raw card numbers are never sent to your server. You operate entirely outside PCI scope.

= Does it work with WooCommerce? =

Yes. When WooCommerce is active the plugin registers a payment gateway. Customers can pay at checkout using a card saved in their portal account. WooCommerce orders and downloadable files are also visible inside the client portal.

= Does it work with WooCommerce Subscriptions? =

The plugin manages its own recurring billing through Square Subscriptions. It does not extend the WooCommerce Subscriptions plugin.

= Can clients create their own accounts? =

Yes. Enable client registration in **Settings → Client Portal**. New clients receive the `spp_client` WordPress role, which allows portal access with no admin capabilities.

= Does it support recurring payments? =

Yes. Create subscription plans synced with Square. Clients can subscribe, upgrade, downgrade, pause, resume, or cancel directly from the portal.

= What happens when I deactivate the plugin? =

The three auto-created portal pages (Client Portal, Portal Login, Portal Registration) are deleted and the scheduled sync cron jobs are cleared. Your Square data, custom database tables, and plugin settings are preserved. Reactivating recreates the pages fresh.

= What happens when I uninstall (delete) the plugin? =

All custom database tables and all plugin options are permanently removed. Square data in your Square account is unaffected.

= Does it support multiple Square locations? =

Yes. Enable multi-location support in **Settings → General** and select your default location. The admin dashboard can filter all data by location.

= Can I customise the portal appearance? =

Yes. Use the `spp_portal_accent_color` option to set a custom brand colour (hex value). You can also add custom CSS under **Settings → Client Portal → Custom CSS**, or disable all plugin styles with `add_filter('spp_disable_styles', '__return_true')` and write your own stylesheet.

= Does the portal work with block/FSE themes? =

Yes. The plugin detects whether your active theme is a classic or block (FSE) theme and adjusts accordingly. In both cases the theme's own header and footer are used so site navigation remains consistent.

= How do I set up webhooks? =

Copy the webhook URL from **Settings → Webhooks**, add it in the Square Developer Dashboard under Webhooks, then paste the generated signature key back into the plugin settings.

== Screenshots ==

1. Client portal dashboard — welcome card, quick actions, and account summary widgets.
2. Invoice list — filterable by status with expandable line-item details and pay-now button.
3. Subscription management — view plan details, billing schedule, and self-service actions.
4. Payment method management — add/remove saved cards with the Square Web Payments SDK form.
5. Admin dashboard — revenue overview, recent transactions, and outstanding balance summary.
6. Plugin settings — feature toggles and sync scheduler configuration.
7. Booking calendar — upcoming and past appointments from Square Appointments.

== Changelog ==

= 1.1.7 =
* Fixed: Renamed main plugin file to `cube-payment-portal.php` for WordPress.org compliance.
* Changed: GitHub auto-updater removed; updates are now delivered exclusively via WordPress.org.
* Fixed: WooCommerce payment gateway now processes real Square charges — previous version accepted all orders without charging.
* Fixed: WooCommerce refund handler now submits actual refund requests to Square.
* Fixed: Removed unsupported WooCommerce Subscriptions declarations from gateway `$supports` array.
* Added: Plugin page template (`spp-client-portal`) that works with both classic and block/FSE themes using the theme's own header and footer.
* Added: Portal pages (Client Portal, Portal Login, Portal Registration) are automatically deleted on deactivation and recreated on reactivation with the portal template pre-assigned.
* Added: `SPP_Template_Loader` now instantiated on every request so `template_include` and `body_class` filters are active at runtime.
* Security: Webhook HMAC verification enforced unconditionally when a signature key is configured — sandbox mode no longer bypasses it.
* Security: Impersonation audit logs now write unconditionally regardless of `WP_DEBUG` state.
* Security: IP detection hardened — only `REMOTE_ADDR` trusted by default; `X-Forwarded-For` is opt-in via `SPP_TRUSTED_PROXY_IP` constant.
* Security: XSS fixed in admin bookings-services template — raw echo replaced with `esc_html_e()`.
* Security: XSS fixed in admin dispute-detail, loyalty, and gift-card templates — `.html()` replaced with `.text()` and safe DOM construction.
* Security: `extract()` replaced with explicit variable assignments in client portal shortcode renderer.
* Security: `console.error` / `console.warn` in client-portal.js and admin-calendar.js gated behind `sppPublic.debug` / `sppCalendar.debug` (derived from `WP_DEBUG`).
* Security: Inline CSS output in client portal partials wrapped with `esc_attr()`.
* Improved: `request_with_retry()` backoff capped at 5 s and rate-limit wait capped at 10 s to prevent PHP timeouts on shared hosts.
* Improved: DDL migration queries wrapped with `phpcs:disable` / `phpcs:enable` for clean PHPCS output.

= 1.1.6 =
* Changed: Default environment switched from Sandbox to Production for new installations.
* Added: `SPP_DEVELOPER_MODE` constant gates sandbox and developer-only settings.
* Improved: Streamlined API Configuration tab with a prominent "Connect to Square" OAuth button.
* Improved: Environment selector and sandbox token fields hidden unless `SPP_DEVELOPER_MODE` is defined.
* Fixed: Docker entrypoint wired up for automatic plugin activation on first install.

= 1.1.5 =
* Security: Added Subresource Integrity (SRI) hashes for all CDN-loaded scripts.
* Security: Fixed XSS in error message display — `.html()` replaced with `.text()`.
* Security: All debug logging calls wrapped in `WP_DEBUG` checks.
* Improved: Client portal glassmorphism card design and hover states.
* Improved: Password strength indicator on registration form.
* Improved: Inline form validation for profile fields.
* Improved: Unsaved-changes warning when navigating away from profile edits.
* Improved: Result count display after filtering subscriptions and invoices.
* Improved: Country dropdown replaced free-text input on profile page.
* Improved: Select element styling with correct padding and text visibility.
* Improved: Invoice row hover effect covers full row including the Pay Now button.
* Added: Auto-update toggle in plugin settings and on the Plugins admin page.
* Added: Plugin update status display on the General settings tab.
* Fixed: Profile save handler now collects `<select>` element values correctly.
* Fixed: Portal dark mode changed from auto-detect (`prefers-color-scheme`) to opt-in via `.spp-portal--dark` class.

= 1.1.4 =
* Added: Booking management with Square Appointments integration — view upcoming and past appointments from the client portal.
* Added: Loyalty programme — clients can check points balance and redemption options.
* Added: Gift card management — view linked gift cards, balances, and activity history.
* Added: Dispute tracking dashboard for admins.
* Added: Notification system — configurable appointment and invoice reminder emails.
* Added: Smart menu integration with three-tier theme detection and fallback.
* Added: GDPR compliance — WordPress personal data exporter and eraser integration, privacy policy disclosure.

= 1.1.0 =
* Added: WooCommerce payment gateway — pay at checkout with saved Square cards.
* Added: Subscription plan management with bidirectional Square sync.
* Added: Invoice sync — import all Square invoices and allow clients to pay from the portal.
* Added: Catalog and inventory sync from Square Item Library.
* Added: Feature toggle system — enable/disable modules individually.
* Added: Webhook processing — real-time Square events for payments, refunds, subscriptions, invoices, and catalog changes.
* Added: Configurable sync scheduler with per-data-type frequency settings.

= 1.0.0 =
* Initial release.
* Client portal with login, registration, and dashboard.
* Payment processing via Square Web Payments SDK (client-side tokenisation).
* Customer management with Square bidirectional sync.
* Transaction history with receipt links.
* Payment method (saved card) management.
* OAuth 2.0 authorisation flow with CSRF protection.
* Sandbox mode for testing.

== Upgrade Notice ==

= 1.1.7 =
Major stability, security, and compliance release. Adds a working theme-compatible page template, fixes WooCommerce payment processing, closes multiple security issues, and prepares the plugin for WordPress.org submission. Upgrade before going live.

= 1.1.6 =
Production-first OAuth flow. Sandbox settings now require `SPP_DEVELOPER_MODE` in wp-config.php. Existing sandbox installations should define this constant before upgrading.

= 1.1.5 =
Security hardening and UX improvements. Includes SRI integrity hashes for CDN scripts, XSS fixes, and auto-update controls.

= 1.1.0 =
Major feature update. Adds WooCommerce integration, subscription management, invoice sync, catalog sync, and webhook support.
