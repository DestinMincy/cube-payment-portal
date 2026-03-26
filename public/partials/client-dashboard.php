<?php
/**
 * Client Dashboard - Overview view.
 *
 * Displays a welcome message, quick action shortcuts, and summary widgets.
 * Widgets load their data client-side via AJAX for fast initial render.
 * Feature flags and admin dashboard settings control visibility.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user = wp_get_current_user();
$display_name = ! empty( $user->first_name ) ? $user->first_name : $user->display_name;
$greeting = '';
$hour = (int) current_time( 'G' );
if ( $hour < 12 ) {
    $greeting = __( 'Good morning', 'cube-payment-portal' );
} elseif ( $hour < 17 ) {
    $greeting = __( 'Good afternoon', 'cube-payment-portal' );
} else {
    $greeting = __( 'Good evening', 'cube-payment-portal' );
}

// Feature flags.
$has_bookings      = (bool) get_option( 'spp_feature_bookings', false );
$has_invoices      = (bool) get_option( 'spp_feature_invoices', true );
$has_loyalty       = (bool) get_option( 'spp_feature_loyalty', false );
$has_subscriptions = (bool) get_option( 'spp_feature_subscriptions', true );
$has_gift_cards    = (bool) get_option( 'spp_feature_gift_cards', false );
$welcome_message   = get_option( 'spp_portal_welcome_message', '' );
if ( empty( $welcome_message ) ) {
    $welcome_message = __( 'Here\'s a summary of your account activity.', 'cube-payment-portal' );
}

// Dashboard customization settings.
$active_actions = get_option( 'spp_dashboard_quick_actions', array() );
$active_widgets = get_option( 'spp_dashboard_widgets', array() );
$widget_order   = get_option( 'spp_dashboard_widget_order', '' );

/**
 * Helper: check if a quick action is enabled.
 * Empty array = all enabled (default / never saved).
 */
function spp_action_enabled( $key, $active_actions ) {
    return empty( $active_actions ) || in_array( $key, $active_actions, true );
}

/**
 * Helper: check if a widget is enabled.
 * Empty array = all enabled (default / never saved).
 */
function spp_widget_enabled( $key, $active_widgets ) {
    return empty( $active_widgets ) || in_array( $key, $active_widgets, true );
}
?>

<div class="spp-portal__section">
    <!-- Welcome Card -->
    <div class="spp-portal__card spp-portal__card--gradient spp-mb-8">
        <h1 class="spp-portal__title" style="color: var(--spp-text-inverse); margin: 0 0 8px 0;">
            <?php
            printf(
                /* translators: %1$s: greeting, %2$s: user name */
                esc_html__( '%1$s, %2$s!', 'cube-payment-portal' ),
                esc_html( $greeting ),
                esc_html( $display_name )
            );
            ?>
        </h1>
        <p style="color: rgba(255,255,255,0.85); margin: 0;">
            <?php echo esc_html( $welcome_message ); ?>
        </p>
    </div>

    <!-- Quick Actions -->
    <?php
    // Build visible quick actions list (feature flag + admin setting).
    $quick_actions = array();

    if ( $has_bookings && spp_action_enabled( 'book_appointment', $active_actions ) ) {
        $quick_actions[] = array(
            'view'   => 'bookings',
            'params' => '{"action":"book"}',
            'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--spp-info)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>',
            'label'  => __( 'Book Appointment', 'cube-payment-portal' ),
        );
    }

    if ( $has_invoices && spp_action_enabled( 'pay_invoice', $active_actions ) ) {
        $quick_actions[] = array(
            'view'   => 'invoices',
            'params' => '{"filter":"UNPAID"}',
            'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--spp-warning)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="9" y1="15" x2="15" y2="15"></line></svg>',
            'label'  => __( 'Pay Invoice', 'cube-payment-portal' ),
        );
    }

    if ( $has_loyalty && spp_action_enabled( 'redeem_rewards', $active_actions ) ) {
        $quick_actions[] = array(
            'view'   => 'loyalty',
            'params' => '{"scrollTo":"redeem"}',
            'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>',
            'label'  => __( 'Redeem Rewards', 'cube-payment-portal' ),
        );
    }

    if ( spp_action_enabled( 'add_card', $active_actions ) ) {
        $quick_actions[] = array(
            'view'   => 'add-card',
            'params' => '',
            'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--spp-success)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="12" y1="9" x2="12" y2="17"></line><line x1="8" y1="13" x2="16" y2="13"></line></svg>',
            'label'  => __( 'Add Card', 'cube-payment-portal' ),
        );
    }

    if ( $has_gift_cards && spp_action_enabled( 'buy_gift_card', $active_actions ) ) {
        $quick_actions[] = array(
            'view'   => 'payment-methods',
            'params' => '{"action":"buy-gift-card"}',
            'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 12 20 22 4 22 4 12"></polyline><rect x="2" y="7" width="20" height="5"></rect><line x1="12" y1="22" x2="12" y2="7"></line><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"></path><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"></path></svg>',
            'label'  => __( 'Buy Gift Card', 'cube-payment-portal' ),
        );
    }

    if ( spp_action_enabled( 'transactions', $active_actions ) ) {
        $quick_actions[] = array(
            'view'   => 'transactions',
            'params' => '',
            'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>',
            'label'  => __( 'Transactions', 'cube-payment-portal' ),
        );
    }

    if ( class_exists( 'WooCommerce' ) && spp_action_enabled( 'wc_orders', $active_actions ) ) {
        $quick_actions[] = array(
            'view'   => 'wc-orders',
            'params' => '',
            'icon'   => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path><line x1="3" y1="6" x2="21" y2="6"></line><path d="M16 10a4 4 0 0 1-8 0"></path></svg>',
            'label'  => __( 'View Orders', 'cube-payment-portal' ),
        );
    }

    if ( ! empty( $quick_actions ) ) : ?>
    <h2 class="spp-portal__subtitle spp-mb-5"><?php esc_html_e( 'Quick Actions', 'cube-payment-portal' ); ?></h2>
    <div class="spp-quick-actions-grid">
        <?php foreach ( $quick_actions as $action ) : ?>
        <button type="button" class="spp-quick-action-btn spp-portal__card--interactive" data-spp-view="<?php echo esc_attr( $action['view'] ); ?>"<?php echo ! empty( $action['params'] ) ? ' data-spp-params=\'' . esc_attr( $action['params'] ) . '\'' : ''; ?>>
            <span aria-hidden="true"><?php echo $action['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG ?></span>
            <?php echo esc_html( $action['label'] ); ?>
        </button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Summary Widgets -->
    <?php
    // Define all available widgets with their feature flag and HTML.
    $all_widgets = array();

    if ( $has_invoices && spp_widget_enabled( 'outstanding-balance', $active_widgets ) ) {
        $all_widgets['outstanding-balance'] = array(
            'title'     => __( 'Outstanding Balance', 'cube-payment-portal' ),
            'link_view' => 'invoices',
            'link_text' => __( 'View all', 'cube-payment-portal' ),
        );
    }

    if ( spp_widget_enabled( 'payment-methods-summary', $active_widgets ) ) {
        $all_widgets['payment-methods-summary'] = array(
            'title'     => __( 'Payment Methods', 'cube-payment-portal' ),
            'link_view' => 'payment-methods',
            'link_text' => __( 'Manage', 'cube-payment-portal' ),
        );
    }

    if ( $has_bookings && spp_widget_enabled( 'upcoming-appointments', $active_widgets ) ) {
        $all_widgets['upcoming-appointments'] = array(
            'title'     => __( 'Upcoming Appointments', 'cube-payment-portal' ),
            'link_view' => 'bookings',
            'link_text' => __( 'View all', 'cube-payment-portal' ),
        );
    }

    if ( $has_invoices && spp_widget_enabled( 'recent-invoices', $active_widgets ) ) {
        $all_widgets['recent-invoices'] = array(
            'title'     => __( 'Recent Invoices', 'cube-payment-portal' ),
            'link_view' => 'invoices',
            'link_text' => __( 'View all', 'cube-payment-portal' ),
        );
    }

    if ( $has_subscriptions && spp_widget_enabled( 'subscription-status', $active_widgets ) ) {
        $all_widgets['subscription-status'] = array(
            'title'     => __( 'Subscriptions', 'cube-payment-portal' ),
            'link_view' => 'subscriptions',
            'link_text' => __( 'View all', 'cube-payment-portal' ),
        );
    }

    if ( $has_loyalty && spp_widget_enabled( 'loyalty-points', $active_widgets ) ) {
        $all_widgets['loyalty-points'] = array(
            'title'     => __( 'Loyalty Points', 'cube-payment-portal' ),
            'link_view' => 'loyalty',
            'link_text' => __( 'View all', 'cube-payment-portal' ),
        );
    }

    if ( class_exists( 'WooCommerce' ) && spp_widget_enabled( 'wc-recent-orders', $active_widgets ) ) {
        $all_widgets['wc-recent-orders'] = array(
            'title'     => __( 'Recent Orders', 'cube-payment-portal' ),
            'link_view' => 'wc-orders',
            'link_text' => __( 'View all', 'cube-payment-portal' ),
        );
    }

    // Apply custom widget order if set.
    if ( ! empty( $widget_order ) ) {
        $order_keys = array_map( 'trim', explode( ',', $widget_order ) );
        $ordered    = array();
        foreach ( $order_keys as $key ) {
            if ( isset( $all_widgets[ $key ] ) ) {
                $ordered[ $key ] = $all_widgets[ $key ];
                unset( $all_widgets[ $key ] );
            }
        }
        // Append any widgets not in the custom order at the end.
        $all_widgets = array_merge( $ordered, $all_widgets );
    }

    if ( ! empty( $all_widgets ) ) : ?>
    <h2 class="spp-portal__subtitle spp-mb-5"><?php esc_html_e( 'Account Summary', 'cube-payment-portal' ); ?></h2>

    <div class="spp-portal__grid spp-portal__grid--2 spp-gap-5">
        <?php foreach ( $all_widgets as $widget_key => $widget ) : ?>
        <div class="spp-portal__card" data-spp-component="<?php echo esc_attr( $widget_key ); ?>">
            <div class="spp-flex spp-justify-between spp-items-center spp-mb-4">
                <h3 class="spp-portal__card-title" style="margin: 0;"><?php echo esc_html( $widget['title'] ); ?></h3>
                <a href="#" class="spp-portal__link spp-portal__text--sm" data-spp-view="<?php echo esc_attr( $widget['link_view'] ); ?>"><?php echo esc_html( $widget['link_text'] ); ?></a>
            </div>
            <div class="spp-portal__skeleton spp-portal__skeleton--card" style="height: 80px;"></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
