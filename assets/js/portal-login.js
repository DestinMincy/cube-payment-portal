/**
 * Cube Payment Portal - Login Page Handler.
 *
 * Handles AJAX login submission with client-side validation,
 * loading states, and error display.
 *
 * @package CubePaymentPortal
 */

/* global jQuery, sppLogin */
(function ($) {
    'use strict';

    $(document).ready(function () {
        var $forms = $('.spp-login-form');

        $forms.each(function () {
            var $form    = $(this);
            var $submit  = $form.find('.spp-login-submit');
            var $btnText = $submit.find('.spp-btn-text');
            var $btnLoad = $submit.find('.spp-btn-loading');
            var $errorJs = $form.siblings('.spp-login-error-js');
            var submitting = false;

            // Password toggle.
            $form.find('.spp-password-toggle').on('click', function () {
                var $input = $(this).siblings('input');
                var $show  = $(this).find('.spp-eye-show');
                var $hide  = $(this).find('.spp-eye-hide');

                if ($input.attr('type') === 'password') {
                    $input.attr('type', 'text');
                    $show.hide();
                    $hide.show();
                } else {
                    $input.attr('type', 'password');
                    $show.show();
                    $hide.hide();
                }
            });

            // Form submission.
            $form.on('submit', function (e) {
                e.preventDefault();

                if (submitting) {
                    return;
                }

                var username = $form.find('input[name="log"]').val().trim();
                var password = $form.find('input[name="pwd"]').val();

                // Client-side validation.
                if (!username) {
                    showError(sppLogin.strings.emptyUsername);
                    return;
                }
                if (!password) {
                    showError(sppLogin.strings.emptyPassword);
                    return;
                }

                // Show loading state.
                submitting = true;
                $btnText.hide();
                $btnLoad.show();
                $submit.prop('disabled', true);
                hideError();

                $.ajax({
                    url: sppLogin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action:       'spp_portal_login',
                        nonce:        sppLogin.nonce,
                        log:          username,
                        pwd:          password,
                        rememberme:   $form.find('input[name="rememberme"]').is(':checked') ? 'forever' : '',
                        redirect_to:  $form.find('input[name="redirect_to"]').val()
                    },
                    success: function (response) {
                        if (response.success && response.data && response.data.redirect_to) {
                            window.location.href = response.data.redirect_to;
                        } else {
                            // Fallback: submit the form normally.
                            $form.off('submit');
                            $form[0].submit();
                        }
                    },
                    error: function () {
                        // On AJAX error, fall back to standard form submission.
                        $form.off('submit');
                        $form[0].submit();
                    },
                    complete: function () {
                        submitting = false;
                        $btnText.show();
                        $btnLoad.hide();
                        $submit.prop('disabled', false);
                    }
                });
            });

            function showError(message) {
                $errorJs.find('.spp-login-error-text').text(message);
                $errorJs.show().addClass('spp-shake');
                setTimeout(function () {
                    $errorJs.removeClass('spp-shake');
                }, 600);
            }

            function hideError() {
                $errorJs.hide();
            }
        });
    });
})(jQuery);
