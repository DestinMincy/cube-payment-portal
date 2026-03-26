# Cube Payment Portal for WordPress

A comprehensive WordPress plugin that integrates Square's payment platform to provide a client payment portal, subscription management, invoicing, and business owner dashboard.

![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple)
![License](https://img.shields.io/badge/License-GPL--2.0-green)

## Features

### For Clients
- **Account Management** - Create accounts, update profile information
- **Payment Methods** - Securely save and manage credit/debit cards
- **Subscriptions** - View, pause, resume, upgrade/downgrade, or cancel subscriptions
- **Invoices** - View and pay outstanding invoices
- **Payment History** - Access complete transaction history with receipts

### For Business Owners
- **Dashboard** - Revenue overview, transaction monitoring, customer insights
- **Subscription Management** - Create and manage subscription plans
- **Invoice Creation** - Generate and send invoices to clients
- **Customer Management** - View and manage client accounts
- **Reports** - Export data, filter by date range, track MRR

### Integrations
- **WooCommerce** - Use as checkout payment gateway
- **Square Dashboard** - Bidirectional sync for subscription plans
- **Multi-Location** - Optional support for businesses with multiple locations

---

## Requirements

| Requirement | Version |
|-------------|---------|
| WordPress | 5.0 or higher |
| PHP | 7.4 or higher |
| SSL Certificate | Required (HTTPS) |
| Square Account | [Developer Account](https://developer.squareup.com) |
| WooCommerce | Optional (for e-commerce) |

---

## Installation

1. Download the plugin ZIP file
2. Go to **Plugins → Add New → Upload Plugin**
3. Upload the ZIP file and click **Install Now**
4. Click **Activate Plugin**
5. Navigate to **Settings → Cube Payment Portal**
6. Click **Connect with Square** to authorize your account

---

## Configuration

### Initial Setup

1. **Connect to Square**
   - Go to **Settings → Cube Payment Portal**
   - Click **Connect with Square**
   - Authorize the plugin to access your Square account
   - Verify connection status shows "Connected"

2. **Set Default Location**
   - Select your primary business location
   - This is required even for single-location businesses

3. **Create Portal Pages**
   - Create a new page for the Client Portal
   - Add the shortcode `[spp_client_portal]`
   - Assign this page in Settings → General Settings

### Settings Overview

#### API Configuration
| Setting | Description |
|---------|-------------|
| Environment | Switch between Sandbox (testing) and Production |
| Application ID | Your Square Application ID |
| Connect with Square | OAuth connection button |
| Connection Status | Shows connected account details |

#### General Settings
| Setting | Description |
|---------|-------------|
| Client Portal Page | Page displaying the client portal |
| Owner Dashboard Page | Page for business owner dashboard |
| Default Currency | USD, CAD, GBP, etc. |
| Sandbox Mode | Enable for testing without real transactions |

#### Client Portal Settings
| Setting | Description |
|---------|-------------|
| Enable Registration | Allow new clients to sign up |
| Require Email Verification | Confirm email before access |
| Max Cards Per Client | Limit saved payment methods (default: 5) |
| Allow Card Deletion | Let clients remove saved cards |

#### Subscription Settings
| Setting | Description |
|---------|-------------|
| Sync Direction | WordPress→Square, Square→WordPress, or Both |
| Auto-sync Interval | Hourly or Daily |
| Default Trial Period | Days for free trials (0 for none) |
| Proration Mode | How mid-cycle changes are billed |

#### Location Settings (Optional)
| Setting | Description |
|---------|-------------|
| Enable Multi-Location | Activate location support |
| Default Location | Primary location for payments |
| Location Selection | How clients are assigned to locations |

---

## Shortcodes

### Client Portal
```
[spp_client_portal]
```
Displays the complete client portal including login, registration, dashboard, payment methods, and subscription management.

**Parameters:**
| Parameter | Description | Default |
|-----------|-------------|---------|
| `redirect_url` | URL to redirect after login | Current page |

### Payment Form
```
[spp_payment_form amount="50.00" description="Service Payment"]
```
Displays a quick payment form for one-time payments.

**Parameters:**
| Parameter | Description | Default |
|-----------|-------------|---------|
| `amount` | Fixed payment amount | Required |
| `description` | Payment description | Optional |

### Payment Button
```
[spp_payment_button amount="25.00" text="Pay Now" style="primary"]
```
Displays a simple payment button.

**Parameters:**
| Parameter | Description | Default |
|-----------|-------------|---------|
| `amount` | Payment amount | Required |
| `text` | Button text | "Pay" |
| `style` | Button style (primary/secondary) | "primary" |

### Subscription Plans
```
[spp_subscription_plans columns="3"]
```
Displays available subscription plans in a grid.

**Parameters:**
| Parameter | Description | Default |
|-----------|-------------|---------|
| `plan_ids` | Comma-separated plan IDs | All active |
| `columns` | Grid columns (1-4) | 3 |

### Subscribe Button
```
[spp_subscribe_button plan_id="PLAN_ID" text="Subscribe"]
```
Button to subscribe to a specific plan.

**Parameters:**
| Parameter | Description | Default |
|-----------|-------------|---------|
| `plan_id` | Square plan ID | Required |
| `text` | Button text | "Subscribe" |

### My Subscriptions
```
[spp_my_subscriptions show_history="true"]
```
Displays logged-in client's subscriptions.

**Parameters:**
| Parameter | Description | Default |
|-----------|-------------|---------|
| `show_history` | Show billing history | "false" |

---

## Client Portal Guide

### Registration
1. Navigate to the Client Portal page
2. Click **Create Account**
3. Enter name, email, and password
4. Verify email if required
5. Log in to access the portal

### Managing Payment Methods
1. Go to **My Payment Methods**
2. Click **Add New Card**
3. Enter card details (processed securely by Square)
4. Card is saved for future payments

### Managing Subscriptions
Clients can self-manage their subscriptions:

| Action | Description |
|--------|-------------|
| **View Details** | See plan info, billing schedule, payment method |
| **Change Plan** | Upgrade or downgrade to different plan |
| **Update Card** | Change payment method for subscription |
| **Pause** | Temporarily stop billing (if enabled) |
| **Resume** | Restart a paused subscription |
| **Cancel** | Cancel at end of current billing period |
| **View History** | See all past payments |

### Paying Invoices
1. Go to **My Invoices**
2. Click on an unpaid invoice
3. Review invoice details
4. Click **Pay Now** to pay with saved card or new card

---

## WooCommerce Integration

When WooCommerce is installed and active, Cube Payment Portal automatically registers as a payment gateway.

### Enabling at Checkout
1. Go to **WooCommerce → Settings → Payments**
2. Enable **Cube Payment Portal**
3. Configure gateway settings

### Features
- Pay with saved cards from portal account
- Save new cards during checkout
- Digital wallets (Apple Pay, Google Pay)
- WooCommerce Subscriptions compatibility

### Shared Customer Data
- WooCommerce customers linked to portal accounts
- Saved cards available at checkout
- Order history synced with portal

---

## Subscription Plans

### Creating Plans (WordPress)
1. Go to **Square Portal → Subscription Plans**
2. Click **Add New Plan**
3. Configure:
   - Plan name and description
   - Price and billing frequency
   - Free trial period (optional)
4. Save and sync to Square

### Creating Plans (Square Dashboard)
Plans created in Square Dashboard automatically sync to WordPress via webhooks.

### Billing Frequencies
- Weekly
- Monthly
- Quarterly
- Annually

### Plan Changes
When clients change plans, proration is handled according to your settings:
- **Prorate Immediately** - Credit remaining time, charge new plan
- **Change at Renewal** - Continue current plan until next billing date
- **No Proration** - Charge full new plan price immediately

---

## Invoicing

### Creating Invoices
1. Go to **Square Portal → Invoices**
2. Click **Create Invoice**
3. Select client
4. Add line items
5. Set due date
6. Click **Send**

### Invoice Features
- Multiple line items per invoice
- Automatic email delivery
- Payment reminder emails
- Auto-pay from saved cards (if enabled)
- Manual payment recording

### Invoice Statuses
| Status | Description |
|--------|-------------|
| Draft | Not yet sent |
| Unpaid | Sent, awaiting payment |
| Partially Paid | Partial payment received |
| Paid | Fully paid |
| Canceled | Invoice canceled |
| Refunded | Payment refunded |

---

## Multi-Location Support

For businesses with multiple Square locations.

### Enabling
1. Go to **Settings → Location Settings**
2. Toggle **Enable Multi-Location**
3. Click **Sync Locations** to fetch from Square
4. Configure location assignment method

### Location Assignment Options
| Option | Description |
|--------|-------------|
| Admin Assigns | Only admins set client locations |
| Client Selects | Clients choose during registration |
| WooCommerce Based | Based on billing/shipping address |
| Auto-detect | Nearest location using geo-data |

### Dashboard Filtering
When enabled, the Owner Dashboard allows filtering all reports by location.

---

## Webhooks

The plugin automatically handles Square webhooks for real-time updates.

### Webhook URL
Your webhook URL is displayed in **Settings → Webhook Configuration**.

### Monitored Events
- `payment.completed` - Payment successful
- `payment.failed` - Payment failed
- `refund.created` - Refund processed
- `subscription.created` - New subscription
- `subscription.updated` - Subscription changed
- `invoice.payment_made` - Invoice paid
- `catalog.version.updated` - Plan changes

### Setup in Square
1. Go to [Square Developer Dashboard](https://developer.squareup.com)
2. Select your application
3. Go to **Webhooks**
4. Add your webhook URL
5. Select events to monitor
6. Copy signature key to plugin settings

---

## Security

### PCI Compliance
Card data is tokenized client-side using Square's Web Payments SDK. Card numbers never touch your server.

### Data Protection
- OAuth tokens encrypted using WordPress salts
- All forms use WordPress nonces
- Role-based access control
- HTTPS required for all operations

### User Roles
| Role | Capabilities |
|------|--------------|
| `spp_client` | Access client portal, manage own data |
| `administrator` | Full access to all features |

---

## Troubleshooting

### Connection Issues
**"Failed to connect to Square"**
- Verify SSL certificate is valid
- Check Application ID is correct
- Ensure OAuth credentials match environment

### Payment Failures
**"Card declined"**
- Card may have insufficient funds
- Card may be expired
- Bank may be blocking transaction

**"Invalid card token"**
- Page may have been open too long (tokens expire)
- Refresh page and try again

### Sync Issues
**"Plans not syncing"**
- Check webhook URL is configured in Square
- Verify signature key matches
- Check cron jobs are running

### WooCommerce Not Showing
**"Cube Payment Portal not available at checkout"**
- Ensure WooCommerce is active
- Check gateway is enabled in WooCommerce settings
- Verify SSL is active

---

## Hooks & Filters

### Actions
```php
// After client registration
do_action('spp_client_registered', $user_id, $square_customer_id);

// After payment processed
do_action('spp_payment_completed', $payment_id, $user_id, $amount);

// After subscription created
do_action('spp_subscription_created', $subscription_id, $user_id);

// After subscription canceled
do_action('spp_subscription_canceled', $subscription_id, $user_id);
```

### Filters
```php
// Modify client portal output
apply_filters('spp_portal_content', $content, $user_id);

// Customize subscription plans display
apply_filters('spp_subscription_plans_args', $args);

// Disable plugin CSS
add_filter('spp_disable_styles', '__return_true');

// Modify payment form fields
apply_filters('spp_payment_form_fields', $fields);
```

---

## CSS Customization

The plugin uses minimal "skeleton" CSS to inherit your theme's styling. To customize:

### CSS Variables
```css
:root {
    --spp-spacing-sm: 0.5rem;
    --spp-spacing-md: 1rem;
    --spp-spacing-lg: 2rem;
    --spp-border-radius: inherit;
    --spp-transition: 0.2s ease;
}
```

### Disable Plugin CSS
```php
add_filter('spp_disable_styles', '__return_true');
```

Then enqueue your own stylesheet with custom styles.

---

## Frequently Asked Questions

**Q: Is this plugin PCI compliant?**
A: Yes. Card data is tokenized by Square's SDK before reaching your server. You never handle raw card numbers.

**Q: Can clients change their subscription plan?**
A: Yes. Clients can upgrade or downgrade plans directly from their portal. Business owners are notified but approval is not required.

**Q: Does this work with WooCommerce Subscriptions?**
A: Yes. The plugin is compatible with WooCommerce Subscriptions for recurring product purchases.

**Q: What happens when a subscription payment fails?**
A: Square automatically sends an invoice with a payment link to the customer's email.

**Q: Can I use this without WooCommerce?**
A: Absolutely. The plugin works as a standalone payment portal. WooCommerce integration is optional.

**Q: How do I test without processing real payments?**
A: Enable Sandbox Mode in settings and use Square's test card numbers.

---

## Support

- **Documentation**: See `/docs` folder for detailed guides
- **Issues**: Report bugs via the plugin support forum
- **Square API**: [Square Developer Documentation](https://developer.squareup.com/docs)

---

## Changelog

### 1.1.5
- Security: Added Subresource Integrity (SRI) hashes for all CDN-loaded scripts
- Security: Fixed potential XSS in error message display (switched .html() to .text())
- Security: Wrapped debug logging calls with WP_DEBUG checks
- Improved: Client portal glassmorphism card design and hover states
- Improved: Password strength indicator on registration form
- Improved: Inline form validation for profile fields
- Improved: Unsaved changes warning when navigating away from profile edits
- Improved: Result count display after filtering subscriptions and invoices
- Improved: Country dropdown replaced text input on profile page
- Improved: Select element styling with proper padding and text visibility
- Improved: Invoice card hover effect now covers full row including Pay Now button
- Added: Auto-update toggle in plugin settings and on the Plugins page
- Added: Plugin update status display on the General settings tab
- Fixed: Profile save handler now collects select element values
- Fixed: Dark mode CSS changed from auto-detect to opt-in only

### 1.1.4
- Added: Booking management with Square Appointments integration
- Added: Loyalty program points display in client portal
- Added: Gift card management and balance checking
- Added: Dispute tracking and management dashboard
- Added: Notification system with appointment and invoice reminders
- Added: Menu integration with smart auto-detection and 3-tier fallback
- Added: Privacy and GDPR compliance (data exporter/eraser)

### 1.1.0
- Added: WooCommerce payment gateway integration
- Added: Subscription plan management with bidirectional Square sync
- Added: Invoice sync and client-facing payment links
- Added: Catalog and inventory sync from Square
- Added: Feature toggle system for modular functionality
- Added: Webhook processing for real-time Square events

### 1.0.0
- Initial release
- Client portal with login, registration, and dashboard
- Payment processing via Square Web Payments SDK
- Customer management with Square sync
- Transaction history and receipt viewing
- Payment method (card) management
- OAuth 2.0 authorization flow
- Sandbox mode for testing

---

## License

This plugin is licensed under the GPL-2.0 License. See `LICENSE` file for details.
