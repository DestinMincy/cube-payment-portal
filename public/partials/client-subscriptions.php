<?php
/**
 * Client Subscriptions view.
 *
 * Lists the client's subscriptions as expandable cards with plan details.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user_id            = get_current_user_id();
$square_customer_id = get_user_meta( $user_id, 'spp_square_customer_id', true );

$subscriptions = array();
$error_message = '';
$plan_details  = array(); // plan_variation_id => plan details

if ( ! empty( $square_customer_id ) ) {
    if ( ! class_exists( 'SPP_Subscriptions' ) ) {
        require_once SPP_PLUGIN_DIR . 'api/class-spp-subscriptions.php';
    }

    $subs_api = new SPP_Subscriptions();
    $result   = $subs_api->get_customer_subscriptions( $square_customer_id );

    if ( is_wp_error( $result ) ) {
        $error_message = $result->get_error_message();
    } else {
        $subscriptions = is_array( $result ) ? $result : array();
    }
}

// ── Resolve plan names, pricing, cadence from catalog ──
if ( ! empty( $subscriptions ) ) {
    // Collect unique plan variation IDs.
    $plan_var_ids = array();
    foreach ( $subscriptions as $sub ) {
        $pv = $sub['plan_variation_id'] ?? $sub['plan_id'] ?? '';
        if ( ! empty( $pv ) ) {
            $plan_var_ids[ $pv ] = true;
        }
    }
    $plan_var_ids = array_keys( $plan_var_ids );

    if ( ! empty( $plan_var_ids ) ) {
        if ( ! class_exists( 'SPP_Catalog' ) ) {
            require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
        }

        $catalog = new SPP_Catalog();
        $batch   = $catalog->batch_retrieve_objects( $plan_var_ids, true );

        if ( ! is_wp_error( $batch ) && ! empty( $batch['objects'] ) ) {
            // Build parent lookup from related_objects.
            $parent_map = array();
            if ( ! empty( $batch['related_objects'] ) ) {
                foreach ( $batch['related_objects'] as $rel ) {
                    $parent_map[ $rel['id'] ] = $rel;
                }
            }

            foreach ( $batch['objects'] as $obj ) {
                $obj_id   = $obj['id'];
                $obj_type = $obj['type'] ?? '';

                if ( 'SUBSCRIPTION_PLAN_VARIATION' === $obj_type ) {
                    $var_data = $obj['subscription_plan_variation_data'] ?? array();
                    $phases   = $var_data['phases'] ?? array();

                    // Extract pricing from first phase.
                    $price    = 0;
                    $currency = 'USD';
                    $cadence  = 'MONTHLY';
                    if ( ! empty( $phases[0] ) ) {
                        $price    = $phases[0]['recurring_price_money']['amount'] ?? 0;
                        $currency = $phases[0]['recurring_price_money']['currency'] ?? 'USD';
                        $cadence  = $phases[0]['cadence'] ?? 'MONTHLY';
                    }

                    // Get parent plan name.
                    $plan_name = $var_data['name'] ?? '';
                    $parent_id = $var_data['subscription_plan_id'] ?? '';
                    if ( ! empty( $parent_id ) && isset( $parent_map[ $parent_id ] ) ) {
                        $parent_plan_data = $parent_map[ $parent_id ]['subscription_plan_data'] ?? array();
                        $plan_name        = $parent_plan_data['name'] ?? $plan_name;
                    }
                    if ( empty( $plan_name ) ) {
                        $plan_name = __( 'Subscription Plan', 'cube-payment-portal' );
                    }

                    $plan_details[ $obj_id ] = array(
                        'name'        => $plan_name,
                        'var_name'    => $var_data['name'] ?? '',
                        'price'       => $price,
                        'currency'    => $currency,
                        'cadence'     => $cadence,
                        'cadence_fmt' => SPP_Catalog::format_cadence( $cadence ),
                        'description' => $var_data['subscription_description'] ?? '',
                        'phases'      => $phases,
                    );
                } elseif ( 'SUBSCRIPTION_PLAN' === $obj_type ) {
                    $plan_data = $obj['subscription_plan_data'] ?? array();
                    $phases    = $plan_data['phases'] ?? array();

                    $price    = 0;
                    $currency = 'USD';
                    $cadence  = 'MONTHLY';
                    if ( ! empty( $phases[0] ) ) {
                        $price    = $phases[0]['recurring_price_money']['amount'] ?? 0;
                        $currency = $phases[0]['recurring_price_money']['currency'] ?? 'USD';
                        $cadence  = $phases[0]['cadence'] ?? 'MONTHLY';
                    }

                    $plan_details[ $obj_id ] = array(
                        'name'        => $plan_data['name'] ?? __( 'Subscription Plan', 'cube-payment-portal' ),
                        'var_name'    => '',
                        'price'       => $price,
                        'currency'    => $currency,
                        'cadence'     => $cadence,
                        'cadence_fmt' => SPP_Catalog::format_cadence( $cadence ),
                        'description' => '',
                        'phases'      => $phases,
                    );
                }
            }
        }
    }
}

$status_config = array(
    'ACTIVE'      => array( 'class' => 'spp-portal__badge--success',   'label' => __( 'Active', 'cube-payment-portal' ) ),
    'PAUSED'      => array( 'class' => 'spp-portal__badge--warning',   'label' => __( 'Paused', 'cube-payment-portal' ) ),
    'CANCELED'    => array( 'class' => 'spp-portal__badge--error',     'label' => __( 'Canceled', 'cube-payment-portal' ) ),
    'DEACTIVATED' => array( 'class' => 'spp-portal__badge--secondary', 'label' => __( 'Deactivated', 'cube-payment-portal' ) ),
    'COMPLETED'   => array( 'class' => 'spp-portal__badge--info',      'label' => __( 'Completed', 'cube-payment-portal' ) ),
    'PENDING'     => array( 'class' => 'spp-portal__badge--warning',   'label' => __( 'Pending', 'cube-payment-portal' ) ),
);
?>

<div class="spp-portal__section">
    <div class="spp-flex spp-justify-between spp-items-center spp-flex-wrap spp-gap-4" style="margin-bottom: var(--spp-space-6);">
        <h2 class="spp-portal__subtitle" style="margin: 0;"><?php esc_html_e( 'My Subscriptions', 'cube-payment-portal' ); ?></h2>
        
        <?php if ( ! empty( $subscriptions ) ) : ?>
        <div class="spp-flex spp-gap-3">
            <select id="spp-sub-filter-status" class="spp-portal__select spp-portal__select--sm" style="width: auto; min-width: 140px;">
                <option value="ALL"><?php esc_html_e( 'All Statuses', 'cube-payment-portal' ); ?></option>
                <option value="ACTIVE"><?php esc_html_e( 'Active', 'cube-payment-portal' ); ?></option>
                <option value="PAUSED"><?php esc_html_e( 'Paused', 'cube-payment-portal' ); ?></option>
                <option value="CANCELED"><?php esc_html_e( 'Canceled', 'cube-payment-portal' ); ?></option>
                <option value="DEACTIVATED"><?php esc_html_e( 'Deactivated', 'cube-payment-portal' ); ?></option>
                <option value="COMPLETED"><?php esc_html_e( 'Completed', 'cube-payment-portal' ); ?></option>
            </select>
            
            <select id="spp-sub-sort" class="spp-portal__select spp-portal__select--sm" style="width: auto; min-width: 140px;">
                <option value="newest"><?php esc_html_e( 'Newest First', 'cube-payment-portal' ); ?></option>
                <option value="oldest"><?php esc_html_e( 'Oldest First', 'cube-payment-portal' ); ?></option>
                <option value="next_billing"><?php esc_html_e( 'Next Billing Date', 'cube-payment-portal' ); ?></option>
            </select>
        </div>
        <?php endif; ?>
    </div>

    <?php if ( ! empty( $error_message ) ) : ?>
        <div class="spp-portal__card">
            <p style="color: var(--spp-error);"><?php echo esc_html( $error_message ); ?></p>
        </div>
    <?php elseif ( empty( $square_customer_id ) ) : ?>
        <div class="spp-portal__card" style="text-align: center; padding: var(--spp-space-8);">
            <div class="spp-portal__text--muted">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4;"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>
                <p class="spp-mt-4"><?php esc_html_e( 'Your account is not linked. Please contact support.', 'cube-payment-portal' ); ?></p>
            </div>
        </div>
    <?php elseif ( empty( $subscriptions ) ) : ?>
        <div class="spp-portal__card" style="text-align: center; padding: var(--spp-space-8);">
            <div class="spp-portal__text--muted">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4;"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>
                <p class="spp-mt-4"><?php esc_html_e( 'You have no subscriptions.', 'cube-payment-portal' ); ?></p>
            </div>
        </div>
    <?php else : ?>
        <div id="spp-sub-list-container" style="display: flex; flex-direction: column; gap: var(--spp-space-4);">
            <?php foreach ( $subscriptions as $idx => $sub ) :
                $sub_id          = $sub['id'] ?? '';
                $status          = $sub['status'] ?? 'PENDING';
                $plan_var_id     = $sub['plan_variation_id'] ?? $sub['plan_id'] ?? '';
                $start_date      = $sub['start_date'] ?? '';
                $charged_through = $sub['charged_through_date'] ?? '';
                $canceled_date   = $sub['canceled_date'] ?? '';
                $created_at      = $sub['created_at'] ?? '';
                $card_id         = $sub['card_id'] ?? '';
                $timezone        = $sub['timezone'] ?? '';
                $version         = $sub['version'] ?? '';
                $source          = $sub['source']['name'] ?? '';
                $invoice_ids     = $sub['invoice_ids'] ?? array();
                $actions         = $sub['actions'] ?? array();

                // Check for scheduled actions
                $scheduled_pause  = false;
                $scheduled_resume = false;
                $scheduled_cancel = false;
                $scheduled_pause_dt  = '';
                $scheduled_cancel_dt = '';

                foreach ( $actions as $action ) {
                    $atype = $action['type'] ?? '';
                    if ( 'PAUSE' === $atype ) {
                        $scheduled_pause = true;
                        if ( ! empty( $action['effective_date'] ) ) {
                            $scheduled_pause_dt = SPP_Client_Portal::format_date( $action['effective_date'] );
                        }
                    } elseif ( 'RESUME' === $atype ) {
                        $scheduled_resume = true;
                    } elseif ( 'CANCEL' === $atype ) {
                        $scheduled_cancel = true;
                        if ( ! empty( $action['effective_date'] ) ) {
                            $scheduled_cancel_dt = SPP_Client_Portal::format_date( $action['effective_date'] );
                        }
                    }
                }

                // Plan details.
                $plan       = $plan_details[ $plan_var_id ] ?? null;
                $plan_name  = $plan ? $plan['name'] : __( 'Subscription Plan', 'cube-payment-portal' );
                $price      = $plan ? $plan['price'] : 0;
                $currency   = $plan ? $plan['currency'] : 'USD';
                $cadence    = $plan ? $plan['cadence_fmt'] : '';
                $description = $plan ? $plan['description'] : '';

                $status_info = $status_config[ $status ] ?? array( 'class' => 'spp-portal__badge--secondary', 'label' => ucfirst( strtolower( $status ) ) );
                $is_active   = in_array( $status, array( 'ACTIVE', 'PENDING' ), true );
                $is_paused   = 'PAUSED' === $status;
                $is_canceled = in_array( $status, array( 'CANCELED', 'DEACTIVATED' ), true );
                $formatted   = $price > 0 ? '$' . number_format( $price / 100, 2 ) : '';
                $uid         = 'spp-sub-' . $idx;
                ?>
                <?php
                $created_ts = ! empty( $created_at ) ? strtotime( $created_at ) : 0;
                $next_ts    = ! empty( $charged_through ) ? strtotime( $charged_through ) : 9999999999;
                ?>
                <div class="spp-portal__card spp-sub-card" style="padding: 0; overflow: hidden;"
                     data-status="<?php echo esc_attr( $status ); ?>"
                     data-created="<?php echo esc_attr( $created_ts ); ?>"
                     data-next="<?php echo esc_attr( $next_ts ); ?>">
                    <!-- ─── Collapsed header (always visible, clickable) ─── -->
                    <button type="button"
                            class="spp-sub-card__header"
                            aria-expanded="false"
                            aria-controls="<?php echo esc_attr( $uid ); ?>"
                            onclick="this.setAttribute('aria-expanded', this.getAttribute('aria-expanded')==='false'?'true':'false'); document.getElementById('<?php echo esc_attr( $uid ); ?>').classList.toggle('spp-sub-card__body--open');">
                        <div style="flex: 1; min-width: 0;">
                            <!-- Plan name + badge -->
                            <div class="spp-flex spp-items-center spp-gap-3" style="margin-bottom: 6px;">
                                <h3 class="spp-sub-card__title"><?php echo esc_html( $plan_name ); ?></h3>
                                <span class="spp-portal__badge <?php echo esc_attr( $status_info['class'] ); ?> spp-portal__badge--sm">
                                    <?php echo esc_html( $status_info['label'] ); ?>
                                </span>
                                <?php if ( $scheduled_pause ) : ?>
                                    <span class="spp-portal__badge spp-portal__badge--warning spp-portal__badge--sm">
                                        <?php esc_html_e( 'Pause Scheduled', 'cube-payment-portal' ); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ( $scheduled_cancel ) : ?>
                                    <span class="spp-portal__badge spp-portal__badge--error spp-portal__badge--sm">
                                        <?php esc_html_e( 'Cancel Scheduled', 'cube-payment-portal' ); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <!-- Key info row -->
                            <div class="spp-sub-card__meta">
                                <?php if ( ! empty( $formatted ) && ! empty( $cadence ) ) : ?>
                                    <span class="spp-sub-card__meta-item">
                                        <strong><?php echo esc_html( $formatted ); ?></strong>
                                        <span class="spp-portal__text--muted">/<?php echo esc_html( strtolower( $cadence ) ); ?></span>
                                    </span>
                                    <span class="spp-sub-card__meta-sep">•</span>
                                <?php endif; ?>

                                <?php if ( $scheduled_pause && ! empty( $scheduled_pause_dt ) ) : ?>
                                    <span class="spp-sub-card__meta-item" style="color: var(--spp-warning);">
                                        <?php esc_html_e( 'Pausing on:', 'cube-payment-portal' ); ?>
                                        <strong><?php echo esc_html( $scheduled_pause_dt ); ?></strong>
                                    </span>
                                <?php elseif ( $scheduled_cancel && ! empty( $scheduled_cancel_dt ) ) : ?>
                                    <span class="spp-sub-card__meta-item" style="color: var(--spp-error);">
                                        <?php esc_html_e( 'Canceling on:', 'cube-payment-portal' ); ?>
                                        <strong><?php echo esc_html( $scheduled_cancel_dt ); ?></strong>
                                    </span>
                                <?php elseif ( ! $is_canceled && ! empty( $charged_through ) ) : ?>
                                    <span class="spp-sub-card__meta-item">
                                        <?php esc_html_e( 'Next:', 'cube-payment-portal' ); ?>
                                        <strong><?php echo esc_html( SPP_Client_Portal::format_date( $charged_through ) ); ?></strong>
                                    </span>
                                <?php elseif ( $is_canceled && ! empty( $canceled_date ) ) : ?>
                                    <span class="spp-sub-card__meta-item" style="color: var(--spp-error);">
                                        <?php esc_html_e( 'Canceled:', 'cube-payment-portal' ); ?>
                                        <strong><?php echo esc_html( SPP_Client_Portal::format_date( $canceled_date ) ); ?></strong>
                                    </span>
                                <?php endif; ?>

                                <?php if ( ! empty( $start_date ) ) : ?>
                                    <span class="spp-sub-card__meta-sep">•</span>
                                    <span class="spp-sub-card__meta-item spp-portal__text--muted">
                                        <?php
                                        printf(
                                            esc_html__( 'Started %s', 'cube-payment-portal' ),
                                            esc_html( SPP_Client_Portal::format_date( $start_date ) )
                                        );
                                        ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <!-- Chevron -->
                        <svg class="spp-sub-card__chevron" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </button>

                    <!-- ─── Expandable body (hidden by default) ─── -->
                    <div class="spp-sub-card__body" id="<?php echo esc_attr( $uid ); ?>">
                        <div class="spp-sub-card__details">
                            <!-- Description -->
                            <?php if ( ! empty( $description ) ) : ?>
                                <p style="margin: 0 0 var(--spp-space-4) 0; color: var(--spp-text-muted); font-size: var(--spp-text-sm);">
                                    <?php echo esc_html( $description ); ?>
                                </p>
                            <?php endif; ?>

                            <!-- Detail grid -->
                            <div class="spp-sub-card__grid">
                                <div class="spp-sub-card__detail">
                                    <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Subscription ID', 'cube-payment-portal' ); ?></span>
                                    <code class="spp-sub-card__detail-code"><?php echo esc_html( $sub_id ); ?></code>
                                </div>

                                <div class="spp-sub-card__detail">
                                    <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Plan ID', 'cube-payment-portal' ); ?></span>
                                    <code class="spp-sub-card__detail-code"><?php echo esc_html( $plan_var_id ); ?></code>
                                </div>

                                <?php if ( ! empty( $cadence ) ) : ?>
                                    <div class="spp-sub-card__detail">
                                        <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Billing Frequency', 'cube-payment-portal' ); ?></span>
                                        <span class="spp-sub-card__detail-value"><?php echo esc_html( $cadence ); ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if ( ! empty( $formatted ) ) : ?>
                                    <div class="spp-sub-card__detail">
                                        <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Amount', 'cube-payment-portal' ); ?></span>
                                        <span class="spp-sub-card__detail-value"><?php echo esc_html( $formatted . ' ' . $currency ); ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if ( ! empty( $start_date ) ) : ?>
                                    <div class="spp-sub-card__detail">
                                        <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Start Date', 'cube-payment-portal' ); ?></span>
                                        <span class="spp-sub-card__detail-value"><?php echo esc_html( SPP_Client_Portal::format_date( $start_date ) ); ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if ( $scheduled_pause && ! empty( $scheduled_pause_dt ) ) : ?>
                                    <div class="spp-sub-card__detail">
                                        <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Pauses On', 'cube-payment-portal' ); ?></span>
                                        <span class="spp-sub-card__detail-value" style="color: var(--spp-warning); font-weight: 500;"><?php echo esc_html( $scheduled_pause_dt ); ?></span>
                                    </div>
                                <?php elseif ( $scheduled_cancel && ! empty( $scheduled_cancel_dt ) ) : ?>
                                    <div class="spp-sub-card__detail">
                                        <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Cancels On', 'cube-payment-portal' ); ?></span>
                                        <span class="spp-sub-card__detail-value" style="color: var(--spp-error); font-weight: 500;"><?php echo esc_html( $scheduled_cancel_dt ); ?></span>
                                    </div>
                                <?php elseif ( ! empty( $charged_through ) ) : ?>
                                    <div class="spp-sub-card__detail">
                                        <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Paid Through', 'cube-payment-portal' ); ?></span>
                                        <span class="spp-sub-card__detail-value"><?php echo esc_html( SPP_Client_Portal::format_date( $charged_through ) ); ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if ( ! empty( $canceled_date ) ) : ?>
                                    <div class="spp-sub-card__detail">
                                        <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Canceled Date', 'cube-payment-portal' ); ?></span>
                                        <span class="spp-sub-card__detail-value" style="color: var(--spp-error);"><?php echo esc_html( SPP_Client_Portal::format_date( $canceled_date ) ); ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if ( ! empty( $timezone ) ) : ?>
                                    <div class="spp-sub-card__detail">
                                        <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Timezone', 'cube-payment-portal' ); ?></span>
                                        <span class="spp-sub-card__detail-value"><?php echo esc_html( $timezone ); ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if ( ! empty( $source ) ) : ?>
                                    <div class="spp-sub-card__detail">
                                        <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Source', 'cube-payment-portal' ); ?></span>
                                        <span class="spp-sub-card__detail-value"><?php echo esc_html( $source ); ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if ( ! empty( $created_at ) ) : ?>
                                    <div class="spp-sub-card__detail">
                                        <span class="spp-sub-card__detail-label"><?php esc_html_e( 'Created', 'cube-payment-portal' ); ?></span>
                                        <span class="spp-sub-card__detail-value"><?php echo esc_html( SPP_Client_Portal::format_date( $created_at, 'datetime' ) ); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Pending actions -->
                            <?php if ( ! empty( $actions ) ) : ?>
                                <div style="margin-top: var(--spp-space-4); padding-top: var(--spp-space-3); border-top: 1px solid var(--spp-border-light);">
                                    <span class="spp-sub-card__detail-label" style="margin-bottom: 8px; display: block;"><?php esc_html_e( 'Pending Actions', 'cube-payment-portal' ); ?></span>
                                    <?php foreach ( $actions as $action ) : ?>
                                        <div class="spp-portal__badge spp-portal__badge--info spp-portal__badge--sm" style="margin-right: 6px; margin-bottom: 4px; display: inline-block;">
                                            <?php echo esc_html( ucfirst( strtolower( str_replace( '_', ' ', $action['type'] ?? '' ) ) ) ); ?>
                                            <?php if ( ! empty( $action['effective_date'] ) ) : ?>
                                                — <?php echo esc_html( SPP_Client_Portal::format_date( $action['effective_date'] ) ); ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Recent invoices -->
                            <?php if ( ! empty( $invoice_ids ) ) : ?>
                                <div style="margin-top: var(--spp-space-4); padding-top: var(--spp-space-3); border-top: 1px solid var(--spp-border-light);">
                                    <span class="spp-sub-card__detail-label" style="margin-bottom: 8px; display: block;"><?php esc_html_e( 'Invoice IDs', 'cube-payment-portal' ); ?></span>
                                    <?php foreach ( array_slice( $invoice_ids, 0, 5 ) as $inv_id ) : ?>
                                        <code class="spp-sub-card__detail-code" style="display: inline-block; margin: 0 4px 4px 0;">
                                            <?php echo esc_html( $inv_id ); ?>
                                        </code>
                                    <?php endforeach; ?>
                                    <?php if ( count( $invoice_ids ) > 5 ) : ?>
                                        <span class="spp-portal__text--muted spp-portal__text--sm">
                                            <?php
                                            printf(
                                                esc_html__( '+%d more', 'cube-payment-portal' ),
                                                count( $invoice_ids ) - 5
                                            );
                                            ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Action buttons -->
                            <?php if ( $is_active || $is_paused ) : ?>
                                <div class="spp-flex spp-gap-2" style="margin-top: var(--spp-space-5); padding-top: var(--spp-space-4); border-top: 1px solid var(--spp-border-light);">
                                    <?php if ( $is_active && ! $scheduled_pause ) : ?>
                                        <button type="button"
                                                class="spp-portal__btn spp-portal__btn--outline spp-portal__btn--sm spp-pause-subscription"
                                                data-subscription-id="<?php echo esc_attr( $sub_id ); ?>">
                                            <?php esc_html_e( 'Pause', 'cube-payment-portal' ); ?>
                                        </button>
                                    <?php elseif ( $is_paused && ! $scheduled_resume ) : ?>
                                        <button type="button"
                                                class="spp-portal__btn spp-portal__btn--primary spp-portal__btn--sm spp-resume-subscription"
                                                data-subscription-id="<?php echo esc_attr( $sub_id ); ?>">
                                            <?php esc_html_e( 'Resume', 'cube-payment-portal' ); ?>
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php if ( ! $scheduled_cancel ) : ?>
                                        <button type="button"
                                                class="spp-portal__btn spp-portal__btn--outline spp-portal__btn--sm spp-portal__btn--danger spp-cancel-subscription"
                                                data-subscription-id="<?php echo esc_attr( $sub_id ); ?>">
                                            <?php esc_html_e( 'Cancel', 'cube-payment-portal' ); ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
