/**
 * Portal Registration Page Script.
 *
 * Handles AJAX registration form submission, client-side validation,
 * and password toggle functionality.
 *
 * @package CubePaymentPortal
 */

(function ($) {
    'use strict';

    $(document).ready(function () {
        $('.spp-register-form').each(function () {
            initRegisterForm($(this));
        });

        // Password visibility toggle.
        $(document).on('click', '.spp-register-form .spp-password-toggle', function (e) {
            e.preventDefault();
            var targetId = $(this).data('target');
            var $input = $('#' + targetId);
            if (!$input.length) return;

            var isPassword = $input.attr('type') === 'password';
            $input.attr('type', isPassword ? 'text' : 'password');
            $(this).find('.spp-icon-show').toggle(!isPassword);
            $(this).find('.spp-icon-hide').toggle(isPassword);
        });

        // Password strength indicator.
        $(document).on('input', '.spp-register-form input[name="password"]', function () {
            var password = $(this).val();
            var $meter = $(this).closest('.spp-form-group').find('.spp-password-strength');
            if (!$meter.length) {
                $meter = $('<div class="spp-password-strength"><div class="spp-password-strength__bar"></div><span class="spp-password-strength__label"></span></div>');
                $(this).closest('.spp-form-group').append($meter);
            }

            if (!password) {
                $meter.hide();
                return;
            }
            $meter.show();

            var strength = 0;
            if (password.length >= 8) strength++;
            if (password.length >= 12) strength++;
            if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
            if (/\d/.test(password)) strength++;
            if (/[^a-zA-Z0-9]/.test(password)) strength++;

            var levels = ['weak', 'weak', 'fair', 'good', 'strong', 'strong'];
            var labels = ['Weak', 'Weak', 'Fair', 'Good', 'Strong', 'Strong'];
            var level = levels[strength] || 'weak';
            var label = labels[strength] || 'Weak';

            $meter.find('.spp-password-strength__bar')
                .removeClass('spp-password-strength--weak spp-password-strength--fair spp-password-strength--good spp-password-strength--strong')
                .addClass('spp-password-strength--' + level)
                .css('width', Math.max(20, (strength / 5) * 100) + '%');
            $meter.find('.spp-password-strength__label').text(label);
        });
    });

    function initRegisterForm($form) {
        var $submitBtn = $form.find('.spp-register-submit');
        var $btnText = $submitBtn.find('.spp-btn-text');
        var $btnLoading = $submitBtn.find('.spp-btn-loading');
        var $errorEl = $form.closest('.spp-login-card').find('.spp-register-error-js');
        var $successEl = $form.closest('.spp-login-card').find('.spp-register-success');
        var isSubmitting = false;

        $form.on('submit', function (e) {
            e.preventDefault();

            if (isSubmitting) return;

            // Clear previous messages.
            hideError();
            $successEl.hide();

            // Get field values.
            var firstName = $form.find('input[name="first_name"]').val().trim();
            var lastName = $form.find('input[name="last_name"]').val().trim();
            var email = $form.find('input[name="email"]').val().trim();
            var password = $form.find('input[name="password"]').val();
            var passwordConfirm = $form.find('input[name="password_confirm"]').val();

            // Client-side validation.
            if (!firstName) return showError(sppRegister.strings.emptyFirstName);
            if (!lastName) return showError(sppRegister.strings.emptyLastName);
            if (!email) return showError(sppRegister.strings.emptyEmail);
            if (!isValidEmail(email)) return showError(sppRegister.strings.invalidEmail);
            if (!password) return showError(sppRegister.strings.emptyPassword);
            if (password.length < 8) return showError(sppRegister.strings.shortPassword);
            if (password !== passwordConfirm) return showError(sppRegister.strings.passwordMismatch);

            // Show loading state.
            isSubmitting = true;
            $btnText.hide();
            $btnLoading.show();
            $submitBtn.prop('disabled', true);

            $.ajax({
                url: sppRegister.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'spp_register_account',
                    nonce: sppRegister.nonce,
                    first_name: firstName,
                    last_name: lastName,
                    email: email,
                    password: password,
                    password_confirm: passwordConfirm,
                    redirect_to: $form.find('input[name="redirect_to"]').val()
                },
                success: function (response) {
                    if (response.success) {
                        // Show success message and redirect.
                        $form.hide();
                        $successEl.find('.spp-register-success-text').text(sppRegister.strings.success);
                        $successEl.show();

                        setTimeout(function () {
                            window.location.href = response.data.redirect_to;
                        }, 1000);
                    } else {
                        showError((response.data && response.data.message) || 'Registration failed.');
                        resetButton();
                    }
                },
                error: function () {
                    showError('An unexpected error occurred. Please try again.');
                    resetButton();
                }
            });
        });

        function showError(message) {
            $errorEl.find('.spp-login-error-text').text(message);
            $errorEl.show();
        }

        function hideError() {
            $errorEl.hide();
        }

        function resetButton() {
            isSubmitting = false;
            $btnText.show();
            $btnLoading.hide();
            $submitBtn.prop('disabled', false);
        }

        function isValidEmail(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        }
    }
})(jQuery);
