=== Cube Payment Portal ===
Contributors: destinmincy
Tags: square, payments, invoices, subscriptions, client-portal
Requires at least: 5.0
Tested up to: 6.9
Stable tag: 1.1.7
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A comprehensive client portal integrating Square for payments, subscriptions, invoices, bookings, and more.

== Description ==

Cube Payment Portal brings the full power of your Square account into WordPress. Give your clients a branded self-service portal where they can view invoices, manage subscriptions, make payments, book appointments, and track their transaction history — all without leaving your site.

**Everything syncs bidirectionally with Square**, so your data stays consistent whether you manage it from the WordPress dashboard, the Square Dashboard, or the client portal.

= Key Features =

* **Client Portal** — A single-page application embedded via shortcode. Clients log in to view their dashboard, pay invoices, manage subscriptions, update payment methods, and edit their profile.
* **Invoices** — Sync invoices from Square and let clients pay outstanding balances directly through the portal with a single click.
* **Subscriptions** — Create and manage recurring billing plans. Clients can upgrade, downgrade, pause, resume, or cancel from the portal.
* **Payments & Transactions** — Process one-time payments and view complete transaction history with receipt details.
* **Bookings** — Integrate with Square Appointments so clients can view upcoming and past bookings.
* **Loyalty & Rewards** — Let clients check their loyalty points balance and enrollment status.
* **Gift Cards** — Sell and manage digital gift cards with balance tracking.
* **Catalog & Inventory** — Sync your Square Item Library and monitor stock levels across locations.
* **Payment Methods** — Clients securely save and manage credit/debit cards using the Square Web Payments SDK. Card data is tokenized client-side and never touches your server.
* **WooCommerce Integration** — Optional payment gateway that lets WooCommerce customers pay with saved Square cards, Apple Pay, and Google Pay.

= Admin Features =

* **Dashboard** — Revenue overview, transaction monitoring, and customer insights at a glance.
* **Customer Management** — View and manage client accounts linked to Square customer profiles.
* **Feature Toggles** — Enable only the modules you need. Each feature (subscriptions, invoices, bookings, loyalty, gift cards, disputes, catalog, inventory) can be toggled on or off.
* **Sandbox Mode** — Test the entire integration using Square sandbox credentials before going live.
* **Webhook Support** — Receive real-time updates from Square for payments, refunds, subscriptions, invoices, catalog changes, and bookings.
* **Auto-Updates** — Opt-in automatic updates from the plugin settings or the WordPress Plugins page.

= Shortcodes =

* `[spp_client_portal]` — Full client portal with login, registration, and dashboard.
* `[spp_login_form]` — Standalone login form.
* `[spp_register_form]` — Standalone registration form.
* `[spp_payment_form]` — One-time payment form with configurable amount and description.
* `[spp_payment_button]` — Simple pay button.
* `[spp_subscription_plans]` — Display subscription plans in a grid.
* `[spp_subscribe_button]` — Subscribe to a specific plan.
* `[spp_my_subscriptions]` — Show the logged-in user's subscriptions.
* `[spp_my_bookings]` — Show the logged-in user's bookings.
* `[spp_loyalty_points]` — Display loyalty points balance.

= Security =

* PCI-compliant card handling — card data tokenized client-side by the Square Web Payments SDK.
* OAuth 2.0 authorization with CSRF state parameter protection.
* Webhook signature verification (HMAC) for all incoming events.
* WordPress nonce verification and capability checks on every AJAX handler.
* Configurable rate limiting and session timeouts.
* Full input sanitization and output escaping throughout.

= Privacy & GDPR =

* Integrates with the WordPress personal data exporter and eraser tools.
* Exports customer profiles, transactions, invoices, subscriptions, and bookings on request.
* Anonymizes records on erasure while preserving referential integrity for compliance.
* Adds a disclosure section to your site's privacy policy automatically.
* Per-user opt-out controls for appointment and invoice reminder emails.

= Requirements =

* WordPress 5.0 or higher
* PHP 7.4 or higher
* HTTPS (required for Square API communication)
* A Square account with API credentials ([Sign up free](https://squareup.com))
* WooCommerce 5.0+ (optional, for e-commerce gateway features)

== Installation ==

1. Upload the `cube-payment-portal` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Square Portal > Settings > API Configuration**.
4. Enter your Square Application ID and Application Secret.
5. Click **Connect to Square** to complete the OAuth authorization flow.
6. Select your default location on the **General** tab.
7. Add the `[spp_client_portal]` shortcode to any page (a portal page is created automatically on activation).

= Sandbox Testing =

1. Go to **Settings > API Configuration** and select **Sandbox** as the environment.
2. Enter your sandbox Application ID and access token from the [Square Developer Dashboard](https://developer.squareup.com).
3. Use Square's [test card numbers](https://developer.squareup.com/docs/testing/test-values) to simulate payments.

== Frequently Asked Questions ==

= Do I need a Square account? =

Yes. You need a Square account with API credentials. You can [create a free account](https://squareup.com) and obtain your Application ID and Secret from the [Square Developer Dashboard](https://developer.squareup.com).

= Is this plugin PCI compliant? =

Yes. All card data is tokenized client-side using the Square Web Payments SDK. Sensitive card numbers never touch your server, which keeps you outside PCI scope.

= Does it work with WooCommerce? =

Yes. When WooCommerce is active, the plugin registers a payment gateway that lets customers pay with saved Square cards, Apple Pay, and Google Pay at checkout. Clients can also view their WooCommerce orders and downloads from the portal.

= Can clients register their own accounts? =

Yes. Client registration can be enabled in **Settings > Client Portal**. New clients receive the `spp_client` role, which grants portal access without WordPress admin capabilities.

= Does it support recurring payments? =

Yes. You can create subscription plans synced with Square. Clients can subscribe, upgrade, downgrade, pause, resume, or cancel directly from the portal.

= Can I use this in sandbox/test mode? =

Yes. Toggle sandbox mode in **Settings > API Configuration** and use Square test card numbers to simulate the full payment flow without real charges.

= Does it support webhooks? =

Yes. Configure your webhook endpoint URL and signature key in **Settings > Webhooks**. The plugin processes events for payments, refunds, subscriptions, invoices, catalog updates, and bookings.

= What happens if I deactivate the plugin? =

Your data is preserved in the database. Reactivating the plugin restores everything. If you delete (uninstall) the plugin, all custom database tables and options are removed.

= Does it support multiple Square locations? =

Yes. You can select your default location in **Settings > General**, and the admin dashboard can filter data by location.

== Screenshots ==

1. Client portal dashboard with quick actions and summary widgets.
2. Invoice list with pay-now buttons and expandable details.
3. Subscription management with upgrade/downgrade options.
4. Payment method management with secure card form.
5. Admin dashboard with revenue overview and transaction charts.
6. Plugin settings with feature toggles.
7. Booking calendar and appointment management.

== Changelog ==

= 1.1.7 =
* Fixed: Renamed main plugin file to `cube-payment-portal.php` for WordPress.org compliance (directory/file name must match).
* Fixed: GitHub auto-updater excluded from WordPress.org build via `.distignore`; plugin loader guards against its absence gracefully.
* Fixed: WooCommerce payment gateway now performs real Square payment processing — previous stub accepted all orders without charging.
* Fixed: WooCommerce refund handler now submits actual refund requests to Square instead of always returning true.
* Fixed: Removed unsupported WooCommerce Subscriptions feature declarations from gateway `$supports` array.
* Security: Webhook signature bypass closed — sandbox mode no longer skips HMAC verification when a signature key is configured.
* Security: Impersonation audit logs now write unconditionally (not only when WP_DEBUG is enabled).
* Security: IP detection hardened against header spoofing — only `REMOTE_ADDR` is trusted by default; `X-Forwarded-For` is opt-in via `SPP_TRUSTED_PROXY_IP` constant.
* Security: Fixed XSS in admin bookings-services template — raw echo replaced with `if/endif` and `esc_html_e()`.
* Security: Fixed XSS in admin dispute-detail, loyalty, and gift-cards templates — raw API data no longer injected via `.html()`; DOM nodes built safely with `.text()` and `$()`.
* Security: `extract()` replaced with explicit variable assignments in client portal shortcode renderer.
* Security: `console.error` / `console.warn` calls in client-portal.js and admin-calendar.js gated behind `sppPublic.debug` / `sppCalendar.debug` (set from `WP_DEBUG`).
* Improved: `phpcs:disable` / `phpcs:enable` block added around DDL migration queries for clean PHPCS output.
* Improved: `request_with_retry()` sleep capped at 10 s (rate-limit) and 5 s (backoff) to prevent PHP execution timeout on shared hosts.

= 1.1.6 =
* Changed: Default environment switched from Sandbox to Production for end users.
* Added: `SPP_DEVELOPER_MODE` constant to gate sandbox and developer-only settings.
* Improved: Streamlined API Configuration tab with prominent "Connect to Square" OAuth button.
* Improved: Environment selector and sandbox token fields hidden unless developer mode is enabled.
* Fixed: Docker entrypoint now wired up for automatic plugin activation on first install.

= 1.1.5 =
* Security: Added Subresource Integrity (SRI) hashes for all CDN-loaded scripts.
* Security: Fixed potential XSS in error message display (switched .html() to .text()).
* Security: Wrapped debug logging calls with WP_DEBUG checks.
* Improved: Client portal glassmorphism card design and hover states.
* Improved: Password strength indicator on registration form.
* Improved: Inline form validation for profile fields.
* Improved: Unsaved changes warning when navigating away from profile edits.
* Improved: Result count display after filtering subscriptions and invoices.
* Improved: Country dropdown replaced text input on profile page.
* Improved: Select element styling with proper padding and text visibility.
* Improved: Invoice card hover effect now covers full row including Pay Now button.
* Added: Auto-update toggle in plugin settings and on the Plugins page.
* Added: Plugin update status display on the General settings tab.
* Fixed: Profile save handler now collects select element values.
* Fixed: Dark mode CSS changed from auto-detect to opt-in only.

= 1.1.4 =
* Added: Booking management with Square Appointments integration.
* Added: Loyalty program points display in client portal.
* Added: Gift card management and balance checking.
* Added: Dispute tracking and management dashboard.
* Added: Notification system with appointment and invoice reminders.
* Added: Menu integration with smart auto-detection and 3-tier fallback.
* Added: Privacy and GDPR compliance (data exporter/eraser).

= 1.1.0 =
* Added: WooCommerce payment gateway integration.
* Added: Subscription plan management with bidirectional Square sync.
* Added: Invoice sync and client-facing payment links.
* Added: Catalog and inventory sync from Square.
* Added: Feature toggle system for modular functionality.
* Added: Webhook processing for real-time Square events.

= 1.0.0 =
* Initial release.
* Client portal with login, registration, and dashboard.
* Payment processing via Square Web Payments SDK.
* Customer management with Square sync.
* Transaction history and receipt viewing.
* Payment method (card) management.
* OAuth 2.0 authorization flow.
* Sandbox mode for testing.

== Upgrade Notice ==

= 1.1.7 =
Pre-launch security and compliance release. Renames main plugin file for WordPress.org submission, implements real WooCommerce payment processing, and closes several security issues. Upgrade before publishing.

= 1.1.6 =
Production-first OAuth flow. Sandbox settings now require SPP_DEVELOPER_MODE. Admins just enter credentials and click "Connect to Square".

= 1.1.5 =
Security and UX improvements. Adds SRI integrity checks for CDN scripts, fixes XSS in error messages, and introduces auto-update controls.

= 1.1.0 =
Major feature update adding WooCommerce integration, subscriptions, invoices, catalog sync, and webhook support.
