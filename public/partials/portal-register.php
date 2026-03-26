<?php
/**
 * Portal Registration Page Template.
 *
 * Premium glassmorphism registration page design (matches login page).
 *
 * @package CubePaymentPortal
 *
 * Available variables:
 * @var string $redirect_to    URL to redirect after registration.
 * @var bool   $show_logo      Whether to show the logo.
 * @var string $logo_url       Logo image URL.
 * @var string $login_url      Login page URL.
 * @var string $register_error Registration error message.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Generate unique form ID for multiple forms on same page.
$form_id = 'spp-register-form-' . wp_unique_id();
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
                    <?php esc_html_e( 'Create Your Account', 'cube-payment-portal' ); ?>
                </h1>
                <p class="spp-login-subtitle">
                    <?php esc_html_e( 'Sign up to access your client portal.', 'cube-payment-portal' ); ?>
                </p>
            </div>

            <?php if ( ! empty( $register_error ) ) : ?>
                <div class="spp-login-error" role="alert">
                    <span class="spp-login-error-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="20" height="20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                        </svg>
                    </span>
                    <span class="spp-login-error-text"><?php echo esc_html( $register_error ); ?></span>
                </div>
            <?php endif; ?>

            <div class="spp-login-error spp-register-error-js" role="alert" style="display: none;">
                <span class="spp-login-error-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="20" height="20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                    </svg>
                </span>
                <span class="spp-login-error-text"></span>
            </div>

            <div class="spp-register-success" style="display: none;" role="status">
                <span class="spp-register-success-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="20" height="20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                </span>
                <span class="spp-register-success-text"></span>
            </div>

            <form
                id="<?php echo esc_attr( $form_id ); ?>"
                class="spp-register-form"
                method="post"
                novalidate
            >
                <?php wp_nonce_field( 'spp_register_form', 'nonce' ); ?>
                <input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_to ); ?>" />

                <div class="spp-form-row-grid">
                    <div class="spp-form-group">
                        <label for="<?php echo esc_attr( $form_id ); ?>-first-name" class="spp-form-label">
                            <?php esc_html_e( 'First Name', 'cube-payment-portal' ); ?>
                        </label>
                        <div class="spp-input-wrapper">
                            <span class="spp-input-icon spp-input-icon-left" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="20" height="20">
                                    <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd" />
                                </svg>
                            </span>
                            <input
                                type="text"
                                id="<?php echo esc_attr( $form_id ); ?>-first-name"
                                name="first_name"
                                class="spp-form-input spp-input-with-icon"
                                placeholder="<?php esc_attr_e( 'First name', 'cube-payment-portal' ); ?>"
                                autocomplete="given-name"
                                required
                            />
                        </div>
                    </div>

                    <div class="spp-form-group">
                        <label for="<?php echo esc_attr( $form_id ); ?>-last-name" class="spp-form-label">
                            <?php esc_html_e( 'Last Name', 'cube-payment-portal' ); ?>
                        </label>
                        <div class="spp-input-wrapper">
                            <span class="spp-input-icon spp-input-icon-left" aria-hidden="true">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="20" height="20">
                                    <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd" />
                                </svg>
                            </span>
                            <input
                                type="text"
                                id="<?php echo esc_attr( $form_id ); ?>-last-name"
                                name="last_name"
                                class="spp-form-input spp-input-with-icon"
                                placeholder="<?php esc_attr_e( 'Last name', 'cube-payment-portal' ); ?>"
                                autocomplete="family-name"
                                required
                            />
                        </div>
                    </div>
                </div>

                <div class="spp-form-group">
                    <label for="<?php echo esc_attr( $form_id ); ?>-email" class="spp-form-label">
                        <?php esc_html_e( 'Email Address', 'cube-payment-portal' ); ?>
                    </label>
                    <div class="spp-input-wrapper">
                        <span class="spp-input-icon spp-input-icon-left" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="20" height="20">
                                <path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z" />
                                <path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" />
                            </svg>
                        </span>
                        <input
                            type="email"
                            id="<?php echo esc_attr( $form_id ); ?>-email"
                            name="email"
                            class="spp-form-input spp-input-with-icon"
                            placeholder="<?php esc_attr_e( 'Enter your email address', 'cube-payment-portal' ); ?>"
                            autocomplete="email"
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
                            name="password"
                            class="spp-form-input spp-input-with-icon spp-input-password"
                            placeholder="<?php esc_attr_e( 'Create a password', 'cube-payment-portal' ); ?>"
                            autocomplete="new-password"
                            minlength="8"
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
                    <p class="spp-form-hint">
                        <?php esc_html_e( 'Must be at least 8 characters.', 'cube-payment-portal' ); ?>
                    </p>
                </div>

                <div class="spp-form-group">
                    <label for="<?php echo esc_attr( $form_id ); ?>-pass-confirm" class="spp-form-label">
                        <?php esc_html_e( 'Confirm Password', 'cube-payment-portal' ); ?>
                    </label>
                    <div class="spp-input-wrapper">
                        <span class="spp-input-icon spp-input-icon-left" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="20" height="20">
                                <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
                            </svg>
                        </span>
                        <input
                            type="password"
                            id="<?php echo esc_attr( $form_id ); ?>-pass-confirm"
                            name="password_confirm"
                            class="spp-form-input spp-input-with-icon spp-input-password"
                            placeholder="<?php esc_attr_e( 'Confirm your password', 'cube-payment-portal' ); ?>"
                            autocomplete="new-password"
                            minlength="8"
                            required
                        />
                        <button
                            type="button"
                            class="spp-password-toggle spp-input-icon spp-input-icon-right"
                            aria-label="<?php esc_attr_e( 'Toggle password visibility', 'cube-payment-portal' ); ?>"
                            data-target="<?php echo esc_attr( $form_id ); ?>-pass-confirm"
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

                <button type="submit" class="spp-btn spp-btn-primary spp-btn-full spp-register-submit">
                    <span class="spp-btn-text">
                        <?php esc_html_e( 'Create Account', 'cube-payment-portal' ); ?>
                    </span>
                    <span class="spp-btn-loading" style="display: none;">
                        <svg class="spp-spinner" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" width="20" height="20">
                            <circle class="spp-spinner-track" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="spp-spinner-head" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span><?php esc_html_e( 'Creating account...', 'cube-payment-portal' ); ?></span>
                    </span>
                </button>
            </form>

            <div class="spp-login-footer">
                <p class="spp-login-register">
                    <?php esc_html_e( 'Already have an account?', 'cube-payment-portal' ); ?>
                    <a href="<?php echo esc_url( $login_url ); ?>">
                        <?php esc_html_e( 'Sign In', 'cube-payment-portal' ); ?>
                    </a>
                </p>
            </div>
        </div>

        <div class="spp-login-branding">
            <p class="spp-powered-by">
                <?php
                printf(
                    /* translators: %s: Site name */
                    esc_html__( 'Secure registration powered by %s', 'cube-payment-portal' ),
                    '<strong>' . esc_html( get_bloginfo( 'name' ) ) . '</strong>'
                );
                ?>
            </p>
        </div>
    </div>
</div>
