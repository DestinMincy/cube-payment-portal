<?php
/**
 * Client Profile view.
 *
 * Displays user profile info and allows basic updates.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user = wp_get_current_user();
$display_name = $user->display_name;
$first_name = $user->first_name;
$last_name = $user->last_name;
$email = $user->user_email;
$phone = get_user_meta( $user->ID, 'billing_phone', true );
$square_customer_id = get_user_meta( $user->ID, 'spp_square_customer_id', true );
$custom_avatar  = get_user_meta( $user->ID, 'spp_custom_avatar', true );
$avatar_url     = ! empty( $custom_avatar ) ? $custom_avatar : get_avatar_url( $user->ID, array( 'size' => 120, 'default' => 'mystery' ) );
$has_custom_avatar = ! empty( $custom_avatar );

// Fetch enriched customer data from local DB (synced from Square).
$company      = '';
$birthday     = '';
$address_line = '';
$address_city = '';
$address_state = '';
$address_zip  = '';
$address_country = '';

if ( ! empty( $square_customer_id ) ) {
    if ( ! class_exists( 'SPP_Database' ) ) {
        require_once SPP_PLUGIN_DIR . 'database/class-spp-database.php';
    }

    $customer_data = SPP_Database::get_customer_by_square_id( $square_customer_id );
    if ( $customer_data ) {
        $phone           = $phone ?: ( $customer_data['phone'] ?? '' );
        $company         = $customer_data['company_name'] ?? '';
        $birthday        = $customer_data['birthday'] ?? '';
        $address_line    = $customer_data['address_line_1'] ?? '';
        $address_city    = $customer_data['address_city'] ?? '';
        $address_state   = $customer_data['address_state'] ?? '';
        $address_zip     = $customer_data['address_postal_code'] ?? '';
        $address_country = $customer_data['address_country'] ?? '';
    }

    // If local DB is missing address, try Square API directly.
    if ( empty( $address_line ) && empty( $address_city ) ) {
        if ( ! class_exists( 'SPP_Customers' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-customers.php';
        }
        $customers_api   = new SPP_Customers();
        $square_customer = $customers_api->get_customer( $square_customer_id );
        if ( ! is_wp_error( $square_customer ) ) {
            $sq_addr         = $square_customer['address'] ?? array();
            $address_line    = $sq_addr['address_line_1'] ?? '';
            $address_city    = $sq_addr['locality'] ?? '';
            $address_state   = $sq_addr['administrative_district_level_1'] ?? '';
            $address_zip     = $sq_addr['postal_code'] ?? '';
            $address_country = $sq_addr['country'] ?? '';
            $company         = $company ?: ( $square_customer['company_name'] ?? '' );
            $phone           = $phone ?: ( $square_customer['phone_number'] ?? '' );
            $birthday        = $square_customer['birthday'] ?? '';
        }
    }
}

// Format phone number for display: +1 (555) 123-4567.
$phone_display = '';
if ( ! empty( $phone ) ) {
    $digits = preg_replace( '/\D/', '', $phone );
    if ( strlen( $digits ) === 10 ) {
        $phone_display = '+1 (' . substr( $digits, 0, 3 ) . ') ' . substr( $digits, 3, 3 ) . '-' . substr( $digits, 6 );
    } elseif ( strlen( $digits ) === 11 && $digits[0] === '1' ) {
        $phone_display = '+1 (' . substr( $digits, 1, 3 ) . ') ' . substr( $digits, 4, 3 ) . '-' . substr( $digits, 7 );
    } else {
        $phone_display = $phone; // Non-US or already formatted — show as-is.
    }
}

?>

<div class="spp-portal__section">
    <h2 class="spp-portal__subtitle spp-mb-5"><?php esc_html_e( 'My Profile', 'cube-payment-portal' ); ?></h2>

    <!-- Profile Header Card -->
    <div class="spp-portal__card spp-mb-5">
        <div class="spp-flex spp-items-center spp-gap-4">
            <div class="spp-avatar-upload" id="spp-avatar-upload">
                <div class="spp-avatar-upload__preview spp-portal__avatar spp-portal__avatar--xl">
                    <img src="<?php echo esc_url( $avatar_url ); ?>" alt="<?php echo esc_attr( $display_name ); ?>" class="spp-portal__avatar-img" id="spp-avatar-img" />
                    <div class="spp-avatar-upload__overlay">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
                    </div>
                </div>
                <input type="file" id="spp-avatar-input" accept="image/jpeg,image/png,image/gif,image/webp" class="spp-avatar-upload__input" />
                <?php if ( $has_custom_avatar ) : ?>
                    <a href="#" class="spp-avatar-upload__remove" id="spp-avatar-remove"><?php esc_html_e( 'Remove photo', 'cube-payment-portal' ); ?></a>
                <?php else : ?>
                    <a href="#" class="spp-avatar-upload__remove" id="spp-avatar-remove" style="display: none;"><?php esc_html_e( 'Remove photo', 'cube-payment-portal' ); ?></a>
                <?php endif; ?>
            </div>
            <div style="flex: 1; min-width: 0;">
                <h3 style="margin: 0 0 4px 0; font-size: var(--spp-text-xl); font-weight: 600;"><?php echo esc_html( $display_name ); ?></h3>
                <?php if ( ! empty( $company ) ) : ?>
                    <p class="spp-portal__text--sm" style="margin: 0 0 2px 0; color: var(--spp-text-main);">
                        <?php echo esc_html( $company ); ?>
                    </p>
                <?php endif; ?>
                <p class="spp-portal__text--muted spp-portal__text--sm" style="margin: 0;">
                    <?php
                    echo esc_html( $email );
                    if ( ! empty( $phone_display ) ) {
                        echo ' &bull; ' . esc_html( $phone_display );
                    }
                    ?>
                </p>
                <p class="spp-portal__text--muted spp-portal__text--xs" style="margin: 4px 0 0;">
                    <?php
                    printf(
                        /* translators: %s: date */
                        esc_html__( 'Member since %s', 'cube-payment-portal' ),
                        esc_html( SPP_Client_Portal::format_date( $user->user_registered ) )
                    );
                    ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Contact Information Card -->
    <form id="spp-profile-contact-form" class="spp-profile-form">
        <div class="spp-portal__card spp-mb-5">
            <div class="spp-flex spp-justify-between spp-items-center" style="margin-bottom: var(--spp-space-4);">
                <h4 style="margin: 0; font-size: var(--spp-text-sm); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--spp-text-muted);">
                    <?php esc_html_e( 'Contact Information', 'cube-payment-portal' ); ?>
                </h4>
                <button type="submit" class="spp-portal__btn spp-portal__btn--primary spp-portal__btn--sm spp-save-profile-btn">
                    <?php esc_html_e( 'Save Changes', 'cube-payment-portal' ); ?>
                </button>
            </div>
            <div class="spp-portal__grid spp-portal__grid--2" style="gap: var(--spp-space-4);">
                <div>
                    <label for="spp-profile-first-name" class="spp-portal__text--xs spp-portal__text--muted" style="display: block; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 500;">
                        <?php esc_html_e( 'First Name', 'cube-payment-portal' ); ?>
                    </label>
                    <input type="text" id="spp-profile-first-name" name="first_name"
                           value="<?php echo esc_attr( $first_name ); ?>"
                           placeholder="<?php esc_attr_e( 'Enter first name', 'cube-payment-portal' ); ?>"
                           class="spp-portal__input" />
                </div>

                <div>
                    <label for="spp-profile-last-name" class="spp-portal__text--xs spp-portal__text--muted" style="display: block; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 500;">
                        <?php esc_html_e( 'Last Name', 'cube-payment-portal' ); ?>
                    </label>
                    <input type="text" id="spp-profile-last-name" name="last_name"
                           value="<?php echo esc_attr( $last_name ); ?>"
                           placeholder="<?php esc_attr_e( 'Enter last name', 'cube-payment-portal' ); ?>"
                           class="spp-portal__input" />
                </div>

                <div>
                    <label for="spp-profile-email" class="spp-portal__text--xs spp-portal__text--muted" style="display: block; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 500;">
                        <?php esc_html_e( 'Email Address', 'cube-payment-portal' ); ?>
                    </label>
                    <input type="email" id="spp-profile-email" name="email"
                           value="<?php echo esc_attr( $email ); ?>"
                           placeholder="<?php esc_attr_e( 'Enter email address', 'cube-payment-portal' ); ?>"
                           class="spp-portal__input" />
                </div>

                <div>
                    <label for="spp-profile-phone" class="spp-portal__text--xs spp-portal__text--muted" style="display: block; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 500;">
                        <?php esc_html_e( 'Phone', 'cube-payment-portal' ); ?>
                    </label>
                    <input type="tel" id="spp-profile-phone" name="phone"
                           class="spp-phone-input spp-portal__input"
                           value="<?php echo esc_attr( $phone_display ?: $phone ); ?>"
                           placeholder="+1 (555) 123-4567" />
                </div>

                <div>
                    <label for="spp-profile-company" class="spp-portal__text--xs spp-portal__text--muted" style="display: block; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 500;">
                        <?php esc_html_e( 'Company', 'cube-payment-portal' ); ?>
                    </label>
                    <input type="text" id="spp-profile-company" name="company"
                           value="<?php echo esc_attr( $company ); ?>"
                           placeholder="<?php esc_attr_e( 'Enter company name', 'cube-payment-portal' ); ?>"
                           class="spp-portal__input" />
                </div>

                <div>
                    <label for="spp-profile-birthday" class="spp-portal__text--xs spp-portal__text--muted" style="display: block; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 500;">
                        <?php esc_html_e( 'Birthday', 'cube-payment-portal' ); ?>
                    </label>
                    <input type="date" id="spp-profile-birthday" name="birthday"
                           value="<?php echo esc_attr( $birthday ); ?>"
                           class="spp-portal__input" />
                </div>
            </div>
        </div>
    </form>

    <!-- Address Card -->
    <form id="spp-profile-address-form" class="spp-profile-form">
        <div class="spp-portal__card spp-mb-5">
            <div class="spp-flex spp-justify-between spp-items-center" style="margin-bottom: var(--spp-space-4);">
                <h4 style="margin: 0; font-size: var(--spp-text-sm); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--spp-text-muted);">
                    <?php esc_html_e( 'Address', 'cube-payment-portal' ); ?>
                </h4>
                <button type="submit" class="spp-portal__btn spp-portal__btn--primary spp-portal__btn--sm spp-save-profile-btn">
                    <?php esc_html_e( 'Save Changes', 'cube-payment-portal' ); ?>
                </button>
            </div>
            <div class="spp-portal__grid spp-portal__grid--2" style="gap: var(--spp-space-4);">
                <div style="grid-column: 1 / -1;">
                    <label for="spp-profile-address-line" class="spp-portal__text--xs spp-portal__text--muted" style="display: block; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 500;">
                        <?php esc_html_e( 'Street Address', 'cube-payment-portal' ); ?>
                    </label>
                    <input type="text" id="spp-profile-address-line" name="address_line"
                           value="<?php echo esc_attr( $address_line ); ?>"
                           placeholder="<?php esc_attr_e( 'Enter street address', 'cube-payment-portal' ); ?>"
                           class="spp-portal__input" />
                </div>

                <div>
                    <label for="spp-profile-address-city" class="spp-portal__text--xs spp-portal__text--muted" style="display: block; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 500;">
                        <?php esc_html_e( 'City', 'cube-payment-portal' ); ?>
                    </label>
                    <input type="text" id="spp-profile-address-city" name="address_city"
                           value="<?php echo esc_attr( $address_city ); ?>"
                           placeholder="<?php esc_attr_e( 'Enter city', 'cube-payment-portal' ); ?>"
                           class="spp-portal__input" />
                </div>

                <div>
                    <label for="spp-profile-address-state" class="spp-portal__text--xs spp-portal__text--muted" style="display: block; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 500;">
                        <?php esc_html_e( 'State / Province', 'cube-payment-portal' ); ?>
                    </label>
                    <input type="text" id="spp-profile-address-state" name="address_state"
                           value="<?php echo esc_attr( $address_state ); ?>"
                           placeholder="<?php esc_attr_e( 'Enter state', 'cube-payment-portal' ); ?>"
                           class="spp-portal__input" />
                </div>

                <div>
                    <label for="spp-profile-address-zip" class="spp-portal__text--xs spp-portal__text--muted" style="display: block; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 500;">
                        <?php esc_html_e( 'ZIP / Postal Code', 'cube-payment-portal' ); ?>
                    </label>
                    <input type="text" id="spp-profile-address-zip" name="address_zip"
                           value="<?php echo esc_attr( $address_zip ); ?>"
                           placeholder="<?php esc_attr_e( 'Enter ZIP code', 'cube-payment-portal' ); ?>"
                           class="spp-portal__input" />
                </div>

                <div>
                    <label for="spp-profile-address-country" class="spp-portal__text--xs spp-portal__text--muted" style="display: block; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 500;">
                        <?php esc_html_e( 'Country', 'cube-payment-portal' ); ?>
                    </label>
                    <?php
                    $spp_countries = array(
                        'US' => __( 'United States (US)', 'cube-payment-portal' ),
                        'CA' => __( 'Canada (CA)', 'cube-payment-portal' ),
                        'GB' => __( 'United Kingdom (GB)', 'cube-payment-portal' ),
                        'AU' => __( 'Australia (AU)', 'cube-payment-portal' ),
                        'NZ' => __( 'New Zealand (NZ)', 'cube-payment-portal' ),
                        'IE' => __( 'Ireland (IE)', 'cube-payment-portal' ),
                        'FR' => __( 'France (FR)', 'cube-payment-portal' ),
                        'DE' => __( 'Germany (DE)', 'cube-payment-portal' ),
                        'ES' => __( 'Spain (ES)', 'cube-payment-portal' ),
                        'IT' => __( 'Italy (IT)', 'cube-payment-portal' ),
                        'NL' => __( 'Netherlands (NL)', 'cube-payment-portal' ),
                        'JP' => __( 'Japan (JP)', 'cube-payment-portal' ),
                        'MX' => __( 'Mexico (MX)', 'cube-payment-portal' ),
                        'BR' => __( 'Brazil (BR)', 'cube-payment-portal' ),
                        'IN' => __( 'India (IN)', 'cube-payment-portal' ),
                    );
                    ?>
                    <select id="spp-profile-address-country" name="address_country"
                            class="spp-portal__select">
                        <option value=""><?php esc_html_e( 'Select a country...', 'cube-payment-portal' ); ?></option>
                        <?php foreach ( $spp_countries as $code => $label ) : ?>
                            <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $address_country, $code ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </form>

    <!-- Account Card -->
    <div class="spp-portal__card">
        <h4 style="margin: 0 0 var(--spp-space-4) 0; font-size: var(--spp-text-sm); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--spp-text-muted);">
            <?php esc_html_e( 'Account', 'cube-payment-portal' ); ?>
        </h4>

        <?php if ( ! empty( $square_customer_id ) ) : ?>
            <div class="spp-flex spp-justify-between spp-items-center" style="padding: 12px 0; border-bottom: 1px solid var(--spp-border-light);">
                <span class="spp-portal__text--sm spp-portal__text--muted"><?php esc_html_e( 'Customer ID', 'cube-payment-portal' ); ?></span>
                <code style="background: var(--spp-bg-tertiary); padding: 4px 10px; border-radius: 6px; font-size: 12px; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"
                      title="<?php echo esc_attr( $square_customer_id ); ?>">
                    <?php echo esc_html( $square_customer_id ); ?>
                </code>
            </div>
        <?php endif; ?>

        <div class="spp-flex spp-justify-between spp-items-center" style="padding: 12px 0;">
            <span class="spp-portal__text--sm spp-portal__text--muted"><?php esc_html_e( 'Password', 'cube-payment-portal' ); ?></span>
            <a href="<?php echo esc_url( wp_lostpassword_url( get_permalink() ) ); ?>"
               class="spp-portal__btn spp-portal__btn--outline spp-portal__btn--sm">
                <?php esc_html_e( 'Change Password', 'cube-payment-portal' ); ?>
            </a>
        </div>
    </div>

    <!-- Display Preferences Card -->
    <?php
    $wp_date_format = get_option( 'date_format', 'F j, Y' );
    $wp_time_format = get_option( 'time_format', 'g:i a' );
    $user_date_format = get_user_meta( $user->ID, 'spp_date_format', true );
    $user_time_format = get_user_meta( $user->ID, 'spp_time_format', true );
    // Fall back to WordPress settings if user hasn't chosen.
    if ( empty( $user_date_format ) ) {
        $user_date_format = $wp_date_format;
    }
    if ( empty( $user_time_format ) ) {
        $user_time_format = $wp_time_format;
    }

    $date_formats = array(
        'M j, Y'  => 'Jan 1, 2026',
        'F j, Y'  => 'January 1, 2026',
        'm/d/Y'   => '01/01/2026 (MM/DD/YYYY)',
        'd/m/Y'   => '01/01/2026 (DD/MM/YYYY)',
        'Y-m-d'   => '2026-01-01 (YYYY-MM-DD)',
    );
    $time_formats = array(
        'g:i a' => '1:30 pm (12-hour)',
        'g:i A' => '1:30 PM (12-hour)',
        'H:i'   => '13:30 (24-hour)',
    );
    ?>
    <div class="spp-portal__card spp-mt-5" id="spp-display-prefs">
        <div class="spp-flex spp-justify-between spp-items-center" style="margin-bottom: var(--spp-space-4);">
            <h4 style="margin: 0; font-size: var(--spp-text-sm); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--spp-text-muted);">
                <?php esc_html_e( 'Display Preferences', 'cube-payment-portal' ); ?>
            </h4>
            <button type="button" class="spp-portal__btn spp-portal__btn--primary spp-portal__btn--sm" id="spp-save-display-prefs">
                <?php esc_html_e( 'Save Preferences', 'cube-payment-portal' ); ?>
            </button>
        </div>

        <div class="spp-portal__grid spp-portal__grid--2" style="gap: var(--spp-space-4);">
            <div>
                <label for="spp-pref-date-format" class="spp-portal__text--xs spp-portal__text--muted" style="display: block; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 500;">
                    <?php esc_html_e( 'Date Format', 'cube-payment-portal' ); ?>
                </label>
                <select id="spp-pref-date-format" name="date_format" class="spp-portal__select">
                    <?php foreach ( $date_formats as $format => $example ) : ?>
                        <option value="<?php echo esc_attr( $format ); ?>" <?php selected( $user_date_format, $format ); ?>>
                            <?php echo esc_html( wp_date( $format ) . ' — ' . $example ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="spp-pref-time-format" class="spp-portal__text--xs spp-portal__text--muted" style="display: block; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 500;">
                    <?php esc_html_e( 'Time Format', 'cube-payment-portal' ); ?>
                </label>
                <select id="spp-pref-time-format" name="time_format" class="spp-portal__select">
                    <?php foreach ( $time_formats as $format => $example ) : ?>
                        <option value="<?php echo esc_attr( $format ); ?>" <?php selected( $user_time_format, $format ); ?>>
                            <?php echo esc_html( wp_date( $format ) . ' — ' . $example ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <p class="spp-portal__text--muted spp-portal__text--xs" style="margin: var(--spp-space-3) 0 0 0;">
            <?php esc_html_e( 'Controls how dates and times are displayed throughout the portal.', 'cube-payment-portal' ); ?>
        </p>
    </div>

    <!-- Notification Preferences Card -->
    <div class="spp-portal__card spp-mt-5" id="spp-notification-prefs">
        <div class="spp-flex spp-justify-between spp-items-center" style="margin-bottom: var(--spp-space-4);">
            <h4 style="margin: 0; font-size: var(--spp-text-sm); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--spp-text-muted);">
                <?php esc_html_e( 'Notification Preferences', 'cube-payment-portal' ); ?>
            </h4>
            <button type="button" class="spp-portal__btn spp-portal__btn--primary spp-portal__btn--sm" id="spp-save-notification-prefs">
                <?php esc_html_e( 'Save Preferences', 'cube-payment-portal' ); ?>
            </button>
        </div>

        <p class="spp-portal__text--muted spp-portal__text--sm" style="margin: 0 0 var(--spp-space-5) 0;">
            <?php esc_html_e( 'Choose which email notifications you would like to receive.', 'cube-payment-portal' ); ?>
        </p>

        <?php
        $notification_types = array();

        // Always show payment receipts.
        $notification_types['payment_receipts'] = array(
            'label' => __( 'Payment Receipts', 'cube-payment-portal' ),
            'desc'  => __( 'Confirmation emails after a payment is processed.', 'cube-payment-portal' ),
        );

        if ( get_option( 'spp_feature_invoices', '1' ) ) {
            $notification_types['invoice_reminders'] = array(
                'label' => __( 'Invoice Reminders', 'cube-payment-portal' ),
                'desc'  => __( 'Reminders when invoices are due or overdue.', 'cube-payment-portal' ),
            );
        }

        if ( get_option( 'spp_feature_subscriptions', '1' ) ) {
            $notification_types['subscription_updates'] = array(
                'label' => __( 'Subscription Updates', 'cube-payment-portal' ),
                'desc'  => __( 'Notifications about renewals, changes, or upcoming charges.', 'cube-payment-portal' ),
            );
        }

        if ( get_option( 'spp_feature_bookings', '1' ) ) {
            $notification_types['appointment_reminders'] = array(
                'label' => __( 'Appointment Reminders', 'cube-payment-portal' ),
                'desc'  => __( 'Confirmations and reminders for upcoming appointments.', 'cube-payment-portal' ),
            );
        }

        if ( get_option( 'spp_feature_loyalty', '1' ) ) {
            $notification_types['loyalty_updates'] = array(
                'label' => __( 'Loyalty & Rewards', 'cube-payment-portal' ),
                'desc'  => __( 'Notifications when you earn points or unlock rewards.', 'cube-payment-portal' ),
            );
        }

        $notification_types['promotional'] = array(
            'label' => __( 'Promotions & Offers', 'cube-payment-portal' ),
            'desc'  => __( 'Special deals, discounts, and promotional announcements.', 'cube-payment-portal' ),
        );

        foreach ( $notification_types as $key => $type ) :
            $meta_key = 'spp_notify_' . $key;
            $enabled  = get_user_meta( $user->ID, $meta_key, true );
            // Default to enabled if meta not set (opt-out model).
            $is_on = ( '' === $enabled || '1' === $enabled );
            ?>
            <div class="spp-flex spp-justify-between spp-items-center" style="<?php echo esc_attr( 'padding: 14px 0;' . ( ( $key !== 'promotional' ) ? ' border-bottom: 1px solid var(--spp-border-light);' : '' ) ); ?>">
                <div style="flex: 1; min-width: 0; padding-right: var(--spp-space-4);">
                    <span style="font-size: var(--spp-text-sm); font-weight: 500; display: block; margin-bottom: 2px;">
                        <?php echo esc_html( $type['label'] ); ?>
                    </span>
                    <span class="spp-portal__text--muted spp-portal__text--xs">
                        <?php echo esc_html( $type['desc'] ); ?>
                    </span>
                </div>
                <label class="spp-toggle" style="flex-shrink: 0;">
                    <input type="checkbox" class="spp-toggle__input spp-notification-toggle"
                           name="<?php echo esc_attr( $key ); ?>"
                           <?php checked( $is_on ); ?> />
                    <span class="spp-toggle__slider"></span>
                </label>
            </div>
        <?php endforeach; ?>
    </div>
</div>
