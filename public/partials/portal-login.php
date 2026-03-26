<?php
/**
 * Portal Login Page Template.
 *
 * Premium glassmorphism login page design.
 *
 * @package CubePaymentPortal
 *
 * Available variables:
 * @var string $redirect_to    URL to redirect after login.
 * @var bool   $show_logo      Whether to show the logo.
 * @var bool   $show_remember  Whether to show "Remember me" checkbox.
 * @var bool   $show_register  Whether to show registration link.
 * @var string $contact_url    Contact page URL.
 * @var string $logo_url       Logo image URL.
 * @var string $login_error    Login error message.
 * @var string $lost_password  Lost password URL.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Generate unique form ID for multiple forms on same page.
$form_id = 'spp-login-form-' . wp_unique_id();
?>

<div class="spp-portal-wrapper spp-login-wrapper">
    <div class="spp-login-container">
        <div class="spp-login-card">
            <?php if ( $show_logo ) : ?>
                <div class="spp-login-logo">
                    <?php if ( ! empty( $logo_url ) ) : ?>
                        <img
                            src="<?php echo esc_url( $logo_url ); ?>"
                            alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
                            class="spp-login-logo-img"
                        />
                    <?php else : ?>
                        <span class="spp-login-site-name"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="spp-login-header">
                <h1 class="spp-login-title">
                    <?php esc_html_e( 'Sign In to Your Account', 'cube-payment-portal' ); ?>
                </h1>
                <p class="spp-login-subtitle">
                    <?php esc_html_e( 'Welcome back! Please enter your credentials.', 'cube-payment-portal' ); ?>
                </p>
            </div>

            <?php if ( ! empty( $login_error ) ) : ?>
                <div class="spp-login-error" role="alert">
                    <span class="spp-login-error-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="20" height="20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                        </svg>
                    </span>
                    <span class="spp-login-error-text"><?php echo esc_html( $login_error ); ?></span>
                </div>
            <?php endif; ?>

            <div class="spp-login-error spp-login-error-js" role="alert" style="display: none;">
                <span class="spp-login-error-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="20" height="20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                    </svg>
                </span>
                <span class="spp-login-error-text"></span>
            </div>

            <form
                id="<?php echo esc_attr( $form_id ); ?>"
                class="spp-login-form"
                method="post"
                action="<?php echo esc_url( wp_login_url() ); ?>"
            >
                <?php wp_nonce_field( 'spp_login_form', 'spp_login_nonce' ); ?>
                <input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_to ); ?>" />

                <div class="spp-form-group">
                    <label for="<?php echo esc_attr( $form_id ); ?>-user" class="spp-form-label">
                        <?php esc_html_e( 'Username or Email', 'cube-payment-portal' ); ?>
                    </label>
                    <div class="spp-input-wrapper">
                        <span class="spp-input-icon spp-input-icon-left" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="20" height="20">
                                <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd" />
                            </svg>
                        </span>
                        <input
                            type="text"
                            id="<?php echo esc_attr( $form_id ); ?>-user"
                            name="log"
                            class="spp-form-input spp-input-with-icon"
                            placeholder="<?php esc_attr_e( 'Enter your username or email', 'cube-payment-portal' ); ?>"
                            autocomplete="username"
                            required
                        />
                    </div>
                </div>

                <div class="spp-form-group">
                    <label for="<?php echo esc_attr( $form_id ); ?>-pass" class="spp-form-label">
                        <?php esc_html_e( 'Password', 'cube-payment-portal' ); ?>
                    </label>
                    <div class="spp-input-wrapper">
                        <span class="spp-input-icon spp-input-icon-left" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="20" height="20">
                                <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
                            </svg>
                        </span>
                        <input
                            type="password"
                            id="<?php echo esc_attr( $form_id ); ?>-pass"
                            name="pwd"
                            class="spp-form-input spp-input-with-icon spp-input-password"
                            placeholder="<?php esc_attr_e( 'Enter your password', 'cube-payment-portal' ); ?>"
                            autocomplete="current-password"
                            required
                        />
                        <button
                            type="button"
                            class="spp-password-toggle spp-input-icon spp-input-icon-right"
                            aria-label="<?php esc_attr_e( 'Toggle password visibility', 'cube-payment-portal' ); ?>"
                            data-target="<?php echo esc_attr( $form_id ); ?>-pass"
                        >
                            <svg class="spp-icon-show" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="20" height="20">
                                <path d="M10 12a2 2 0 100-4 2 2 0 000 4z" />
                                <path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd" />
                            </svg>
                            <svg class="spp-icon-hide" style="display: none;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="20" height="20">
                                <path fill-rule="evenodd" d="M3.707 2.293a1 1 0 00-1.414 1.414l14 14a1 1 0 001.414-1.414l-1.473-1.473A10.014 10.014 0 0019.542 10C18.268 5.943 14.478 3 10 3a9.958 9.958 0 00-4.512 1.074l-1.78-1.781zm4.261 4.26l1.514 1.515a2.003 2.003 0 012.45 2.45l1.514 1.514a4 4 0 00-5.478-5.478z" clip-rule="evenodd" />
                                <path d="M12.454 16.697L9.75 13.992a4 4 0 01-3.742-3.741L2.335 6.578A9.98 9.98 0 00.458 10c1.274 4.057 5.065 7 9.542 7 .847 0 1.669-.105 2.454-.303z" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="spp-form-row spp-form-row-flex">
                    <?php if ( $show_remember ) : ?>
                        <label class="spp-checkbox-label">
                            <input
                                type="checkbox"
                                name="rememberme"
                                value="forever"
                                class="spp-checkbox"
                            />
                            <span class="spp-checkbox-custom"></span>
                            <span class="spp-checkbox-text">
                                <?php esc_html_e( 'Remember me', 'cube-payment-portal' ); ?>
                            </span>
                        </label>
                    <?php else : ?>
                        <span></span>
                    <?php endif; ?>

                    <a href="<?php echo esc_url( $lost_password ); ?>" class="spp-forgot-password">
                        <?php esc_html_e( 'Forgot password?', 'cube-payment-portal' ); ?>
                    </a>
                </div>

                <button type="submit" class="spp-btn spp-btn-primary spp-btn-full spp-login-submit">
                    <span class="spp-btn-text">
                        <?php esc_html_e( 'Sign In', 'cube-payment-portal' ); ?>
                    </span>
                    <span class="spp-btn-loading" style="display: none;">
                        <svg class="spp-spinner" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" width="20" height="20">
                            <circle class="spp-spinner-track" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="spp-spinner-head" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span><?php esc_html_e( 'Signing in...', 'cube-payment-portal' ); ?></span>
                    </span>
                </button>
            </form>

            <div class="spp-login-footer">
                <?php
                $register_page_id  = get_option( 'spp_register_page_id' );
                $register_page_url = $register_page_id ? get_permalink( $register_page_id ) : '';
                ?>
                <?php if ( ! empty( $register_page_url ) ) : ?>
                    <p class="spp-login-register">
                        <?php esc_html_e( "Don't have an account?", 'cube-payment-portal' ); ?>
                        <a href="<?php echo esc_url( $register_page_url ); ?>">
                            <?php esc_html_e( 'Create one', 'cube-payment-portal' ); ?>
                        </a>
                    </p>
                <?php elseif ( ! empty( $contact_url ) ) : ?>
                    <p class="spp-login-contact">
                        <?php esc_html_e( "Don't have an account?", 'cube-payment-portal' ); ?>
                        <a href="<?php echo esc_url( $contact_url ); ?>">
                            <?php esc_html_e( 'Contact us', 'cube-payment-portal' ); ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="spp-login-branding">
            <p class="spp-powered-by">
                <?php
                printf(
                    /* translators: %s: Site name */
                    esc_html__( 'Secure login powered by %s', 'cube-payment-portal' ),
                    '<strong>' . esc_html( get_bloginfo( 'name' ) ) . '</strong>'
                );
                ?>
            </p>
        </div>
    </div>
</div>
