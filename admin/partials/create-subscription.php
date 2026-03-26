<?php
/**
 * Admin Create Subscription page.
 *
 * Simplified dropdown-based subscription creation flow.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Capability check.
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'You do not have permission to access this page.', 'cube-payment-portal' ) );
}

// Load required classes.
if ( ! class_exists( 'SPP_Subscriptions' ) ) {
    require_once SPP_PLUGIN_DIR . 'api/class-spp-subscriptions.php';
}
if ( ! class_exists( 'SPP_Catalog' ) ) {
    require_once SPP_PLUGIN_DIR . 'api/class-spp-catalog.php';
}

$catalog = new SPP_Catalog();
$create_message = null;

// Handle error messages from URL parameters (error code) and transients (detailed message).
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only error display.
if ( isset( $_GET['error'] ) ) {
    $error_code = sanitize_text_field( wp_unslash( $_GET['error'] ) );
    $error_messages = array(
        'missing_fields'        => __( 'Customer, Plan, and Payment Card are required.', 'cube-payment-portal' ),
        'order_template_failed' => __( 'Failed to create order template.', 'cube-payment-portal' ),
        'creation_failed'       => __( 'Failed to create subscription.', 'cube-payment-portal' ),
    );

    $error_text = $error_messages[ $error_code ] ?? __( 'An error occurred.', 'cube-payment-portal' );

    // Get detailed error message from transient (more secure than URL parameter).
    $transient_key = 'spp_subscription_error_' . get_current_user_id();
    $detailed_error = get_transient( $transient_key );
    if ( $detailed_error ) {
        $error_text .= ' ' . esc_html( $detailed_error );
        delete_transient( $transient_key );
    }

    $create_message = array( 'type' => 'error', 'text' => $error_text );
}

// Get data for dropdowns.
$customers = SPP_Database::get_customers( array( 'limit' => 500 ) );
$available_plans = $catalog->get_subscription_plans();
if ( is_wp_error( $available_plans ) ) {
    $available_plans = array();
}

// Get catalog items for RELATIVE pricing.
$catalog_items_response = $catalog->get_catalog_items();
$catalog_items = array();
if ( ! is_wp_error( $catalog_items_response ) && ! empty( $catalog_items_response['objects'] ) ) {
    foreach ( $catalog_items_response['objects'] as $item ) {
        if ( 'ITEM' === $item['type'] && ! empty( $item['item_data'] ) ) {
            $item_id = $item['id'];
            $category_id = $item['item_data']['category_id'] ?? '';
            
            foreach ( $item['item_data']['variations'] ?? array() as $var ) {
                $price = $var['item_variation_data']['price_money']['amount'] ?? 0;
                $catalog_items[] = array(
                    'id'          => $var['id'],
                    'item_id'     => $item_id, // Parent item ID for eligibility check.
                    'category_id' => $category_id, // Category ID for category-based eligibility.
                    'name'        => $item['item_data']['name'] . ' - ' . ( $var['item_variation_data']['name'] ?? 'Regular' ),
                    'price'       => $price,
                    'currency'    => $var['item_variation_data']['price_money']['currency'] ?? 'USD',
                );
            }
        }
    }
}

// Build plans data for JavaScript.
$plans_js_data = array();
foreach ( $available_plans as $plan ) {
    $plan_data = $plan['raw']['subscription_plan_data'] ?? array();
    
    // Get eligible item IDs for filtering the items dropdown.
    $eligible_item_ids = array();
    $all_items = false;
    
    // Check if plan allows all items (all_items flag).
    if ( ! empty( $plan_data['all_items'] ) ) {
        $all_items = true;
    }
    
    // Check for eligible_item_ids on the plan.
    if ( ! empty( $plan_data['eligible_item_ids'] ) ) {
        $eligible_item_ids = $plan_data['eligible_item_ids'];
    }
    
    // Also check eligible_category_ids if present (for category-based eligibility).
    $eligible_category_ids = $plan_data['eligible_category_ids'] ?? array();
    
    $plan_obj = array(
        'id'                    => $plan['id'],
        'name'                  => $plan['name'],
        'variations'            => array(),
        'eligible_item_ids'     => $eligible_item_ids,
        'eligible_category_ids' => $eligible_category_ids,
        'all_items'             => $all_items,
    );
    
    $variations = $plan_data['subscription_plan_variations'] ?? array();
    foreach ( $variations as $var ) {
        $var_data = $var['subscription_plan_variation_data'] ?? array();
        $var_phases = $var_data['phases'] ?? array();
        
        $var_price = 0;
        $var_currency = 'USD';
        $var_cadence = 'MONTHLY';
        $pricing_type = 'STATIC';
        
        if ( ! empty( $var_phases[0] ) ) {
            $phase = $var_phases[0];
            $var_cadence = $phase['cadence'] ?? 'MONTHLY';
            $pricing_type = $phase['pricing']['type'] ?? 'STATIC';
            
            if ( ! empty( $phase['recurring_price_money'] ) ) {
                $var_price = $phase['recurring_price_money']['amount'] ?? 0;
                $var_currency = $phase['recurring_price_money']['currency'] ?? 'USD';
            } elseif ( ! empty( $phase['pricing']['price'] ) ) {
                $var_price = $phase['pricing']['price']['amount'] ?? 0;
                $var_currency = $phase['pricing']['price']['currency'] ?? 'USD';
            }
        }
        
        $plan_obj['variations'][] = array(
            'id'           => $var['id'] ?? '',
            'name'         => $var_data['name'] ?? $plan['name'],
            'cadence'      => $var_cadence,
            'pricing_type' => $pricing_type,
            'price'        => $var_price,
            'currency'     => $var_currency,
        );
    }
    
    $plans_js_data[] = $plan_obj;
}

$back_url = admin_url( 'admin.php?page=spp-subscriptions' );
?>

<style>
.spp-create-wrap {
    max-width: 700px;
    margin-top: 20px;
}
.spp-create-card {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    padding: 24px;
    margin-bottom: 20px;
}
.spp-form-row {
    margin-bottom: 20px;
}
.spp-form-row:last-child {
    margin-bottom: 0;
}
.spp-form-row label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
    color: #1e3a5f;
}
.spp-form-row label .required {
    color: #dc3545;
}
.spp-form-row select,
.spp-form-row input[type="date"],
.spp-form-row input[type="number"] {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
    background: #fff;
}
.spp-form-row select:focus,
.spp-form-row input:focus {
    border-color: #1e3a5f;
    outline: none;
    box-shadow: 0 0 0 2px rgba(30, 58, 95, 0.1);
}
.spp-form-row .description {
    font-size: 13px;
    color: #666;
    margin-top: 6px;
}
.spp-form-row.hidden {
    display: none;
}
.spp-summary-box {
    background: #f8f9fa;
    border-radius: 6px;
    padding: 16px;
    margin-top: 20px;
}
.spp-summary-box h4 {
    margin: 0 0 12px 0;
    font-size: 14px;
    color: #1e3a5f;
}
.spp-summary-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #e0e0e0;
}
.spp-summary-row:last-child {
    border-bottom: none;
}
.spp-summary-row.total {
    font-weight: 600;
    font-size: 16px;
    border-top: 2px solid #1e3a5f;
    margin-top: 8px;
    padding-top: 12px;
}

/* Subscription Items Section */
.spp-items-section {
    background: #f8f9fa;
    border: 1px solid #e2e4e7;
    border-radius: 8px;
    padding: 20px;
    margin-top: 15px;
}
.spp-items-section .section-title {
    font-size: 13px;
    font-weight: 600;
    color: #1d2327;
    margin: 0 0 15px 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.spp-items-section .section-title .dashicons {
    color: #2271b1;
}
.spp-items-list {
    margin-bottom: 15px;
}
.spp-items-table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
    border-radius: 6px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}
.spp-items-table th {
    background: #f0f0f1;
    padding: 10px 15px;
    text-align: left;
    font-size: 12px;
    font-weight: 600;
    color: #50575e;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    border-bottom: 1px solid #e2e4e7;
}
.spp-items-table th:last-child {
    text-align: center;
    width: 60px;
}
.spp-items-table td {
    padding: 12px 15px;
    border-bottom: 1px solid #f0f0f1;
    vertical-align: middle;
}
.spp-items-table tr:last-child td {
    border-bottom: none;
}
.spp-items-table .item-name {
    font-weight: 500;
    color: #1d2327;
}
.spp-items-table .item-price {
    color: #666;
    font-size: 13px;
}
.spp-items-table .item-qty {
    width: 80px;
}
.spp-items-table .item-qty input {
    width: 60px;
    padding: 6px 10px;
    text-align: center;
    border: 1px solid #8c8f94;
    border-radius: 4px;
    font-size: 14px;
}
.spp-items-table .item-qty input:focus {
    border-color: #2271b1;
    box-shadow: 0 0 0 1px #2271b1;
    outline: none;
}
.spp-items-table .item-total {
    font-weight: 600;
    color: #1d2327;
    text-align: right;
    width: 100px;
}
.spp-items-table .item-remove {
    text-align: center;
}
.spp-items-table .item-remove-btn {
    background: none;
    border: none;
    color: #b32d2e;
    cursor: pointer;
    padding: 4px;
    border-radius: 4px;
    transition: all 0.15s ease;
}
.spp-items-table .item-remove-btn:hover {
    background: #fcf0f1;
    color: #a00;
}
.spp-items-empty {
    text-align: center;
    padding: 30px;
    color: #666;
    background: #fff;
    border-radius: 6px;
    border: 1px dashed #ccc;
}
.spp-items-empty .dashicons {
    font-size: 24px;
    width: 24px;
    height: 24px;
    color: #ccc;
    margin-bottom: 8px;
}
.spp-add-item-row {
    display: flex;
    gap: 10px;
    align-items: center;
    padding-top: 15px;
    border-top: 1px solid #e2e4e7;
}
.spp-add-item-row select {
    flex: 1;
    padding: 10px 12px;
    border: 1px solid #8c8f94;
    border-radius: 6px;
    font-size: 14px;
    background: #fff;
}
.spp-add-item-row select:focus {
    border-color: #2271b1;
    box-shadow: 0 0 0 1px #2271b1;
    outline: none;
}
.spp-add-item-row button {
    white-space: nowrap;
    padding: 8px 16px;
}

.spp-submit-row {
    display: flex;
    gap: 12px;
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid #e0e0e0;
}
.spp-modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.7);
    z-index: 100000;
    display: flex;
    align-items: center;
    justify-content: center;
}
.spp-modal-content {
    background: #fff;
    border-radius: 8px;
    width: 90%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
}
.spp-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid #e0e0e0;
}
.spp-modal-header h3 {
    margin: 0;
    font-size: 18px;
}
.spp-modal-close {
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
    color: #666;
}
.spp-modal-close:hover {
    color: #000;
}
.spp-modal-body {
    padding: 24px;
}
.spp-modal-footer {
    padding: 16px 24px;
    border-top: 1px solid #e0e0e0;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}
#card-container {
    min-height: 120px;
}
</style>

<div class="wrap spp-admin-wrap">
    <h1 class="screen-reader-text"><?php esc_html_e( 'Create Subscription', 'cube-payment-portal' ); ?></h1>
    <hr class="wp-header-end">
    <h2 style="margin: 0; font-size: 24px; font-weight: 600; color: #333;">
        <a href="<?php echo esc_url( $back_url ); ?>" style="text-decoration: none; color: inherit; margin-right: 8px;">
            <span class="dashicons dashicons-arrow-left-alt2"></span>
        </a>
        <?php esc_html_e( 'Create Subscription', 'cube-payment-portal' ); ?>
    </h2>

    <?php if ( $create_message ) : ?>
        <div class="notice notice-<?php echo esc_attr( $create_message['type'] ); ?> is-dismissible">
            <p><?php echo esc_html( $create_message['text'] ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( empty( $available_plans ) ) : ?>
        <div class="notice notice-warning">
            <p>
                <?php esc_html_e( 'No subscription plans found.', 'cube-payment-portal' ); ?>
                <a href="https://squareup.com/dashboard/subscriptions/plans" target="_blank">
                    <?php esc_html_e( 'Create plans in Square Dashboard', 'cube-payment-portal' ); ?> →
                </a>
            </p>
        </div>
    <?php else : ?>

    <div class="spp-create-wrap">
        <form method="post" id="spp-create-form">
            <?php wp_nonce_field( 'spp_create_subscription', 'spp_create_nonce' ); ?>
            <input type="hidden" name="plan_variation_id" id="plan_variation_id" value="">
            <input type="hidden" name="subscription_items" id="subscription_items" value="[]">
            <input type="hidden" name="pricing_type" id="pricing_type" value="STATIC">

            <div class="spp-create-card">
                <!-- Customer -->
                <div class="spp-form-row">
                    <label for="customer_id">
                        <?php esc_html_e( 'Customer', 'cube-payment-portal' ); ?>
                        <span class="required">*</span>
                    </label>
                    <select name="customer_id" id="customer_id" required>
                        <option value=""><?php esc_html_e( '-- Select Customer --', 'cube-payment-portal' ); ?></option>
                        <?php foreach ( $customers as $customer ) : 
                            $name = trim( $customer['given_name'] . ' ' . $customer['family_name'] );
                            if ( empty( $name ) ) $name = $customer['email'] ?? $customer['square_customer_id'];
                        ?>
                            <option value="<?php echo esc_attr( $customer['square_customer_id'] ); ?>">
                                <?php echo esc_html( $name ); ?>
                                <?php if ( ! empty( $customer['email'] ) && $name !== $customer['email'] ) : ?>
                                    (<?php echo esc_html( $customer['email'] ); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Plan -->
                <div class="spp-form-row" id="plan-row">
                    <label for="plan_id">
                        <?php esc_html_e( 'Subscription Plan', 'cube-payment-portal' ); ?>
                        <span class="required">*</span>
                    </label>
                    <select id="plan_id" required>
                        <option value=""><?php esc_html_e( '-- Select Plan --', 'cube-payment-portal' ); ?></option>
                        <?php foreach ( $available_plans as $plan ) : ?>
                            <option value="<?php echo esc_attr( $plan['id'] ); ?>">
                                <?php echo esc_html( $plan['name'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Frequency (populated dynamically) -->
                <div class="spp-form-row hidden" id="frequency-row">
                    <label for="frequency_id">
                        <?php esc_html_e( 'Billing Frequency', 'cube-payment-portal' ); ?>
                        <span class="required">*</span>
                    </label>
                    <select id="frequency_id">
                        <option value=""><?php esc_html_e( '-- Select Frequency --', 'cube-payment-portal' ); ?></option>
                    </select>
                </div>

                <!-- Items Section (for RELATIVE pricing) -->
                <div class="spp-form-row hidden" id="items-row">
                    <label><?php esc_html_e( 'Subscription Items', 'cube-payment-portal' ); ?></label>
                    
                    <div class="spp-items-section">
                        <h4 class="section-title">
                            <span class="dashicons dashicons-products"></span>
                            <?php esc_html_e( 'This plan uses item-based pricing. Add items below.', 'cube-payment-portal' ); ?>
                        </h4>
                        
                        <div class="spp-items-list" id="items-list"></div>
                        

                    <div class="spp-add-item-row">
                        <select id="add-item-select">
                            <option value=""><?php esc_html_e( '-- Select Item to Add --', 'cube-payment-portal' ); ?></option>
                            <?php foreach ( $catalog_items as $item ) : ?>
                                <option value="<?php echo esc_attr( $item['id'] ); ?>" 
                                        data-name="<?php echo esc_attr( $item['name'] ); ?>"
                                        data-price="<?php echo esc_attr( $item['price'] ); ?>"
                                        data-currency="<?php echo esc_attr( $item['currency'] ); ?>"
                                        data-item-id="<?php echo esc_attr( $item['item_id'] ); ?>"
                                        data-category-id="<?php echo esc_attr( $item['category_id'] ); ?>">
                                    <?php echo esc_html( $item['name'] ); ?> 
                                    (<?php echo esc_html( '$' . number_format( $item['price'] / 100, 2 ) ); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button type="button" class="button button-primary" id="add-item-btn">
                            <span class="dashicons dashicons-plus-alt2" style="margin-top: 3px;"></span>
                            <?php esc_html_e( 'Add Item', 'cube-payment-portal' ); ?>
                        </button>
                    </div>
                    </div><!-- .spp-items-section -->
                </div>


                <!-- Card Selection -->
                <div class="spp-form-row hidden" id="card-row">
                    <label for="card_id">
                        <?php esc_html_e( 'Payment Card', 'cube-payment-portal' ); ?>
                        <span class="required">*</span>
                    </label>
                    <select name="card_id" id="card_id" required>
                        <option value=""><?php esc_html_e( 'Loading cards...', 'cube-payment-portal' ); ?></option>
                    </select>
                    <button type="button" class="button" id="add-card-btn" style="margin-top: 10px;">
                        <span class="dashicons dashicons-plus-alt2" style="margin-top: 3px;"></span>
                        <?php esc_html_e( 'Add New Card', 'cube-payment-portal' ); ?>
                    </button>
                </div>

                <!-- Add Card Modal -->
                <div id="add-card-modal" class="spp-modal" style="display: none;">
                    <div class="spp-modal-content">
                        <div class="spp-modal-header">
                            <h3><?php esc_html_e( 'Add New Card', 'cube-payment-portal' ); ?></h3>
                            <button type="button" class="spp-modal-close" id="close-card-modal">
                                <span class="dashicons dashicons-no-alt"></span>
                            </button>
                        </div>
                        <div class="spp-modal-body">
                            <?php if ( ! is_ssl() && ( strpos( $_SERVER['HTTP_HOST'], 'localhost' ) !== false || strpos( $_SERVER['HTTP_HOST'], '127.0.0.1' ) !== false ) ) : ?>
                                <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; padding: 12px; margin-bottom: 15px; font-size: 13px;">
                                    <strong>Development Mode:</strong> You may see a security warning from Square because you're using HTTP. This is normal for localhost development and won't appear on production HTTPS sites.
                                </div>
                            <?php endif; ?>
                            <div id="card-container"></div>
                            <div id="card-errors" style="color: #dc3545; margin-top: 10px; display: none;"></div>
                        </div>
                        <div class="spp-modal-footer">
                            <button type="button" class="button button-primary" id="save-card-btn">
                                <?php esc_html_e( 'Save Card', 'cube-payment-portal' ); ?>
                            </button>
                            <button type="button" class="button" id="cancel-card-btn">
                                <?php esc_html_e( 'Cancel', 'cube-payment-portal' ); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Start Date -->
                <div class="spp-form-row hidden" id="date-row">
                    <label for="start_date"><?php esc_html_e( 'Start Date', 'cube-payment-portal' ); ?></label>
                    <input type="date" name="start_date" id="start_date" value="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>">
                    <p class="description"><?php esc_html_e( 'When billing should begin.', 'cube-payment-portal' ); ?></p>
                </div>

                <!-- Summary -->
                <div class="spp-summary-box hidden" id="summary-box">
                    <h4><?php esc_html_e( 'Subscription Summary', 'cube-payment-portal' ); ?></h4>
                    <div id="summary-content"></div>
                </div>

                <!-- Submit -->
                <div class="spp-submit-row">
                    <button type="submit" name="spp_create_subscription" class="button button-primary button-large" id="submit-btn" disabled>
                        <?php esc_html_e( 'Create Subscription', 'cube-payment-portal' ); ?>
                    </button>
                    <a href="<?php echo esc_url( $back_url ); ?>" class="button button-large">
                        <?php esc_html_e( 'Cancel', 'cube-payment-portal' ); ?>
                    </a>
                </div>
            </div>
        </form>
    </div>

    <?php
    $environment = get_option( 'spp_environment', 'production' );
    $square_sdk_url = ( 'sandbox' === $environment ) 
        ? 'https://sandbox.web.squarecdn.com/v1/square.js' 
        : 'https://web.squarecdn.com/v1/square.js';
    ?>
    <script src="<?php echo esc_url( $square_sdk_url ); ?>"></script>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        var plansData = <?php echo wp_json_encode( $plans_js_data ); ?>;
        var selectedPlan = null;
        var selectedVariation = null;
        var subscriptionItems = [];
        var customerCards = [];
        var payments = null;
        var card = null;
        
        var cadenceLabels = {
            'DAILY': 'Daily',
            'WEEKLY': 'Weekly',
            'EVERY_TWO_WEEKS': 'Every 2 Weeks',
            'MONTHLY': 'Monthly',
            'EVERY_TWO_MONTHS': 'Every 2 Months',
            'QUARTERLY': 'Quarterly',
            'EVERY_FOUR_MONTHS': 'Every 4 Months',
            'EVERY_SIX_MONTHS': 'Every 6 Months',
            'ANNUAL': 'Yearly',
            'EVERY_TWO_YEARS': 'Every 2 Years'
        };
        
        function formatCurrency(cents, currency) {
            var symbol = currency === 'EUR' ? '€' : (currency === 'GBP' ? '£' : '$');
            return symbol + (cents / 100).toFixed(2);
        }
        
        function updateFormState() {
            var customerId = $('#customer_id').val();
            var planId = $('#plan_id').val();
            var variationId = $('#frequency_id').val();
            var cardId = $('#card_id').val();
            
            // Show/hide frequency based on plan selection
            if (planId) {
                $('#frequency-row').removeClass('hidden');
            } else {
                $('#frequency-row').addClass('hidden');
            }
            
            // Show card, date, and summary when frequency selected
            if (variationId) {
                $('#card-row, #date-row').removeClass('hidden');
                
                // Show items section for RELATIVE pricing
                if (selectedVariation && selectedVariation.pricing_type === 'RELATIVE') {
                    $('#items-row').removeClass('hidden');
                } else {
                    $('#items-row').addClass('hidden');
                }
                
                updateSummary();
            } else {
                $('#card-row, #date-row, #items-row, #summary-box').addClass('hidden');
            }
            
            // Enable submit button - require customer, variation, and card
            var canSubmit = customerId && variationId && cardId;
            if (selectedVariation && selectedVariation.pricing_type === 'RELATIVE' && subscriptionItems.length === 0) {
                canSubmit = false;
            }
            $('#submit-btn').prop('disabled', !canSubmit);
        }
        
        function updateSummary() {
            if (!selectedVariation) {
                $('#summary-box').addClass('hidden');
                return;
            }
            
            var html = '';
            var total = 0;
            
            if (selectedVariation.pricing_type === 'RELATIVE') {
                for (var i = 0; i < subscriptionItems.length; i++) {
                    var item = subscriptionItems[i];
                    var lineTotal = item.price * item.quantity;
                    total += lineTotal;
                    html += '<div class="spp-summary-row">';
                    html += '<span>' + item.name + ' x ' + item.quantity + '</span>';
                    html += '<span>' + formatCurrency(lineTotal, item.currency) + '</span>';
                    html += '</div>';
                }
                if (subscriptionItems.length === 0) {
                    html += '<div class="spp-summary-row"><span style="color:#999;">No items added</span></div>';
                }
            } else {
                total = selectedVariation.price;
                html += '<div class="spp-summary-row">';
                html += '<span>' + selectedPlan.name + ' (' + (cadenceLabels[selectedVariation.cadence] || selectedVariation.cadence) + ')</span>';
                html += '<span>' + formatCurrency(total, selectedVariation.currency) + '</span>';
                html += '</div>';
            }
            
            html += '<div class="spp-summary-row total">';
            html += '<span>Total per ' + (cadenceLabels[selectedVariation.cadence] || 'period').toLowerCase().replace('ly', '').replace('every ', '') + '</span>';
            html += '<span>' + formatCurrency(total, selectedVariation.currency || 'USD') + '</span>';
            html += '</div>';
            
            $('#summary-content').html(html);
            $('#summary-box').removeClass('hidden');
            
            // Update hidden field
            $('#subscription_items').val(JSON.stringify(subscriptionItems));
        }
        
        function renderItems() {
            var html = '';
            
            if (subscriptionItems.length === 0) {
                html = '<div class="spp-items-empty">';
                html += '<span class="dashicons dashicons-products"></span>';
                html += '<div>No items added yet</div>';
                html += '</div>';
            } else {
                html = '<table class="spp-items-table">';
                html += '<thead><tr>';
                html += '<th>Item</th>';
                html += '<th>Unit Price</th>';
                html += '<th>Qty</th>';
                html += '<th>Total</th>';
                html += '<th></th>';
                html += '</tr></thead>';
                html += '<tbody>';
                
                for (var i = 0; i < subscriptionItems.length; i++) {
                    var item = subscriptionItems[i];
                    var lineTotal = item.price * item.quantity;
                    
                    html += '<tr data-index="' + i + '">';
                    html += '<td class="item-name">' + item.name + '</td>';
                    html += '<td class="item-price">' + formatCurrency(item.price, item.currency) + '</td>';
                    html += '<td class="item-qty"><input type="number" min="1" value="' + item.quantity + '" class="item-qty-input"></td>';
                    html += '<td class="item-total">' + formatCurrency(lineTotal, item.currency) + '</td>';
                    html += '<td class="item-remove"><button type="button" class="item-remove-btn" title="Remove"><span class="dashicons dashicons-trash"></span></button></td>';
                    html += '</tr>';
                }
                
                html += '</tbody></table>';
            }
            
            $('#items-list').html(html);
            updateSummary();
            updateFormState();
        }
        
        function loadCustomerCards(customerId, selectCardId) {
            $('#card_id').html('<option value="">Loading...</option>');
            
            return $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'spp_get_customer_cards',
                    customer_id: customerId,
                    nonce: '<?php echo wp_create_nonce( 'spp_get_cards' ); ?>'
                },
                success: function(response) {
                    var html = '<option value="">-- Select Card --</option>';
                    if (response.success && response.data.cards && response.data.cards.length > 0) {
                        customerCards = response.data.cards;
                        for (var i = 0; i < response.data.cards.length; i++) {
                            var card = response.data.cards[i];
                            var selected = selectCardId && card.id === selectCardId ? ' selected' : '';
                            html += '<option value="' + card.id + '"' + selected + '>' + card.card_brand + ' •••• ' + card.last_4 + '</option>';
                        }
                    } else {
                        customerCards = [];
                    }
                    $('#card_id').html(html);
                    updateFormState();
                },
                error: function() {
                    $('#card_id').html('<option value="">Error loading cards</option>');
                    customerCards = [];
                }
            });
        }
        
        // Customer change - load cards
        $('#customer_id').on('change', function() {
            var customerId = $(this).val();
            if (customerId) {
                loadCustomerCards(customerId);
            }
            updateFormState();
        });
        
        // Plan change - populate frequencies and filter items
        $('#plan_id').on('change', function() {
            var planId = $(this).val();
            selectedPlan = null;
            selectedVariation = null;
            subscriptionItems = [];
            
            var $freq = $('#frequency_id');
            $freq.html('<option value="">-- Select Frequency --</option>');
            
            if (!planId) {
                updateFormState();
                return;
            }
            
            // Find plan
            for (var i = 0; i < plansData.length; i++) {
                if (plansData[i].id === planId) {
                    selectedPlan = plansData[i];
                    break;
                }
            }
            
            if (selectedPlan) {
                for (var j = 0; j < selectedPlan.variations.length; j++) {
                    var v = selectedPlan.variations[j];
                    var label = (cadenceLabels[v.cadence] || v.cadence);
                    if (v.pricing_type !== 'RELATIVE' && v.price > 0) {
                        label += ' - ' + formatCurrency(v.price, v.currency);
                    } else if (v.pricing_type === 'RELATIVE') {
                        label += ' - Item-based pricing';
                    }
                    $freq.append('<option value="' + v.id + '">' + label + '</option>');
                }
                
                // Filter items dropdown based on plan eligibility.
                filterItemsDropdown(selectedPlan);
            }
            
            updateFormState();
        });
        
        // Filter items dropdown based on plan eligibility.
        function filterItemsDropdown(plan) {
            var $itemSelect = $('#add-item-select');
            var $options = $itemSelect.find('option[value!=""]');
            
            $options.each(function() {
                var $opt = $(this);
                var itemId = $opt.data('item-id');
                var categoryId = $opt.data('category-id');
                
                // If plan has all_items flag, show all items.
                if (plan.all_items) {
                    $opt.show();
                    return;
                }
                
                // Check if item is eligible by item ID.
                var isEligible = false;
                
                if (plan.eligible_item_ids && plan.eligible_item_ids.length > 0) {
                    if (plan.eligible_item_ids.indexOf(itemId) !== -1) {
                        isEligible = true;
                    }
                }
                
                // Check if item is eligible by category ID.
                if (!isEligible && plan.eligible_category_ids && plan.eligible_category_ids.length > 0) {
                    if (categoryId && plan.eligible_category_ids.indexOf(categoryId) !== -1) {
                        isEligible = true;
                    }
                }
                
                // If no eligibility criteria set, assume all items are eligible.
                if ((!plan.eligible_item_ids || plan.eligible_item_ids.length === 0) && 
                    (!plan.eligible_category_ids || plan.eligible_category_ids.length === 0)) {
                    isEligible = true;
                }
                
                // Show or hide the option.
                if (isEligible) {
                    $opt.show();
                } else {
                    $opt.hide();
                }
            });
            
            // Reset selection.
            $itemSelect.val('');
        }
        

        // Frequency change
        $('#frequency_id').on('change', function() {
            var variationId = $(this).val();
            selectedVariation = null;
            subscriptionItems = [];
            $('#items-list').html('');
            
            if (variationId && selectedPlan) {
                for (var i = 0; i < selectedPlan.variations.length; i++) {
                    if (selectedPlan.variations[i].id === variationId) {
                        selectedVariation = selectedPlan.variations[i];
                        break;
                    }
                }
            }
            
            $('#plan_variation_id').val(variationId);
            $('#pricing_type').val(selectedVariation ? selectedVariation.pricing_type : 'STATIC');
            updateFormState();
        });
        
        // Card selection change
        $('#card_id').on('change', function() {
            updateFormState();
        });
        
        // Add New Card button
        $('#add-card-btn').on('click', async function() {
            var customerId = $('#customer_id').val();
            if (!customerId) {
                alert('Please select a customer first.');
                return;
            }
            
            $('#add-card-modal').fadeIn(200);
            await initSquarePayments();
        });
        
        // Close modal buttons
        $('#close-card-modal, #cancel-card-btn').on('click', function() {
            $('#add-card-modal').fadeOut(200);
            if (card) {
                card.destroy();
                card = null;
            }
        });
        
        // Save card button
        $('#save-card-btn').on('click', async function() {
            var $btn = $(this);
            var customerId = $('#customer_id').val();
            
            $btn.prop('disabled', true).text('Saving...');
            $('#card-errors').hide();
            
            try {
                const result = await card.tokenize();
                
                if (result.status === 'OK') {
                    // Save card to Square via AJAX
                    const response = await $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'spp_create_card',
                            customer_id: customerId,
                            card_token: result.token,
                            nonce: '<?php echo wp_create_nonce( 'spp_create_card' ); ?>'
                        }
                    });
                    
                    if (response.success) {
                        // Reload cards and select the new one
                        await loadCustomerCards(customerId, response.data.card.id);
                        $('#add-card-modal').fadeOut(200);
                        card.destroy();
                        card = null;
                    } else {
                        $('#card-errors').text(response.data.message || 'Failed to save card').show();
                    }
                } else {
                    var errorMessage = result.errors ? result.errors.map(e => e.message).join(', ') : 'Invalid card details';
                    $('#card-errors').text(errorMessage).show();
                }
            } catch (error) {
                $('#card-errors').text(error.message || 'An error occurred').show();
            } finally {
                $btn.prop('disabled', false).text('Save Card');
            }
        });
        
        // Initialize Square Web Payments SDK
        async function initSquarePayments() {
            if (payments) return;
            
            var appId = '<?php echo esc_js( get_option( 'spp_application_id' ) ); ?>';
            var locationId = '<?php echo esc_js( get_option( 'spp_default_location_id' ) ); ?>';
            
            console.log('Initializing Square Payments...');
            console.log('Application ID:', appId);
            console.log('Location ID:', locationId);
            
            if (!appId || !locationId) {
                var settingsUrl = '<?php echo esc_js( admin_url( 'admin.php?page=spp-settings' ) ); ?>';
                var errorHtml = 'Square credentials not configured. Please go to <a href="' + settingsUrl + '" target="_blank">Settings</a> to configure your Application ID' + (!locationId ? ' and connect to Square' : '') + '.';
                $('#card-errors').html(errorHtml).show();
                return;
            }
            
            if (typeof Square === 'undefined') {
                $('#card-errors').text('Square SDK failed to load. Please check your internet connection and refresh.').show();
                return;
            }
            
            try {
                payments = Square.payments(appId, locationId);
                card = await payments.card();
                await card.attach('#card-container');
                console.log('Square Payments initialized successfully');
            } catch (error) {
                console.error('Failed to initialize Square Payments:', error);
                var errorMsg = 'Failed to load payment form: ' + (error.message || 'Unknown error');
                $('#card-errors').text(errorMsg).show();
            }
        }
        
        // Add item
        $('#add-item-btn').on('click', function() {
            var $select = $('#add-item-select');
            var $option = $select.find(':selected');
            var itemId = $select.val();
            
            if (!itemId) return;
            
            // Check if already added
            var existing = -1;
            for (var i = 0; i < subscriptionItems.length; i++) {
                if (subscriptionItems[i].variation_id === itemId) {
                    existing = i;
                    break;
                }
            }
            
            if (existing > -1) {
                subscriptionItems[existing].quantity++;
            } else {
                subscriptionItems.push({
                    variation_id: itemId,
                    name: $option.data('name'),
                    price: parseInt($option.data('price')),
                    currency: $option.data('currency'),
                    quantity: 1
                });
            }
            
            $select.val('');
            renderItems();
        });
        
        // Item quantity change
        $('#items-list').on('change', '.item-qty-input', function() {
            var index = $(this).closest('tr').data('index');
            var qty = parseInt($(this).val()) || 1;
            if (qty < 1) qty = 1;
            subscriptionItems[index].quantity = qty;
            renderItems();
        });
        
        // Remove item
        $('#items-list').on('click', '.item-remove-btn', function() {
            var index = $(this).closest('tr').data('index');
            subscriptionItems.splice(index, 1);
            renderItems();
        });
        
        // Start date change
        $('#start_date').on('change', function() {
            updateSummary();
        });
        
        // Initialize
        updateFormState();
    });
    </script>

    <?php endif; ?>
</div>
