<?php
/**
 * Admin Settings page.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Capability check - ensure user has permission to manage settings.
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'You do not have permission to access this page.', 'cube-payment-portal' ) );
}

$oauth = new SPP_OAuth();
$is_connected = $oauth->is_connected();
$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';

// Handle manual database migration.
if ( isset( $_POST['spp_run_migration'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['spp_migration_nonce'] ) ), 'spp_run_migration' ) ) {
    // Load activator class if not already loaded.
    if ( ! class_exists( 'SPP_Activator' ) ) {
        require_once SPP_PLUGIN_DIR . 'includes/class-spp-activator.php';
    }
    
    // Run both create_tables (for new tables via dbDelta) and migrate_tables (for column updates).
    SPP_Activator::create_tables();
    SPP_Activator::migrate_tables();
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Database migration completed successfully! New tables created and existing tables updated.', 'cube-payment-portal' ) . '</p></div>';
}

// Show success message if just connected.
if ( isset( $_GET['connected'] ) && sanitize_text_field( wp_unslash( $_GET['connected'] ) ) === '1' ) {
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Successfully connected to Square!', 'cube-payment-portal' ) . '</p></div>';
}

// Show error message if connection failed.
if ( isset( $_GET['error'] ) && sanitize_text_field( wp_unslash( $_GET['error'] ) ) === '1' ) {
    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to connect to Square. Please check your credentials and try again.', 'cube-payment-portal' ) . '</p></div>';
}
?>

<div class="wrap spp-admin-wrap">
    <h1 class="screen-reader-text"><?php esc_html_e( 'Cube Payment Portal Settings', 'cube-payment-portal' ); ?></h1>
    <hr class="wp-header-end">
    <h2 style="margin: 0; font-size: 24px; font-weight: 600; color: #333;"><?php esc_html_e( 'Cube Payment Portal Settings', 'cube-payment-portal' ); ?></h2>

    <!-- Tabs -->
    <nav class="nav-tab-wrapper">
        <a href="?page=spp-settings&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'General', 'cube-payment-portal' ); ?>
        </a>
        <a href="?page=spp-settings&tab=features" class="nav-tab <?php echo $active_tab === 'features' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'Features', 'cube-payment-portal' ); ?>
        </a>
        <a href="?page=spp-settings&tab=portal" class="nav-tab <?php echo $active_tab === 'portal' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'Client Portal', 'cube-payment-portal' ); ?>
        </a>
        <a href="?page=spp-settings&tab=webhooks" class="nav-tab <?php echo $active_tab === 'webhooks' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'Webhooks', 'cube-payment-portal' ); ?>
        </a>
        <a href="?page=spp-settings&tab=api" class="nav-tab <?php echo $active_tab === 'api' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'API Configuration', 'cube-payment-portal' ); ?>
        </a>
        <a href="?page=spp-settings&tab=scheduled-tasks" class="nav-tab <?php echo $active_tab === 'scheduled-tasks' ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'Scheduled Tasks', 'cube-payment-portal' ); ?>
        </a>
    </nav>

    <div class="spp-admin-card" style="margin-top: 20px;">
        
        <?php if ( $active_tab === 'features' ) : ?>
            
            <form method="post" action="options.php">
                <?php settings_fields( 'spp_feature_settings' ); ?>
                <div class="spp-features-grid-wrapper">
                    <?php do_settings_sections( 'spp_feature_settings' ); ?>
                </div>
            </form>

        <?php elseif ( $active_tab === 'api' ) : ?>
            
            <!-- Connection Status -->
            <div class="spp-connection-status <?php echo $is_connected ? 'connected' : 'disconnected'; ?>" style="margin-bottom: 20px;">
                <?php if ( $is_connected ) : ?>
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php esc_html_e( 'Connected to Square', 'cube-payment-portal' ); ?>
                    <button type="button" class="button spp-disconnect-button" style="margin-left: 10px;">
                        <?php esc_html_e( 'Disconnect', 'cube-payment-portal' ); ?>
                    </button>
                <?php else : ?>
                    <span class="dashicons dashicons-warning"></span>
                    <?php esc_html_e( 'Not connected to Square', 'cube-payment-portal' ); ?>
                <?php endif; ?>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'spp_api_settings' ); ?>

                <table class="form-table">
                    <?php if ( defined( 'SPP_DEVELOPER_MODE' ) && SPP_DEVELOPER_MODE ) : ?>
                    <tr>
                        <th scope="row">
                            <?php esc_html_e( 'Environment', 'cube-payment-portal' ); ?>
                            <span class="spp-badge" style="background: #d63638; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; margin-left: 5px;">DEV</span>
                        </th>
                        <td>
                            <select name="spp_environment">
                                <option value="sandbox" <?php selected( get_option( 'spp_environment' ), 'sandbox' ); ?>>
                                    <?php esc_html_e( 'Sandbox (Testing)', 'cube-payment-portal' ); ?>
                                </option>
                                <option value="production" <?php selected( get_option( 'spp_environment' ), 'production' ); ?>>
                                    <?php esc_html_e( 'Production', 'cube-payment-portal' ); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Application ID', 'cube-payment-portal' ); ?></th>
                        <td>
                            <input type="text" name="spp_application_id" value="<?php echo esc_attr( get_option( 'spp_application_id' ) ); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e( 'Your Square Application ID from the Square Developer Console.', 'cube-payment-portal' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Application Secret', 'cube-payment-portal' ); ?></th>
                        <td>
                            <input type="password" name="spp_application_secret" value="<?php echo esc_attr( get_option( 'spp_application_secret' ) ); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e( 'Your Square Application Secret, used for OAuth authentication.', 'cube-payment-portal' ); ?></p>
                        </td>
                    </tr>
                    <?php if ( defined( 'SPP_DEVELOPER_MODE' ) && SPP_DEVELOPER_MODE && get_option( 'spp_environment', 'production' ) === 'sandbox' ) : ?>
                    <tr class="spp-sandbox-token-row">
                        <th scope="row">
                            <?php esc_html_e( 'Sandbox Access Token', 'cube-payment-portal' ); ?>
                            <span class="spp-badge" style="background: #d63638; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; margin-left: 5px;">DEV</span>
                        </th>
                        <td>
                            <input type="password" name="spp_sandbox_access_token" value="<?php echo esc_attr( get_option( 'spp_sandbox_access_token' ) ); ?>" class="regular-text">
                            <p class="description">
                                <?php esc_html_e( 'Paste your Sandbox access token from Square Developer Console for direct testing (bypasses OAuth).', 'cube-payment-portal' ); ?>
                                <br>
                                <a href="https://developer.squareup.com/console/en/sandbox-test-accounts" target="_blank">
                                    <?php esc_html_e( 'Get your sandbox access token', 'cube-payment-portal' ); ?> &rarr;
                                </a>
                            </p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>

                <?php submit_button(); ?>
            </form>

            <?php if ( ! $is_connected ) : ?>
            <div style="margin-top: 10px; padding: 20px; background: #f0f6fc; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1;">
                <h3 style="margin-top: 0;"><?php esc_html_e( 'Connect to Square', 'cube-payment-portal' ); ?></h3>
                <p><?php esc_html_e( 'After saving your Application ID and Secret above, click the button below to connect your Square account via OAuth.', 'cube-payment-portal' ); ?></p>
                <button type="button" class="button button-primary button-hero spp-connect-button">
                    <?php esc_html_e( 'Connect to Square', 'cube-payment-portal' ); ?>
                </button>
            </div>
            <?php endif; ?>

        <?php elseif ( $active_tab === 'general' ) : ?>
            
            <form method="post" action="options.php">
                <?php settings_fields( 'spp_general_settings' ); ?>
                
                <h3><?php esc_html_e( 'Location Settings', 'cube-payment-portal' ); ?></h3>
                
                <?php if ( $is_connected ) : ?>
                    <?php
                    // Fetch locations from Square.
                    $client = new SPP_Square_Client();
                    $locations_response = $client->get( 'locations' );
                    $locations = array();
                    
                    if ( ! is_wp_error( $locations_response ) && ! empty( $locations_response['locations'] ) ) {
                        $locations = $locations_response['locations'];
                    }
                    ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Default Location', 'cube-payment-portal' ); ?></th>
                            <td>
                                <?php if ( ! empty( $locations ) ) : ?>
                                    <select name="spp_default_location_id" class="regular-text">
                                        <option value=""><?php esc_html_e( '— Select a Location —', 'cube-payment-portal' ); ?></option>
                                        <?php foreach ( $locations as $location ) : ?>
                                            <option value="<?php echo esc_attr( $location['id'] ); ?>" <?php selected( get_option( 'spp_default_location_id' ), $location['id'] ); ?>>
                                                <?php 
                                                echo esc_html( $location['name'] );
                                                if ( ! empty( $location['address']['locality'] ) ) {
                                                    echo ' (' . esc_html( $location['address']['locality'] );
                                                    if ( ! empty( $location['address']['administrative_district_level_1'] ) ) {
                                                        echo ', ' . esc_html( $location['address']['administrative_district_level_1'] );
                                                    }
                                                    echo ')';
                                                }
                                                ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        <?php esc_html_e( 'Select the default Square location for invoices and transactions.', 'cube-payment-portal' ); ?>
                                    </p>
                                <?php else : ?>
                                    <p class="description" style="color: #d63638;">
                                        <span class="dashicons dashicons-warning"></span>
                                        <?php esc_html_e( 'Unable to fetch locations. Please check your API connection.', 'cube-payment-portal' ); ?>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Current Location ID', 'cube-payment-portal' ); ?></th>
                            <td>
                                <?php  
                                $current_location = get_option( 'spp_default_location_id', '' );
                                if ( ! empty( $current_location ) ) {
                                    echo '<code>' . esc_html( $current_location ) . '</code>';
                                } else {
                                    echo '<em>' . esc_html__( 'Not set', 'cube-payment-portal' ) . '</em>';
                                }
                                ?>
                                <p class="description">
                                    <?php esc_html_e( 'This is your currently saved location ID.', 'cube-payment-portal' ); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                <?php else : ?>
                    <div class="notice notice-warning inline">
                        <p>
                            <strong><?php esc_html_e( 'Not connected to Square', 'cube-payment-portal' ); ?></strong><br>
                            <?php esc_html_e( 'Please connect to Square in the API Configuration tab to manage location settings.', 'cube-payment-portal' ); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <!-- Plugin Updates -->
                <hr style="border: none; border-top: 1px solid #e2e4e7; margin: 24px 0;">
                <h3><?php esc_html_e( 'Plugin Updates', 'cube-payment-portal' ); ?></h3>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Installed Version', 'cube-payment-portal' ); ?></th>
                        <td>
                            <strong><?php echo esc_html( SPP_VERSION ); ?></strong>
                            <?php
                            $update_plugins = get_site_transient( 'update_plugins' );
                            $plugin_file    = SPP_PLUGIN_BASENAME;
                            if ( isset( $update_plugins->response[ $plugin_file ] ) ) {
                                $update_info = $update_plugins->response[ $plugin_file ];
                                echo ' &mdash; <span style="color: #d63638;">';
                                echo '<span class="dashicons dashicons-update" style="font-size: 16px; width: 16px; height: 16px; vertical-align: text-bottom;"></span> ';
                                printf(
                                    /* translators: %s: new version number */
                                    esc_html__( 'Update available: %s', 'cube-payment-portal' ),
                                    '<strong>' . esc_html( $update_info->new_version ) . '</strong>'
                                );
                                echo ' <a href="' . esc_url( self_admin_url( 'update-core.php' ) ) . '">' . esc_html__( 'Update now', 'cube-payment-portal' ) . '</a>';
                                echo '</span>';
                            } else {
                                echo ' &mdash; <span style="color: #00a32a;">';
                                echo '<span class="dashicons dashicons-yes" style="font-size: 16px; width: 16px; height: 16px; vertical-align: text-bottom;"></span> ';
                                esc_html_e( 'You are running the latest version.', 'cube-payment-portal' );
                                echo '</span>';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Auto-Updates', 'cube-payment-portal' ); ?></th>
                        <td>
                            <?php if ( version_compare( get_bloginfo( 'version' ), '5.5', '>=' ) ) : ?>
                                <?php
                                $auto_update    = (bool) get_option( 'spp_auto_update', false );
                                $core_updates   = (array) get_site_option( 'auto_update_plugins', array() );
                                $auto_update    = $auto_update || in_array( SPP_PLUGIN_BASENAME, $core_updates, true );
                                ?>
                                <label class="spp-switch">
                                    <input type="checkbox" name="spp_auto_update" value="1" <?php checked( $auto_update ); ?>>
                                    <span class="spp-slider round"></span>
                                    <span class="spp-toggle-label">
                                        <?php echo $auto_update ? esc_html__( 'Enabled', 'cube-payment-portal' ) : esc_html__( 'Disabled', 'cube-payment-portal' ); ?>
                                    </span>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( 'When enabled, WordPress will automatically install new versions of Cube Payment Portal when they become available.', 'cube-payment-portal' ); ?>
                                </p>
                            <?php else : ?>
                                <p class="description" style="color: #666;">
                                    <span class="dashicons dashicons-info" style="font-size: 16px; width: 16px; height: 16px; vertical-align: text-bottom;"></span>
                                    <?php
                                    printf(
                                        /* translators: %s: required WordPress version */
                                        esc_html__( 'Auto-updates require WordPress %s or higher.', 'cube-payment-portal' ),
                                        '5.5'
                                    );
                                    ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <!-- Portal Pages -->
            <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #ccc;">
                <h3><?php esc_html_e( 'Portal Pages', 'cube-payment-portal' ); ?></h3>
                <p class="description">
                    <?php esc_html_e( 'These pages are automatically created on plugin activation. If accidentally deleted, click "Recreate Pages" to restore them.', 'cube-payment-portal' ); ?>
                </p>

                <table class="form-table" style="margin-top: 10px;">
                    <?php
                    $page_entries = array(
                        'spp_portal_page_id'   => __( 'Portal Page', 'cube-payment-portal' ),
                        'spp_login_page_id'    => __( 'Login Page', 'cube-payment-portal' ),
                        'spp_register_page_id' => __( 'Registration Page', 'cube-payment-portal' ),
                    );
                    foreach ( $page_entries as $option_key => $label ) :
                        $pg_id = get_option( $option_key );
                    ?>
                        <tr>
                            <th scope="row"><?php echo esc_html( $label ); ?></th>
                            <td>
                                <?php if ( $pg_id && 'publish' === get_post_status( $pg_id ) ) : ?>
                                    <strong><?php echo esc_html( get_the_title( $pg_id ) ); ?></strong>
                                    &mdash;
                                    <a href="<?php echo esc_url( get_permalink( $pg_id ) ); ?>" target="_blank"><?php esc_html_e( 'View', 'cube-payment-portal' ); ?></a>
                                    |
                                    <a href="<?php echo esc_url( get_edit_post_link( $pg_id ) ); ?>"><?php esc_html_e( 'Edit', 'cube-payment-portal' ); ?></a>
                                    <p class="description"><?php echo esc_html( get_permalink( $pg_id ) ); ?></p>
                                <?php else : ?>
                                    <span style="color: #d63638;"><?php esc_html_e( 'Not found. Click "Recreate Pages" below to restore.', 'cube-payment-portal' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <p style="margin-top: 15px;">
                    <button type="button" class="button" id="spp-recreate-pages">
                        <span class="dashicons dashicons-admin-page" style="margin-top: 3px; margin-right: 3px;"></span>
                        <?php esc_html_e( 'Recreate Pages', 'cube-payment-portal' ); ?>
                    </button>
                    <span id="spp-recreate-pages-status" style="margin-left: 10px;"></span>
                </p>

                <script type="text/javascript">
                jQuery(document).ready(function($) {
                    $('#spp-recreate-pages').on('click', function(e) {
                        e.preventDefault();
                        var $btn = $(this);
                        var $status = $('#spp-recreate-pages-status');
                        $btn.prop('disabled', true);
                        $status.text('<?php echo esc_js( __( 'Creating pages...', 'cube-payment-portal' ) ); ?>');

                        $.post(ajaxurl, {
                            action: 'spp_recreate_pages',
                            nonce: typeof sppAdmin !== 'undefined' ? sppAdmin.nonce : '<?php echo esc_js( wp_create_nonce( 'spp_admin_nonce' ) ); ?>'
                        }, function(response) {
                            if (response.success) {
                                $status.html('<span style="color: #00a32a;">' + response.data.message + '</span>');
                                setTimeout(function() { location.reload(); }, 1500);
                            } else {
                                $status.html('<span style="color: #d63638;">' + ((response.data && response.data.message) || 'Failed.') + '</span>');
                                $btn.prop('disabled', false);
                            }
                        }).fail(function() {
                            $status.html('<span style="color: #d63638;">Request failed.</span>');
                            $btn.prop('disabled', false);
                        });
                    });
                });
                </script>
            </div>

            <!-- Database Migration Tool -->
            <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #ccc;">
                <h3><?php esc_html_e( 'Database Tools', 'cube-payment-portal' ); ?></h3>
                
                <div style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ccd0d4; border-left: 4px solid #72aee6; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <h4 style="margin-top: 0;">
                        <span class="dashicons dashicons-database" style="color: #2271b1; margin-right: 5px;"></span>
                        <?php esc_html_e( 'Update Database Schema', 'cube-payment-portal' ); ?>
                    </h4>
                    <p class="description" style="margin-bottom: 15px;">
                        <?php esc_html_e( 'Use this tool to ensure your database tables match the latest plugin version. Run this if you are experiencing issues or after a plugin update.', 'cube-payment-portal' ); ?>
                    </p>
                    <form method="post" style="margin-top: 10px;">
                        <?php wp_nonce_field( 'spp_run_migration', 'spp_migration_nonce' ); ?>
                        <button type="submit" name="spp_run_migration" class="button button-secondary">
                            <?php esc_html_e( 'Run Database Migration', 'cube-payment-portal' ); ?>
                        </button>
                    </form>
                </div>
            </div>

        <?php elseif ( $active_tab === 'portal' ) : ?>
            
            <form method="post" action="options.php">
                <?php settings_fields( 'spp_portal_settings' ); ?>
                
                <h3 style="margin-top: 0;"><?php esc_html_e( 'Appearance', 'cube-payment-portal' ); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="spp_portal_title"><?php esc_html_e( 'Portal Page Title', 'cube-payment-portal' ); ?></label>
                        </th>
                        <td>
                            <input type="text" name="spp_portal_title" id="spp_portal_title"
                                   value="<?php echo esc_attr( get_option( 'spp_portal_title', '' ) ); ?>"
                                   class="regular-text"
                                   placeholder="<?php esc_attr_e( 'Client Portal', 'cube-payment-portal' ); ?>">
                            <p class="description">
                                <?php esc_html_e( 'Customize the page title shown in the browser tab. Leave blank to use the default "Client Portal".', 'cube-payment-portal' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="spp_portal_welcome_message"><?php esc_html_e( 'Welcome Message', 'cube-payment-portal' ); ?></label>
                        </th>
                        <td>
                            <input type="text" name="spp_portal_welcome_message" id="spp_portal_welcome_message"
                                   value="<?php echo esc_attr( get_option( 'spp_portal_welcome_message', '' ) ); ?>"
                                   class="large-text"
                                   placeholder="<?php esc_attr_e( "Here's a summary of your account activity.", 'cube-payment-portal' ); ?>">
                            <p class="description">
                                <?php esc_html_e( 'Custom subtitle text shown below the greeting on the dashboard. Leave blank for the default message.', 'cube-payment-portal' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="spp_portal_accent_color"><?php esc_html_e( 'Brand / Accent Color', 'cube-payment-portal' ); ?></label>
                        </th>
                        <td>
                            <?php $accent_color = get_option( 'spp_portal_accent_color', '' ); ?>
                            <input type="text" name="spp_portal_accent_color" id="spp_portal_accent_color"
                                   value="<?php echo esc_attr( $accent_color ); ?>"
                                   class="spp-color-picker"
                                   data-default-color="">
                            <p class="description">
                                <?php esc_html_e( 'Overrides the primary color used for buttons, links, gradients, and the header. Leave blank for the default blue (#006aff).', 'cube-payment-portal' ); ?>
                            </p>
                            <?php if ( ! empty( $accent_color ) ) : ?>
                                <p style="margin-top: 8px;">
                                    <span style="display: inline-block; width: 120px; height: 30px; border-radius: 6px; background: linear-gradient(135deg, <?php echo esc_attr( $accent_color ); ?>, <?php echo esc_attr( $accent_color ); ?>99); vertical-align: middle;"></span>
                                    <span style="margin-left: 8px; color: #666;"><?php esc_html_e( 'Current accent', 'cube-payment-portal' ); ?></span>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <hr style="border: none; border-top: 1px solid #e2e4e7; margin: 24px 0;">

                <h3><?php esc_html_e( 'Dashboard', 'cube-payment-portal' ); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Quick Actions', 'cube-payment-portal' ); ?></th>
                        <td>
                            <?php
                            $saved_actions = get_option( 'spp_dashboard_quick_actions', array() );
                            $all_actions = array(
                                'book_appointment' => __( 'Book Appointment', 'cube-payment-portal' ),
                                'pay_invoice'      => __( 'Pay Invoice', 'cube-payment-portal' ),
                                'redeem_rewards'   => __( 'Redeem Rewards', 'cube-payment-portal' ),
                                'add_card'         => __( 'Add Card', 'cube-payment-portal' ),
                                'buy_gift_card'    => __( 'Buy Gift Card', 'cube-payment-portal' ),
                                'transactions'     => __( 'Transactions', 'cube-payment-portal' ),
                            );
                            foreach ( $all_actions as $key => $label ) :
                                $checked = empty( $saved_actions ) || in_array( $key, $saved_actions, true );
                            ?>
                                <label style="display: block; margin-bottom: 6px;">
                                    <input type="checkbox" name="spp_dashboard_quick_actions[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $checked ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </label>
                            <?php endforeach; ?>
                            <p class="description">
                                <?php esc_html_e( 'Select which quick action buttons appear on the client dashboard. Feature flags still apply (e.g., Bookings must be enabled for "Book Appointment" to show).', 'cube-payment-portal' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Dashboard Widgets', 'cube-payment-portal' ); ?></th>
                        <td>
                            <?php
                            $saved_widgets = get_option( 'spp_dashboard_widgets', array() );
                            $all_widgets = array(
                                'outstanding-balance'     => __( 'Outstanding Balance', 'cube-payment-portal' ),
                                'payment-methods-summary' => __( 'Payment Methods', 'cube-payment-portal' ),
                                'upcoming-appointments'   => __( 'Upcoming Appointments', 'cube-payment-portal' ),
                                'recent-invoices'         => __( 'Recent Invoices', 'cube-payment-portal' ),
                                'subscription-status'     => __( 'Subscriptions', 'cube-payment-portal' ),
                                'loyalty-points'          => __( 'Loyalty Points', 'cube-payment-portal' ),
                            );
                            foreach ( $all_widgets as $key => $label ) :
                                $checked = empty( $saved_widgets ) || in_array( $key, $saved_widgets, true );
                            ?>
                                <label style="display: block; margin-bottom: 6px;">
                                    <input type="checkbox" name="spp_dashboard_widgets[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $checked ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </label>
                            <?php endforeach; ?>
                            <p class="description">
                                <?php esc_html_e( 'Select which summary widgets appear on the client dashboard. Feature flags still apply.', 'cube-payment-portal' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Widget Order', 'cube-payment-portal' ); ?></th>
                        <td>
                            <?php
                            $saved_order = get_option( 'spp_dashboard_widget_order', '' );
                            $widget_labels = array(
                                'outstanding-balance'     => __( 'Outstanding Balance', 'cube-payment-portal' ),
                                'payment-methods-summary' => __( 'Payment Methods', 'cube-payment-portal' ),
                                'upcoming-appointments'   => __( 'Upcoming Appointments', 'cube-payment-portal' ),
                                'recent-invoices'         => __( 'Recent Invoices', 'cube-payment-portal' ),
                                'subscription-status'     => __( 'Subscriptions', 'cube-payment-portal' ),
                                'loyalty-points'          => __( 'Loyalty Points', 'cube-payment-portal' ),
                            );

                            // Build ordered list: saved order first, then any remaining in default order.
                            $ordered_keys = array();
                            if ( ! empty( $saved_order ) ) {
                                $order_keys = array_map( 'trim', explode( ',', $saved_order ) );
                                foreach ( $order_keys as $k ) {
                                    if ( isset( $widget_labels[ $k ] ) ) {
                                        $ordered_keys[] = $k;
                                    }
                                }
                            }
                            foreach ( array_keys( $widget_labels ) as $k ) {
                                if ( ! in_array( $k, $ordered_keys, true ) ) {
                                    $ordered_keys[] = $k;
                                }
                            }
                            ?>
                            <ul id="spp-widget-sortable" class="spp-widget-sortable">
                                <?php foreach ( $ordered_keys as $key ) : ?>
                                    <li data-widget-key="<?php echo esc_attr( $key ); ?>">
                                        <span class="spp-drag-handle" aria-hidden="true">&vellip;&vellip;</span>
                                        <?php echo esc_html( $widget_labels[ $key ] ); ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <input type="hidden" name="spp_dashboard_widget_order" id="spp_dashboard_widget_order"
                                   value="<?php echo esc_attr( $saved_order ); ?>">
                            <p class="description">
                                <?php esc_html_e( 'Drag to reorder how widgets appear on the client dashboard.', 'cube-payment-portal' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <hr style="border: none; border-top: 1px solid #e2e4e7; margin: 24px 0;">

                <h3><?php esc_html_e( 'Navigation & Redirects', 'cube-payment-portal' ); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="spp_menu_location"><?php esc_html_e( 'Navbar Menu Location', 'cube-payment-portal' ); ?></label>
                        </th>
                        <td>
                            <?php
                            $menu_setting    = get_option( 'spp_menu_location', 'auto' );
                            $registered_navs = get_registered_nav_menus();
                            $assigned_navs   = get_nav_menu_locations();
                            ?>
                            <select name="spp_menu_location" id="spp_menu_location">
                                <option value="auto" <?php selected( $menu_setting, 'auto' ); ?>>
                                    <?php esc_html_e( 'Auto-detect (recommended)', 'cube-payment-portal' ); ?>
                                </option>
                                <?php foreach ( $registered_navs as $slug => $description ) :
                                    $has_menu = ! empty( $assigned_navs[ $slug ] );
                                    $label    = $description . ' (' . $slug . ')';
                                    if ( ! $has_menu ) {
                                        $label .= ' — ' . __( 'no menu assigned', 'cube-payment-portal' );
                                    }
                                ?>
                                    <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $menu_setting, $slug ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="disabled" <?php selected( $menu_setting, 'disabled' ); ?>>
                                    <?php esc_html_e( 'Disabled (do not add to any menu)', 'cube-payment-portal' ); ?>
                                </option>
                            </select>
                            <p class="description">
                                <?php esc_html_e( 'Which navigation menu to add the Sign In / Account items to. "Auto-detect" finds the first menu with items assigned. If your theme changes, this adapts automatically.', 'cube-payment-portal' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="spp_login_redirect_page"><?php esc_html_e( 'Login Redirect', 'cube-payment-portal' ); ?></label>
                        </th>
                        <td>
                            <?php
                            wp_dropdown_pages( array(
                                'name'              => 'spp_login_redirect_page',
                                'id'                => 'spp_login_redirect_page',
                                'selected'          => (int) get_option( 'spp_login_redirect_page', 0 ),
                                'show_option_none'  => __( '— Default (Client Portal) —', 'cube-payment-portal' ),
                                'option_none_value' => '0',
                            ) );
                            ?>
                            <p class="description">
                                <?php esc_html_e( 'Where to redirect portal clients after login. Defaults to the Client Portal page.', 'cube-payment-portal' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="spp_logout_redirect_page"><?php esc_html_e( 'Logout Redirect', 'cube-payment-portal' ); ?></label>
                        </th>
                        <td>
                            <?php
                            wp_dropdown_pages( array(
                                'name'              => 'spp_logout_redirect_page',
                                'id'                => 'spp_logout_redirect_page',
                                'selected'          => (int) get_option( 'spp_logout_redirect_page', 0 ),
                                'show_option_none'  => __( '— Default (Home Page) —', 'cube-payment-portal' ),
                                'option_none_value' => '0',
                            ) );
                            ?>
                            <p class="description">
                                <?php esc_html_e( 'Where to redirect portal clients after logout. Defaults to the site home page.', 'cube-payment-portal' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <hr style="border: none; border-top: 1px solid #e2e4e7; margin: 24px 0;">

                <h3><?php esc_html_e( 'Bookings', 'cube-payment-portal' ); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="spp_booking_max_advance_days"><?php esc_html_e( 'Max Advance Booking', 'cube-payment-portal' ); ?></label>
                        </th>
                        <td>
                            <input type="number" name="spp_booking_max_advance_days" id="spp_booking_max_advance_days"
                                   value="<?php echo esc_attr( get_option( 'spp_booking_max_advance_days', 90 ) ); ?>"
                                   min="1" max="365" class="small-text">
                            <?php esc_html_e( 'days', 'cube-payment-portal' ); ?>
                            <p class="description">
                                <?php esc_html_e( 'How far in advance clients can book appointments. Also controls the date range shown in the appointments list.', 'cube-payment-portal' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Client Cancellation', 'cube-payment-portal' ); ?></th>
                        <td>
                            <?php $allow_cancel = (bool) get_option( 'spp_allow_booking_cancellation', false ); ?>
                            <label class="spp-switch">
                                <input type="checkbox" name="spp_allow_booking_cancellation" value="1" <?php checked( $allow_cancel ); ?>>
                                <span class="spp-slider round"></span>
                                <span class="spp-toggle-label"><?php echo $allow_cancel ? esc_html__( 'Enabled', 'cube-payment-portal' ) : esc_html__( 'Disabled', 'cube-payment-portal' ); ?></span>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'Allow clients to cancel their own appointments from the portal.', 'cube-payment-portal' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="spp_cancellation_window_hours"><?php esc_html_e( 'Cancellation Window', 'cube-payment-portal' ); ?></label>
                        </th>
                        <td>
                            <input type="number" name="spp_cancellation_window_hours" id="spp_cancellation_window_hours"
                                   value="<?php echo esc_attr( get_option( 'spp_cancellation_window_hours', 24 ) ); ?>"
                                   min="0" max="168" class="small-text">
                            <?php esc_html_e( 'hours before appointment', 'cube-payment-portal' ); ?>
                            <p class="description">
                                <?php esc_html_e( 'Minimum number of hours before an appointment that a client can cancel. Set to 0 to allow cancellation any time.', 'cube-payment-portal' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Staff Selection', 'cube-payment-portal' ); ?></th>
                        <td>
                            <?php $allow_staff = (bool) get_option( 'spp_allow_staff_selection', false ); ?>
                            <label class="spp-switch">
                                <input type="checkbox" name="spp_allow_staff_selection" value="1" <?php checked( $allow_staff ); ?>>
                                <span class="spp-slider round"></span>
                                <span class="spp-toggle-label"><?php echo $allow_staff ? esc_html__( 'Enabled', 'cube-payment-portal' ) : esc_html__( 'Disabled', 'cube-payment-portal' ); ?></span>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'Allow clients to choose their preferred staff member when booking an appointment.', 'cube-payment-portal' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <hr style="border: none; border-top: 1px solid #e2e4e7; margin: 24px 0;">

                <h3><?php esc_html_e( 'Notifications', 'cube-payment-portal' ); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Appointment Reminders', 'cube-payment-portal' ); ?></th>
                        <td>
                            <?php $appt_reminders = (bool) get_option( 'spp_reminder_appointment_enabled', true ); ?>
                            <label class="spp-switch">
                                <input type="checkbox" name="spp_reminder_appointment_enabled" value="1" <?php checked( $appt_reminders ); ?>>
                                <span class="spp-slider round"></span>
                                <span class="spp-toggle-label"><?php echo $appt_reminders ? esc_html__( 'Enabled', 'cube-payment-portal' ) : esc_html__( 'Disabled', 'cube-payment-portal' ); ?></span>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'Send email reminders to clients before their upcoming appointments.', 'cube-payment-portal' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Invoice Reminders', 'cube-payment-portal' ); ?></th>
                        <td>
                            <?php $invoice_reminders = (bool) get_option( 'spp_reminder_invoice_enabled', true ); ?>
                            <label class="spp-switch">
                                <input type="checkbox" name="spp_reminder_invoice_enabled" value="1" <?php checked( $invoice_reminders ); ?>>
                                <span class="spp-slider round"></span>
                                <span class="spp-toggle-label"><?php echo $invoice_reminders ? esc_html__( 'Enabled', 'cube-payment-portal' ) : esc_html__( 'Disabled', 'cube-payment-portal' ); ?></span>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'Send email reminders to clients with unpaid or overdue invoices.', 'cube-payment-portal' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="spp_reminder_frequency_hours"><?php esc_html_e( 'Reminder Frequency', 'cube-payment-portal' ); ?></label>
                        </th>
                        <td>
                            <?php $frequency = (int) get_option( 'spp_reminder_frequency_hours', 24 ); ?>
                            <select name="spp_reminder_frequency_hours" id="spp_reminder_frequency_hours">
                                <option value="12" <?php selected( $frequency, 12 ); ?>><?php esc_html_e( '12 hours before', 'cube-payment-portal' ); ?></option>
                                <option value="24" <?php selected( $frequency, 24 ); ?>><?php esc_html_e( '24 hours before (default)', 'cube-payment-portal' ); ?></option>
                                <option value="48" <?php selected( $frequency, 48 ); ?>><?php esc_html_e( '48 hours before', 'cube-payment-portal' ); ?></option>
                                <option value="72" <?php selected( $frequency, 72 ); ?>><?php esc_html_e( '72 hours before', 'cube-payment-portal' ); ?></option>
                            </select>
                            <p class="description">
                                <?php esc_html_e( 'How far in advance to send appointment reminders. For invoice reminders, this controls how often overdue reminders are re-sent.', 'cube-payment-portal' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <hr style="border: none; border-top: 1px solid #e2e4e7; margin: 24px 0;">

                <h3><?php esc_html_e( 'Security', 'cube-payment-portal' ); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="spp_portal_session_timeout"><?php esc_html_e( 'Session Timeout', 'cube-payment-portal' ); ?></label>
                        </th>
                        <td>
                            <?php $session_timeout = (int) get_option( 'spp_portal_session_timeout', 30 ); ?>
                            <select name="spp_portal_session_timeout" id="spp_portal_session_timeout">
                                <option value="15" <?php selected( $session_timeout, 15 ); ?>><?php esc_html_e( '15 minutes', 'cube-payment-portal' ); ?></option>
                                <option value="30" <?php selected( $session_timeout, 30 ); ?>><?php esc_html_e( '30 minutes', 'cube-payment-portal' ); ?></option>
                                <option value="60" <?php selected( $session_timeout, 60 ); ?>><?php esc_html_e( '1 hour', 'cube-payment-portal' ); ?></option>
                                <option value="0" <?php selected( $session_timeout, 0 ); ?>><?php esc_html_e( 'Never (disabled)', 'cube-payment-portal' ); ?></option>
                            </select>
                            <p class="description">
                                <?php esc_html_e( 'Automatically log out clients after this period of inactivity. Recommended for portals accessed from shared or public computers.', 'cube-payment-portal' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="spp_rate_limit_per_minute"><?php esc_html_e( 'Rate Limit', 'cube-payment-portal' ); ?></label>
                        </th>
                        <td>
                            <?php $rate_limit = (int) get_option( 'spp_rate_limit_per_minute', 30 ); ?>
                            <select name="spp_rate_limit_per_minute" id="spp_rate_limit_per_minute">
                                <option value="15" <?php selected( $rate_limit, 15 ); ?>><?php esc_html_e( '15 requests/min', 'cube-payment-portal' ); ?></option>
                                <option value="30" <?php selected( $rate_limit, 30 ); ?>><?php esc_html_e( '30 requests/min (default)', 'cube-payment-portal' ); ?></option>
                                <option value="60" <?php selected( $rate_limit, 60 ); ?>><?php esc_html_e( '60 requests/min', 'cube-payment-portal' ); ?></option>
                                <option value="120" <?php selected( $rate_limit, 120 ); ?>><?php esc_html_e( '120 requests/min', 'cube-payment-portal' ); ?></option>
                            </select>
                            <p class="description">
                                <?php esc_html_e( 'Maximum number of AJAX requests per IP address per minute. Lower values improve security but may affect usability.', 'cube-payment-portal' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <hr style="border: none; border-top: 1px solid #e2e4e7; margin: 24px 0;">

                <h3><?php esc_html_e( 'Advanced', 'cube-payment-portal' ); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="spp_portal_items_per_page"><?php esc_html_e( 'Items Per Page', 'cube-payment-portal' ); ?></label>
                        </th>
                        <td>
                            <?php $items_per_page = (int) get_option( 'spp_portal_items_per_page', 25 ); ?>
                            <select name="spp_portal_items_per_page" id="spp_portal_items_per_page">
                                <option value="10" <?php selected( $items_per_page, 10 ); ?>>10</option>
                                <option value="25" <?php selected( $items_per_page, 25 ); ?>>25</option>
                                <option value="50" <?php selected( $items_per_page, 50 ); ?>>50</option>
                            </select>
                            <p class="description">
                                <?php esc_html_e( 'Number of items shown per page in list views (invoices, subscriptions, transactions, appointments).', 'cube-payment-portal' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="spp_portal_custom_css"><?php esc_html_e( 'Custom CSS', 'cube-payment-portal' ); ?></label>
                        </th>
                        <td>
                            <textarea name="spp_portal_custom_css" id="spp_portal_custom_css"
                                      rows="10" class="large-text code"
                                      placeholder="<?php esc_attr_e( "/* Example: change the sidebar background */\n.spp-portal-nav {\n    background: #1a1a2e;\n}", 'cube-payment-portal' ); ?>"
                            ><?php echo esc_textarea( get_option( 'spp_portal_custom_css', '' ) ); ?></textarea>
                            <p class="description">
                                <?php esc_html_e( 'Add custom CSS that will be applied to the client portal. Use this for fine-tuning styles beyond the accent color. HTML tags are stripped on save.', 'cube-payment-portal' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

        <?php elseif ( $active_tab === 'webhooks' ) : ?>

            <h3><?php esc_html_e( 'Webhook URL', 'cube-payment-portal' ); ?></h3>
            <p><?php esc_html_e( 'Add this URL to your Square Developer Dashboard:', 'cube-payment-portal' ); ?></p>
            <code><?php echo esc_html( rest_url( 'spp/v1/webhook' ) ); ?></code>

        <?php elseif ( $active_tab === 'scheduled-tasks' ) : ?>

            <?php
            // ── Task Registry ──
            $frequency_labels = array(
                'spp_15min'  => __( 'Every 15 min', 'cube-payment-portal' ),
                'spp_30min'  => __( 'Every 30 min', 'cube-payment-portal' ),
                'spp_hourly' => __( 'Hourly', 'cube-payment-portal' ),
                'spp_6hours' => __( 'Every 6 hours', 'cube-payment-portal' ),
                'spp_daily'  => __( 'Daily', 'cube-payment-portal' ),
                'disabled'   => __( 'Disabled', 'cube-payment-portal' ),
            );

            $frequency_options = array(
                'spp_15min'  => __( 'Every 15 Minutes', 'cube-payment-portal' ),
                'spp_30min'  => __( 'Every 30 Minutes', 'cube-payment-portal' ),
                'spp_hourly' => __( 'Once Hourly', 'cube-payment-portal' ),
                'spp_6hours' => __( 'Every 6 Hours', 'cube-payment-portal' ),
                'spp_daily'  => __( 'Once Daily', 'cube-payment-portal' ),
                'disabled'   => __( 'Disabled', 'cube-payment-portal' ),
            );

            $scheduled_tasks = array(
                // Sync tasks.
                'spp_sync_customers' => array(
                    'label'     => __( 'Customers', 'cube-payment-portal' ),
                    'type'      => 'sync',
                    'freq_opt'  => 'spp_sync_freq_customers',
                    'default'   => 'spp_hourly',
                    'last_run'  => 'spp_customers_last_sync',
                ),
                'spp_sync_invoices' => array(
                    'label'     => __( 'Invoices', 'cube-payment-portal' ),
                    'type'      => 'sync',
                    'freq_opt'  => 'spp_sync_freq_invoices',
                    'default'   => 'spp_hourly',
                    'last_run'  => 'spp_invoices_last_sync',
                ),
                'spp_sync_subscriptions' => array(
                    'label'     => __( 'Subscriptions', 'cube-payment-portal' ),
                    'type'      => 'sync',
                    'freq_opt'  => 'spp_sync_freq_subscriptions',
                    'default'   => 'spp_hourly',
                    'last_run'  => 'spp_subscriptions_last_sync',
                ),
                'spp_sync_transactions' => array(
                    'label'     => __( 'Transactions', 'cube-payment-portal' ),
                    'type'      => 'sync',
                    'freq_opt'  => 'spp_sync_freq_transactions',
                    'default'   => 'spp_6hours',
                    'last_run'  => 'spp_transactions_last_sync',
                ),
                'spp_sync_catalog' => array(
                    'label'     => __( 'Catalog', 'cube-payment-portal' ),
                    'type'      => 'sync',
                    'freq_opt'  => 'spp_sync_freq_catalog',
                    'default'   => 'spp_daily',
                    'last_run'  => 'spp_catalog_last_sync',
                ),
                'spp_sync_orders' => array(
                    'label'     => __( 'Orders', 'cube-payment-portal' ),
                    'type'      => 'sync',
                    'freq_opt'  => 'spp_sync_freq_orders',
                    'default'   => 'spp_daily',
                    'last_run'  => 'spp_orders_last_sync',
                ),
                'spp_sync_gift_card_activities' => array(
                    'label'     => __( 'Gift Cards', 'cube-payment-portal' ),
                    'type'      => 'sync',
                    'freq_opt'  => 'spp_sync_freq_gift_card_activities',
                    'default'   => 'spp_6hours',
                    'last_run'  => 'spp_gift_card_activities_last_sync',
                ),
                // Notification tasks.
                'spp_send_appointment_reminders' => array(
                    'label'      => __( 'Appointment Reminders', 'cube-payment-portal' ),
                    'type'       => 'notification',
                    'enabled_opt' => 'spp_reminder_appointment_enabled',
                    'last_run'   => 'spp_last_appointment_reminder',
                ),
                'spp_send_invoice_reminders' => array(
                    'label'      => __( 'Invoice Reminders', 'cube-payment-portal' ),
                    'type'       => 'notification',
                    'enabled_opt' => 'spp_reminder_invoice_enabled',
                    'last_run'   => 'spp_last_invoice_reminder',
                ),
            );

            // ── Handle form submission (enable/disable + frequency changes) ──
            if ( isset( $_POST['spp_save_scheduled_tasks'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['spp_tasks_nonce'] ) ), 'spp_save_scheduled_tasks' ) ) {
                $save_notices = array();

                foreach ( $scheduled_tasks as $hook => $task ) {
                    if ( 'sync' === $task['type'] ) {
                        $new_freq = isset( $_POST[ 'spp_task_freq_' . $hook ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'spp_task_freq_' . $hook ] ) ) : $task['default'];
                        $current  = get_option( $task['freq_opt'], $task['default'] );

                        if ( $current !== $new_freq || ( ! wp_next_scheduled( $hook ) && 'disabled' !== $new_freq ) ) {
                            update_option( $task['freq_opt'], $new_freq );
                            wp_clear_scheduled_hook( $hook );

                            if ( 'disabled' !== $new_freq ) {
                                // Schedule the first run one full interval from now.
                                $interval = function_exists( 'spp_get_schedule_interval' ) ? spp_get_schedule_interval( $new_freq ) : 3600;
                                wp_schedule_event( time() + $interval, $new_freq, $hook );
                            }
                        }
                    } elseif ( 'notification' === $task['type'] ) {
                        $was_enabled = (bool) get_option( $task['enabled_opt'], true );
                        $is_enabled  = ! empty( $_POST[ 'spp_task_enabled_' . $hook ] );

                        if ( $was_enabled !== $is_enabled ) {
                            update_option( $task['enabled_opt'], $is_enabled ? '1' : '' );
                            wp_clear_scheduled_hook( $hook );

                            if ( $is_enabled && ! wp_next_scheduled( $hook ) ) {
                                wp_schedule_event( time() + 3600, 'hourly', $hook );
                            }
                        }
                    }
                }

                echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Scheduled task settings saved.', 'cube-payment-portal' ) . '</p></div>';
            }

            // ── Compute summary stats ──
            $active_count   = 0;
            $disabled_count = 0;
            $next_run_all   = PHP_INT_MAX;

            foreach ( $scheduled_tasks as $hook => $task ) {
                $is_active = false;
                if ( 'sync' === $task['type'] ) {
                    $is_active = 'disabled' !== get_option( $task['freq_opt'], $task['default'] );
                } else {
                    $is_active = (bool) get_option( $task['enabled_opt'], true );
                }

                if ( $is_active ) {
                    $active_count++;
                    $next = wp_next_scheduled( $hook );
                    if ( $next && $next < $next_run_all ) {
                        $next_run_all = $next;
                    }
                } else {
                    $disabled_count++;
                }
            }
            ?>

            <h3 style="margin-top: 0;"><?php esc_html_e( 'Scheduled Tasks', 'cube-payment-portal' ); ?></h3>
            <p class="description"><?php esc_html_e( 'Manage all background tasks — sync schedules and notification reminders — from one place.', 'cube-payment-portal' ); ?></p>

            <!-- Summary Bar -->
            <div style="display: flex; gap: 16px; align-items: center; flex-wrap: wrap; margin: 16px 0; padding: 10px 14px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; font-size: 13px;">
                <span>
                    <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #00a32a; margin-right: 4px;"></span>
                    <strong><?php echo esc_html( $active_count ); ?></strong> <?php esc_html_e( 'active', 'cube-payment-portal' ); ?>
                </span>
                <?php if ( $disabled_count > 0 ) : ?>
                <span>
                    <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #a7aaad; margin-right: 4px;"></span>
                    <strong><?php echo esc_html( $disabled_count ); ?></strong> <?php esc_html_e( 'disabled', 'cube-payment-portal' ); ?>
                </span>
                <?php endif; ?>
                <?php if ( $next_run_all < PHP_INT_MAX ) : ?>
                <span style="color: #666;">
                    <?php
                    printf(
                        /* translators: %s: human-readable time diff */
                        esc_html__( 'Next run in %s', 'cube-payment-portal' ),
                        esc_html( human_time_diff( time(), $next_run_all ) )
                    );
                    ?>
                </span>
                <?php endif; ?>
                <span style="margin-left: auto;">
                    <button type="button" id="spp-run-all-btn" class="button button-primary button-small">
                        <span class="dashicons dashicons-update" style="font-size: 14px; width: 14px; height: 14px; margin-top: 3px; margin-right: 2px;"></span>
                        <?php esc_html_e( 'Run All', 'cube-payment-portal' ); ?>
                    </button>
                    <span id="spp-run-all-spinner" class="spinner" style="float: none; margin: 0 0 0 4px;"></span>
                </span>
            </div>
            <div id="spp-run-all-result" style="display: none; margin-bottom: 12px;"></div>

            <!-- Tasks Table -->
            <style>
            .spp-tasks-table th,
            .spp-tasks-table td {
                padding: 6px 8px !important;
                vertical-align: middle;
            }
            .spp-tasks-table th {
                font-size: 12px;
            }
            .spp-tasks-table td {
                font-size: 13px;
            }
            .spp-tasks-table select {
                height: 28px;
                min-height: 28px;
                padding: 0 24px 0 6px;
                font-size: 12px;
                line-height: 1;
            }
            .spp-tasks-table .button-small {
                padding: 0 8px;
                min-height: 26px;
                line-height: 24px;
                font-size: 12px;
            }
            .spp-tasks-table .button-small .dashicons {
                font-size: 13px;
                width: 13px;
                height: 13px;
                margin-top: 1px;
            }
            </style>
            <form method="post">
                <?php wp_nonce_field( 'spp_save_scheduled_tasks', 'spp_tasks_nonce' ); ?>

                <table class="widefat spp-tasks-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Task', 'cube-payment-portal' ); ?></th>
                            <th><?php esc_html_e( 'Type', 'cube-payment-portal' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'cube-payment-portal' ); ?></th>
                            <th><?php esc_html_e( 'Frequency', 'cube-payment-portal' ); ?></th>
                            <th><?php esc_html_e( 'Next Run', 'cube-payment-portal' ); ?></th>
                            <th><?php esc_html_e( 'Last Run', 'cube-payment-portal' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'cube-payment-portal' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $scheduled_tasks as $hook => $task ) :
                            $next_scheduled = wp_next_scheduled( $hook );
                            $last_run_ts    = (int) get_option( $task['last_run'], 0 );
                            $is_disabled    = false;
                            $current_freq   = '';

                            if ( 'sync' === $task['type'] ) {
                                $current_freq = get_option( $task['freq_opt'], $task['default'] );
                                $is_disabled  = ( 'disabled' === $current_freq );
                            } else {
                                $is_disabled  = ! (bool) get_option( $task['enabled_opt'], true );
                                $current_freq = 'hourly';
                            }

                            $is_overdue = ! $is_disabled && $next_scheduled && $next_scheduled < time();
                            ?>
                            <tr>
                                <!-- Task Name -->
                                <td><strong><?php echo esc_html( $task['label'] ); ?></strong></td>

                                <!-- Type Badge -->
                                <td>
                                    <?php if ( 'sync' === $task['type'] ) : ?>
                                        <span style="display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 10px; font-weight: 600; background: #e7f3ff; color: #0073aa; line-height: 16px;">
                                            <?php esc_html_e( 'Sync', 'cube-payment-portal' ); ?>
                                        </span>
                                    <?php else : ?>
                                        <span style="display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 10px; font-weight: 600; background: #f0e6ff; color: #7c3aed; line-height: 16px;">
                                            <?php esc_html_e( 'Notification', 'cube-payment-portal' ); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Status -->
                                <td>
                                    <?php if ( $is_disabled ) : ?>
                                        <span style="display: inline-flex; align-items: center; gap: 4px; color: #a7aaad; font-size: 12px;">
                                            <span style="display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: #a7aaad;"></span>
                                            <?php esc_html_e( 'Disabled', 'cube-payment-portal' ); ?>
                                        </span>
                                    <?php elseif ( $is_overdue ) : ?>
                                        <span style="display: inline-flex; align-items: center; gap: 4px; color: #d63638; font-size: 12px;">
                                            <span style="display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: #d63638;"></span>
                                            <?php esc_html_e( 'Overdue', 'cube-payment-portal' ); ?>
                                        </span>
                                    <?php else : ?>
                                        <span style="display: inline-flex; align-items: center; gap: 4px; color: #00a32a; font-size: 12px;">
                                            <span style="display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: #00a32a;"></span>
                                            <?php esc_html_e( 'Active', 'cube-payment-portal' ); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Frequency -->
                                <td>
                                    <?php if ( 'sync' === $task['type'] ) : ?>
                                        <select name="spp_task_freq_<?php echo esc_attr( $hook ); ?>" style="width: 140px;">
                                            <?php foreach ( $frequency_options as $value => $label ) : ?>
                                                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_freq, $value ); ?>>
                                                    <?php echo esc_html( $label ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else : ?>
                                        <span style="color: #666; font-size: 12px;"><?php esc_html_e( 'Hourly', 'cube-payment-portal' ); ?></span>
                                        <label style="margin-left: 8px; font-size: 11px;">
                                            <input type="checkbox" name="spp_task_enabled_<?php echo esc_attr( $hook ); ?>" value="1" <?php checked( ! $is_disabled ); ?>>
                                            <?php esc_html_e( 'Enabled', 'cube-payment-portal' ); ?>
                                        </label>
                                    <?php endif; ?>
                                </td>

                                <!-- Next Run -->
                                <td style="font-size: 12px; white-space: nowrap;">
                                    <?php if ( $is_disabled ) : ?>
                                        <span style="color: #a7aaad;">—</span>
                                    <?php elseif ( $next_scheduled ) : ?>
                                        <span title="<?php echo esc_attr( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_scheduled ) ); ?>">
                                            <?php
                                            if ( $is_overdue ) {
                                                echo '<span style="color: #d63638;">';
                                                echo esc_html( human_time_diff( $next_scheduled, time() ) . ' ' . __( 'overdue', 'cube-payment-portal' ) );
                                                echo '</span>';
                                            } else {
                                                echo esc_html( human_time_diff( time(), $next_scheduled ) );
                                            }
                                            ?>
                                        </span>
                                    <?php else : ?>
                                        <em style="color: #a7aaad;"><?php esc_html_e( 'Not scheduled', 'cube-payment-portal' ); ?></em>
                                    <?php endif; ?>
                                </td>

                                <!-- Last Run -->
                                <td style="font-size: 12px; white-space: nowrap;">
                                    <?php if ( $last_run_ts ) : ?>
                                        <span title="<?php echo esc_attr( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_run_ts ) ); ?>">
                                            <?php echo esc_html( human_time_diff( $last_run_ts, time() ) . ' ' . __( 'ago', 'cube-payment-portal' ) ); ?>
                                        </span>
                                    <?php else : ?>
                                        <em style="color: #a7aaad;"><?php esc_html_e( 'Never', 'cube-payment-portal' ); ?></em>
                                    <?php endif; ?>
                                </td>

                                <!-- Actions -->
                                <td style="white-space: nowrap;">
                                    <button type="button"
                                            class="button button-small spp-run-task-btn"
                                            data-hook="<?php echo esc_attr( $hook ); ?>"
                                            <?php disabled( $is_disabled ); ?>>
                                        <span class="dashicons dashicons-controls-play"></span>
                                        <?php esc_html_e( 'Run Now', 'cube-payment-portal' ); ?>
                                    </button>
                                    <span class="spinner spp-task-spinner" data-hook="<?php echo esc_attr( $hook ); ?>" style="float: none; margin: 0 0 0 2px;"></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="margin-top: 12px;">
                    <button type="submit" name="spp_save_scheduled_tasks" class="button button-primary">
                        <?php esc_html_e( 'Save Changes', 'cube-payment-portal' ); ?>
                    </button>
                </p>
            </form>

            <?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
            <div class="notice notice-info inline" style="margin-top: 12px;">
                <p>
                    <strong><?php esc_html_e( 'WP-Cron is disabled.', 'cube-payment-portal' ); ?></strong>
                    <?php esc_html_e( 'Tasks rely on a system cron job hitting wp-cron.php. If tasks appear overdue, verify your server crontab is configured.', 'cube-payment-portal' ); ?>
                </p>
            </div>
            <?php endif; ?>

            <p class="description" style="margin-top: 8px; font-size: 12px;">
                <?php esc_html_e( 'Tip: If tasks frequently show as "Overdue", your site may have low traffic. Consider setting up a real server cron job to trigger wp-cron.php reliably.', 'cube-payment-portal' ); ?>
            </p>

            <!-- Run Now JS -->
            <style>
            .spp-spin {
                animation: spp-rotation 1s infinite linear;
                display: inline-block;
            }
            @keyframes spp-rotation {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
            </style>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Run All button.
                $('#spp-run-all-btn').on('click', function(e) {
                    e.preventDefault();

                    var $btn = $(this);
                    var $spinner = $('#spp-run-all-spinner');
                    var $result = $('#spp-run-all-result');
                    var originalHtml = $btn.html();

                    $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spp-spin" style="font-size:14px;width:14px;height:14px;margin-top:3px;margin-right:2px;"></span> <?php echo esc_js( __( 'Running...', 'cube-payment-portal' ) ); ?>');
                    $spinner.addClass('is-active');
                    $result.hide();

                    $.ajax({
                        url: sppAdmin.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'spp_sync_all',
                            nonce: sppAdmin.nonce
                        },
                        timeout: 300000,
                        success: function(response) {
                            if (response.success) {
                                var html = '<div class="notice notice-success inline"><p><strong><?php echo esc_js( __( 'All tasks completed!', 'cube-payment-portal' ) ); ?></strong> ' + (response.data.message || '') + '</p>';

                                var results = response.data.results;
                                if (results) {
                                    html += '<ul style="margin: 8px 0 0; padding-left: 20px;">';
                                    if (results.customers) {
                                        html += '<li><strong><?php echo esc_js( __( 'Customers:', 'cube-payment-portal' ) ); ?></strong> ' + (results.customers.from_square || 0) + ' <?php echo esc_js( __( 'synced', 'cube-payment-portal' ) ); ?></li>';
                                    }
                                    if (results.subscriptions) {
                                        html += '<li><strong><?php echo esc_js( __( 'Subscriptions:', 'cube-payment-portal' ) ); ?></strong> ' + (results.subscriptions.synced || 0) + ' <?php echo esc_js( __( 'synced', 'cube-payment-portal' ) ); ?></li>';
                                    }
                                    if (results.invoices) {
                                        html += '<li><strong><?php echo esc_js( __( 'Invoices:', 'cube-payment-portal' ) ); ?></strong> ' + (results.invoices.synced || 0) + ' <?php echo esc_js( __( 'synced', 'cube-payment-portal' ) ); ?></li>';
                                    }
                                    if (results.transactions) {
                                        html += '<li><strong><?php echo esc_js( __( 'Transactions:', 'cube-payment-portal' ) ); ?></strong> ' + (results.transactions.synced || 0) + ' <?php echo esc_js( __( 'synced', 'cube-payment-portal' ) ); ?></li>';
                                    }
                                    if (results.errors && results.errors.length > 0) {
                                        html += '<li style="color:#d63638;"><strong><?php echo esc_js( __( 'Errors:', 'cube-payment-portal' ) ); ?></strong><ul style="margin:4px 0 0;padding-left:20px;">';
                                        $.each(results.errors, function(i, err) {
                                            html += '<li>' + err + '</li>';
                                        });
                                        html += '</ul></li>';
                                    }
                                    html += '</ul>';
                                }

                                html += '</p></div>';
                                $result.html(html).show();
                            } else {
                                $result.html('<div class="notice notice-error inline"><p><strong><?php echo esc_js( __( 'Sync failed:', 'cube-payment-portal' ) ); ?></strong> ' + (response.data.message || '<?php echo esc_js( __( 'Unknown error', 'cube-payment-portal' ) ); ?>') + '</p></div>').show();
                            }
                        },
                        error: function(xhr, status) {
                            var msg = status === 'timeout'
                                ? '<?php echo esc_js( __( 'The operation timed out. This may happen with large datasets. Please try again.', 'cube-payment-portal' ) ); ?>'
                                : '<?php echo esc_js( __( 'An error occurred. Please try again.', 'cube-payment-portal' ) ); ?>';
                            $result.html('<div class="notice notice-error inline"><p>' + msg + '</p></div>').show();
                        },
                        complete: function() {
                            $btn.prop('disabled', false).html(originalHtml);
                            $spinner.removeClass('is-active');
                        }
                    });
                });

                // Individual Run Now buttons.
                $('.spp-run-task-btn').on('click', function(e) {
                    e.preventDefault();

                    var $btn = $(this);
                    var hook = $btn.data('hook');
                    var $spinner = $('.spp-task-spinner[data-hook="' + hook + '"]');
                    var originalHtml = $btn.html();

                    $btn.prop('disabled', true);
                    $spinner.addClass('is-active');

                    $.post(sppAdmin.ajaxUrl, {
                        action: 'spp_run_scheduled_task',
                        nonce: sppAdmin.nonce,
                        task_hook: hook
                    }, function(response) {
                        if (response.success) {
                            $btn.html('<span class="dashicons dashicons-yes" style="font-size:14px;width:14px;height:14px;margin-top:2px;color:#00a32a;"></span> ' + (response.data.message || 'Done'));
                            // Update last run cell.
                            var $row = $btn.closest('tr');
                            var $lastRun = $row.find('td:eq(5)');
                            if (response.data.last_run) {
                                $lastRun.html('<span>' + response.data.last_run + '</span>');
                            }
                            setTimeout(function() {
                                $btn.html(originalHtml).prop('disabled', false);
                            }, 3000);
                        } else {
                            $btn.html('<span class="dashicons dashicons-no" style="font-size:14px;width:14px;height:14px;margin-top:2px;color:#d63638;"></span> ' + (response.data.message || 'Failed'));
                            setTimeout(function() {
                                $btn.html(originalHtml).prop('disabled', false);
                            }, 4000);
                        }
                    }).fail(function() {
                        $btn.html(originalHtml).prop('disabled', false);
                    }).always(function() {
                        $spinner.removeClass('is-active');
                    });
                });
            });
            </script>

        <?php endif; ?>

    </div>
</div>
