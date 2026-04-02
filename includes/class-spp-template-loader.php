<?php
/**
 * Template loader for the Client Portal page template.
 *
 * Registers a plugin-provided page template so it appears in the
 * WordPress "Page Attributes → Template" dropdown, and intercepts
 * template_include to serve the file from the plugin directory
 * (templates live in the theme folder by default, but WordPress
 * allows plugins to override this via template_include).
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Template_Loader
 */
class SPP_Template_Loader {

    /**
     * Template slug used as the _wp_page_template meta value.
     */
    const TEMPLATE_SLUG = 'spp-client-portal';

    /**
     * Absolute path to the template file inside the plugin.
     */
    const TEMPLATE_FILE = 'public/templates/spp-client-portal.php';

    /**
     * Initialize hooks.
     */
    public function __construct() {
        // Expose the template in the Page Attributes dropdown.
        add_filter( 'theme_page_templates', array( $this, 'register_template' ) );

        // Serve the template from the plugin directory when selected.
        add_filter( 'template_include', array( $this, 'load_template' ) );

        // Body class so CSS can target portal pages.
        add_filter( 'body_class', array( $this, 'add_body_class' ) );
    }

    /**
     * Add the portal template to the template list shown in the page editor.
     *
     * @param array $templates Existing templates keyed by slug => label.
     * @return array Modified templates.
     */
    public function register_template( array $templates ) {
        $templates[ self::TEMPLATE_SLUG ] = __( 'Client Portal (Full Width)', 'cube-payment-portal' );
        return $templates;
    }

    /**
     * When a page uses our template slug, return the plugin's template file
     * instead of looking for it inside the active theme.
     *
     * @param string $template Path to the template WordPress would load.
     * @return string Path to the template that should actually be loaded.
     */
    public function load_template( $template ) {
        global $post;

        if ( ! $post instanceof WP_Post ) {
            return $template;
        }

        $page_template = get_post_meta( $post->ID, '_wp_page_template', true );

        if ( self::TEMPLATE_SLUG !== $page_template ) {
            return $template;
        }

        $plugin_template = SPP_PLUGIN_DIR . self::TEMPLATE_FILE;

        if ( file_exists( $plugin_template ) ) {
            return $plugin_template;
        }

        // Plugin template file missing — fall back to WordPress default.
        return $template;
    }

    /**
     * Add a body class on portal template pages for targeted CSS.
     *
     * @param array $classes Existing body classes.
     * @return array Modified body classes.
     */
    public function add_body_class( array $classes ) {
        global $post;

        if ( $post instanceof WP_Post &&
             self::TEMPLATE_SLUG === get_post_meta( $post->ID, '_wp_page_template', true ) ) {
            $classes[] = 'spp-portal-template';
            $classes[] = 'spp-no-sidebar';
        }

        return $classes;
    }

    /**
     * Assign the portal template to a page by ID.
     *
     * Convenience wrapper used by the activator and upgrade routines.
     *
     * @param int $page_id WP post ID.
     * @return bool True if the meta was updated/already correct, false on failure.
     */
    public static function assign_template( $page_id ) {
        $page_id = absint( $page_id );

        if ( ! $page_id ) {
            return false;
        }

        $current = get_post_meta( $page_id, '_wp_page_template', true );

        if ( self::TEMPLATE_SLUG === $current ) {
            return true; // Already assigned.
        }

        return (bool) update_post_meta( $page_id, '_wp_page_template', self::TEMPLATE_SLUG );
    }

    /**
     * Assign the portal template to all existing portal pages.
     *
     * Called during plugin upgrades so sites that created pages before
     * this feature existed also get the template applied.
     */
    public static function apply_to_portal_pages() {
        $portal_page_options = array(
            'spp_portal_page_id',
            'spp_login_page_id',
            'spp_register_page_id',
        );

        foreach ( $portal_page_options as $option ) {
            $page_id = (int) get_option( $option, 0 );

            if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
                self::assign_template( $page_id );
            }
        }
    }
}
