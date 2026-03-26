<?php
/**
 * Admin Catalog page.
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

// Get default currency.
$default_currency = get_option( 'spp_default_currency', 'USD' );

// Get categories for filter dropdown.
$categories = SPP_Database::get_catalog_categories();
$total_items = SPP_Database::get_catalog_items_count();

// Helper function.
if ( ! function_exists( 'spp_format_currency' ) ) {
    function spp_format_currency( $amount, $currency = 'USD' ) {
        $symbol = '$';
        if ( 'EUR' === $currency ) {
            $symbol = '€';
        } elseif ( 'GBP' === $currency ) {
            $symbol = '£';
        }
        return $symbol . number_format( (float) $amount, 2 );
    }
}
?>

<div class="wrap spp-admin-wrap">
    <h1 class="screen-reader-text"><?php esc_html_e( 'Items Catalog', 'cube-payment-portal' ); ?></h1>
    <hr class="wp-header-end">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <h2 style="margin: 0; font-size: 24px; font-weight: 600; color: #333;"><?php esc_html_e( 'Items Catalog', 'cube-payment-portal' ); ?></h2>
             <span class="spp-badge-count" style="background: #e3f2fd; color: #006AFF; padding: 2px 8px; border-radius: 99px; font-size: 12px; font-weight: 600; vertical-align: middle;"><?php echo esc_html( number_format( $total_items ) ); ?> <?php esc_html_e( 'items', 'cube-payment-portal' ); ?></span>
        </div>
        <div style="display: flex; gap: 8px;">
            <button type="button" id="spp-add-item" class="spp-button">
                <span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e( 'Add Item', 'cube-payment-portal' ); ?>
            </button>
            <button type="button" id="spp-bulk-delete" class="spp-button" style="display: none;">
                <span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Delete Selected', 'cube-payment-portal' ); ?>
            </button>
            <button type="button" id="spp-sync-catalog" class="spp-button">
                <span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Sync Catalog', 'cube-payment-portal' ); ?>
            </button>
        </div>
    </div>



    <!-- Sync Message -->
    <div id="spp-sync-message" class="notice" style="display: none;">
        <p></p>
    </div>

    <!-- Filters -->
    <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px;">
        <form id="spp-catalog-filter-form" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
            <div class="spp-filter-group">
                <label for="spp-search" style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 12px; color: #666;">
                    <?php esc_html_e( 'Search', 'cube-payment-portal' ); ?>
                </label>
                <input type="search" id="spp-search" name="search" placeholder="<?php esc_attr_e( 'Item name, description...', 'cube-payment-portal' ); ?>" style="min-width: 250px;">
            </div>
            
            <div class="spp-filter-group">
                <label for="spp-category" style="display: block; margin-bottom: 5px; font-weight: 500; font-size: 12px; color: #666;">
                    <?php esc_html_e( 'Category', 'cube-payment-portal' ); ?>
                </label>
                <select id="spp-category" name="category_id" style="min-width: 180px;">
                    <option value=""><?php esc_html_e( 'All Categories', 'cube-payment-portal' ); ?></option>
                    <?php foreach ( $categories as $cat ) : ?>
                        <option value="<?php echo esc_attr( $cat['category_id'] ); ?>">
                            <?php echo esc_html( $cat['category_name'] ?: __( 'Uncategorized', 'cube-payment-portal' ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="spp-filter-group" style="display: flex; gap: 8px;">
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Filter', 'cube-payment-portal' ); ?></button>
                <button type="button" id="spp-reset-filters" class="button"><?php esc_html_e( 'Reset', 'cube-payment-portal' ); ?></button>
            </div>
        </form>
    </div>

    <!-- Loading -->
    <div id="spp-loading" style="display: none; text-align: center; padding: 60px; background: #fff; border-radius: 8px;">
        <span class="spinner is-active" style="float: none;"></span>
        <p style="color: #666;"><?php esc_html_e( 'Loading catalog items...', 'cube-payment-portal' ); ?></p>
    </div>

    <!-- Empty State -->
    <div id="spp-empty-state" style="display: none; text-align: center; padding: 60px; background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <span class="dashicons dashicons-products" style="font-size: 64px; color: #ccc; width: 64px; height: 64px;"></span>
        <h2 style="color: #666;"><?php esc_html_e( 'No Items Found', 'cube-payment-portal' ); ?></h2>
        <p style="color: #999;"><?php esc_html_e( 'Sync your catalog from Square to see items here.', 'cube-payment-portal' ); ?></p>
        <button type="button" id="spp-sync-catalog-empty" class="button button-primary" style="margin-top: 15px;">
            <?php esc_html_e( 'Sync Catalog Now', 'cube-payment-portal' ); ?>
        </button>
    </div>

    <!-- Catalog Table -->
    <div id="spp-catalog-table-wrap" class="spp-admin-card" style="display: none;">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" class="manage-column column-toggle" style="width: 30px; padding: 10px 0 10px 10px;"></th>
                    <td id="cb" class="manage-column column-cb check-column">
                        <input type="checkbox" id="spp-select-all">
                    </td>
                    <th scope="col" class="manage-column column-name column-primary sortable" style="width: 40%;">
                        <a href="#" class="spp-sort-link" data-sort="name">
                            <span><?php esc_html_e( 'Item', 'cube-payment-portal' ); ?></span>
                            <span class="sorting-indicators"><span class="sorting-indicator asc" aria-hidden="true"></span><span class="sorting-indicator desc" aria-hidden="true"></span></span>
                        </a>
                    </th>
                    <th scope="col" class="manage-column column-category" style="width: 20%;">
                        <?php esc_html_e( 'Reporting category', 'cube-payment-portal' ); ?>
                    </th>
                    <th scope="col" class="manage-column column-stock" style="width: 10%;">
                        <?php esc_html_e( 'Stock', 'cube-payment-portal' ); ?>
                    </th>
                    <th scope="col" class="manage-column column-price sortable" style="width: 15%;">
                        <a href="#" class="spp-sort-link" data-sort="price">
                            <span><?php esc_html_e( 'Price', 'cube-payment-portal' ); ?></span>
                            <span class="sorting-indicators"><span class="sorting-indicator asc" aria-hidden="true"></span><span class="sorting-indicator desc" aria-hidden="true"></span></span>
                        </a>
                    </th>
                    <th scope="col" class="manage-column column-actions" style="width: 50px; text-align: right;">
                        <span class="dashicons dashicons-ellipsis" style="color: #666;"></span>
                    </th>
                </tr>
            </thead>
            <tbody id="spp-catalog-tbody">
                <!-- Rows will be populated via JS -->
            </tbody>
            <tfoot>
                <tr>
                    <th scope="col" class="manage-column column-toggle"></th>
                    <td class="manage-column column-cb check-column">
                        <input type="checkbox" id="spp-select-all-footer">
                    </td>
                    <th scope="col" class="manage-column column-image"><?php esc_html_e( 'Image', 'cube-payment-portal' ); ?></th>
                    <th scope="col" class="manage-column column-name"><?php esc_html_e( 'Item', 'cube-payment-portal' ); ?></th>
                    <th scope="col" class="manage-column column-category"><?php esc_html_e( 'Reporting category', 'cube-payment-portal' ); ?></th>
                    <th scope="col" class="manage-column column-stock"><?php esc_html_e( 'Stock', 'cube-payment-portal' ); ?></th>
                    <th scope="col" class="manage-column column-price"><?php esc_html_e( 'Price', 'cube-payment-portal' ); ?></th>
                    <th scope="col" class="manage-column column-actions"></th>
                </tr>
            </tfoot>
        </table>

        <div class="tablenav bottom">
            <div class="tablenav-pages" id="spp-pagination">
                <span class="displaying-num" id="spp-total-count"></span>
                <span class="pagination-links" id="spp-pagination-links"></span>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Item Modal -->
<div id="spp-item-modal" class="spp-modal" style="display: none;">
    <div class="spp-modal-content">
        <div class="spp-modal-header">
            <h2 id="spp-modal-title"><?php esc_html_e( 'Add Catalog Item', 'cube-payment-portal' ); ?></h2>
            <span class="spp-close-modal">&times;</span>
        </div>
        
        <form id="spp-item-form" class="spp-modal-form">
            <input type="hidden" id="spp-item-id" name="item_id">
            
            <div class="spp-modal-body">
                <!-- Left Column: Image and Basic Info -->
                <div class="spp-form-section section-split">
                    <div class="spp-form-column side-panel">
                        <div class="spp-form-group">
                            <label class="spp-label"><?php esc_html_e( 'Image', 'cube-payment-portal' ); ?></label>
                            <div class="spp-image-upload-zone">
                                <div class="spp-image-preview-wrapper">
                                    <div id="spp-image-placeholder" class="spp-placeholder-box">
                                        <span class="dashicons dashicons-format-image"></span>
                                    </div>
                                    <img id="spp-item-image-preview" src="" style="display: none;">
                                </div>
                                <input type="hidden" id="spp-item-image-id" name="image_id" value="">
                                <div class="spp-image-actions">
                                    <button type="button" class="button" id="spp-upload-image-btn"><?php esc_html_e( 'Select Image', 'cube-payment-portal' ); ?></button>
                                    <button type="button" class="button button-link-delete" id="spp-remove-image-btn" style="display: none;"><?php esc_html_e( 'Remove', 'cube-payment-portal' ); ?></button>
                                </div>
                                <p class="description"><?php esc_html_e( 'JPG, PNG, GIF. Max 14MB.', 'cube-payment-portal' ); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="spp-form-column main-panel">
                        <div class="spp-form-group">
                            <label for="spp-item-name" class="spp-label"><?php esc_html_e( 'Item Name', 'cube-payment-portal' ); ?> <span class="required">*</span></label>
                            <input type="text" id="spp-item-name" name="name" class="spp-input-large" required placeholder="<?php esc_attr_e( 'e.g. Test Item', 'cube-payment-portal' ); ?>">
                        </div>

                        <div class="spp-form-row">
                            <div class="spp-form-group half">
                                <label for="spp-item-type" class="spp-label"><?php esc_html_e( 'Item Type', 'cube-payment-portal' ); ?></label>
                                <select id="spp-item-type" name="product_type" class="spp-select">
                                    <option value="REGULAR"><?php esc_html_e( 'Regular (Physical Good)', 'cube-payment-portal' ); ?></option>
                                    <option value="APPOINTMENTS_SERVICE"><?php esc_html_e( 'Service (Appointment)', 'cube-payment-portal' ); ?></option>
                                    <option value="FOOD_AND_BEV"><?php esc_html_e( 'Food & Beverage', 'cube-payment-portal' ); ?></option>
                                    <option value="GIFT_CARD"><?php esc_html_e( 'Gift Card', 'cube-payment-portal' ); ?></option>
                                </select>
                            </div>
                            <div class="spp-form-group half">
                                <label for="spp-item-category" class="spp-label"><?php esc_html_e( 'Category', 'cube-payment-portal' ); ?></label>
                                <select id="spp-item-category" name="category_id" class="spp-select">
                                    <option value=""><?php esc_html_e( 'None', 'cube-payment-portal' ); ?></option>
                                </select>
                            </div>
                        </div>

                        <div id="spp-service-duration-row" class="spp-form-group" style="display: none;">
                            <label for="spp-service-duration" class="spp-label"><?php esc_html_e( 'Duration (Minutes)', 'cube-payment-portal' ); ?> <span class="required">*</span></label>
                            <input type="number" id="spp-service-duration" name="service_duration" class="regular-text" min="1" step="1" placeholder="e.g. 60">
                        </div>
                    </div>
                </div>

                <hr class="spp-modal-divider">

                <!-- Inventory and Description -->
                <div class="spp-form-section">
                    <div class="spp-form-row">
                        <div class="spp-form-group half">
                            <label class="spp-label"><?php esc_html_e( 'Inventory', 'cube-payment-portal' ); ?></label>
                            <div class="spp-checkbox-wrapper">
                                <label for="spp-track-inventory">
                                    <input type="checkbox" id="spp-track-inventory" name="track_inventory" value="1">
                                    <?php esc_html_e( 'Track stock for this item', 'cube-payment-portal' ); ?>
                                </label>
                                <span class="spp-bulk-notice" style="display: none;">
                                    <span class="dashicons dashicons-warning"></span>
                                    <?php esc_html_e( 'Applies to all variations', 'cube-payment-portal' ); ?>
                                </span>
                            </div>
                        </div>
                        <div class="spp-form-group half">
                            <label for="spp-item-price" class="spp-label"><?php esc_html_e( 'Price', 'cube-payment-portal' ); ?></label>
                            <div class="spp-price-input-group">
                                <input type="number" id="spp-item-price" name="price" class="spp-input-price" step="0.01" min="0" placeholder="0.00">
                                <select id="spp-item-currency" name="currency" class="spp-select-currency">
                                    <option value="USD">USD ($)</option>
                                    <option value="EUR">EUR (€)</option>
                                    <option value="GBP">GBP (£)</option>
                                    <option value="CAD">CAD ($)</option>
                                    <option value="AUD">AUD ($)</option>
                                    <option value="JPY">JPY (¥)</option>
                                </select>
                                <button type="button" id="spp-apply-price-to-all" class="button" style="display: none;"><?php esc_html_e( 'Apply to variations', 'cube-payment-portal' ); ?></button>
                            </div>
                        </div>
                    </div>

                    <div class="spp-form-group">
                        <label for="spp-item-description" class="spp-label"><?php esc_html_e( 'Description', 'cube-payment-portal' ); ?></label>
                        <textarea id="spp-item-description" name="description" class="spp-textarea" rows="4" placeholder="<?php esc_attr_e( 'Brief description of the item...', 'cube-payment-portal' ); ?>"></textarea>
                    </div>
                </div>

                <!-- Variations Section -->
                <div id="spp-variations-preview-row" class="spp-form-section" style="display: none;">
                    <label class="spp-label"><?php esc_html_e( 'Variations', 'cube-payment-portal' ); ?></label>
                    <div id="spp-variations-list" class="spp-variations-container">
                        <!-- List of variations will be populated here -->
                    </div>
                </div>
            </div>
            
            <div class="spp-modal-footer">
                <button type="button" class="button spp-close-modal-btn"><?php esc_html_e( 'Cancel', 'cube-payment-portal' ); ?></button>
                <button type="submit" class="button button-primary button-large" id="spp-save-item-btn">
                    <?php esc_html_e( 'Save Catalog Item', 'cube-payment-portal' ); ?>
                    <span class="spinner" id="spp-modal-spinner"></span>
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* Modal Styles */
.spp-modal {
    position: fixed;
    z-index: 100000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0,0,0,0.4);
    display: none;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(2px);
}
.spp-modal-content {
    background-color: #fff;
    margin: auto;
    width: 90%;
    max-width: 800px;
    border-radius: 12px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    overflow: hidden;
    animation: sppModalFadeIn 0.3s ease-out;
}

@keyframes sppModalFadeIn {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}

.spp-modal-header {
    padding: 20px 30px;
    border-bottom: 1px solid #f0f0f1;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #fcfcfc;
}
.spp-modal-header h2 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
    color: #1e293b;
}
.spp-close-modal {
    color: #64748b;
    font-size: 24px;
    font-weight: 400;
    cursor: pointer;
    line-height: 1;
    padding: 5px;
    border-radius: 4px;
    transition: all 0.2s;
}
.spp-close-modal:hover {
    color: #0f172a;
    background: #f1f5f9;
}

.spp-modal-body {
    padding: 30px;
    max-height: calc(100vh - 200px);
    overflow-y: auto;
}

.spp-modal-footer {
    padding: 20px 30px;
    border-top: 1px solid #f0f0f1;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    background: #fcfcfc;
}

/* Layout Helpers */
.spp-form-section {
    margin-bottom: 25px;
}
.spp-form-section.section-split {
    display: grid;
    grid-template-columns: 200px 1fr;
    gap: 30px;
}
.spp-form-row {
    display: flex;
    gap: 20px;
    margin-bottom: 15px;
}
.spp-form-group {
    margin-bottom: 20px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.spp-form-group.half {
    flex: 1;
}

.spp-label {
    font-weight: 600;
    font-size: 13px;
    color: #475569;
}

/* Inputs */
.spp-input-large {
    padding: 10px 14px !important;
    font-size: 15px !important;
    border-radius: 6px !important;
    border: 1px solid #cbd5e1 !important;
    width: 100%;
}
.spp-input-large:focus {
    border-color: #2271b1 !important;
    box-shadow: 0 0 0 1px #2271b1 !important;
    outline: none;
}

.spp-select {
    padding: 8px 12px !important;
    border-radius: 6px !important;
    border: 1px solid #cbd5e1 !important;
    width: 100%;
    height: 40px !important;
}

.spp-textarea {
    width: 100%;
    padding: 12px !important;
    border-radius: 8px !important;
    border: 1px solid #cbd5e1 !important;
    font-size: 14px !important;
    resize: vertical;
}

/* Image Upload */
.spp-image-upload-zone {
    text-align: center;
}
.spp-image-preview-wrapper {
    width: 100%;
    aspect-ratio: 1;
    border: 2px dashed #e2e8f0;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 15px;
    overflow: hidden;
    background: #f8fafc;
}
.spp-image-preview-wrapper img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}
.spp-placeholder-box {
    display: flex;
    flex-direction: column;
    align-items: center;
    color: #cbd5e1;
}
.spp-placeholder-box .dashicons {
    font-size: 48px;
    width: 48px;
    height: 48px;
}

.spp-image-actions {
    display: flex;
    gap: 8px;
    justify-content: center;
}

/* Price Input */
.spp-price-input-group {
    display: flex;
    gap: 0;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    overflow: hidden;
    width: fit-content;
}
.spp-input-price {
    border: none !important;
    padding: 8px 12px !important;
    width: 100px !important;
    margin: 0 !important;
}
.spp-select-currency {
    border: none !important;
    border-left: 1px solid #cbd5e1 !important;
    background: #f8fafc !important;
    padding: 0 10px !important;
    margin: 0 !important;
    border-radius: 0 !important;
}

/* Checkbox */
.spp-checkbox-wrapper {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 5px;
}
.spp-checkbox-wrapper label {
    font-size: 14px;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}
.spp-checkbox-wrapper input[type="checkbox"] {
    margin: 0 !important;
}

.spp-bulk-notice {
    color: #e11d48;
    background: #fff1f2;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.spp-bulk-notice .dashicons {
    font-size: 14px;
    width: 14px;
    height: 14px;
}

/* Variations */
.spp-variations-container {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 5px 15px;
    max-height: 250px;
    overflow-y: auto;
}

.spp-modal-divider {
    border: 0;
    border-top: 1px solid #f1f5f9;
    margin: 5px 0 25px 0;
}

/* Spinner adjustment */
#spp-modal-spinner {
    float: none;
    margin: 0 5px 0 10px;
    vertical-align: middle;
}

/* Clickable Item Name in List */
.spp-item-name-link {
    color: #2271b1;
    text-decoration: none;
    cursor: pointer;
    font-weight: 600;
    position: relative;
    padding-bottom: 1px;
}
.spp-item-name-link:hover {
    color: #135e96;
    border-bottom: 2px solid #2271b1;
}

@media (max-width: 640px) {
    .spp-form-section.section-split {
        grid-template-columns: 1fr;
    }
    .spp-form-row {
        flex-direction: column;
    }
}
</style>

<script>
(function($) {
    'use strict';

    var SPPCatalog = {
        currentPage: 1,
        perPage: 20,
        sortBy: 'name',
        sortOrder: 'ASC',
        selectedItems: [],
        debounceTimer: null,

        init: function() {
            this.bindEvents();
            this.loadCatalog();
        },

        bindEvents: function() {
            var self = this;

            // Sync buttons
            $('#spp-sync-catalog, #spp-sync-catalog-empty').on('click', function() {
                self.syncCatalog();
            });

            // Filter form
            $('#spp-catalog-filter-form').on('submit', function(e) {
                e.preventDefault();
                self.currentPage = 1;
                self.loadCatalog();
            });

            $('#spp-reset-filters').on('click', function() {
                $('#spp-catalog-filter-form')[0].reset();
                self.currentPage = 1;
                self.loadCatalog();
            });

            // Debounced search
            $('#spp-search').on('input', function() {
                clearTimeout(self.debounceTimer);
                self.debounceTimer = setTimeout(function() {
                    self.currentPage = 1;
                    self.loadCatalog();
                }, 500);
            });

            // Category filter
            $('#spp-category').on('change', function() {
                self.currentPage = 1;
                self.loadCatalog();
            });

            // Pagination
            $(document).on('click', '.spp-page-link', function(e) {
                e.preventDefault();
                var page = $(this).data('page');
                if (page && page !== self.currentPage) {
                    self.currentPage = page;
                    self.loadCatalog();
                }
            });

            // Sorting
            $(document).on('click', '.spp-sort-link', function(e) {
                e.preventDefault();
                var sortBy = $(this).data('sort');
                if (self.sortBy === sortBy) {
                    self.sortOrder = self.sortOrder === 'ASC' ? 'DESC' : 'ASC';
                } else {
                    self.sortBy = sortBy;
                    self.sortOrder = 'ASC';
                }
                self.updateSortIndicators();
                self.loadCatalog();
            });

            // Select all (header and footer)
            $('#spp-select-all, #spp-select-all-footer').on('change', function() {
                var checked = $(this).is(':checked');
                $('#spp-select-all, #spp-select-all-footer').prop('checked', checked);
                $('.spp-item-checkbox').prop('checked', checked);
                self.updateSelectedItems();
            });

            // Individual checkboxes
            $(document).on('change', '.spp-item-checkbox', function() {
                self.updateSelectedItems();
            });

            // Bulk delete
            $('#spp-bulk-delete').on('click', function() {
                if (self.selectedItems.length > 0 && confirm('<?php echo esc_js( __( 'Delete selected items from Square and local database?', 'cube-payment-portal' ) ); ?>')) {
                    self.bulkDelete();
                }
            });

            // Add Item Modal
            $('#spp-add-item').on('click', function() {
                self.openModal();
            });

            // Close Modal
            $('.spp-close-modal, .spp-close-modal-btn').on('click', function() {
                $('#spp-item-modal').hide();
            });

            $(window).on('click', function(e) {
                if ($(e.target).is('#spp-item-modal')) {
                    $('#spp-item-modal').hide();
                }
            });

            // Edit Item
            $(document).on('click', '.spp-edit-item, .spp-item-name-link', function(e) {
                e.preventDefault();
                var $row = $(this).closest('tr');
                var itemJson = $row.find('.spp-edit-item').attr('data-item');
                
                if (!itemJson) {
                     // If triggered from name link directly in a way that doesn't have the attribute on itself
                     // fallback to data-item on the edit button in the same row
                     itemJson = $row.find('.spp-edit-item').attr('data-item');
                }

                try {
                    var item = typeof itemJson === 'string' ? JSON.parse(itemJson.replace(/&quot;/g, '"')) : itemJson;
                    if (item) {
                        item.price = parseFloat(item.price);
                        self.openModal(item);
                    }
                } catch(err) {
                    console.error('Error parsing item data', err);
                }
            });

            // Delete Item (Single)
            $(document).on('click', '.spp-delete-item', function(e) {
                e.preventDefault();
                var itemId = $(this).data('id');
                if (itemId && confirm('<?php echo esc_js( __( 'Are you sure you want to delete this item from Square?', 'cube-payment-portal' ) ); ?>')) {
                    self.deleteItem(itemId.toString().trim());
                }
            });

            // Archive/Unarchive Item
            $(document).on('click', '.spp-archive-item', function(e) {
                e.preventDefault();
                var itemId = $(this).data('id');
                var archived = $(this).data('archived');
                var confirmMsg = archived 
                    ? '<?php echo esc_js( __( 'Archive this item? It will be hidden from your POS and main Square dashboard.', 'cube-payment-portal' ) ); ?>'
                    : '<?php echo esc_js( __( 'Unarchive this item?', 'cube-payment-portal' ) ); ?>';

                if (itemId && confirm(confirmMsg)) {
                    self.archiveItem(itemId, archived);
                }
            });

            // Duplicate Item
            $(document).on('click', '.spp-duplicate-item', function(e) {
                e.preventDefault();
                var itemId = $(this).data('id');
                if (itemId && confirm('<?php echo esc_js( __( 'Duplicate this item?', 'cube-payment-portal' ) ); ?>')) {
                    self.duplicateItem(itemId);
                }
            });

            // Save Item Form
            $('#spp-item-form').on('submit', function(e) {
                e.preventDefault();
                self.saveItem();
            });

            // Action Menu Toggle
            $(document).on('click', '.spp-action-btn', function(e) {
                e.stopPropagation();
                var $menu = $(this).siblings('.spp-action-menu');
                $('.spp-action-menu').not($menu).removeClass('is-active');
                $menu.toggleClass('is-active');
            });

            // Close menu on outside click
            $(document).on('click', function() {
                $('.spp-action-menu').removeClass('is-active');
            });

            // Row Toggle (Expand/Collapse)
            $(document).on('click', '.spp-row-toggle', function() {
                var $toggle = $(this);
                var $row = $toggle.closest('tr');
                var itemId = $row.data('id');
                var $variations = $('.spp-parent-' + itemId);

                if ($toggle.hasClass('dashicons-arrow-right-alt2')) {
                    $toggle.removeClass('dashicons-arrow-right-alt2').addClass('dashicons-arrow-down-alt2');
                    $variations.show();
                } else {
                    $toggle.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-right-alt2');
                    $variations.hide();
                }
            });

            // Media Uploader
            var file_frame;
            $('#spp-upload-image-btn').on('click', function(e) {
                e.preventDefault();

                // If the media frame already exists, reopen it.
                if (file_frame) {
                    file_frame.open();
                    return;
                }

                // Create the media frame.
                file_frame = wp.media.frames.file_frame = wp.media({
                    title: '<?php echo esc_js( __( 'Select Item Image', 'cube-payment-portal' ) ); ?>',
                    button: {
                        text: '<?php echo esc_js( __( 'Use this image', 'cube-payment-portal' ) ); ?>',
                    },
                    library: {
                        type: ['image/jpeg', 'image/png', 'image/gif'] // Strict format check
                    },
                    multiple: false
                });

                // When an image is selected, run a callback.
                file_frame.on('select', function() {
                    var attachment = file_frame.state().get('selection').first().toJSON();

                    // Size validation (14MB = 14 * 1024 * 1024 bytes)
                    if (attachment.filesizeInBytes > 14680064) {
                        alert('<?php echo esc_js( __( 'Image exceeds the 14MB limit.', 'cube-payment-portal' ) ); ?>');
                        return;
                    }

                    $('#spp-item-image-id').val(attachment.id);
                    $('#spp-item-image-preview').attr('src', attachment.url).show();
                    $('#spp-remove-image-btn').show();
                    $('#spp-upload-image-btn').text('<?php echo esc_js( __( 'Change Image', 'cube-payment-portal' ) ); ?>');
                });

                // Finally, open the modal
                file_frame.open();
            });

            $('#spp-remove-image-btn').on('click', function() {
                $('#spp-item-image-id').val('');
                $('#spp-item-image-preview').attr('src', '').hide();
                $('#spp-remove-image-btn').hide();
                $('#spp-upload-image-btn').text('<?php echo esc_js( __( 'Select Image', 'cube-payment-portal' ) ); ?>');
            });

            // Apply Price to All Variations
            $('#spp-apply-price-to-all').on('click', function() {
                var price = $('#spp-item-price').val();
                if (price !== '') {
                    $('.spp-variation-price').val(parseFloat(price).toFixed(2));
                }
            });

            // Item Type Toggle
            $('#spp-item-type').on('change', function() {
                if ($(this).val() === 'APPOINTMENTS_SERVICE') {
                    $('#spp-service-duration-row').show();
                    $('#spp-service-duration').prop('required', true).addClass('spp-input-large');
                } else {
                    $('#spp-service-duration-row').hide();
                    $('#spp-service-duration').prop('required', false);
                }
            });
        },

        openModal: function(item) {
            var $modal = $('#spp-item-modal');
            var $form = $('#spp-item-form');
            var $title = $('#spp-modal-title');
            
            // Populate categories if empty
            if ($('#spp-item-category option').length <= 1) {
                this.loadCategories();
            }

            // Reset image fields
            $('#spp-item-image-id').val('');
            $('#spp-item-image-preview').attr('src', '').hide();
            $('#spp-image-placeholder').show();
            $('#spp-remove-image-btn').hide();
            $('#spp-upload-image-btn').text('<?php echo esc_js( __( 'Select Image', 'cube-payment-portal' ) ); ?>');
            
            // Reset types
            $('#spp-item-type').val('REGULAR').trigger('change');
            $('#spp-track-inventory').prop('checked', true); // Default to tracked
            $('#spp-service-duration').val('');

            if (item) {
                $title.text('<?php echo esc_js( __( 'Edit Catalog Item', 'cube-payment-portal' ) ); ?>');
                $('#spp-item-id').val(item.square_item_id);
                $('#spp-item-name').val(item.name);
                $('#spp-item-description').val(item.description);
                $('#spp-item-price').val(item.price ? item.price.toFixed(2) : '');
                $('#spp-item-currency').val(item.currency || 'USD');
                $('#spp-item-category').val(item.category_id);
                
                // Set type
                if (item.product_type) {
                    $('#spp-item-type').val(item.product_type).trigger('change');
                }

                // Set tracking
                // If it's a variation-less item or we just check the first variation (simplified model)
                var isTracked = false;
                if (item.variations) {
                     var vars = typeof item.variations === 'string' ? JSON.parse(item.variations) : item.variations;
                     if (vars && vars.length > 0) {
                         // Check if ANY variation is tracked (simplification)
                         for(var i=0; i<vars.length; i++) {
                             if(vars[i].track_inventory) {
                                 isTracked = true;
                                 break;
                             }
                         }
                     }
                }
                // Also check our flattened property from list view if available
                if (item.track_inventory) {
                    isTracked = true;
                }
                $('#spp-track-inventory').prop('checked', isTracked);

                // Render variations preview
                this.renderVariationsPreview(vars);

                // We don't have duration in item object yet, so leave empty.

                // Populate image if exists (Note: item.image_url comes from local DB sync)
                if (item.image_url) {
                    $('#spp-item-image-preview').attr('src', item.image_url).show();
                    $('#spp-image-placeholder').hide();
                    $('#spp-remove-image-btn').show();
                    $('#spp-upload-image-btn').text('<?php echo esc_js( __( 'Change Image', 'cube-payment-portal' ) ); ?>');
                }
            } else {
                $title.text('<?php echo esc_js( __( 'Add Catalog Item', 'cube-payment-portal' ) ); ?>');
                $form[0].reset();
                $('#spp-item-id').val('');
                $('#spp-item-currency').val('<?php echo esc_js( $default_currency ); ?>');
                $('#spp-variations-preview-row').hide();
                $('.spp-bulk-notice').hide();
                $('#spp-image-placeholder').show();
            }

            $modal.css('display', 'flex');
        },

        renderVariationsPreview: function(variations) {
            var $container = $('#spp-variations-list');
            var $row = $('#spp-variations-preview-row');
            
            if (!variations || variations.length === 0) {
                $row.hide();
                $('#spp-apply-price-to-all').hide();
                $('.spp-bulk-notice').hide();
                return;
            }

            $('#spp-apply-price-to-all').show();
            $('.spp-bulk-notice').show();
            $container.empty();
            var self = this;
            var currencySymbol = this.getCurrencySymbol($('#spp-item-currency').val());

            $.each(variations, function(i, v) {
                var price = parseFloat(v.price).toFixed(2);
                var html = '<div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 15px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">' +
                    '<div style="flex: 1; display: flex; align-items: center; gap: 10px;">' +
                        '<span class="dashicons dashicons-menu" style="color: #cbd5e1; cursor: grab;"></span>' +
                        '<input type="text" class="spp-variation-name-input" data-id="' + v.id + '" value="' + self.escapeHtml(v.name) + '" style="font-weight: 500; border: 1px solid transparent; background: transparent; padding: 4px 8px; border-radius: 4px; transition: all 0.2s; width: 60%;" onfocus="this.style.border=\'1px solid #cbd5e1\'; this.style.background=\'#fff\'" onblur="this.style.border=\'1px solid transparent\'; this.style.background=\'transparent\'">' +
                    '</div>' +
                    '<div style="display: flex; align-items: center; gap: 12px;">' +
                        '<div style="display: flex; align-items: center; background: #f1f5f9; border-radius: 6px; padding: 2px 8px;">' +
                            '<span style="color: #64748b; font-size: 13px; margin-right: 5px;">' + currencySymbol + '</span>' +
                            '<input type="number" class="spp-variation-price-input" data-id="' + v.id + '" value="' + price + '" step="0.01" style="width: 70px; border: none; background: transparent; font-weight: 600; padding: 4px 0; margin: 0;">' +
                        '</div>' +
                    '</div>' +
                '</div>';
                $container.append(html);
            });

            $row.show();
        },

        loadCategories: function() {
            $.ajax({
                url: sppAdmin.ajaxUrl,
                type: 'POST',
                data: { action: 'spp_get_square_categories', nonce: sppAdmin.nonce },
                success: function(response) {
                    if (response.success && response.data.categories) {
                        var options = '<option value=""><?php echo esc_js( __( 'None', 'cube-payment-portal' ) ); ?></option>';
                        $.each(response.data.categories, function(i, cat) {
                            options += '<option value="' + cat.id + '">' + cat.name + '</option>';
                        });
                        $('#spp-item-category').html(options);
                    }
                }
            });
        },

        saveItem: function() {
            var self = this;
            var $form = $('#spp-item-form');
            var $btn = $('#spp-save-item-btn');
            var $spinner = $('#spp-modal-spinner');
            var itemId = $('#spp-item-id').val();
            var action = itemId ? 'spp_update_catalog_item' : 'spp_create_catalog_item';

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');

            var variationPrices = {};
            $('.spp-variation-price-input').each(function() {
                var id = $(this).data('id');
                var val = $(this).val();
                if (id && val !== '') {
                    variationPrices[id] = val;
                }
            });

            var variationNames = {};
            $('.spp-variation-name-input').each(function() {
                var id = $(this).data('id');
                var val = $(this).val();
                if (id && val !== '') {
                    variationNames[id] = val;
                }
            });

            var data = {
                action: action,
                nonce: sppAdmin.nonce,
                name: $('#spp-item-name').val(),
                description: $('#spp-item-description').val(),
                price: $('#spp-item-price').val(),
                currency: $('#spp-item-currency').val(),
                category_id: $('#spp-item-category').val(),
                image_id: $('#spp-item-image-id').val(),
                product_type: $('#spp-item-type').val(),
                track_inventory: $('#spp-track-inventory').is(':checked') ? 1 : 0,
                service_duration: $('#spp-service-duration').val(),
                variation_prices: variationPrices,
                variation_names: variationNames
            };

            if (itemId) {
                data.item_id = itemId;
            }

            $.ajax({
                url: sppAdmin.ajaxUrl,
                type: 'POST',
                data: data,
                success: function(response) {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');

                    if (response.success) {
                        $('#spp-item-modal').hide();
                        // self.showSuccess(response.data.message); // Should implement a nice toast
                        self.loadCatalog(); // Refresh list
                    } else {
                        alert(response.data.message || 'Error saving item');
                    }
                },
                error: function() {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    alert('Network error');
                }
            });
        },

        deleteItem: function(itemId) {
            var self = this;
            $.ajax({
                url: sppAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'spp_delete_catalog_item',
                    nonce: sppAdmin.nonce,
                    item_id: itemId
                },
                success: function(response) {
                    if (response.success) {
                        var $message = $('#spp-sync-message');
                        $message.removeClass('notice-error').addClass('notice-success is-dismissible').show();
                        $message.find('p').text(response.data.message || '<?php echo esc_js( __( 'Item deleted successfully.', 'cube-payment-portal' ) ); ?>');
                        setTimeout(function() { $message.fadeOut(); }, 5000);
                        self.loadCatalog();
                    } else {
                        alert(response.data.message || 'Error deleting item');
                    }
                }
            });
        },

        archiveItem: function(itemId, archived) {
            var self = this;
            $.ajax({
                url: sppAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'spp_archive_catalog_item',
                    nonce: sppAdmin.nonce,
                    item_id: itemId,
                    archived: archived
                },
                success: function(response) {
                    if (response.success) {
                        var $message = $('#spp-sync-message');
                        $message.removeClass('notice-error').addClass('notice-success is-dismissible').show();
                        $message.find('p').text(response.data.message);
                        setTimeout(function() { $message.fadeOut(); }, 5000);
                        self.loadCatalog();
                    } else {
                        alert(response.data.message || 'Error updating item');
                    }
                }
            });
        },

        duplicateItem: function(itemId) {
            var self = this;
            $.ajax({
                url: sppAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'spp_duplicate_catalog_item',
                    nonce: sppAdmin.nonce,
                    item_id: itemId
                },
                success: function(response) {
                    if (response.success) {
                        var $message = $('#spp-sync-message');
                        $message.removeClass('notice-error').addClass('notice-success is-dismissible').show();
                        $message.find('p').text(response.data.message);
                        setTimeout(function() { $message.fadeOut(); }, 5000);
                        self.loadCatalog();
                    } else {
                        alert(response.data.message || 'Error duplicating item');
                    }
                }
            });
        },

        updateSortIndicators: function() {
            $('.spp-sort-link').each(function() {
                var $link = $(this);
                var $th = $link.closest('th');
                $th.removeClass('asc desc sorted');
            });

            var $activeLink = $('.spp-sort-link[data-sort="' + this.sortBy + '"]');
            var $activeTh = $activeLink.closest('th');
            $activeTh.addClass('sorted ' + this.sortOrder.toLowerCase());
        },

        updateSelectedItems: function() {
            var self = this;
            self.selectedItems = [];
            $('.spp-item-checkbox:checked').each(function() {
                self.selectedItems.push($(this).val());
            });

            if (self.selectedItems.length > 0) {
                $('#spp-bulk-delete').show();
            } else {
                $('#spp-bulk-delete').hide();
            }
        },

        loadCatalog: function() {
            var self = this;
            
            $('#spp-loading').show();
            $('#spp-catalog-table-wrap').hide();
            $('#spp-empty-state').hide();

            $.ajax({
                url: sppAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'spp_get_catalog',
                    nonce: sppAdmin.nonce,
                    search: $('#spp-search').val(),
                    category_id: $('#spp-category').val(),
                    page: self.currentPage,
                    per_page: self.perPage,
                    sort_by: self.sortBy,
                    sort_order: self.sortOrder
                },
                success: function(response) {
                    $('#spp-loading').hide();

                    if (!response || !response.success) {
                        var errorMsg = (response && response.data && response.data.message) ? response.data.message : '<?php echo esc_js( __( 'Failed to load catalog.', 'cube-payment-portal' ) ); ?>';
                        self.showError(errorMsg);
                        return;
                    }

                    var data = response.data;
                    if (!data || !data.items || data.items.length === 0) {
                        $('#spp-empty-state').show();
                        // Reset logic handled by empty state itself
                        return;
                    }

                    self.renderCatalog(data.items);
                    self.renderPagination(data.pagination);
                    $('#spp-catalog-table-wrap').show();
                },
                error: function() {
                    $('#spp-loading').hide();
                    self.showError('<?php echo esc_js( __( 'Network error. Please try again.', 'cube-payment-portal' ) ); ?>');
                }
            });
        },

        renderCatalog: function(items) {
            var $tbody = $('#spp-catalog-tbody');
            $tbody.empty();

            var self = this;
            $.each(items, function(i, item) {
                var row = self.buildRow(item);
                $tbody.append(row);
            });

            $('#spp-select-all, #spp-select-all-footer').prop('checked', false);
            self.selectedItems = [];
            $('#spp-bulk-delete').hide();
        },

        buildRow: function(item) {
            var self = this;
            var currencySymbol = this.getCurrencySymbol(item.currency);
            var variations = item.variations;
            if (typeof variations === 'string') {
                try {
                    variations = JSON.parse(variations);
                } catch(e) { variations = []; }
            }
            variations = variations || [];

            var variationCount = variations.length;
            var categoryName = item.category_name || '<?php echo esc_js( __( 'Uncategorized', 'cube-payment-portal' ) ); ?>';

            // Check if tracked
            var isTracked = this.isItemTracked(item, variations);

            // Price display (range if multiply prices)
            var priceDisplay = currencySymbol + parseFloat(item.price).toFixed(2);
            var totalStock = 0;
            var hasStockData = false;

            if (variationCount > 0) {
                $.each(variations, function(i, v) {
                    if (v.inventory_count !== undefined && v.inventory_count !== null) {
                        totalStock += parseInt(v.inventory_count);
                        hasStockData = true;
                    }
                });
            }

            if (variationCount > 1) {
                var prices = variations.map(function(v) { return parseFloat(v.price); }).filter(function(p) { return !isNaN(p); });
                if (prices.length > 1) {
                    var minPrice = Math.min.apply(null, prices);
                    var maxPrice = Math.max.apply(null, prices);
                    if (minPrice !== maxPrice) {
                        priceDisplay = currencySymbol + minPrice.toFixed(2) + ' - ' + currencySymbol + maxPrice.toFixed(2);
                    }
                }
            }

            // Image display
            var imageDisplay = item.image_url
                ? '<img src="' + item.image_url + '" style="width: 32px; height: 32px; object-fit: cover; border-radius: 4px;">'
                : '<div style="width: 32px; height: 32px; background: #f0f0f0; border-radius: 4px;"></div>';

            var itemJson = JSON.stringify(item).replace(/"/g, '&quot;');
            
            // Expand toggle
            var toggleHtml = variationCount > 1 
                ? '<span class="spp-row-toggle dashicons dashicons-arrow-right-alt2" style="cursor: pointer; color: #666; font-size: 16px; width: 16px; height: 16px;"></span>' 
                : '';

            var archiveIcon = item.is_archived ? ' <span class="dashicons dashicons-archive archived-indicator" title="<?php esc_attr_e( 'This item is archived', 'cube-payment-portal' ); ?>" style="color: #999; font-size: 16px; margin-left: 5px; cursor: help;"></span>' : '';
            var archiveAction = item.is_archived 
                ? '<a href="#" class="spp-action-menu-item spp-archive-item" data-id="' + item.square_item_id + '" data-archived="0"><span class="dashicons dashicons-undo"></span><?php esc_html_e( 'Unarchive', 'cube-payment-portal' ); ?></a>'
                : '<a href="#" class="spp-action-menu-item spp-archive-item" data-id="' + item.square_item_id + '" data-archived="1"><span class="dashicons dashicons-archive"></span><?php esc_html_e( 'Archive', 'cube-payment-portal' ); ?></a>';

            // Parent Row
            var html = '<tr class="spp-item-row' + (item.is_archived ? ' is-archived' : '') + '" data-id="' + item.square_item_id + '">' +
                '<td class="column-toggle">' + toggleHtml + '</td>' +
                '<th scope="row" class="check-column"><input type="checkbox" name="item[]" value="' + item.square_item_id + '" class="spp-item-checkbox"></th>' +
                '<td class="column-name">' +
                    '<div style="display: flex; align-items: center; gap: 12px;">' +
                        imageDisplay +
                        '<div>' +
                            '<span class="spp-item-name-link">' + this.escapeHtml(item.name) + '</span>' + archiveIcon +
                        '</div>' +
                    '</div>' +
                '</td>' +
                '<td class="column-category">' + this.escapeHtml(categoryName) + '</td>' +
                '<td class="column-stock">' + ( (isTracked || hasStockData) ? totalStock : '-' ) + '</td>' +
                '<td class="column-price">' + priceDisplay + '/ea</td>' +
                '<td class="column-actions">' +
                    '<span class="spp-action-btn dashicons dashicons-ellipsis"></span>' +
                    '<div class="spp-action-menu">' +
                        '<a href="#" class="spp-action-menu-item spp-edit-item" data-item="' + itemJson + '"><span class="dashicons dashicons-edit"></span><?php esc_html_e( 'Edit', 'cube-payment-portal' ); ?></a>' +
                        '<a href="#" class="spp-action-menu-item spp-duplicate-item" data-id="' + item.square_item_id + '"><span class="dashicons dashicons-admin-page"></span><?php esc_html_e( 'Duplicate', 'cube-payment-portal' ); ?></a>' +
                        archiveAction +
                        '<a href="#" class="spp-action-menu-item delete spp-delete-item" data-id="' + item.square_item_id + '"><span class="dashicons dashicons-trash"></span><?php esc_html_e( 'Delete', 'cube-payment-portal' ); ?></a>' +
                    '</div>' +
                '</td>' +
            '</tr>';

            // Variation Rows (indented)
            if (variationCount > 1) {
                $.each(variations, function(idx, v) {
                    var vPrice = currencySymbol + parseFloat(v.price).toFixed(2);
                    var vImage = item.image_url 
                        ? '<img src="' + item.image_url + '" style="width: 24px; height: 24px; object-fit: cover; border-radius: 3px; opacity: 0.7;">' 
                        : '<div style="width: 24px; height: 24px; background: #eee; border-radius: 3px;"></div>';

                    html += '<tr class="spp-variation-row spp-parent-' + item.square_item_id + '" style="display: none; background: #fafafa;">' +
                        '<td class="column-toggle"></td>' +
                        '<td class="check-column" style="padding-left: 10px;"><input type="checkbox" disabled style="opacity: 0.5;"></td>' +
                        '<td class="column-name" style="padding-left: 20px;">' +
                            '<div style="display: flex; align-items: center; gap: 10px; padding-left: 25px; border-left: 2px solid #eee;">' +
                                vImage +
                                '<span style="color: #666; font-size: 13px;">' + self.escapeHtml(v.name) + '</span>' +
                            '</div>' +
                        '</td>' +
                        '<td class="column-category"></td>' +
                        '<td class="column-stock" style="color: #666; font-size: 13px;">' + ( v.inventory_count !== undefined ? v.inventory_count : '-' ) + '</td>' +
                        '<td class="column-price" style="color: #666; font-size: 13px;">' + vPrice + '/ea</td>' +
                        '<td class="column-actions"></td>' +
                    '</tr>';
                });
            }

            return html;
        },

        isItemTracked: function(item, variations) {
            // Honor the track_inventory flag from the database if present for the item or any variation
            if (item.track_inventory) return true;

            if (variations && variations.length > 0) {
                for (var i = 0; i < variations.length; i++) {
                    if (variations[i].track_inventory) return true;
                }
            }
            return false;
        },


        getCurrencySymbol: function(currency) {
            var symbols = { EUR: '€', GBP: '£', JPY: '¥' };
            return symbols[currency] || '$';
        },

        formatTimeAgo: function(dateString) {
            if (!dateString) return '';
            var date = new Date(dateString.replace(' ', 'T')); // Handle MySQL datetime format
            if (isNaN(date.getTime())) return dateString; // Return raw string if parsing fails
            
            // Format as short date + time
            var options = { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' };
            return date.toLocaleDateString('en-US', options);
        },

        renderPagination: function(pagination) {
            var total = pagination.total, totalPages = pagination.total_pages, currentPage = pagination.current_page;
            $('#spp-total-count').text(total + ' <?php echo esc_js( __( 'items', 'cube-payment-portal' ) ); ?>');

            if (totalPages <= 1) { $('#spp-pagination-links').empty(); return; }

            var links = '';
            links += currentPage > 1 ? '<a href="#" class="first-page button spp-page-link" data-page="1">&laquo;</a> <a href="#" class="prev-page button spp-page-link" data-page="' + (currentPage - 1) + '">&lsaquo;</a> ' : '<span class="button disabled">&laquo;</span> <span class="button disabled">&lsaquo;</span> ';
            links += '<span class="paging-input">' + currentPage + ' <?php echo esc_js( __( 'of', 'cube-payment-portal' ) ); ?> ' + totalPages + '</span>';
            links += currentPage < totalPages ? ' <a href="#" class="next-page button spp-page-link" data-page="' + (currentPage + 1) + '">&rsaquo;</a> <a href="#" class="last-page button spp-page-link" data-page="' + totalPages + '">&raquo;</a>' : ' <span class="button disabled">&rsaquo;</span> <span class="button disabled">&raquo;</span>';

            $('#spp-pagination-links').html(links);
        },

        syncCatalog: function() {
            var self = this;
            var $button = $('#spp-sync-catalog, #spp-sync-catalog-empty');
            var $message = $('#spp-sync-message');

            $button.prop('disabled', true).find('.dashicons').addClass('spin');

            $.ajax({
                url: sppAdmin.ajaxUrl,
                type: 'POST',
                data: { action: 'spp_sync_catalog', nonce: sppAdmin.nonce },
                success: function(response) {
                    $button.prop('disabled', false).find('.dashicons').removeClass('spin');

                    if (response && response.success) {
                        $message.removeClass('notice-error').addClass('notice-success is-dismissible').show();
                        $message.find('p').text((response.data && response.data.message) || '<?php echo esc_js( __( 'Catalog synced successfully.', 'cube-payment-portal' ) ); ?>');
                        $('#spp-last-sync-time').text('<?php echo esc_js( __( 'Just now', 'cube-payment-portal' ) ); ?>');
                        self.currentPage = 1;
                        self.loadCatalog();
                    } else {
                        $message.removeClass('notice-success').addClass('notice-error is-dismissible').show();
                        $message.find('p').text((response && response.data && response.data.message) || '<?php echo esc_js( __( 'Sync failed.', 'cube-payment-portal' ) ); ?>');
                    }
                    setTimeout(function() { $message.fadeOut(); }, 8000);
                },
                error: function() {
                    $button.prop('disabled', false).find('.dashicons').removeClass('spin');
                    $message.removeClass('notice-success').addClass('notice-error is-dismissible').show();
                    $message.find('p').text('<?php echo esc_js( __( 'Network error.', 'cube-payment-portal' ) ); ?>');
                }
            });
        },

        bulkDelete: function() {
            var self = this;
            var $message = $('#spp-sync-message');

            $.ajax({
                url: sppAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'spp_bulk_delete_catalog',
                    nonce: sppAdmin.nonce,
                    item_ids: self.selectedItems
                },
                success: function(response) {
                    if (response && response.success) {
                        $message.removeClass('notice-error').addClass('notice-success is-dismissible').show();
                        $message.find('p').text((response.data && response.data.message) || '<?php echo esc_js( __( 'Items deleted.', 'cube-payment-portal' ) ); ?>');
                        self.loadCatalog();
                    } else {
                        $message.removeClass('notice-success').addClass('notice-error is-dismissible').show();
                        $message.find('p').text((response && response.data && response.data.message) || '<?php echo esc_js( __( 'Delete failed.', 'cube-payment-portal' ) ); ?>');
                    }
                    setTimeout(function() { $message.fadeOut(); }, 5000);
                }
            });
        },

        showError: function(message) { $('#spp-empty-state').show().find('h2').text(message); },
        escapeHtml: function(text) { if (!text) return ''; var div = document.createElement('div'); div.appendChild(document.createTextNode(text)); return div.innerHTML; }
    };

    $(document).ready(function() { SPPCatalog.init(); });
})(jQuery);
</script>

<style>
/* Action Menu Styles */
.column-actions { 
    position: relative; 
    overflow: visible !important; 
    text-align: right !important; 
    vertical-align: middle !important; 
}
.spp-action-btn {
    cursor: pointer;
    color: #333;
    font-size: 20px;
    width: 24px;
    height: 24px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    transition: background 0.2s;
}
.spp-action-btn:hover { background: #f0f0f0; color: #2271b1; }

.spp-action-menu {
    position: absolute;
    right: 5px;
    top: 35px;
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 6px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 9999;
    min-width: 140px;
    padding: 5px 0;
    display: none;
    text-align: left;
}
.spp-action-menu.is-active { display: block; }

.spp-action-menu-item {
    display: flex;
    align-items: center;
    padding: 8px 15px;
    color: #444;
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    transition: background 0.1s;
    cursor: pointer;
}
.spp-action-menu-item:hover { background: #f6f7f7; color: #2271b1; }
.spp-action-menu-item.delete { color: #d63638; }
.spp-action-menu-item.delete:hover { background: #fcf0f1; color: #d63638; }

/* Archived Item Styling */
.spp-item-row.is-archived td {
    opacity: 0.6;
    background-color: #fbfbfb;
}
.spp-item-row.is-archived td strong {
    color: #666;
    font-weight: 500;
}
.archived-indicator {
    vertical-align: middle;
    color: #999;
}

.spp-action-menu-item .dashicons { 
    font-size: 16px; 
    width: 16px; 
    height: 16px; 
    margin-right: 10px; 
    line-height: inherit; 
}

/* Nested Variations Styles */
.spp-item-row { background: #fff !important; }
.spp-variation-row td { 
    border-top: none !important; 
    padding-top: 5px !important; 
    padding-bottom: 5px !important; 
}
.spp-variation-row:last-child td { border-bottom: 1px solid #f0f0f1 !important; }
.column-toggle { vertical-align: middle !important; padding-top: 12px !important; }
.spp-row-toggle:hover { color: #2271b1 !important; }

/* Vertically align ellipsis */
.column-actions { vertical-align: middle !important; }

/* Remove striping from variation rows to maintain indentation look */
.striped > tbody > .spp-variation-row:nth-child(even) { background-color: #fafafa !important; }
.striped > tbody > .spp-variation-row:nth-child(odd) { background-color: #fafafa !important; }

/* Badge styles */
.spp-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 500;
    line-height: 1.4;
}
.spp-badge-info {
    background: #e3f2fd;
    color: #1565c0;
}
.spp-badge-default {
    background: #f0f0f1;
    color: #50575e;
}
.spp-badge-muted {
    background: #f9f9f9;
    color: #999;
}

/* Table enhancements */
.wp-list-table .column-name { font-weight: 500; }
.wp-list-table .column-price strong { color: #0073aa; }

/* Sorting */
.wp-list-table th.sorted.asc .sorting-indicator.asc,
.wp-list-table th.sorted.desc .sorting-indicator.desc { visibility: visible; }

/* Spin animation */
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
.dashicons.spin { animation: spin 1s linear infinite; }
</style>
