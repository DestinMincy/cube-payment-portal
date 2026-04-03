<p align="left">
  <img src="assets/images/icon.png" alt="Cube Payment Portal" width="250">
</p>

# Cube Payment Portal

[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple)](https://php.net)
[![Square](https://img.shields.io/badge/Square-API-black)](https://developer.squareup.com)
[![License](https://img.shields.io/badge/License-GPL--2.0-green)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/Version-1.1.7-orange)](https://github.com/DestinMincy/cube-payment-portal)

<p align="left">
  <a href="https://git.io/typing-svg">
    <img src="https://readme-typing-svg.demolab.com?font=Inter&weight=500&size=22&pause=2000&color=C0C0C0&width=600&lines=Accept+payments+directly+from+your+WordPress+site.;Give+clients+a+self-service+portal+powered+by+Square.;Sync+invoices%2C+subscriptions%2C+and+bookings+automatically." alt="Typing SVG">
  </a>
</p>

A self-service client portal for WordPress, powered by Square. Clients can pay invoices, manage subscriptions, save payment methods, view transaction history, book appointments, and more — all without leaving your site.

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Shortcodes](#shortcodes)
- [Client Portal Guide](#client-portal-guide)
- [WooCommerce Integration](#woocommerce-integration)
- [Webhooks](#webhooks)
- [Security](#security)
- [Page Template](#page-template)
- [Developer Reference](#developer-reference)
- [Troubleshooting](#troubleshooting)
- [Changelog](#changelog)

---

## Features

### Client Portal
| Feature | Description |
|---------|-------------|
| Dashboard | Welcome card, quick-action buttons, and live summary widgets |
| Payment Methods | Save, view, and delete credit/debit cards (Square Web Payments SDK) |
| Invoices | View, filter, and pay Square invoices with one click |
| Subscriptions | View plans; pause, resume, upgrade, downgrade, or cancel |
| Transactions | Full payment history with receipt links |
| Appointments | Upcoming and past bookings from Square Appointments |
| Loyalty & Rewards | Points balance and redemption options from Square Loyalty |
| Gift Cards | View linked gift cards, balances, and activity history |
| Profile | Edit name, email, address, notifications; country selector included |

### Admin Dashboard
| Feature | Description |
|---------|-------------|
| Revenue Overview | Charts, MRR, outstanding balances, transaction volume |
| Customer Management | All Square customers linked to WordPress accounts |
| Invoice Management | View and filter all Square invoices |
| Subscription Plans | Manage plans and subscribers |
| Catalog & Inventory | Synced Square Item Library with stock monitoring |
| Bookings | View all appointments, filter by service and staff |
| Disputes | Track and manage payment disputes |
| Loyalty | Customer enrollment and points data |
| Gift Cards | Issuance, balances, and activity logs |
| Feature Toggles | Enable/disable modules individually |
| Sync Scheduler | Per-type sync frequency (15 min → daily) |

### Integrations
- **Square** — Payments, invoices, subscriptions, catalog, bookings, loyalty, gift cards, disputes
- **WooCommerce** — Payment gateway using saved Square cards; order and download history in portal
- **Block/FSE Themes** — Full compatibility; uses the theme's own header and footer
- **Classic Themes** — Full compatibility via `get_header()` / `get_footer()`

---

## Requirements

| Requirement | Minimum |
|-------------|---------|
| WordPress | 5.0 |
| PHP | 7.4 |
| SSL Certificate | Required (HTTPS) |
| Square Account | [Free sign-up](https://squareup.com) |
| WooCommerce | Optional — 5.0+ for e-commerce features |

---

## Installation

### From WordPress Admin
1. Go to **Plugins → Add New → Upload Plugin**.
2. Upload `cube-payment-portal.zip` and click **Install Now**.
3. Click **Activate Plugin**.

### Manual
1. Upload the `cube-payment-portal` folder to `/wp-content/plugins/`.
2. Activate via **Plugins → Installed Plugins**.

### Initial Setup
1. Go to **Cube Payment Portal → Settings → API Configuration**.
2. Enter your **Application ID** and **Application Secret** from the [Square Developer Dashboard](https://developer.squareup.com).
3. Click **Connect to Square** to complete the OAuth flow.
4. On the **General** tab, select your default **Square Location**.

The plugin automatically creates three pages on activation:

| Page | Shortcode | Purpose |
|------|-----------|---------|
| Client Portal | `[spp_client_portal]` | Full portal (login + dashboard) |
| Portal Login | `[spp_login_form]` | Standalone login form |
| Portal Registration | `[spp_register_form]` | Standalone registration form |

> **Note:** Deactivating the plugin deletes these pages. They are recreated fresh on reactivation.

---

## Configuration

### API Configuration

| Setting | Description |
|---------|-------------|
| Application ID | Your Square Application ID |
| Application Secret | Your Square Application Secret |
| Connect to Square | Initiates the OAuth authorisation flow |
| Connection Status | Shows the connected Square account name and location |

> **Sandbox mode:** Define `SPP_DEVELOPER_MODE` in `wp-config.php` to reveal sandbox environment options for testing.

```php
// wp-config.php
define( 'SPP_DEVELOPER_MODE', true );
```

### General Settings

| Setting | Description |
|---------|-------------|
| Default Location | Primary Square location for payments and syncing |
| Default Currency | USD, CAD, GBP, AUD, etc. |
| Portal Accent Color | Hex colour applied to buttons and highlights |
| Custom CSS | Additional CSS injected into the portal stylesheet |

### Client Portal Settings

| Setting | Description |
|---------|-------------|
| Enable Registration | Allow new clients to register from the portal |
| Require Email Verification | Confirm email address before granting access |
| Max Cards Per Client | Limit on saved payment methods (default: 5) |
| Allow Card Deletion | Let clients remove their saved cards |
| Welcome Message | Custom greeting shown on the dashboard |
| Quick Actions | Configure which shortcut buttons appear on the dashboard |
| Dashboard Widgets | Configure and reorder the summary widget cards |

### Feature Toggles

Each module can be enabled or disabled independently:

| Module | Default |
|--------|---------|
| Invoices | On |
| Subscriptions | On |
| Bookings | Off |
| Loyalty & Rewards | Off |
| Gift Cards | Off |
| Disputes | Off |
| Catalog | Off |
| Inventory | Off |

### Sync Scheduler

Configure independent sync frequencies for each data type:

| Data Type | Options | Default |
|-----------|---------|---------|
| Customers | 15 min / 30 min / Hourly / 6 Hours / Daily / Disabled | Hourly |
| Invoices | 15 min / 30 min / Hourly / 6 Hours / Daily / Disabled | Hourly |
| Subscriptions | 15 min / 30 min / Hourly / 6 Hours / Daily / Disabled | Hourly |
| Transactions | 15 min / 30 min / Hourly / 6 Hours / Daily / Disabled | 6 Hours |
| Catalog | 15 min / 30 min / Hourly / 6 Hours / Daily / Disabled | Daily |
| Orders | 15 min / 30 min / Hourly / 6 Hours / Daily / Disabled | Daily |
| Gift Card Activities | 15 min / 30 min / Hourly / 6 Hours / Daily / Disabled | 6 Hours |

---

## Shortcodes

### `[spp_client_portal]`
Full client portal — handles login, registration, and the authenticated dashboard in one shortcode.

| Attribute | Description | Default |
|-----------|-------------|---------|
| `redirect_url` | URL to redirect to after login | Current page |

### `[spp_login_form]`
Standalone login form. Redirects already-logged-in users to the portal.

| Attribute | Description | Default |
|-----------|-------------|---------|
| `redirect_to` | URL after successful login | Portal page |
| `show_logo` | Show site logo above the form | `true` |
| `show_remember` | Show "Remember Me" checkbox | `true` |
| `show_register` | Show link to registration page | `false` |
| `contact_url` | URL for "Need help?" link | Empty |

### `[spp_register_form]`
Standalone registration form.

| Attribute | Description | Default |
|-----------|-------------|---------|
| `redirect_to` | URL after successful registration | Portal page |
| `show_logo` | Show site logo above the form | `true` |

### `[spp_payment_form]`
One-time payment form with Square Web Payments SDK card entry.

| Attribute | Description | Default |
|-----------|-------------|---------|
| `amount` | Fixed payment amount in dollars | Required |
| `description` | Payment description shown on receipt | Empty |

### `[spp_payment_button]`
Minimal pay button that opens the payment form in a modal.

| Attribute | Description | Default |
|-----------|-------------|---------|
| `amount` | Payment amount | Required |
| `text` | Button label | `Pay` |
| `style` | `primary` or `secondary` | `primary` |

### `[spp_subscription_plans]`
Subscription plan grid pulled from Square.

| Attribute | Description | Default |
|-----------|-------------|---------|
| `plan_ids` | Comma-separated Square plan IDs | All active plans |
| `columns` | Grid columns (1–4) | `3` |

### `[spp_subscribe_button]`
Subscribe button for a specific plan.

| Attribute | Description | Default |
|-----------|-------------|---------|
| `plan_id` | Square catalog subscription plan ID | Required |
| `text` | Button label | `Subscribe` |

### `[spp_my_subscriptions]`
Displays the logged-in client's subscriptions.

### `[spp_my_bookings]`
Displays the logged-in client's upcoming and past bookings.

### `[spp_loyalty_points]`
Displays the logged-in client's loyalty points balance.

---

## Client Portal Guide

### For Clients — Getting Started

1. Navigate to the Client Portal page on your site.
2. Click **Create Account** (if registration is enabled) or **Sign In**.
3. After logging in you land on the dashboard — a summary of your account.

### Paying an Invoice

1. Click **Invoices** in the sidebar (or the **Pay Invoice** quick-action button).
2. Find the invoice and click **Pay Now**.
3. Confirm the amount and select or enter a payment card.
4. Click **Pay** — you receive an email receipt automatically.

### Managing Payment Methods

1. Click **Payment Methods** in the sidebar.
2. Click **Add New Card** — the Square card form appears.
3. Enter your card details. The card is tokenised by Square client-side and saved to your account.
4. To remove a card, click the delete icon next to it.

### Managing Subscriptions

| Action | How |
|--------|-----|
| View details | Click the subscription card to expand |
| Change plan | Click **Change Plan** and select a new plan |
| Update billing card | Click **Update Card** |
| Pause | Click **Pause Subscription** |
| Resume | Click **Resume** on a paused subscription |
| Cancel | Click **Cancel Subscription** |

---

## WooCommerce Integration

When WooCommerce is installed and active, Cube Payment Portal registers automatically as a payment gateway.

### Enabling the Gateway

1. Go to **WooCommerce → Settings → Payments**.
2. Enable **Cube Payment Portal**.
3. Customers who are logged in and have saved cards in their portal account can select a card at checkout.
4. New cards entered at checkout are saved to the portal account.

### Refunds

Refunds initiated from **WooCommerce → Orders** are submitted to Square automatically and the refund ID is stored against the order.

### Client Portal Order History

Logged-in WooCommerce customers can view their order history and downloadable files from the **portal dashboard** without visiting the WooCommerce My Account page.

---

## Webhooks

Square webhooks keep your data up to date in real time between scheduled syncs.

### Setup

1. Go to **Cube Payment Portal → Settings → Webhooks**.
2. Copy the **Webhook Endpoint URL**.
3. In the [Square Developer Dashboard](https://developer.squareup.com), open your application and go to **Webhooks → Subscriptions**.
4. Add the endpoint URL and subscribe to the events listed below.
5. Copy the **Signature Key** Square generates and paste it into the plugin's Webhook settings.

### Supported Events

| Square Event | Effect |
|--------------|--------|
| `payment.completed` | Records transaction, updates invoice status |
| `payment.updated` | Updates payment status |
| `refund.created` | Records refund against transaction |
| `invoice.payment_made` | Marks invoice as paid |
| `invoice.updated` | Syncs invoice status changes |
| `subscription.created` | Creates subscription record |
| `subscription.updated` | Updates subscription status |
| `catalog.version.updated` | Triggers catalog re-sync |
| `booking.created` | Creates booking record |
| `booking.updated` | Updates booking status |
| `gift_card_activity.created` | Records gift card activity in real time |
| `customer.created` | Creates customer record |
| `customer.updated` | Updates customer profile |

---

## Security

### PCI Compliance
Card data is tokenised entirely client-side by the [Square Web Payments SDK](https://developer.squareup.com/docs/web-payments/overview). Raw card numbers never reach your server. You operate outside PCI scope.

### Authentication & Authorisation
- OAuth 2.0 with CSRF state-parameter protection.
- OAuth tokens encrypted with AES-256-CBC before storage.
- Every AJAX handler verifies a WordPress nonce.
- Admin actions require `manage_options`. Client actions are gated to the `spp_client` role.
- Configurable session timeout and rate limiting.

### Webhook Verification
HMAC-SHA256 signature verification on every incoming Square webhook event. Verification is **always enforced** when a signature key is configured, regardless of environment (sandbox or production).

### IP Spoofing Protection
Only `REMOTE_ADDR` is trusted for IP detection by default. To trust a reverse proxy, define:

```php
// wp-config.php
define( 'SPP_TRUSTED_PROXY_IP', '10.0.0.1' ); // Your proxy's IP
```

### User Roles

| Role | Capabilities |
|------|--------------|
| `spp_client` | Access own portal data, manage own payment methods and profile |
| `spp_client_placeholder` | No access — placeholder for Square customers pending account claim |
| `administrator` | Full access to all plugin features and settings |

---

## Page Template

The plugin registers a **"Client Portal (Full Width)"** page template that:

- Is automatically assigned to all portal pages on activation.
- Works with **block/FSE themes** — WordPress renders the theme's own block template, the plugin strips the content-area width constraints via CSS.
- Works with **classic themes** — `get_header()` / `get_footer()` are called, keeping the theme's navigation and footer intact.
- Adds `body.spp-portal-template` so targeted CSS can neutralise theme layout constraints without affecting the header or footer.

---

## Developer Reference

### Constants

| Constant | Description |
|----------|-------------|
| `SPP_VERSION` | Plugin version string |
| `SPP_PLUGIN_DIR` | Absolute path to plugin directory (trailing slash) |
| `SPP_PLUGIN_URL` | URL to plugin directory (trailing slash) |
| `SPP_PLUGIN_BASENAME` | Plugin basename (e.g. `cube-payment-portal/cube-payment-portal.php`) |
| `SPP_MINIMUM_PHP_VERSION` | Minimum required PHP version |
| `SPP_MINIMUM_WP_VERSION` | Minimum required WordPress version |
| `SPP_DEVELOPER_MODE` | Define to reveal sandbox and developer settings |
| `SPP_TRUSTED_PROXY_IP` | Define to trust `X-Forwarded-For` from a specific proxy IP |
| `SPP_SKIP_SSL_CHECK` | Define to bypass the SSL requirement check (dev only) |

### Actions

```php
// After a client registers
do_action( 'spp_client_registered', $user_id, $square_customer_id );

// After a payment is completed
do_action( 'spp_payment_completed', $payment_id, $user_id, $amount );

// After a subscription is created
do_action( 'spp_subscription_created', $subscription_id, $user_id );

// After a subscription is cancelled
do_action( 'spp_subscription_canceled', $subscription_id, $user_id );

// After a gift card activity arrives via webhook
do_action( 'spp_gift_card_activity_created', $activity_id, $activity );
```

### Filters

```php
// Disable all plugin stylesheets (load your own)
add_filter( 'spp_disable_styles', '__return_true' );

// Modify which menu locations are checked for primary nav integration
add_filter( 'spp_menu_locations', function( $locations ) {
    $locations[] = 'my-custom-location';
    return $locations;
} );
```

### Custom CSS Variables

Override the portal's design tokens in your theme stylesheet or the Custom CSS field:

```css
:root {
    --spp-primary:           #006aff;
    --spp-primary-hover:     #0055cc;
    --spp-primary-gradient:  linear-gradient(135deg, #006aff, #00d2ff);
    --spp-success:           #10b981;
    --spp-warning:           #f59e0b;
    --spp-error:             #ef4444;
    --spp-text-main:         #1a1a2e;
    --spp-text-muted:        #6b7280;
    --spp-bg-secondary:      #f8fafc;
    --spp-border-light:      #e5e7eb;
    --spp-radius-lg:         12px;
    --spp-font-body:         'Inter', system-ui, sans-serif;
    --spp-font-heading:      'Lexend', system-ui, sans-serif;
}
```

---

## Troubleshooting

### "Failed to connect to Square"
- Confirm your site has a valid SSL certificate and is accessible over HTTPS.
- Check the Application ID matches the environment (sandbox IDs start with `sandbox-`).
- Ensure no security plugin is blocking the OAuth redirect.

### "Card declined" or "Invalid card token"
- The card token expires after a few minutes — refresh the page and try again.
- In sandbox mode, use [Square test card numbers](https://developer.squareup.com/docs/testing/test-values) only.
- In production, the client's bank may be declining — they should contact their bank.

### Invoices / subscriptions not syncing
- Confirm the webhook URL is registered in Square and the signature key matches.
- Check that WP-Cron is running (`wp cron event list` via WP-CLI).
- Increase sync frequency in **Settings → Sync Scheduler**.

### Portal pages not appearing after activation
- If the pages were manually deleted, use the **Recreate Pages** button in **Settings → General**.
- Deactivate and reactivate the plugin to trigger a fresh page creation.

### WooCommerce gateway not visible at checkout
- Confirm WooCommerce is active and version 5.0 or higher.
- Go to **WooCommerce → Settings → Payments** and enable the gateway.
- Ensure the site has SSL — the gateway is hidden on non-HTTPS sites.

---

## Changelog

### 1.1.7
- Fixed: Renamed main plugin file to `cube-payment-portal.php` for WordPress.org compliance.
- Changed: GitHub auto-updater removed; updates now delivered via WordPress.org.
- Fixed: WooCommerce gateway now charges real Square payments — previous version was a stub that accepted all orders without charging.
- Fixed: WooCommerce refund handler now submits actual refund requests to Square.
- Fixed: Removed unsupported WooCommerce Subscriptions declarations from gateway `$supports` array.
- Added: Plugin page template compatible with both classic and block/FSE themes using the theme's own header and footer.
- Added: Portal pages automatically deleted on deactivation and recreated on reactivation with portal template pre-assigned.
- Added: `SPP_Template_Loader` instantiated on every request so `template_include` and `body_class` filters are active at runtime.
- Security: Webhook HMAC verification enforced unconditionally when a signature key is configured.
- Security: Impersonation audit logs write unconditionally regardless of `WP_DEBUG`.
- Security: IP detection hardened — only `REMOTE_ADDR` trusted by default; `X-Forwarded-For` is opt-in.
- Security: XSS fixed in admin bookings-services, dispute-detail, loyalty, and gift-card templates.
- Security: `extract()` replaced with explicit variable assignments in shortcode renderer.
- Security: Debug console calls gated behind `WP_DEBUG`-derived flags.
- Security: Inline CSS output in client portal partials wrapped with `esc_attr()`.
- Improved: API retry backoff capped at 5 s; rate-limit wait capped at 10 s.
- Improved: DDL migration queries wrapped with `phpcs:disable` / `phpcs:enable`.

### 1.1.6
- Changed: Default environment is now Production for new installations.
- Added: `SPP_DEVELOPER_MODE` constant gates sandbox and developer-only settings.
- Improved: Streamlined API Configuration tab with prominent Connect to Square button.
- Improved: Environment selector and sandbox token fields hidden unless developer mode is enabled.
- Fixed: Docker entrypoint wired up for automatic plugin activation.

### 1.1.5
- Security: SRI hashes added for all CDN-loaded scripts.
- Security: XSS fixed in error message display (`.html()` → `.text()`).
- Security: All `error_log()` calls gated behind `WP_DEBUG`.
- Improved: Glassmorphism card design and hover states in client portal.
- Improved: Password strength indicator on registration form.
- Improved: Inline form validation for profile fields.
- Improved: Unsaved-changes warning when navigating away from profile edits.
- Improved: Result count after filtering subscriptions and invoices.
- Improved: Country dropdown replaced free-text input on profile page.
- Improved: Invoice row hover covers full row including Pay Now button.
- Added: Auto-update toggle in plugin settings and on the Plugins page.
- Added: Plugin update status on General settings tab.
- Fixed: Profile save handler collects `<select>` values correctly.
- Fixed: Portal dark mode changed to opt-in only (`.spp-portal--dark` class).

### 1.1.4
- Added: Booking management — view upcoming and past Square Appointments from the portal.
- Added: Loyalty programme — points balance and redemption options from Square Loyalty.
- Added: Gift card management — linked cards, balances, and activity history.
- Added: Dispute tracking dashboard for admins.
- Added: Notification system — appointment and invoice reminder emails.
- Added: Smart theme menu integration with three-tier detection and fallback.
- Added: GDPR compliance — WordPress personal data exporter and eraser, privacy policy disclosure.

### 1.1.0
- Added: WooCommerce payment gateway using saved Square cards.
- Added: Subscription plan management with bidirectional Square sync.
- Added: Invoice sync — import all Square invoices and allow client payments.
- Added: Catalog and inventory sync from Square Item Library.
- Added: Feature toggle system — enable/disable modules individually.
- Added: Webhook processing — real-time Square events.
- Added: Configurable per-type sync scheduler.

### 1.0.0
- Initial release.
- Client portal with login, registration, and dashboard.
- Payment processing via Square Web Payments SDK (client-side tokenisation).
- Customer management with Square sync.
- Transaction history with receipt links.
- Saved payment method (card) management.
- OAuth 2.0 authorisation flow with CSRF protection.
- Sandbox mode for testing.

---

## License

GPL-2.0 or later — see [LICENSE](LICENSE) for full text.

---

<p align="center">
  <img src="assets/images/dlm-logo.png" alt="Destin L. Mincy" width="120"><br>
  <sub>Powered by <a href="https://destinlmincy.com">Destin L. Mincy</a></sub>
</p>
