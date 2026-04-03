<?php
/**
 * Plugin-provided full-page template for the Client Portal.
 *
 * Used only for classic (non-FSE) themes. Block/FSE themes let WordPress
 * render their own block template normally — the body class .spp-portal-template
 * + CSS handles stripping content-area width constraints in both cases.
 *
 * @package CubePaymentPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();

while ( have_posts() ) :
    the_post();
    the_content();
endwhile;

get_footer();
