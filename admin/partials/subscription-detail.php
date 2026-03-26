<?php
/**
 * Admin Subscription Detail page.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Capability check - ensure user has permission to manage subscriptions.
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'You do not have permission to access this page.', 'cube-payment-portal' ) );
}

$subscription_id = isset( $_GET['subscription_id'] ) ? intval( $_GET['subscription_id'] ) : 0;

if ( ! $subscription_id ) {
    wp_die( __( 'Invalid subscription ID.', 'cube-payment-portal' ) );
}

// Get subscription from database.
$subscription = SPP_Database::get_subscription_by_id( $subscription_id );

if ( ! $subscription ) {
    wp_die( __( 'Subscription not found.', 'cube-payment-portal' ) );
}

// Load required classes.
if ( ! class_exists( 'SPP_Subscriptions' ) ) {
    require_once SPP_PLUGIN_DIR . 'api/class-spp-subscriptions.php';
}
if ( ! class_exists( 'SPP_Catalog' ) ) {
    require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
}

$subscriptions_api = new SPP_Subscriptions();
$action_message = null;

// Handle actions.
if ( isset( $_POST['spp_subscription_action'] ) && wp_verify_nonce( $_POST['spp_action_nonce'], 'spp_subscription_action' ) ) {
    $action = sanitize_text_field( $_POST['spp_subscription_action'] );
    $square_id = $subscription['square_subscription_id'];
    
    switch ( $action ) {
        case 'pause':
            $result = $subscriptions_api->pause_subscription( $square_id );
            if ( is_wp_error( $result ) ) {
                $action_message = array( 'type' => 'error', 'text' => $result->get_error_message() );
            } else {
                $action_message = array( 'type' => 'success', 'text' => __( 'Subscription paused successfully.', 'cube-payment-portal' ) );
                $subscription['status'] = 'paused';
            }
            break;
            
        case 'resume':
            $result = $subscriptions_api->resume_subscription( $square_id );
            if ( is_wp_error( $result ) ) {
                $action_message = array( 'type' => 'error', 'text' => $result->get_error_message() );
            } else {
                $action_message = array( 'type' => 'success', 'text' => __( 'Subscription resumed successfully.', 'cube-payment-portal' ) );
                $subscription['status'] = 'active';
            }
            break;
            
        case 'cancel':
            $result = $subscriptions_api->cancel_subscription( $square_id );
            if ( is_wp_error( $result ) ) {
                $action_message = array( 'type' => 'error', 'text' => $result->get_error_message() );
            } else {
                $action_message = array( 'type' => 'success', 'text' => __( 'Subscription canceled successfully.', 'cube-payment-portal' ) );
                $subscription['status'] = 'canceled';
            }
            break;
    }
}

// Handle plan change.
if ( isset( $_POST['spp_change_plan'] ) && wp_verify_nonce( $_POST['spp_plan_nonce'], 'spp_change_plan' ) ) {
    $new_plan_id = sanitize_text_field( $_POST['new_plan_id'] );
    if ( ! empty( $new_plan_id ) && $new_plan_id !== $subscription['square_plan_id'] ) {
        $result = $subscriptions_api->swap_plan( $subscription['square_subscription_id'], $new_plan_id );
        if ( is_wp_error( $result ) ) {
            $action_message = array( 'type' => 'error', 'text' => $result->get_error_message() );
        } else {
            $action_message = array( 'type' => 'success', 'text' => __( 'Subscription plan changed successfully.', 'cube-payment-portal' ) );
            // Refresh subscription data.
            $subscription = SPP_Database::get_subscription_by_id( $subscription_id );
        }
    }
}

// Get billing history events from Square.
$billing_events = array();
if ( ! empty( $subscription['square_subscription_id'] ) ) {
    $events_result = $subscriptions_api->list_subscription_events( $subscription['square_subscription_id'] );
    if ( ! is_wp_error( $events_result ) ) {
        $billing_events = $events_result;
    }
}

// Get available plans for plan change.
$catalog = new SPP_Catalog();
$available_plans = $catalog->get_subscription_plans();
if ( is_wp_error( $available_plans ) ) {
    $available_plans = array();
}

// Helper function to format currency.
if ( ! function_exists( 'spp_format_currency' ) ) {
    function spp_format_currency( $amount, $currency = 'USD' ) {
        $symbol = '$';
        if ( 'EUR' === $currency ) {
            $symbol = '€';
        } elseif ( 'GBP' === $currency ) {
            $symbol = '£';
        }
        return $symbol . number_format( $amount, 2 );
    }
}

// Helper function to get status badge class.
function spp_get_detail_status_class( $status ) {
    switch ( strtolower( $status ) ) {
        case 'active':
            return 'spp-status-active';
        case 'paused':
            return 'spp-status-paused';
        case 'canceled':
            return 'spp-status-canceled';
        case 'completed':
            return 'spp-status-completed';
        case 'pending':
            return 'spp-status-pending';
        default:
            return 'spp-status-pending';
    }
}

$back_url = admin_url( 'admin.php?page=spp-subscriptions' );
?>

<div class="wrap spp-admin-wrap">
    <h1 class="screen-reader-text"><?php esc_html_e( 'Subscription Detail', 'cube-payment-portal' ); ?></h1>
    <hr class="wp-header-end">
    <h2 style="margin: 0; font-size: 24px; font-weight: 600; color: #333;">
        <a href="<?php echo esc_url( $back_url ); ?>" style="text-decoration: none; color: inherit;">
            <span class="dashicons dashicons-arrow-left-alt" style="margin-right: 5px;"></span>
        </a>
        <?php
        printf(
            /* translators: %s: subscription ID */
            esc_html__( 'Subscription #%s', 'cube-payment-portal' ),
            esc_html( $subscription['id'] )
        );
        ?>
        <span class="spp-status-badge <?php echo esc_attr( spp_get_detail_status_class( $subscription['status'] ) ); ?>" style="margin-left: 10px; vertical-align: middle;">
            <?php echo esc_html( ucfirst( $subscription['status'] ) ); ?>
        </span>
    </h2>

    <?php if ( isset( $action_message ) ) : ?>
        <div class="notice notice-<?php echo esc_attr( $action_message['type'] ); ?> is-dismissible">
            <p><?php echo esc_html( $action_message['text'] ); ?></p>
        </div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-top: 20px;">
        <!-- Main Content -->
        <div>
            <!-- Subscription Info Card -->
            <div class="spp-admin-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px;">
                <h2 style="margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px;">
                    <?php esc_html_e( 'Subscription Details', 'cube-payment-portal' ); ?>
                </h2>
                
                <table class="form-table" style="margin: 0;">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Square ID', 'cube-payment-portal' ); ?></th>
                        <td>
                            <code><?php echo esc_html( $subscription['square_subscription_id'] ); ?></code>
                            <?php if ( ! empty( $subscription['square_subscription_id'] ) ) : ?>
                                <a href="https://squareup.com/dashboard/subscriptions/<?php echo esc_attr( $subscription['square_subscription_id'] ); ?>" target="_blank" style="margin-left: 10px;">
                                    <?php esc_html_e( 'View in Square →', 'cube-payment-portal' ); ?>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Plan', 'cube-payment-portal' ); ?></th>
                        <td>
                            <strong><?php echo esc_html( $subscription['plan_name'] ?: __( 'Unknown Plan', 'cube-payment-portal' ) ); ?></strong>
                            <span style="color: #666; margin-left: 5px;">(<?php echo esc_html( SPP_Catalog::format_cadence( $subscription['cadence'] ?? 'MONTHLY' ) ); ?>)</span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Amount', 'cube-payment-portal' ); ?></th>
                        <td>
                            <strong style="font-size: 18px; color: #2271b1;">
                                <?php echo esc_html( spp_format_currency( $subscription['amount'], $subscription['currency'] ) ); ?>
                            </strong>
                            <span style="color: #666;">/ <?php echo esc_html( strtolower( SPP_Catalog::format_cadence( $subscription['cadence'] ?? 'MONTHLY' ) ) ); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Start Date', 'cube-payment-portal' ); ?></th>
                        <td>
                            <?php 
                            if ( ! empty( $subscription['start_date'] ) ) {
                                echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $subscription['start_date'] ) ) );
                            } else {
                                echo '—';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Next Billing Date', 'cube-payment-portal' ); ?></th>
                        <td>
                            <?php 
                            if ( ! empty( $subscription['next_billing_date'] ) && ! in_array( strtolower( $subscription['status'] ), array( 'canceled', 'completed' ), true ) ) {
                                echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $subscription['next_billing_date'] ) ) );
                            } else {
                                echo '—';
                            }
                            ?>
                        </td>
                    </tr>
                    <?php if ( 'canceled' === strtolower( $subscription['status'] ) && ! empty( $subscription['canceled_at'] ) ) : ?>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Canceled At', 'cube-payment-portal' ); ?></th>
                        <td style="color: #d63638;">
                            <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $subscription['canceled_at'] ) ) ); ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Created', 'cube-payment-portal' ); ?></th>
                        <td>
                            <?php 
                            if ( ! empty( $subscription['created_at'] ) ) {
                                echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $subscription['created_at'] ) ) );
                            } else {
                                echo '—';
                            }
                            ?>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Billing History -->
            <div class="spp-admin-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h2 style="margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px;">
                    <?php esc_html_e( 'Billing History', 'cube-payment-portal' ); ?>
                </h2>
                
                <?php if ( empty( $billing_events ) ) : ?>
                    <p style="color: #666; text-align: center; padding: 20px;">
                        <?php esc_html_e( 'No billing events found.', 'cube-payment-portal' ); ?>
                    </p>
                <?php else : ?>
                    <table class="wp-list-table widefat striped" style="margin-top: 10px;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Date', 'cube-payment-portal' ); ?></th>
                                <th><?php esc_html_e( 'Event', 'cube-payment-portal' ); ?></th>
                                <th><?php esc_html_e( 'Details', 'cube-payment-portal' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $billing_events as $event ) : ?>
                            <tr>
                                <td>
                                    <?php 
                                    if ( ! empty( $event['effective_date'] ) ) {
                                        echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $event['effective_date'] ) ) );
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <strong><?php echo esc_html( str_replace( '_', ' ', $event['subscription_event_type'] ?? 'Unknown' ) ); ?></strong>
                                </td>
                                <td>
                                    <?php 
                                    if ( ! empty( $event['info']['detail'] ) ) {
                                        echo esc_html( $event['info']['detail'] );
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sidebar -->
        <div>
            <!-- Customer Info -->
            <div class="spp-admin-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px;">
                <h3 style="margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px;">
                    <span class="dashicons dashicons-admin-users" style="margin-right: 5px;"></span>
                    <?php esc_html_e( 'Customer', 'cube-payment-portal' ); ?>
                </h3>
                
                <?php if ( ! empty( $subscription['customer_name'] ) ) : ?>
                    <p style="margin: 0 0 10px 0;">
                        <strong style="font-size: 16px;"><?php echo esc_html( $subscription['customer_name'] ); ?></strong>
                    </p>
                <?php endif; ?>
                
                <?php if ( ! empty( $subscription['customer_email'] ) ) : ?>
                    <p style="margin: 0 0 10px 0;">
                        <a href="mailto:<?php echo esc_attr( $subscription['customer_email'] ); ?>">
                            <?php echo esc_html( $subscription['customer_email'] ); ?>
                        </a>
                    </p>
                <?php endif; ?>
                
                <?php if ( ! empty( $subscription['user_id'] ) && $subscription['user_id'] > 0 ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=spp-customers&customer_id=' . $subscription['user_id'] ) ); ?>" class="button" style="margin-top: 10px;">
                        <?php esc_html_e( 'View Customer', 'cube-payment-portal' ); ?>
                    </a>
                <?php endif; ?>
            </div>

            <!-- Actions -->
            <?php if ( ! in_array( strtolower( $subscription['status'] ), array( 'canceled', 'completed' ), true ) ) : ?>
            <div class="spp-admin-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px;">
                <h3 style="margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px;">
                    <span class="dashicons dashicons-admin-tools" style="margin-right: 5px;"></span>
                    <?php esc_html_e( 'Actions', 'cube-payment-portal' ); ?>
                </h3>
                
                <form method="post" style="display: flex; flex-direction: column; gap: 10px;">
                    <?php wp_nonce_field( 'spp_subscription_action', 'spp_action_nonce' ); ?>
                    
                    <?php if ( 'active' === strtolower( $subscription['status'] ) ) : ?>
                        <button type="submit" name="spp_subscription_action" value="pause" class="button" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to pause this subscription?', 'cube-payment-portal' ); ?>');">
                            <span class="dashicons dashicons-controls-pause" style="margin-top: 3px;"></span>
                            <?php esc_html_e( 'Pause Subscription', 'cube-payment-portal' ); ?>
                        </button>
                    <?php endif; ?>
                    
                    <?php if ( 'paused' === strtolower( $subscription['status'] ) ) : ?>
                        <button type="submit" name="spp_subscription_action" value="resume" class="button button-primary">
                            <span class="dashicons dashicons-controls-play" style="margin-top: 3px;"></span>
                            <?php esc_html_e( 'Resume Subscription', 'cube-payment-portal' ); ?>
                        </button>
                    <?php endif; ?>
                    
                    <button type="submit" name="spp_subscription_action" value="cancel" class="button" style="color: #d63638; border-color: #d63638;" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to cancel this subscription? This action cannot be undone.', 'cube-payment-portal' ); ?>');">
                        <span class="dashicons dashicons-dismiss" style="margin-top: 3px;"></span>
                        <?php esc_html_e( 'Cancel Subscription', 'cube-payment-portal' ); ?>
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <!-- Change Plan -->
            <?php if ( ! in_array( strtolower( $subscription['status'] ), array( 'canceled', 'completed' ), true ) && ! empty( $available_plans ) ) : ?>
            <div class="spp-admin-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h3 style="margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px;">
                    <span class="dashicons dashicons-update" style="margin-right: 5px;"></span>
                    <?php esc_html_e( 'Change Plan', 'cube-payment-portal' ); ?>
                </h3>
                
                <form method="post">
                    <?php wp_nonce_field( 'spp_change_plan', 'spp_plan_nonce' ); ?>
                    
                    <select name="new_plan_id" style="width: 100%; margin-bottom: 10px;">
                        <option value=""><?php esc_html_e( 'Select new plan...', 'cube-payment-portal' ); ?></option>
                        <?php foreach ( $available_plans as $plan ) : ?>
                            <?php if ( $plan['id'] !== $subscription['square_plan_id'] ) : ?>
                                <option value="<?php echo esc_attr( $plan['id'] ); ?>">
                                    <?php echo esc_html( $plan['name'] ); ?> - 
                                    <?php echo esc_html( spp_format_currency( $plan['price_amount'] / 100, $plan['price_currency'] ) ); ?>/<?php echo esc_html( strtolower( SPP_Catalog::format_cadence( $plan['cadence'] ) ) ); ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    
                    <button type="submit" name="spp_change_plan" class="button" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to change the subscription plan? Proration may apply.', 'cube-payment-portal' ); ?>');">
                        <?php esc_html_e( 'Change Plan', 'cube-payment-portal' ); ?>
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.spp-status-active {
    background: #d4edda;
    color: #155724;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
}
.spp-status-paused {
    background: #fff3cd;
    color: #856404;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
}
.spp-status-canceled {
    background: #f8d7da;
    color: #721c24;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
}
.spp-status-completed {
    background: #d1ecf1;
    color: #0c5460;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
}
.spp-status-pending {
    background: #e2e3e5;
    color: #383d41;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
}
</style>
