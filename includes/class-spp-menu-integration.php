<?php
/**
 * Smart Menu Integration for Cube Payment Portal.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Menu_Integration
 *
 * Handles dynamic menu items based on user authentication state.
 */
class SPP_Menu_Integration {

    /**
     * Common primary menu location slugs (legacy fallback).
     *
     * @var array
     */
    private $common_locations = array(
        'primary', 'main', 'header', 'main-menu', 'primary-menu',
        'primary_navigation', 'main-navigation', 'top', 'menu-1',
        'header-menu', 'navigation', 'nav', 'top-menu',
    );

    /**
     * The resolved target menu location (null = not resolved yet).
     *
     * @var string|null|false
     */
    private $target_location = null;

    /**
     * Whether menu items were successfully injected via wp_nav_menu_items.
     *
     * @var bool
     */
    private $items_injected = false;

    /**
     * Menu locations that have already received portal items.
     *
     * Prevents duplicate injection per location while allowing
     * multiple locations (e.g. desktop + mobile) to each get items.
     *
     * @var array
     */
    private $injected_locations = array();

    /**
     * Constructor.
     */
    public function __construct() {
        // Apply filter for backwards compatibility.
        $this->common_locations = apply_filters(
            'spp_menu_integration_locations',
            $this->common_locations
        );
    }

    /**
     * Initialize hooks.
     */
    public function init() {
        // Filter nav menu items (classic themes).
        add_filter( 'wp_nav_menu_items', array( $this, 'add_portal_menu_items' ), 10, 2 );

        // Fallback: inject into block theme navigation blocks.
        add_filter( 'render_block', array( $this, 'add_portal_block_nav_items' ), 10, 2 );

        // Enqueue menu styles and dropdown script.
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
        add_action( 'wp_footer', array( $this, 'print_dropdown_script' ) );

        // Final fallback: render floating element if nothing else worked.
        add_action( 'wp_footer', array( $this, 'render_fallback_menu' ), 99 );

        // Handle impersonation requests early.
        add_action( 'init', array( $this, 'handle_impersonation_requests' ) );

        // Add body class when impersonating.
        add_filter( 'body_class', array( $this, 'add_impersonation_body_class' ) );
    }

    /**
     * Add portal menu items to navigation.
     *
     * @param string   $items The HTML list content for the menu items.
     * @param stdClass $args  An object containing wp_nav_menu() arguments.
     * @return string Modified menu items HTML.
     */
    public function add_portal_menu_items( $items, $args ) {
        // Check if this is a menu location we should integrate with.
        if ( ! $this->should_integrate_with_menu( $args ) ) {
            return $items;
        }

        // Check if integration is enabled.
        if ( ! apply_filters( 'spp_enable_menu_integration', true ) ) {
            return $items;
        }

        // Prevent duplicate injection for the same location (filter can fire multiple times).
        $location_key = ! empty( $args->theme_location ) ? $args->theme_location : 'menu_' . ( is_object( $args->menu ?? null ) ? ( $args->menu->slug ?? '' ) : ( $args->menu ?? '' ) );
        if ( in_array( $location_key, $this->injected_locations, true ) ) {
            return $items;
        }

        // Generate the appropriate menu item based on login state.
        if ( is_user_logged_in() ) {
            $items .= $this->get_logged_in_menu_item();
        } else {
            $items .= $this->get_logged_out_menu_item();
        }

        $this->injected_locations[] = $location_key;
        $this->items_injected       = true;

        return $items;
    }

    /**
     * Resolve the target menu location.
     *
     * Priority:
     * 1. Explicit admin setting (spp_menu_location) — returns that slug
     * 2. "auto" — returns 'auto' to signal broad matching
     * 3. "disabled" — returns false
     *
     * @return string|false The target location slug, 'auto', or false.
     */
    private function get_target_location() {
        if ( null !== $this->target_location ) {
            return $this->target_location;
        }

        $setting = get_option( 'spp_menu_location', 'auto' );

        if ( 'disabled' === $setting ) {
            $this->target_location = false;
            return false;
        }

        if ( 'auto' === $setting || '' === $setting ) {
            $this->target_location = 'auto';
            return 'auto';
        }

        // Explicit location slug.
        $this->target_location = $setting;
        return $this->target_location;
    }

    /**
     * Check if we should integrate with this menu.
     *
     * In "auto" mode, injects into any menu whose theme_location has an
     * assigned menu, or whose slug matches the common locations list.
     * This allows both desktop and mobile menu locations to receive items.
     *
     * @param stdClass $args The menu arguments.
     * @return bool True if we should integrate.
     */
    private function should_integrate_with_menu( $args ) {
        $target = $this->get_target_location();

        if ( false === $target ) {
            return false;
        }

        // Explicit location — match only that slug.
        if ( 'auto' !== $target ) {
            if ( ! empty( $args->theme_location ) ) {
                return $args->theme_location === $target;
            }
            if ( ! empty( $args->menu ) ) {
                $menu = is_object( $args->menu ) ? ( $args->menu->slug ?? '' ) : $args->menu;
                return $menu === $target;
            }
            return false;
        }

        // Auto mode — match any registered location that has an assigned menu.
        if ( ! empty( $args->theme_location ) ) {
            $assigned = get_nav_menu_locations();
            if ( ! empty( $assigned[ $args->theme_location ] ) ) {
                return true;
            }
            // Fallback: match common primary menu location slugs.
            return in_array( $args->theme_location, $this->common_locations, true );
        }

        // Check by menu ID or slug against common locations.
        if ( ! empty( $args->menu ) ) {
            $menu = is_object( $args->menu ) ? ( $args->menu->slug ?? '' ) : $args->menu;
            return in_array( $menu, $this->common_locations, true );
        }

        return false;
    }

    /**
     * Get menu item HTML for logged-out users.
     *
     * @return string HTML for the sign-in menu item.
     */
    private function get_logged_out_menu_item() {
        $login_url = $this->get_login_page_url();

        $signin = sprintf(
            '<li class="menu-item spp-nav-item spp-nav-signin">
                <a href="%s" class="spp-nav-link spp-nav-signin-link">
                    <span class="spp-nav-signin-text">%s</span>
                </a>
            </li>',
            esc_url( $login_url ),
            esc_html__( 'Sign In', 'cube-payment-portal' )
        );

        $signup_url = $this->get_register_page_url();
        $signup = sprintf(
            '<li class="menu-item spp-nav-item spp-nav-signup">
                <a href="%s" class="spp-nav-link spp-nav-signup-link">
                    <span class="spp-nav-signup-text">%s</span>
                </a>
            </li>',
            esc_url( $signup_url ),
            esc_html__( 'Sign Up', 'cube-payment-portal' )
        );

        $html = $signin . $signup;

        return apply_filters( 'spp_logged_out_menu_item', $html, $login_url );
    }

    /**
     * Get menu item HTML for logged-in users.
     *
     * @return string HTML for the user dropdown menu item.
     */
    private function get_logged_in_menu_item() {
        $user = wp_get_current_user();
        $avatar_url = get_avatar_url( $user->ID, array( 'size' => 40 ) );
        $display_name = $this->get_user_display_name( $user );

        // Check if impersonating.
        $is_impersonating = class_exists( 'SPP_Permissions' ) && SPP_Permissions::is_impersonating();
        $impersonation_class = $is_impersonating ? ' spp-nav-impersonating' : '';

        $impersonation_notice = '';
        if ( $is_impersonating ) {
            $impersonated_user = SPP_Permissions::get_impersonated_user();
            $impersonated_name = $impersonated_user ? $impersonated_user->display_name : __( 'Unknown', 'cube-payment-portal' );
            $impersonation_notice = sprintf(
                '<div class="spp-nav-impersonation-notice">%s <strong>%s</strong></div>',
                esc_html__( 'Viewing as:', 'cube-payment-portal' ),
                esc_html( $impersonated_name )
            );
        }

        $html = sprintf(
            '<li class="menu-item spp-nav-item spp-nav-user%s">
                <a href="#" class="spp-nav-link spp-nav-user-toggle" aria-expanded="false" aria-haspopup="true">
                    <img src="%s" alt="%s" class="spp-nav-avatar" width="32" height="32" />
                    <span class="spp-nav-user-name">%s</span>
                    <span class="spp-nav-dropdown-arrow">%s</span>
                </a>
                <div class="spp-nav-dropdown" role="menu">
                    %s
                    %s
                </div>
            </li>',
            esc_attr( $impersonation_class ),
            esc_url( $avatar_url ),
            esc_attr( $display_name ),
            esc_html( $display_name ),
            $this->get_dropdown_arrow_svg(),
            $impersonation_notice,
            $this->get_dropdown_menu_items( $is_impersonating )
        );

        return apply_filters( 'spp_logged_in_menu_item', $html, $user );
    }

    /**
     * Get the dropdown menu items.
     *
     * @param bool $is_impersonating Whether the user is currently impersonating.
     * @return string HTML for dropdown items.
     */
    private function get_dropdown_menu_items( $is_impersonating = false ) {
        $items = array();

        // Dashboard link.
        $dashboard_url = $this->get_portal_page_url();
        if ( $dashboard_url ) {
            $items[] = array(
                'url'   => $dashboard_url,
                'icon'  => $this->get_dashboard_icon_svg(),
                'label' => __( 'Dashboard', 'cube-payment-portal' ),
                'class' => 'spp-nav-dropdown-dashboard',
            );
        }

        // Portal navigation views — order matches sidebar in class-spp-client-portal.php.
        $portal_views = array(
            array(
                'view'  => 'payment-methods',
                'label' => __( 'Payment Methods', 'cube-payment-portal' ),
                'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>',
            ),
            array(
                'view'  => 'subscriptions',
                'label' => __( 'Subscriptions', 'cube-payment-portal' ),
                'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>',
                'feature' => 'spp_feature_subscriptions',
            ),
            array(
                'view'  => 'invoices',
                'label' => __( 'Invoices', 'cube-payment-portal' ),
                'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>',
                'feature' => 'spp_feature_invoices',
            ),
            array(
                'view'  => 'transactions',
                'label' => __( 'Transactions', 'cube-payment-portal' ),
                'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>',
            ),
            array(
                'view'  => 'wc-orders',
                'label' => __( 'Orders', 'cube-payment-portal' ),
                'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path><line x1="3" y1="6" x2="21" y2="6"></line><path d="M16 10a4 4 0 0 1-8 0"></path></svg>',
                'wc'    => true,
            ),
            array(
                'view'  => 'wc-downloads',
                'label' => __( 'Downloads', 'cube-payment-portal' ),
                'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>',
                'wc'    => true,
            ),
            array(
                'view'  => 'bookings',
                'label' => __( 'Appointments', 'cube-payment-portal' ),
                'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>',
                'feature' => 'spp_feature_bookings',
            ),
            array(
                'view'    => 'loyalty',
                'label'   => __( 'Loyalty & Rewards', 'cube-payment-portal' ),
                'icon'    => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>',
                'feature' => 'spp_feature_loyalty',
            ),
        );

        foreach ( $portal_views as $pv ) {
            // Skip disabled features.
            if ( ! empty( $pv['feature'] ) && ! get_option( $pv['feature'], true ) ) {
                continue;
            }
            // Skip WooCommerce-dependent views if WC not active.
            if ( ! empty( $pv['wc'] ) && ! class_exists( 'WooCommerce' ) ) {
                continue;
            }
            $view_url = $this->get_portal_page_url( $pv['view'] );
            if ( $view_url ) {
                $items[] = array(
                    'url'   => $view_url,
                    'icon'  => $pv['icon'],
                    'label' => $pv['label'],
                    'class' => 'spp-nav-dropdown-portal-view spp-nav-dropdown-' . $pv['view'],
                );
            }
        }

        // Profile link.
        $profile_url = $this->get_portal_page_url( 'profile' );
        if ( $profile_url ) {
            $items[] = array(
                'url'   => $profile_url,
                'icon'  => $this->get_profile_icon_svg(),
                'label' => __( 'Profile', 'cube-payment-portal' ),
                'class' => 'spp-nav-dropdown-profile',
            );
        }

        // Separator.
        $items[] = array(
            'separator' => true,
        );

        // Admin portal link (for admins only).
        if ( ! $is_impersonating && function_exists( 'spp_current_user_can' ) && spp_current_user_can( SPP_Permissions::MANAGE_PORTAL ) ) {
            $items[] = array(
                'url'   => admin_url( 'admin.php?page=spp-dashboard' ),
                'icon'  => $this->get_admin_icon_svg(),
                'label' => __( 'Admin Portal', 'cube-payment-portal' ),
                'class' => 'spp-nav-dropdown-admin',
            );
        }

        // Exit impersonation link.
        if ( $is_impersonating ) {
            $items[] = array(
                'url'   => SPP_Permissions::get_exit_impersonation_url(),
                'icon'  => $this->get_exit_icon_svg(),
                'label' => __( 'Exit View Mode', 'cube-payment-portal' ),
                'class' => 'spp-nav-dropdown-exit-impersonation',
            );
        }

        // Logout link.
        $items[] = array(
            'url'   => wp_logout_url( home_url() ),
            'icon'  => $this->get_logout_icon_svg(),
            'label' => __( 'Sign Out', 'cube-payment-portal' ),
            'class' => 'spp-nav-dropdown-logout',
        );

        // Build HTML.
        $html = '';
        foreach ( $items as $item ) {
            if ( ! empty( $item['separator'] ) ) {
                $html .= '<div class="spp-nav-dropdown-separator" role="separator"></div>';
                continue;
            }

            $html .= sprintf(
                '<a href="%s" class="spp-nav-dropdown-item %s" role="menuitem">
                    <span class="spp-nav-dropdown-icon">%s</span>
                    <span class="spp-nav-dropdown-label">%s</span>
                </a>',
                esc_url( $item['url'] ),
                esc_attr( $item['class'] ?? '' ),
                $item['icon'] ?? '',
                esc_html( $item['label'] )
            );
        }

        return $html;
    }

    /**
     * Get the user's display name.
     *
     * @param WP_User $user The user object.
     * @return string The display name.
     */
    private function get_user_display_name( $user ) {
        // Try display name first.
        if ( ! empty( $user->display_name ) ) {
            return $user->display_name;
        }

        // Fall back to first name.
        $first_name = get_user_meta( $user->ID, 'first_name', true );
        if ( ! empty( $first_name ) ) {
            return $first_name;
        }

        // Fall back to username.
        return $user->user_login;
    }

    /**
     * Get the login page URL.
     *
     * @return string The login page URL.
     */
    private function get_login_page_url() {
        // Check for a dedicated login page.
        $login_page_id = get_option( 'spp_login_page_id' );
        if ( $login_page_id ) {
            $url = get_permalink( $login_page_id );
            if ( $url ) {
                // Add redirect_to parameter.
                return add_query_arg( 'redirect_to', urlencode( $this->get_current_url() ), $url );
            }
        }

        // Check for portal page (which includes login form).
        $portal_page_id = get_option( 'spp_portal_page_id' );
        if ( $portal_page_id ) {
            $url = get_permalink( $portal_page_id );
            if ( $url ) {
                return add_query_arg( 'redirect_to', urlencode( $this->get_current_url() ), $url );
            }
        }

        // Fall back to WordPress login.
        return wp_login_url( $this->get_current_url() );
    }

    /**
     * Get the registration page URL.
     *
     * @return string The registration page URL.
     */
    private function get_register_page_url() {
        // Check for a dedicated registration page (must be published).
        $register_page_id = get_option( 'spp_register_page_id' );
        if ( $register_page_id && 'publish' === get_post_status( $register_page_id ) ) {
            $url = get_permalink( $register_page_id );
            if ( $url ) {
                return $url;
            }
        }

        // Try to find a page with the register shortcode.
        $page_id = $this->find_page_with_shortcode( 'spp_register_form' );
        if ( $page_id ) {
            return get_permalink( $page_id );
        }

        // Fall back to login page (which has a "create account" link).
        return $this->get_login_page_url();
    }

    /**
     * Get the portal page URL.
     *
     * @param string $view Optional view parameter.
     * @return string|false The portal page URL or false.
     */
    private function get_portal_page_url( $view = '' ) {
        $portal_page_id = get_option( 'spp_portal_page_id' );
        if ( ! $portal_page_id ) {
            // Try to find a page with the shortcode.
            $portal_page_id = $this->find_page_with_shortcode( 'spp_client_portal' );
        }

        if ( ! $portal_page_id ) {
            return false;
        }

        $url = get_permalink( $portal_page_id );
        if ( ! $url ) {
            return false;
        }

        if ( ! empty( $view ) ) {
            $url = add_query_arg( 'view', $view, $url );
        }

        return $url;
    }

    /**
     * Find a page containing a specific shortcode.
     *
     * @param string $shortcode The shortcode to search for.
     * @return int|false The page ID or false if not found.
     */
    private function find_page_with_shortcode( $shortcode ) {
        global $wpdb;

        // Cache the result.
        $cache_key = 'spp_page_shortcode_' . $shortcode;
        $page_id = wp_cache_get( $cache_key );

        if ( false !== $page_id ) {
            return $page_id ?: false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $page_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                WHERE post_type = 'page'
                AND post_status = 'publish'
                AND post_content LIKE %s
                LIMIT 1",
                '%[' . $wpdb->esc_like( $shortcode ) . '%'
            )
        );

        wp_cache_set( $cache_key, $page_id ?: 0, '', HOUR_IN_SECONDS );

        return $page_id ?: false;
    }

    /**
     * Get the current page URL.
     *
     * @return string The current URL.
     */
    private function get_current_url() {
        global $wp;
        return home_url( add_query_arg( array(), $wp->request ) );
    }

    /**
     * Enqueue menu integration styles.
     */
    public function enqueue_styles() {
        wp_enqueue_style(
            'spp-menu-integration',
            SPP_PLUGIN_URL . 'assets/css/menu-integration.css',
            array(),
            SPP_VERSION
        );
    }

    /**
     * Print dropdown toggle script in footer.
     *
     * Uses wp_footer instead of wp_add_inline_script to ensure
     * the script loads on every page, not just when jQuery is enqueued.
     */
    public function print_dropdown_script() {
        echo '<script>' . $this->get_dropdown_script() . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hardcoded JS, no user input.
    }

    /**
     * Get the dropdown toggle JavaScript.
     *
     * @return string JavaScript code.
     */
    private function get_dropdown_script() {
        return "
        (function() {
            'use strict';

            function closeAllDropdowns() {
                document.querySelectorAll('.spp-nav-user.spp-nav-open').forEach(function(el) {
                    el.classList.remove('spp-nav-open');
                    var t = el.querySelector('.spp-nav-user-toggle');
                    if (t) t.setAttribute('aria-expanded', 'false');
                });
            }

            function toggleDropdown(e) {
                var toggle = e.target.closest('.spp-nav-user-toggle');
                if (toggle) {
                    e.preventDefault();
                    e.stopImmediatePropagation();

                    var parent = toggle.closest('.spp-nav-user');
                    var isOpen = parent.classList.contains('spp-nav-open');
                    closeAllDropdowns();
                    if (!isOpen) {
                        parent.classList.add('spp-nav-open');
                        toggle.setAttribute('aria-expanded', 'true');
                    }
                    return;
                }

                if (!e.target.closest('.spp-nav-user')) {
                    closeAllDropdowns();
                }
            }

            // Timestamp to deduplicate touch + click on same tap.
            var lastTouch = 0;

            // touchend fires even when theme's touchstart calls preventDefault
            // (which suppresses the subsequent click event).
            document.addEventListener('touchend', function(e) {
                var toggle = e.target.closest('.spp-nav-user-toggle');
                if (toggle) {
                    lastTouch = Date.now();
                    toggleDropdown(e);
                    return;
                }

                // Dropdown link items — navigate immediately on touch.
                var item = e.target.closest('.spp-nav-dropdown-item');
                if (item) {
                    lastTouch = Date.now();
                    e.stopImmediatePropagation();
                    var href = item.getAttribute('href');
                    if (href && href !== '#') {
                        window.location.href = href;
                    }
                }
            }, true);

            // click handles desktop/mouse; skip if just handled by touch.
            document.addEventListener('click', function(e) {
                if (Date.now() - lastTouch < 500) {
                    var el = e.target.closest('.spp-nav-user-toggle, .spp-nav-dropdown-item');
                    if (el) {
                        e.preventDefault();
                        e.stopImmediatePropagation();
                        return;
                    }
                }
                toggleDropdown(e);
            }, true);

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeAllDropdowns();
                }
            });

            // On mobile, move account items to the top of the menu list.
            if (window.matchMedia('(max-width: 768px)').matches) {
                var items = document.querySelectorAll('.spp-nav-user, .spp-nav-signin, .spp-nav-signup');
                for (var i = items.length - 1; i >= 0; i--) {
                    var el = items[i];
                    if (el.parentNode) {
                        el.parentNode.insertBefore(el, el.parentNode.firstChild);
                    }
                }
            }
        })();
        ";
    }

    /**
     * Handle impersonation requests.
     */
    public function handle_impersonation_requests() {
        if ( class_exists( 'SPP_Permissions' ) ) {
            SPP_Permissions::handle_impersonation_request();
        }
    }

    /**
     * Add body class when impersonating.
     *
     * @param array $classes Array of body classes.
     * @return array Modified array of body classes.
     */
    public function add_impersonation_body_class( $classes ) {
        if ( class_exists( 'SPP_Permissions' ) && SPP_Permissions::is_impersonating() ) {
            $classes[] = 'spp-impersonating';
        }

        return $classes;
    }

    /**
     * Filter block theme navigation to inject portal menu items.
     *
     * Handles Full Site Editing (FSE) themes that use the core/navigation
     * block instead of wp_nav_menu().
     *
     * @param string $block_content The block content.
     * @param array  $block         The block data.
     * @return string Modified block content.
     */
    public function add_portal_block_nav_items( $block_content, $block ) {
        // Only process navigation blocks.
        if ( 'core/navigation' !== ( $block['blockName'] ?? '' ) ) {
            return $block_content;
        }

        // Skip if already injected via classic menu or a previous block.
        if ( $this->items_injected ) {
            return $block_content;
        }

        // Check if integration is enabled.
        if ( ! apply_filters( 'spp_enable_menu_integration', true ) ) {
            return $block_content;
        }

        // Check setting — if explicitly disabled, skip.
        if ( 'disabled' === get_option( 'spp_menu_location', 'auto' ) ) {
            return $block_content;
        }

        // Generate portal items and inject before closing </nav> or </ul>.
        if ( is_user_logged_in() ) {
            $portal_html = $this->get_logged_in_menu_item();
        } else {
            $portal_html = $this->get_logged_out_menu_item();
        }

        $this->items_injected = true;

        // Insert before the closing </ul> inside the nav.
        $close_ul_pos = strrpos( $block_content, '</ul>' );
        if ( false !== $close_ul_pos ) {
            $block_content = substr_replace( $block_content, $portal_html, $close_ul_pos, 0 );
        }

        return $block_content;
    }

    /**
     * Render a floating fallback menu in the footer.
     *
     * If neither wp_nav_menu_items nor render_block injected the portal
     * items, render a fixed-position element so login/account is always accessible.
     */
    public function render_fallback_menu() {
        // Skip if already injected.
        if ( $this->items_injected ) {
            return;
        }

        // Check if integration is enabled.
        if ( ! apply_filters( 'spp_enable_menu_integration', true ) ) {
            return;
        }

        // Check setting — if explicitly disabled, skip.
        if ( 'disabled' === get_option( 'spp_menu_location', 'auto' ) ) {
            return;
        }

        // Don't render fallback in admin.
        if ( is_admin() ) {
            return;
        }

        $this->items_injected = true;

        echo '<div class="spp-nav-fallback" style="position: fixed; top: 12px; right: 12px; z-index: 99999;">';
        echo '<ul class="spp-nav-fallback-list" style="list-style: none; margin: 0; padding: 0; display: flex; gap: 8px; align-items: center;">';

        if ( is_user_logged_in() ) {
            echo $this->get_logged_in_menu_item(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- method returns pre-escaped HTML.
        } else {
            echo $this->get_logged_out_menu_item(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- method returns pre-escaped HTML.
        }

        echo '</ul></div>';
    }

    /**
     * SVG Icons
     */

    /**
     * Get sign-in icon SVG.
     *
     * @return string SVG markup.
     */
    private function get_signin_icon_svg() {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path><polyline points="10 17 15 12 10 7"></polyline><line x1="15" y1="12" x2="3" y2="12"></line></svg>';
    }

    /**
     * Get dropdown arrow SVG.
     *
     * @return string SVG markup.
     */
    private function get_dropdown_arrow_svg() {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>';
    }

    /**
     * Get dashboard icon SVG.
     *
     * @return string SVG markup.
     */
    private function get_dashboard_icon_svg() {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>';
    }

    /**
     * Get profile icon SVG.
     *
     * @return string SVG markup.
     */
    private function get_profile_icon_svg() {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>';
    }

    /**
     * Get admin icon SVG.
     *
     * @return string SVG markup.
     */
    private function get_admin_icon_svg() {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>';
    }

    /**
     * Get logout icon SVG.
     *
     * @return string SVG markup.
     */
    private function get_logout_icon_svg() {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>';
    }

    /**
     * Get exit impersonation icon SVG.
     *
     * @return string SVG markup.
     */
    private function get_exit_icon_svg() {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>';
    }
}
