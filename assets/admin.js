jQuery(function ($) {
    let toastTimer = null;

    function showToast(message, success) {
        const $toast = $('#nhsmtp-toast');
        const $icon = $toast.find('.nhsmtp-toast-icon');

        window.clearTimeout(toastTimer);

        $toast
            .stop(true, true)
            .removeClass('success error')
            .addClass(success ? 'success' : 'error')
            .css('display', 'flex');

        $icon
            .removeClass('dashicons-yes-alt dashicons-warning')
            .addClass(success ? 'dashicons-yes-alt' : 'dashicons-warning');

        $toast.find('.nhsmtp-toast-text').text(message);

        toastTimer = window.setTimeout(function () {
            $toast.fadeOut(250);
        }, 5000);
    }

    function updateActivity(data) {
        if (!data) {
            return;
        }

        if (typeof data.log_content !== 'undefined') {
            $('.nhsmtp-log').val(data.log_content);
        }

        if (typeof data.history_html !== 'undefined') {
            $('#nhsmtp-history-content').html(data.history_html);
        }
    }

    function smtpFormReady() {
        const host = $.trim($('#nhsmtp-host').val() || '');
        const port = $.trim($('#nhsmtp-port').val() || '');
        const fromEmail = $.trim($('#nhsmtp-from_email').val() || '');
        const authEnabled = $('input[name="nhsmtp_settings[auth]"]').is(':checked');
        const username = $.trim($('#nhsmtp-username').val() || '');
        const $password = $('#nhsmtp-password');
        const hasSavedPassword = $password.prop('disabled') && $password.val() !== '';
        const enteredPassword = !$password.prop('disabled') && $.trim($password.val() || '') !== '';
        const passwordReady = hasSavedPassword || enteredPassword;

        return host !== ''
            && port !== ''
            && fromEmail !== ''
            && (!authEnabled || (username !== '' && passwordReady));
    }

    function updateTestAvailability() {
        const ready = smtpFormReady();
        const $card = $('#nhsmtp-test-email');
        const $button = $('#nhsmtp-send-test');
        const $note = $('.nhsmtp-test-disabled-note');

        $card.toggleClass('is-disabled', !ready).attr('data-ready', ready ? '1' : '0');
        $button.prop('disabled', !ready);
        $note.toggle(!ready);
    }

    updateTestAvailability();

    let recipientSaveTimer = null;

    $('#nhsmtp-test-to').on('input change', function () {
        const email = $.trim($(this).val());

        window.clearTimeout(recipientSaveTimer);

        recipientSaveTimer = window.setTimeout(function () {
            if (!email) {
                return;
            }

            $.post(nhsmtpData.ajaxUrl, {
                action: 'nhsmtp_save_test_recipient',
                nonce: nhsmtpData.nonce,
                email: email
            });
        }, 600);
    });

    $(document).on(
        'input change',
        '#nhsmtp-host, #nhsmtp-port, #nhsmtp-from_email, #nhsmtp-username, #nhsmtp-password, input[name="nhsmtp_settings[auth]"]',
        updateTestAvailability
    );

    $(document).on('click', '.nhsmtp-toast-close', function () {
        window.clearTimeout(toastTimer);
        $('#nhsmtp-toast').fadeOut(150);
    });

    $('#nhsmtp-encryption').on('change', function () {
        const encryption = $(this).val();
        const $port = $('#nhsmtp-port');
        const currentPort = String($port.val());

        if (encryption === 'ssl' && ['', '25', '587'].includes(currentPort)) {
            $port.val('465');
        } else if (encryption === 'tls' && ['', '25', '465'].includes(currentPort)) {
            $port.val('587');
        } else if (encryption === '' && ['', '465', '587'].includes(currentPort)) {
            $port.val('25');
        }
    });

    $(document).on('click', '.nhsmtp-toggle-password', function () {
        const $button = $(this);
        const $input = $('#nhsmtp-password');

        if ($input.prop('disabled')) {
            return;
        }

        const show = $input.attr('type') === 'password';
        $input.attr('type', show ? 'text' : 'password');
        $button.text(show ? 'Hide' : 'Show');
    });

    $(document).on('click', '.nhsmtp-remove-password', function () {
        const $button = $(this);
        const $wrapper = $button.closest('.nhsmtp-password');
        const $input = $('#nhsmtp-password');
        const $remove = $('#nhsmtp-remove-password');
        const $show = $wrapper.find('.nhsmtp-toggle-password');
        const removing = $remove.val() === '1';

        if (!removing) {
            $input.prop('disabled', false).val('').attr('type', 'password').focus();
            $remove.val('1');
            $show.show().text('Show');
            $button.text('Cancel');
            $wrapper.removeClass('is-locked');
        } else {
            $input.prop('disabled', true).val('************').attr('type', 'password');
            $remove.val('0');
            $show.hide();
            $button.text('Remove Password');
            $wrapper.addClass('is-locked');
        }

        updateTestAvailability();
    });

    $(document).on('click', '.nhsmtp-admin-notice .notice-dismiss', function () {
        const $notice = $(this).closest('.nhsmtp-admin-notice');

        $.post(nhsmtpData.ajaxUrl, {
            action: 'nhsmtp_dismiss_notice',
            nonce: nhsmtpData.nonce
        }).always(function () {
            $notice.slideUp(150);
        });
    });

    $('#nhsmtp-settings-form').on('submit', function (event) {
        event.preventDefault();

        const $form = $(this);
        const $button = $('#nhsmtp-save-settings');
        const settings = {};

        $form.serializeArray().forEach(function (item) {
            const match = item.name.match(/^nhsmtp_settings\[([^\]]+)\]$/);
            if (match) {
                settings[match[1]] = item.value;
            }
        });

        $form.find('input[type="checkbox"][name^="nhsmtp_settings["]').each(function () {
            const match = this.name.match(/^nhsmtp_settings\[([^\]]+)\]$/);
            if (match && !this.checked) {
                settings[match[1]] = '0';
            }
        });

        $button.prop('disabled', true).val('Saving...');

        $.post(nhsmtpData.ajaxUrl, {
            action: 'nhsmtp_save_settings',
            nonce: nhsmtpData.nonce,
            settings: settings
        })
        .done(function (response) {
            const ok = !!response.success;
            const message = response.data && response.data.message ? response.data.message : 'Settings saved.';
            showToast(message, ok);

            if (ok && typeof response.data.enabled !== 'undefined') {
                const $status = $('.nhsmtp-status');
                $status.toggleClass('is-on', response.data.enabled).toggleClass('is-off', !response.data.enabled);
                $status.find('.nhsmtp-status-text').text(response.data.enabled ? 'SMTP Enabled' : 'SMTP Disabled');
            }

            updateTestAvailability();
        })
        .fail(function () {
            showToast('The settings could not be saved. Please try again.', false);
        })
        .always(function () {
            $button.prop('disabled', false).val('Save SMTP Settings');
        });
    });

    $('#nhsmtp-send-test').on('click', function () {
        const $button = $(this);

        if (!smtpFormReady()) {
            showToast('Complete and save all required SMTP fields before sending a test email.', false);
            return;
        }
        const to = $('#nhsmtp-test-to').val();

        $button.prop('disabled', true).text('Testing SMTP...');

        $.post(nhsmtpData.ajaxUrl, {
            action: 'nhsmtp_send_test',
            nonce: nhsmtpData.nonce,
            to: to
        })
        .done(function (response) {
            showToast(response.data.message, !!response.success);
            updateActivity(response.data);
        })
        .fail(function (xhr) {
            const message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
                ? xhr.responseJSON.data.message
                : 'The SMTP test request failed.';
            showToast(message, false);

            if (xhr.responseJSON && xhr.responseJSON.data) {
                updateActivity(xhr.responseJSON.data);
            }
        })
        .always(function () {
            $button.prop('disabled', false).text('Send Test Email');
        });
    });

    $('#nhsmtp-copy-log').on('click', async function () {
        const $button = $(this);
        const text = $('.nhsmtp-log').val() || '';

        if (!text) {
            showToast('There is no log content to copy.', false);
            return;
        }

        try {
            await navigator.clipboard.writeText(text);
        } catch (error) {
            const $temp = $('<textarea>').val(text).appendTo('body').select();
            document.execCommand('copy');
            $temp.remove();
        }

        const original = $button.text();
        $button.text('Copied!');
        showToast('Debug log copied to clipboard.', true);

        window.setTimeout(function () {
            $button.text(original);
        }, 1800);
    });

    $('#nhsmtp-clear-log').on('click', function () {
        const $button = $(this);
        $button.prop('disabled', true);

        $.post(nhsmtpData.ajaxUrl, {
            action: 'nhsmtp_clear_log',
            nonce: nhsmtpData.nonce
        })
        .done(function (response) {
            showToast(response.data.message, !!response.success);
            updateActivity(response.data);
        })
        .fail(function () {
            showToast('The log could not be cleared.', false);
        })
        .always(function () {
            $button.prop('disabled', false);
        });
    });

    $('#nhsmtp-clear-history').on('click', function () {
        const $button = $(this);

        $button.prop('disabled', true);

        $.post(nhsmtpData.ajaxUrl, {
            action: 'nhsmtp_clear_history',
            nonce: nhsmtpData.nonce
        })
        .done(function (response) {
            showToast(response.data.message, !!response.success);
            updateActivity(response.data);
        })
        .fail(function () {
            showToast('Email history could not be cleared.', false);
        })
        .always(function () {
            $button.prop('disabled', false);
        });
    });

    $(document).on('click', '.nhsmtp-retry-email', function () {
        const $button = $(this);

        $button.prop('disabled', true).text('Retrying...');

        $.post(nhsmtpData.ajaxUrl, {
            action: 'nhsmtp_retry_email',
            nonce: nhsmtpData.nonce,
            id: $button.data('id')
        })
        .done(function (response) {
            showToast(response.data.message, !!response.success);
            updateActivity(response.data);
        })
        .fail(function (xhr) {
            const message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
                ? xhr.responseJSON.data.message
                : 'The retry request failed.';
            showToast(message, false);

            if (xhr.responseJSON && xhr.responseJSON.data) {
                updateActivity(xhr.responseJSON.data);
            }
        })
        .always(function () {
            $button.prop('disabled', false).text('Retry');
        });
    });
});
