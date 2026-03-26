<?php
/**
 * Notification handler for scheduled email reminders.
 *
 * Sends appointment and invoice reminder emails via wp_mail().
 * Called by WordPress cron hooks.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Notifications
 *
 * Handles appointment and invoice reminder emails.
 */
class SPP_Notifications {

    /**
     * Send appointment reminders for bookings starting within the configured window.
     *
     * Called by WP cron hook `spp_send_appointment_reminders`.
     */
    public function send_appointment_reminders() {
        if ( ! get_option( 'spp_reminder_appointment_enabled', true ) ) {
            return;
        }

        // Transient lock to prevent overlapping runs.
        $lock_key = 'spp_appt_reminder_lock';
        if ( get_transient( $lock_key ) ) {
            return;
        }
        set_transient( $lock_key, true, 300 );

        $frequency_hours = (int) get_option( 'spp_reminder_frequency_hours', 24 );
        $now             = time();
        $window_end      = $now + ( $frequency_hours * HOUR_IN_SECONDS );

        // Get all WP users with linked Square customer IDs.
        $users = get_users( array(
            'meta_key'   => 'spp_square_customer_id',
            'meta_compare' => 'EXISTS',
            'fields'     => array( 'ID' ),
        ) );

        if ( empty( $users ) ) {
            delete_transient( $lock_key );
            return;
        }

        if ( ! class_exists( 'SPP_Bookings' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-bookings.php';
        }

        $bookings_api = new SPP_Bookings();
        $site_name    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

        foreach ( $users as $user_obj ) {
            $user_id = $user_obj->ID;

            // Check user's notification preference (opt-out model).
            $pref = get_user_meta( $user_id, 'spp_notify_appointment_reminders', true );
            if ( '0' === $pref ) {
                continue;
            }

            $square_customer_id = get_user_meta( $user_id, 'spp_square_customer_id', true );
            if ( empty( $square_customer_id ) ) {
                continue;
            }

            // Fetch bookings starting within the reminder window.
            $result = $bookings_api->list_bookings( array(
                'customer_id'  => $square_customer_id,
                'start_at_min' => gmdate( 'Y-m-d\TH:i:s\Z', $now ),
                'start_at_max' => gmdate( 'Y-m-d\TH:i:s\Z', $window_end ),
            ) );

            if ( is_wp_error( $result ) ) {
                continue;
            }

            $bookings = $result['bookings'] ?? array();
            if ( empty( $bookings ) ) {
                continue;
            }

            $user = get_userdata( $user_id );
            if ( ! $user || empty( $user->user_email ) ) {
                continue;
            }

            foreach ( $bookings as $booking ) {
                $booking_id = $booking['id'] ?? '';
                $status     = $booking['status'] ?? '';

                // Only remind for active bookings.
                if ( ! in_array( $status, array( 'ACCEPTED', 'PENDING' ), true ) ) {
                    continue;
                }

                // Check if we already sent a reminder for this booking.
                $reminded_key = 'spp_reminded_booking_' . $booking_id;
                if ( get_transient( $reminded_key ) ) {
                    continue;
                }

                $start_at = $booking['start_at'] ?? '';
                if ( empty( $start_at ) ) {
                    continue;
                }

                $start_time = strtotime( $start_at );
                $date_str   = wp_date( get_option( 'date_format' ), $start_time );
                $time_str   = wp_date( get_option( 'time_format' ), $start_time );

                // Build email.
                $display_name = ! empty( $user->first_name ) ? $user->first_name : $user->display_name;

                $subject = sprintf(
                    /* translators: %s: site name */
                    __( 'Appointment Reminder — %s', 'cube-payment-portal' ),
                    $site_name
                );

                $body = sprintf(
                    /* translators: %1$s: client name, %2$s: date, %3$s: time, %4$s: site name */
                    __(
                        "Hi %1\$s,\n\nThis is a reminder that you have an upcoming appointment:\n\nDate: %2\$s\nTime: %3\$s\n\nIf you need to make changes, please log in to your account or contact us.\n\nThank you,\n%4\$s",
                        'cube-payment-portal'
                    ),
                    $display_name,
                    $date_str,
                    $time_str,
                    $site_name
                );

                wp_mail( $user->user_email, $subject, $body );

                // Mark as reminded (TTL = frequency window to prevent re-sends).
                set_transient( $reminded_key, true, $frequency_hours * HOUR_IN_SECONDS );
            }
        }

        delete_transient( $lock_key );
        update_option( 'spp_last_appointment_reminder', time() );
    }

    /**
     * Send unpaid invoice reminders for overdue invoices.
     *
     * Called by WP cron hook `spp_send_invoice_reminders`.
     */
    public function send_invoice_reminders() {
        if ( ! get_option( 'spp_reminder_invoice_enabled', true ) ) {
            return;
        }

        // Transient lock to prevent overlapping runs.
        $lock_key = 'spp_invoice_reminder_lock';
        if ( get_transient( $lock_key ) ) {
            return;
        }
        set_transient( $lock_key, true, 300 );

        $frequency_hours = (int) get_option( 'spp_reminder_frequency_hours', 24 );

        global $wpdb;
        $table = $wpdb->prefix . 'spp_invoices';

        // Get overdue/unpaid invoices with due dates in the past.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table
        $invoices = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE status IN (%s, %s) AND due_date IS NOT NULL AND due_date <= %s",
                'UNPAID',
                'OVERDUE',
                current_time( 'Y-m-d' )
            ),
            ARRAY_A
        );

        if ( empty( $invoices ) ) {
            delete_transient( $lock_key );
            return;
        }

        $site_name  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        $portal_url = '';
        $portal_page_id = get_option( 'spp_portal_page_id' );
        if ( $portal_page_id ) {
            $portal_url = get_permalink( $portal_page_id );
        }

        foreach ( $invoices as $invoice ) {
            $invoice_id = $invoice['square_invoice_id'] ?? '';
            $user_id    = $invoice['user_id'] ?? 0;

            if ( empty( $invoice_id ) || empty( $user_id ) ) {
                continue;
            }

            // Check if we already sent a reminder recently.
            $today        = current_time( 'Y-m-d' );
            $reminded_key = 'spp_invoice_reminded_' . $invoice_id . '_' . $today;
            if ( get_transient( $reminded_key ) ) {
                continue;
            }

            // Check user's notification preference (opt-out model).
            $pref = get_user_meta( $user_id, 'spp_notify_invoice_reminders', true );
            if ( '0' === $pref ) {
                continue;
            }

            $user = get_userdata( $user_id );
            if ( ! $user || empty( $user->user_email ) ) {
                continue;
            }

            $display_name = ! empty( $user->first_name ) ? $user->first_name : $user->display_name;
            $amount       = number_format( (float) ( $invoice['amount'] ?? 0 ), 2 );
            $currency     = $invoice['currency'] ?? 'USD';
            $due_date     = ! empty( $invoice['due_date'] ) ? wp_date( get_option( 'date_format' ), strtotime( $invoice['due_date'] ) ) : '';

            $subject = sprintf(
                /* translators: %s: site name */
                __( 'Unpaid Invoice Reminder — %s', 'cube-payment-portal' ),
                $site_name
            );

            $body_parts = array(
                sprintf(
                    /* translators: %s: client name */
                    __( 'Hi %s,', 'cube-payment-portal' ),
                    $display_name
                ),
                '',
                __( 'You have an outstanding invoice that requires your attention:', 'cube-payment-portal' ),
                '',
                sprintf(
                    /* translators: %1$s: amount, %2$s: currency */
                    __( 'Amount: %1$s %2$s', 'cube-payment-portal' ),
                    $amount,
                    $currency
                ),
            );

            if ( $due_date ) {
                $body_parts[] = sprintf(
                    /* translators: %s: due date */
                    __( 'Due Date: %s', 'cube-payment-portal' ),
                    $due_date
                );
            }

            if ( $portal_url ) {
                $body_parts[] = '';
                $body_parts[] = sprintf(
                    /* translators: %s: portal URL */
                    __( 'Log in to your account to make a payment: %s', 'cube-payment-portal' ),
                    $portal_url
                );
            }

            $body_parts[] = '';
            $body_parts[] = sprintf(
                /* translators: %s: site name */
                __( 'Thank you,%s', 'cube-payment-portal' ),
                "\n" . $site_name
            );

            $body = implode( "\n", $body_parts );

            wp_mail( $user->user_email, $subject, $body );

            // Mark as reminded (TTL = frequency hours to prevent re-sends).
            set_transient( $reminded_key, true, $frequency_hours * HOUR_IN_SECONDS );
        }

        delete_transient( $lock_key );
        update_option( 'spp_last_invoice_reminder', time() );
    }
}
