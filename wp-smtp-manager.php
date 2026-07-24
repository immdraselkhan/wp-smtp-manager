<?php
/**
 * Plugin Name: WP SMTP Manager
 * Plugin URI: https://github.com/immdraselkhan/wp-smtp-manager
 * Description: Lightweight SMTP delivery manager with a clean admin UI, test email, debug logging, HELO control, and secure password storage.
 * Version: 1.4.0
 * Author: Md Rasel Khan
 * Author URI: https://raselkhan.dev
 * Text Domain: wp-smtp-manager
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('plugin_row_meta', function($links,$file){
    if($file!==plugin_basename(__FILE__)) return $links;
    $links[]='<a href="https://raselkhan.dev" target="_blank" rel="noopener">Visit Website</a>';
    return $links;
},10,2);

final class WP_SMTP_Manager
{
    const OPTION_KEY  = 'nhsmtp_settings';
    const PAGE_SLUG   = 'wp-smtp-manager';
    const HISTORY_KEY = 'nhsmtp_mail_history';
    const STATUS_KEY  = 'nhsmtp_mail_status';
    const VERSION     = '1.4.0';

    private static $instance = null;

    private $testing_smtp = false;

    private $retrying_history_id = '';

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'plugin_action_links']);

        add_action('phpmailer_init', [$this, 'configure_phpmailer'], 999);
        add_filter('wp_mail_from', [$this, 'filter_from_email'], 999);
        add_filter('wp_mail_from_name', [$this, 'filter_from_name'], 999);
        add_action('wp_mail_failed', [$this, 'log_mail_failure']);
        add_action('wp_mail_succeeded', [$this, 'log_mail_success']);
        add_action('admin_notices', [$this, 'admin_failure_notice']);
        add_action('wp_dashboard_setup', [$this, 'register_dashboard_widget']);

        add_action('wp_ajax_nhsmtp_send_test', [$this, 'ajax_send_test']);
        add_action('wp_ajax_nhsmtp_save_test_recipient', [$this, 'ajax_save_test_recipient']);
        add_action('wp_ajax_nhsmtp_clear_log', [$this, 'ajax_clear_log']);
        add_action('wp_ajax_nhsmtp_dismiss_notice', [$this, 'ajax_dismiss_notice']);
        add_action('wp_ajax_nhsmtp_clear_history', [$this, 'ajax_clear_history']);
        add_action('wp_ajax_nhsmtp_retry_email', [$this, 'ajax_retry_email']);
        add_action('wp_ajax_nhsmtp_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_nhsmtp_refresh_activity', [$this, 'ajax_refresh_activity']);

        register_uninstall_hook(__FILE__, [__CLASS__, 'uninstall']);
    }

    public static function defaults()
    {
        $domain = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $domain = preg_replace('/^www\./i', '', strtolower($domain));
        $admin_email = sanitize_email((string) get_option('admin_email'));
        $site_name = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);

        return [
            'enabled'             => 0,
            'host'                => $domain ? 'mail.' . $domain : '',
            'port'                => 465,
            'encryption'          => 'ssl',
            'auth'                => 1,
            'username'            => '',
            'password'            => '',
            'from_email'          => $admin_email,
            'from_name'           => $site_name,
            'force_from_email'    => 1,
            'force_from_name'     => 1,
            'set_return_path'     => 1,
            'helo_enabled'        => 0,
            'helo'                => '',
            'verify_ssl'          => 1,
            'auto_tls'            => 1,
            'timeout'             => 30,
            'debug_enabled'       => 0,
            'delete_on_uninstall' => 0,
        ];
    }

    public function get_settings()
    {
        $defaults = self::defaults();
        $saved = get_option(self::OPTION_KEY, []);
        $settings = wp_parse_args(is_array($saved) ? $saved : [], $defaults);

        foreach (['host', 'from_email', 'from_name'] as $key) {
            if (!isset($settings[$key]) || trim((string) $settings[$key]) === '') {
                $settings[$key] = $defaults[$key];
            }
        }

        return $settings;
    }

    public function admin_menu()
    {
        add_options_page(
            __('WP SMTP Manager', 'wp-smtp-manager'),
            __('WP SMTP Manager', 'wp-smtp-manager'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function plugin_action_links($links)
    {
        array_unshift(
            $links,
            '<a href="' . esc_url(admin_url('options-general.php?page=' . self::PAGE_SLUG)) . '">' .
            esc_html__('Settings', 'wp-smtp-manager') .
            '</a>'
        );

        return $links;
    }

    public function register_settings()
    {
        register_setting(
            'nhsmtp_settings_group',
            self::OPTION_KEY,
            [
                'type'              => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default'           => self::defaults(),
            ]
        );
    }

    public function sanitize_settings($input)
    {
        $old = $this->get_settings();

        $clean = self::defaults();

        $checkboxes = [
            'enabled',
            'auth',
            'force_from_email',
            'force_from_name',
            'set_return_path',
            'helo_enabled',
            'verify_ssl',
            'auto_tls',
            'debug_enabled',
            'delete_on_uninstall',
        ];

        foreach ($checkboxes as $key) {
            $clean[$key] = !empty($input[$key]) ? 1 : 0;
        }

        $clean['host']       = sanitize_text_field(wp_unslash($input['host'] ?? ''));
        $clean['port']       = min(65535, max(1, absint($input['port'] ?? 465)));
        $clean['encryption'] = in_array(($input['encryption'] ?? ''), ['', 'ssl', 'tls'], true)
            ? $input['encryption']
            : 'ssl';

        $clean['username']   = sanitize_text_field(wp_unslash($input['username'] ?? ''));
        $clean['from_email'] = sanitize_email(wp_unslash($input['from_email'] ?? ''));
        $clean['from_name']  = sanitize_text_field(wp_unslash($input['from_name'] ?? ''));
        $clean['helo']       = sanitize_text_field(wp_unslash($input['helo'] ?? ''));
        $clean['timeout']    = min(120, max(5, absint($input['timeout'] ?? 30)));

        $new_password = isset($input['password']) ? (string) wp_unslash($input['password']) : '';
        $remove_password = !empty($input['remove_password']);

        if ($remove_password && $new_password === '') {
            $clean['password'] = '';
        } elseif ($new_password === '') {
            $clean['password'] = $old['password'] ?? '';
        } else {
            $clean['password'] = $this->encrypt($new_password);
        }

        return $clean;
    }

    private function encryption_key()
    {
        return hash('sha256', wp_salt('auth') . '|' . wp_salt('secure_auth'), true);
    }

    private function legacy_encryption_key()
    {
        return hash('sha256', wp_salt('auth'), true);
    }

    private function encrypt($value)
    {
        if ($value === '' || !function_exists('openssl_encrypt')) {
            return $value;
        }

        try {
            $iv = random_bytes(16);
        } catch (Exception $e) {
            return $value;
        }

        $encrypted = openssl_encrypt(
            $value,
            'AES-256-CBC',
            $this->encryption_key(),
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($encrypted === false) {
            return $value;
        }

        $mac = hash_hmac('sha256', $iv . $encrypted, $this->encryption_key(), true);

        return 'enc2:' . base64_encode($iv . $mac . $encrypted);
    }

    private function decrypt($value)
    {
        if (!is_string($value) || $value === '') {
            return '';
        }

        $original = $value;

        for ($depth = 0; $depth < 3; $depth++) {
            $decrypted = $this->decrypt_once($value);

            if ($decrypted === '' || $decrypted === $value) {
                return $decrypted === '' ? '' : $value;
            }

            $value = $decrypted;

            if (strpos($value, 'enc2:') !== 0 && strpos($value, 'enc:') !== 0) {
                return $value;
            }
        }

        return $value === $original ? $original : $value;
    }

    private function decrypt_once($value)
    {
        if (strpos($value, 'enc2:') === 0 && function_exists('openssl_decrypt')) {
            $payload = base64_decode(substr($value, 5), true);

            if ($payload === false || strlen($payload) <= 48) {
                return '';
            }

            $iv        = substr($payload, 0, 16);
            $mac       = substr($payload, 16, 32);
            $encrypted = substr($payload, 48);
            $expected  = hash_hmac('sha256', $iv . $encrypted, $this->encryption_key(), true);

            if (!hash_equals($expected, $mac)) {
                return '';
            }

            $decrypted = openssl_decrypt(
                $encrypted,
                'AES-256-CBC',
                $this->encryption_key(),
                OPENSSL_RAW_DATA,
                $iv
            );

            return $decrypted === false ? '' : $decrypted;
        }

        if (strpos($value, 'enc:') === 0 && function_exists('openssl_decrypt')) {
            $payload = base64_decode(substr($value, 4), true);

            if ($payload === false || strlen($payload) <= 16) {
                return '';
            }

            $iv        = substr($payload, 0, 16);
            $encrypted = substr($payload, 16);

            $decrypted = openssl_decrypt(
                $encrypted,
                'AES-256-CBC',
                $this->legacy_encryption_key(),
                OPENSSL_RAW_DATA,
                $iv
            );

            return $decrypted === false ? '' : $decrypted;
        }

        return $value;
    }

    private function saved_password_is_readable($stored_password)
    {
        if ($stored_password === '') {
            return true;
        }

        return $this->decrypt($stored_password) !== '';
    }

    public function configure_phpmailer($phpmailer)
    {
        $settings = $this->get_settings();

        if ((empty($settings['enabled']) && !$this->testing_smtp) || empty($settings['host'])) {
            return;
        }

        $password = $this->decrypt((string) $settings['password']);

        $phpmailer->isSMTP();
        $phpmailer->Host          = trim((string) $settings['host']);
        $phpmailer->Port          = (int) $settings['port'];
        $phpmailer->SMTPAuth      = !empty($settings['auth']);
        $phpmailer->SMTPAutoTLS   = !empty($settings['auto_tls']);
        $phpmailer->SMTPKeepAlive = false;
        $phpmailer->Timeout       = (int) $settings['timeout'];
        $phpmailer->CharSet       = 'UTF-8';
        $phpmailer->AuthType      = '';

        if ($phpmailer->SMTPAuth) {
            $phpmailer->Username = trim((string) $settings['username']);
            $phpmailer->Password = $password;
        }

        if ($settings['encryption'] === 'ssl') {
            $phpmailer->SMTPSecure = 'ssl';
        } elseif ($settings['encryption'] === 'tls') {
            $phpmailer->SMTPSecure = 'tls';
        } else {
            $phpmailer->SMTPSecure = '';
        }

        if (!empty($settings['from_email'])) {
            try {
                $phpmailer->setFrom(
                    (string) $settings['from_email'],
                    (string) $settings['from_name'],
                    false
                );
            } catch (Exception $e) {
                $phpmailer->From     = (string) $settings['from_email'];
                $phpmailer->FromName = (string) $settings['from_name'];
            }

            if (!empty($settings['set_return_path'])) {
                $phpmailer->Sender = (string) $settings['from_email'];
            }
        }

        if (!empty($settings['helo_enabled']) && !empty($settings['helo'])) {
            $phpmailer->Helo = trim((string) $settings['helo']);
        } else {
            $phpmailer->Helo = '';
        }

        $verify = !empty($settings['verify_ssl']);

        $phpmailer->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => $verify,
                'verify_peer_name'  => $verify,
                'allow_self_signed' => !$verify,
            ],
        ];

        if (!empty($settings['debug_enabled'])) {
            $resolved_ip = gethostbyname($phpmailer->Host);
            $php_host = function_exists('gethostname') ? gethostname() : php_uname('n');

            $this->write_debug_log(
                '[SMTP Diagnostic] Host=' . $phpmailer->Host .
                ' ResolvedIP=' . $resolved_ip .
                ' Port=' . $phpmailer->Port .
                ' Encryption=' . ($phpmailer->SMTPSecure ?: 'none') .
                ' PHPHost=' . $php_host .
                ' WordPress=' . get_bloginfo('version') .
                ' Plugin=' . self::VERSION
            );

            $this->write_debug_log(
                '[SMTP Config] Auth=' . ($phpmailer->SMTPAuth ? 'yes' : 'no') .
                ' Username=' . $phpmailer->Username .
                ' From=' . $phpmailer->From .
                ' Helo=' . ($phpmailer->Helo ?: 'default') .
                ' PasswordLength=' . strlen($password) .
                ' Mode=' . ($this->testing_smtp ? 'test' : 'normal')
            );

            $phpmailer->SMTPDebug = 2;
            $phpmailer->Debugoutput = function ($message, $level) {
                $this->write_debug_log('[SMTP Debug ' . (int) $level . '] ' . trim($message));
            };
        }
    }

    public function filter_from_email($email)
    {
        $settings = $this->get_settings();

        if (
            !empty($settings['enabled']) &&
            !empty($settings['force_from_email']) &&
            !empty($settings['from_email'])
        ) {
            return $settings['from_email'];
        }

        return $email;
    }

    public function filter_from_name($name)
    {
        $settings = $this->get_settings();

        if (
            !empty($settings['enabled']) &&
            !empty($settings['force_from_name']) &&
            !empty($settings['from_name'])
        ) {
            return $settings['from_name'];
        }

        return $name;
    }

    public function log_mail_failure($error)
    {
        $message = $error instanceof WP_Error
            ? $error->get_error_message()
            : __('Unknown wp_mail error.', 'wp-smtp-manager');

        $data = $error instanceof WP_Error ? (array) $error->get_error_data() : [];
        $mail_data = isset($data['to']) ? $data : (isset($data['mail_data']) ? (array) $data['mail_data'] : []);

        if ($this->retrying_history_id !== '') {
            $this->update_history_item($this->retrying_history_id, [
                'status' => 'failed',
                'time'   => time(),
                'error'  => $message,
            ]);
        } else {
            $this->add_history([
                'status'      => 'failed',
                'to'          => $mail_data['to'] ?? '',
                'subject'     => $mail_data['subject'] ?? '',
                'message'     => $mail_data['message'] ?? '',
                'headers'     => $mail_data['headers'] ?? [],
                'attachments' => $mail_data['attachments'] ?? [],
                'error'       => $message,
            ]);
        }

        $this->sync_failure_status($message);
        $this->write_debug_log('[Mail Failed] ' . $message . ' | Data: ' . wp_json_encode($data));
    }

    public function log_mail_success($mail_data)
    {
        $mail_data = (array) $mail_data;

        if ($this->retrying_history_id !== '') {
            $this->update_history_item($this->retrying_history_id, [
                'status' => 'sent',
                'time'   => time(),
                'error'  => '',
            ]);
        } else {
            $this->add_history([
                'status'      => 'sent',
                'to'          => $mail_data['to'] ?? '',
                'subject'     => $mail_data['subject'] ?? '',
                'message'     => '',
                'headers'     => [],
                'attachments' => [],
                'error'       => '',
            ]);
        }

        $status = $this->get_status();
        $status['last_success'] = time();
        update_option(self::STATUS_KEY, $status, false);
        $this->sync_failure_status();
    }

    private function get_status()
    {
        return wp_parse_args(get_option(self::STATUS_KEY, []), [
            'last_success'   => 0,
            'last_failure'   => 0,
            'last_error'     => '',
            'dismissed_at'   => 0,
            'failed_24h'     => 0,
        ]);
    }

    private function add_history($entry)
    {
        $history = get_option(self::HISTORY_KEY, []);
        $history = is_array($history) ? $history : [];

        $entry = wp_parse_args($entry, [
            'id'          => wp_generate_uuid4(),
            'time'        => time(),
            'status'      => 'sent',
            'to'          => '',
            'subject'     => '',
            'message'     => '',
            'headers'     => [],
            'attachments' => [],
            'error'       => '',
        ]);

        array_unshift($history, $entry);
        $history = array_slice($history, 0, 50);
        update_option(self::HISTORY_KEY, $history, false);
    }

    private function update_history_item($id, array $changes)
    {
        $history = get_option(self::HISTORY_KEY, []);
        $history = is_array($history) ? $history : [];

        foreach ($history as &$item) {
            if (($item['id'] ?? '') === $id) {
                $item = array_merge($item, $changes);
                break;
            }
        }
        unset($item);

        update_option(self::HISTORY_KEY, $history, false);
    }

    private function sync_failure_status($latest_error = '')
    {
        $history = get_option(self::HISTORY_KEY, []);
        $status = $this->get_status();
        $latest_failure = null;

        foreach ((array) $history as $item) {
            if (($item['status'] ?? '') === 'failed') {
                $latest_failure = $item;
                break;
            }
        }

        $status['failed_24h'] = $this->count_recent_failures();

        if ($latest_failure) {
            $status['last_failure'] = (int) ($latest_failure['time'] ?? time());
            $status['last_error'] = $latest_error !== '' ? $latest_error : (string) ($latest_failure['error'] ?? '');
        } else {
            $status['last_failure'] = 0;
            $status['last_error'] = '';
            $status['dismissed_at'] = 0;
        }

        update_option(self::STATUS_KEY, $status, false);
    }

    private function count_recent_failures()
    {
        $history = get_option(self::HISTORY_KEY, []);
        $cutoff = time() - DAY_IN_SECONDS;
        $count = 0;

        foreach ((array) $history as $item) {
            if (($item['status'] ?? '') === 'failed' && (int) ($item['time'] ?? 0) >= $cutoff) {
                $count++;
            }
        }

        return $count;
    }

    public function admin_failure_notice()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $status = $this->get_status();
        $last_failure = (int) $status['last_failure'];

        if (!$last_failure || $last_failure < (time() - DAY_IN_SECONDS)) {
            return;
        }

        if ((int) $status['dismissed_at'] >= $last_failure) {
            return;
        }

        $details_url = admin_url('options-general.php?page=' . self::PAGE_SLUG . '#nhsmtp-mail-history');
        ?>
        <div class="notice notice-error is-dismissible nhsmtp-admin-notice">
            <p>
                <strong><?php esc_html_e('WP SMTP:', 'wp-smtp-manager'); ?></strong>
                <?php esc_html_e('One or more emails recently failed to send.', 'wp-smtp-manager'); ?>
                <a href="<?php echo esc_url($details_url); ?>"><?php esc_html_e('View details', 'wp-smtp-manager'); ?></a>
            </p>
        </div>
        <?php
    }

    public function register_dashboard_widget()
    {
        if (current_user_can('manage_options')) {
            wp_add_dashboard_widget(
                'nhsmtp_dashboard_widget',
                __('WP SMTP Status', 'wp-smtp-manager'),
                [$this, 'render_dashboard_widget']
            );
        }
    }

    public function render_dashboard_widget()
    {
        $settings = $this->get_settings();
        $status = $this->get_status();
        $enabled = !empty($settings['enabled']);
        ?>
        <div class="nhsmtp-widget">
            <p><strong><?php echo $enabled ? '🟢 ' . esc_html__('SMTP Enabled', 'wp-smtp-manager') : '🔴 ' . esc_html__('SMTP Disabled', 'wp-smtp-manager'); ?></strong></p>
            <p><?php esc_html_e('Last successful email:', 'wp-smtp-manager'); ?>
                <strong><?php echo $status['last_success'] ? esc_html(human_time_diff($status['last_success'], time()) . ' ago') : esc_html__('No record yet', 'wp-smtp-manager'); ?></strong>
            </p>
            <p><?php esc_html_e('Failed in the last 24 hours:', 'wp-smtp-manager'); ?>
                <strong><?php echo esc_html((string) $this->count_recent_failures()); ?></strong>
            </p>
            <?php if (!empty($status['last_error'])) : ?>
                <p class="nhsmtp-widget-error"><?php echo esc_html(wp_trim_words($status['last_error'], 18)); ?></p>
            <?php endif; ?>
            <p>
                <a class="button button-primary" href="<?php echo esc_url(admin_url('options-general.php?page=' . self::PAGE_SLUG)); ?>"><?php esc_html_e('Open Settings', 'wp-smtp-manager'); ?></a>
                <a class="button" href="<?php echo esc_url(admin_url('options-general.php?page=' . self::PAGE_SLUG . '#nhsmtp-test-email')); ?>"><?php esc_html_e('Send Test', 'wp-smtp-manager'); ?></a>
            </p>
        </div>
        <?php
    }

    private function log_file()
    {
        $uploads = wp_upload_dir();
        return trailingslashit($uploads['basedir']) . 'namehost-smtp.log';
    }

    private function append_log($message)
    {
        $line = '[' . current_time('mysql') . '] ' . wp_strip_all_tags((string) $message) . PHP_EOL;
        error_log($line, 3, $this->log_file());
    }

    private function write_debug_log($message)
    {
        $settings = $this->get_settings();

        if (empty($settings['debug_enabled'])) {
            return;
        }

        $this->append_log($message);
    }

    private function render_history_html()
    {
        $history = get_option(self::HISTORY_KEY, []);
        $history = is_array($history) ? $history : [];

        ob_start();

        if (empty($history)) {
            echo '<p class="nhsmtp-empty">' . esc_html__('No email activity has been recorded yet.', 'wp-smtp-manager') . '</p>';
        } else {
            echo '<div class="nhsmtp-history">';

            foreach (array_slice($history, 0, 15) as $item) {
                $is_sent = ($item['status'] ?? '') === 'sent';
                echo '<div class="nhsmtp-history-item">';
                echo '<div class="nhsmtp-history-top">';
                echo '<span class="nhsmtp-pill ' . ($is_sent ? 'sent' : 'failed') . '">';
                echo $is_sent ? esc_html__('Sent', 'wp-smtp-manager') : esc_html__('Failed', 'wp-smtp-manager');
                echo '</span>';
                echo '<small>' . esc_html(human_time_diff((int) ($item['time'] ?? time()), time()) . ' ago') . '</small>';
                echo '</div>';
                echo '<strong>' . esc_html($item['subject'] ?: __('(No subject)', 'wp-smtp-manager')) . '</strong>';

                $to = is_array($item['to'] ?? '') ? implode(', ', $item['to']) : ($item['to'] ?? '');
                echo '<p>' . esc_html($to) . '</p>';

                if (!empty($item['error'])) {
                    echo '<div class="nhsmtp-history-error">' . esc_html($item['error']) . '</div>';
                    echo '<button type="button" class="button button-small nhsmtp-retry-email" data-id="' . esc_attr($item['id']) . '">' . esc_html__('Retry', 'wp-smtp-manager') . '</button>';
                }

                echo '</div>';
            }

            echo '</div>';
        }

        return ob_get_clean();
    }

    private function get_log_content()
    {
        $file = $this->log_file();

        if (!file_exists($file)) {
            return '';
        }

        $content = file_get_contents($file);
        return $content ? substr($content, -30000) : '';
    }

    private function activity_payload($message = '', $success = true)
    {
        return [
            'message'      => $message,
            'success'      => $success,
            'history_html' => $this->render_history_html(),
            'log_content'  => $this->get_log_content(),
            'failed_24h'   => $this->count_recent_failures(),
        ];
    }

    public function ajax_save_test_recipient()
    {
        check_ajax_referer('nhsmtp_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-smtp-manager')], 403);
        }

        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));

        if (!is_email($email)) {
            wp_send_json_error(['message' => __('Please enter a valid email address.', 'wp-smtp-manager')]);
        }

        update_user_meta(get_current_user_id(), 'nhsmtp_test_recipient', $email);
        wp_send_json_success();
    }

    public function ajax_send_test()
    {
        check_ajax_referer('nhsmtp_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-smtp-manager')], 403);
        }

        $to = sanitize_email(wp_unslash($_POST['to'] ?? ''));
        $settings = $this->get_settings();

        if (!is_email($to)) {
            wp_send_json_error(['message' => __('Please enter a valid recipient email address.', 'wp-smtp-manager')]);
        }

        update_user_meta(get_current_user_id(), 'nhsmtp_test_recipient', $to);

        if (empty($settings['host']) || empty($settings['port']) || empty($settings['from_email'])) {
            wp_send_json_error($this->activity_payload(__('Complete and save all required SMTP fields before sending a test email.', 'wp-smtp-manager'), false));
        }

        if (!empty($settings['auth']) && (empty($settings['username']) || empty($settings['password']))) {
            wp_send_json_error($this->activity_payload(__('Complete and save the SMTP username and password before sending a test email.', 'wp-smtp-manager'), false));
        }

        $subject = sprintf(
            __('SMTP Test from %s', 'wp-smtp-manager'),
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)
        );

        $message = '<div style="font-family:Arial,sans-serif;max-width:620px;margin:auto;border:1px solid #e5e7eb;border-radius:12px;padding:28px;">';
        $message .= '<h2 style="margin:0 0 12px;">WP SMTP Manager</h2>';
        $message .= '<p style="font-size:16px;line-height:1.6;">Your saved SMTP configuration successfully handed this message to the SMTP server.</p>';
        $message .= '<p style="color:#64748b;">Sent from: ' . esc_html(home_url()) . '</p>';
        $message .= '</div>';

        $this->testing_smtp = true;
        $this->write_debug_log('[SMTP Test] Starting forced SMTP test to ' . $to);

        try {
            $sent = wp_mail($to, $subject, $message, ['Content-Type: text/html; charset=UTF-8']);
        } finally {
            $this->testing_smtp = false;
        }

        if ($sent) {
            $this->write_debug_log('[SMTP Test] SMTP server accepted the test message.');
            wp_send_json_success($this->activity_payload(
                __('SMTP server accepted the test email. Please check the inbox and spam folder.', 'wp-smtp-manager'),
                true
            ));
        }

        $this->write_debug_log('[SMTP Test] Test email failed.');
        wp_send_json_error($this->activity_payload(
            __('SMTP test failed. Check the updated email history and debug log below.', 'wp-smtp-manager'),
            false
        ));
    }

    public function ajax_clear_log()
    {
        check_ajax_referer('nhsmtp_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-smtp-manager')], 403);
        }

        $file = $this->log_file();

        if (file_exists($file)) {
            file_put_contents($file, '');
        }

        $status = $this->get_status();
        $status['last_failure'] = 0;
        $status['last_error'] = '';
        $status['dismissed_at'] = 0;
        $status['failed_24h'] = 0;
        update_option(self::STATUS_KEY, $status, false);

        wp_send_json_success($this->activity_payload(__('Log and failure notice cleared successfully.', 'wp-smtp-manager'), true));
    }

    public function ajax_dismiss_notice()
    {
        check_ajax_referer('nhsmtp_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-smtp-manager')], 403);
        }

        $status = $this->get_status();
        $status['dismissed_at'] = time();
        update_option(self::STATUS_KEY, $status, false);
        wp_send_json_success();
    }

    public function ajax_clear_history()
    {
        check_ajax_referer('nhsmtp_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-smtp-manager')], 403);
        }

        delete_option(self::HISTORY_KEY);
        update_option(self::STATUS_KEY, [
            'last_success' => 0,
            'last_failure' => 0,
            'last_error'   => '',
            'dismissed_at' => 0,
            'failed_24h'   => 0,
        ], false);

        wp_send_json_success($this->activity_payload(__('Email history cleared successfully.', 'wp-smtp-manager'), true));
    }

    public function ajax_retry_email()
    {
        check_ajax_referer('nhsmtp_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-smtp-manager')], 403);
        }

        $id = sanitize_text_field(wp_unslash($_POST['id'] ?? ''));
        $history = get_option(self::HISTORY_KEY, []);
        $item = null;

        foreach ((array) $history as $row) {
            if (($row['id'] ?? '') === $id) {
                $item = $row;
                break;
            }
        }

        if (!$item || ($item['status'] ?? '') !== 'failed') {
            wp_send_json_error(['message' => __('Failed email record not found.', 'wp-smtp-manager')]);
        }

        $this->retrying_history_id = $id;

        try {
            $sent = wp_mail(
                $item['to'] ?? '',
                $item['subject'] ?? '',
                $item['message'] ?? '',
                $item['headers'] ?? [],
                $item['attachments'] ?? []
            );
        } finally {
            $this->retrying_history_id = '';
        }

        if ($sent) {
            wp_send_json_success($this->activity_payload(__('Email resent successfully.', 'wp-smtp-manager'), true));
        }

        wp_send_json_error($this->activity_payload(__('Retry failed. The existing history record was updated.', 'wp-smtp-manager'), false));
    }

    public function ajax_save_settings()
    {
        check_ajax_referer('nhsmtp_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-smtp-manager')], 403);
        }

        $raw = isset($_POST['settings']) && is_array($_POST['settings'])
            ? wp_unslash($_POST['settings'])
            : [];

        update_option(self::OPTION_KEY, $raw, false);
        $saved = $this->get_settings();

        wp_send_json_success([
            'message' => __('SMTP settings saved successfully.', 'wp-smtp-manager'),
            'enabled' => !empty($saved['enabled']),
        ]);
    }

    public function ajax_refresh_activity()
    {
        check_ajax_referer('nhsmtp_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'wp-smtp-manager')], 403);
        }

        wp_send_json_success($this->activity_payload('', true));
    }

    public function enqueue_assets($hook)
    {
        wp_enqueue_script(
            'nhsmtp-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.js',
            ['jquery'],
            self::VERSION,
            true
        );

        wp_localize_script('nhsmtp-admin', 'nhsmtpData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('nhsmtp_admin_nonce'),
        ]);

        if ($hook !== 'settings_page_' . self::PAGE_SLUG && $hook !== 'index.php') {
            return;
        }

        wp_enqueue_style(
            'nhsmtp-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.css',
            [],
            self::VERSION
        );
    }

    public function render_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();
        $log_file = $this->log_file();
        $log_content = file_exists($log_file) ? file_get_contents($log_file) : '';
        $log_content = $log_content ? substr($log_content, -30000) : '';
        $history = get_option(self::HISTORY_KEY, []);
        $history = is_array($history) ? $history : [];
        $status = $this->get_status();
        $test_recipient = sanitize_email(get_user_meta(get_current_user_id(), 'nhsmtp_test_recipient', true));

        if (!is_email($test_recipient)) {
            $test_recipient = wp_get_current_user()->user_email;
        }

        $smtp_ready = !empty($settings['host'])
            && !empty($settings['port'])
            && !empty($settings['from_email']);

        if (!empty($settings['auth'])) {
            $smtp_ready = $smtp_ready
                && !empty($settings['username'])
                && !empty($settings['password']);
        }
        ?>
        <div class="wrap nhsmtp-wrap">
            <div id="nhsmtp-toast" class="nhsmtp-toast" role="status" aria-live="polite" aria-atomic="true">
                <span class="dashicons nhsmtp-toast-icon" aria-hidden="true"></span>
                <strong class="nhsmtp-toast-text"></strong>
                <button type="button" class="nhsmtp-toast-close" aria-label="<?php esc_attr_e('Dismiss message', 'wp-smtp-manager'); ?>">&times;</button>
            </div>
            <h1 class="nhsmtp-page-title"><?php esc_html_e('WP SMTP Manager', 'wp-smtp-manager'); ?></h1>

            <div class="nhsmtp-page-summary">
                <p><?php esc_html_e('Configure reliable SMTP delivery, test outgoing email, and review delivery activity.', 'wp-smtp-manager'); ?></p>
                <div class="nhsmtp-status <?php echo !empty($settings['enabled']) ? 'is-on' : 'is-off'; ?>" role="status">
                    <span class="nhsmtp-status-dot" aria-hidden="true"></span>
                    <span class="nhsmtp-status-text"><?php echo !empty($settings['enabled']) ? esc_html__('SMTP Enabled', 'wp-smtp-manager') : esc_html__('SMTP Disabled', 'wp-smtp-manager'); ?></span>
                </div>
            </div>
<?php if (!empty($_GET['settings-updated'])) : ?>
                <div class="nhsmtp-save-notice" role="status">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <strong><?php esc_html_e('SMTP settings saved successfully.', 'wp-smtp-manager'); ?></strong>
                    <button type="button" class="nhsmtp-close-save-notice" aria-label="<?php esc_attr_e('Dismiss notice', 'wp-smtp-manager'); ?>">&times;</button>
                </div>
            <?php endif; ?>

            <div class="nhsmtp-grid">
                <div class="nhsmtp-main">
                    <form method="post" action="options.php" id="nhsmtp-settings-form">
                        <?php settings_fields('nhsmtp_settings_group'); ?>

                        <div class="nhsmtp-card">
                            <div class="nhsmtp-card-head">
                                <h2><?php esc_html_e('General', 'wp-smtp-manager'); ?></h2>
                                <p><?php esc_html_e('Turn SMTP delivery on or off for the whole website.', 'wp-smtp-manager'); ?></p>
                            </div>

                            <?php $this->toggle('enabled', __('Enable SMTP', 'wp-smtp-manager'), __('When disabled, WordPress uses its default mail method.', 'wp-smtp-manager'), $settings); ?>
                        </div>

                        <div class="nhsmtp-card">
                            <div class="nhsmtp-card-head">
                                <h2><?php esc_html_e('SMTP Connection', 'wp-smtp-manager'); ?></h2>
                                <p><?php esc_html_e('Enter the connection details supplied by your email provider.', 'wp-smtp-manager'); ?></p>
                            </div>

                            <div class="nhsmtp-fields two-col">
                                <?php $this->text_field('host', __('SMTP Host', 'wp-smtp-manager'), $settings, 'mail.example.com', __('Automatically uses mail.yourdomain.com until you change it.', 'wp-smtp-manager')); ?>
                                <?php $this->number_field('port', __('SMTP Port', 'wp-smtp-manager'), $settings, 1, 65535); ?>
                            </div>

                            <div class="nhsmtp-fields two-col">
                                <div class="nhsmtp-field">
                                    <label for="nhsmtp-encryption"><?php esc_html_e('Encryption', 'wp-smtp-manager'); ?></label>
                                    <select id="nhsmtp-encryption" name="<?php echo esc_attr(self::OPTION_KEY); ?>[encryption]">
                                        <option value="ssl" <?php selected($settings['encryption'], 'ssl'); ?>>SSL / SMTPS</option>
                                        <option value="tls" <?php selected($settings['encryption'], 'tls'); ?>>TLS / STARTTLS</option>
                                        <option value="" <?php selected($settings['encryption'], ''); ?>>None</option>
                                    </select>
                                </div>
                                <?php $this->number_field('timeout', __('Timeout (seconds)', 'wp-smtp-manager'), $settings, 5, 120); ?>
                            </div>

                            <?php $this->toggle('auth', __('Enable SMTP Authentication', 'wp-smtp-manager'), __('Recommended for almost every SMTP account.', 'wp-smtp-manager'), $settings); ?>

                            <div class="nhsmtp-fields two-col">
                                <?php $this->username_field('username', __('SMTP Username', 'wp-smtp-manager'), $settings, 'info@example.com'); ?>
                                <div class="nhsmtp-field">
                                    <label for="nhsmtp-password"><?php esc_html_e('SMTP Password', 'wp-smtp-manager'); ?></label>
                                    <?php
                                    $has_saved_password = !empty($settings['password']);
                                    $saved_password_readable = $this->saved_password_is_readable((string) $settings['password']);
                                    ?>
                                    <div class="nhsmtp-password <?php echo $has_saved_password ? 'is-locked' : ''; ?>">
                                        <input
                                            type="password"
                                            id="nhsmtp-password"
                                            name="<?php echo esc_attr(self::OPTION_KEY); ?>[password]"
                                            value="<?php echo $has_saved_password ? '************' : ''; ?>"
                                            autocomplete="new-password"
                                            spellcheck="false"
                                            placeholder="<?php esc_attr_e('Enter SMTP password', 'wp-smtp-manager'); ?>"
                                            <?php disabled($has_saved_password); ?>
                                        >
                                        <input type="hidden" id="nhsmtp-remove-password" name="<?php echo esc_attr(self::OPTION_KEY); ?>[remove_password]" value="0">
                                        <button type="button" class="button nhsmtp-toggle-password" <?php echo $has_saved_password ? 'style="display:none"' : ''; ?>><?php esc_html_e('Show', 'wp-smtp-manager'); ?></button>
                                        <?php if ($has_saved_password) : ?>
                                            <button type="button" class="button nhsmtp-remove-password"><?php esc_html_e('Remove Password', 'wp-smtp-manager'); ?></button>
                                        <?php endif; ?>
                                    </div>
                                    <small>
                                        <?php
                                        if ($has_saved_password && !$saved_password_readable) {
                                            esc_html_e('The saved password cannot be decrypted. Click Remove Password, enter it again, and save.', 'wp-smtp-manager');
                                        } elseif ($has_saved_password) {
                                            esc_html_e('A password is saved securely. Remove it before entering a new password.', 'wp-smtp-manager');
                                        } else {
                                            esc_html_e('Stored encrypted using your WordPress security salts.', 'wp-smtp-manager');
                                        }
                                        ?>
                                    </small>
                                </div>
                            </div>

                            <?php $this->toggle('auto_tls', __('Automatic TLS', 'wp-smtp-manager'), __('Allows PHPMailer to automatically upgrade a connection to TLS when supported.', 'wp-smtp-manager'), $settings); ?>
                            <?php $this->toggle('verify_ssl', __('Verify SSL Certificate', 'wp-smtp-manager'), __('Keep enabled for production. Disable only when the server uses an invalid or self-signed certificate.', 'wp-smtp-manager'), $settings); ?>
                        </div>

                        <div class="nhsmtp-card">
                            <div class="nhsmtp-card-head">
                                <h2><?php esc_html_e('Sender Identity', 'wp-smtp-manager'); ?></h2>
                                <p><?php esc_html_e('Control the From address and sender name used by WordPress.', 'wp-smtp-manager'); ?></p>
                            </div>

                            <div class="nhsmtp-fields two-col">
                                <?php $this->email_field('from_email', __('From Email', 'wp-smtp-manager'), $settings, 'info@example.com', __('Automatically uses Settings → General → Administration Email Address until you change it here.', 'wp-smtp-manager')); ?>
                                <?php $this->text_field('from_name', __('From Name', 'wp-smtp-manager'), $settings, get_bloginfo('name')); ?>
                            </div>

                            <?php $this->toggle('force_from_email', __('Force From Email', 'wp-smtp-manager'), __('Prevents plugins and themes from replacing the From email.', 'wp-smtp-manager'), $settings); ?>
                            <?php $this->toggle('force_from_name', __('Force From Name', 'wp-smtp-manager'), __('Prevents plugins and themes from replacing the From name.', 'wp-smtp-manager'), $settings); ?>
                            <?php $this->toggle('set_return_path', __('Set Return-Path', 'wp-smtp-manager'), __('Uses the From email as the envelope sender for bounce handling.', 'wp-smtp-manager'), $settings); ?>
                        </div>

                        <div class="nhsmtp-card">
                            <div class="nhsmtp-card-head">
                                <h2><?php esc_html_e('Advanced', 'wp-smtp-manager'); ?></h2>
                                <p><?php esc_html_e('Optional controls for HELO/EHLO and troubleshooting.', 'wp-smtp-manager'); ?></p>
                            </div>

                            <?php $this->toggle('helo_enabled', __('Custom HELO/EHLO Name', 'wp-smtp-manager'), __('Overrides the hostname sent by PHPMailer to the SMTP server. This does not change the remote mail server hostname.', 'wp-smtp-manager'), $settings); ?>
                            <?php $this->text_field('helo', __('HELO/EHLO Hostname', 'wp-smtp-manager'), $settings, __('Leave empty to use the SMTP server default', 'wp-smtp-manager')); ?>

                            <?php $this->toggle('debug_enabled', __('Enable SMTP Debug Log', 'wp-smtp-manager'), __('Writes the SMTP conversation to the log file. Disable after troubleshooting.', 'wp-smtp-manager'), $settings); ?>
                            <?php $this->toggle('delete_on_uninstall', __('Delete Settings on Uninstall', 'wp-smtp-manager'), __('Removes saved SMTP settings when the plugin is deleted.', 'wp-smtp-manager'), $settings); ?>
                        </div>

                        <div class="nhsmtp-save">
                            <?php submit_button(__('Save SMTP Settings', 'wp-smtp-manager'), 'primary', 'submit', false, ['id' => 'nhsmtp-save-settings']); ?>
                            <div id="nhsmtp-save-result" class="nhsmtp-save-result" aria-live="polite"></div>
                        </div>
                    </form>
                </div>

                <div class="nhsmtp-side">
                    <div class="nhsmtp-card <?php echo $smtp_ready ? '' : 'is-disabled'; ?>" id="nhsmtp-test-email" data-ready="<?php echo $smtp_ready ? '1' : '0'; ?>">
                        <div class="nhsmtp-card-head">
                            <h2><?php esc_html_e('Send Test Email', 'wp-smtp-manager'); ?></h2>
                            <p><?php esc_html_e('Save your settings first, then send a real test message.', 'wp-smtp-manager'); ?></p>
                        </div>

                        <div class="nhsmtp-field">
                            <label for="nhsmtp-test-to"><?php esc_html_e('Recipient Email', 'wp-smtp-manager'); ?></label>
                            <input type="email" id="nhsmtp-test-to" value="<?php echo esc_attr($test_recipient); ?>">
                        </div>

                        <button type="button" class="button button-primary button-large" id="nhsmtp-send-test" <?php disabled(!$smtp_ready); ?>>
                            <?php esc_html_e('Send Test Email', 'wp-smtp-manager'); ?>
                        </button>
                        <p class="nhsmtp-test-disabled-note" <?php echo $smtp_ready ? 'style="display:none"' : ''; ?>>
                            <?php esc_html_e('Complete and save all required SMTP fields before sending a test email.', 'wp-smtp-manager'); ?>
                        </p>

                        <div id="nhsmtp-test-result" class="nhsmtp-result" aria-live="polite"></div>
                    </div>

                    <div class="nhsmtp-card">
                        <div class="nhsmtp-card-head">
                            <h2><?php esc_html_e('Debug Log', 'wp-smtp-manager'); ?></h2>
                            <p><code><?php echo esc_html($log_file); ?></code></p>
                        </div>

                        <textarea class="nhsmtp-log" readonly><?php echo esc_textarea($log_content); ?></textarea>

                        <div class="nhsmtp-log-actions">
                            <button type="button" class="button" id="nhsmtp-copy-log">
                                <?php esc_html_e('Copy Log', 'wp-smtp-manager'); ?>
                            </button>
                            <button type="button" class="button" id="nhsmtp-clear-log">
                                <?php esc_html_e('Clear Log', 'wp-smtp-manager'); ?>
                            </button>
                        </div>
                        <div id="nhsmtp-log-result" class="nhsmtp-result" aria-live="polite"></div>
                    </div>

                    <div class="nhsmtp-card" id="nhsmtp-mail-history">
                        <div class="nhsmtp-card-head nhsmtp-card-head-actions">
                            <div>
                                <h2><?php esc_html_e('Email History', 'wp-smtp-manager'); ?></h2>
                                <p><?php esc_html_e('The latest 50 WordPress email attempts.', 'wp-smtp-manager'); ?></p>
                            </div>
                            <button type="button" class="button" id="nhsmtp-clear-history"><?php esc_html_e('Clear', 'wp-smtp-manager'); ?></button>
                        </div>

                        <div id="nhsmtp-history-content">
                            <?php echo $this->render_history_html(); ?>
                        </div>
                        <div id="nhsmtp-history-result" class="nhsmtp-result" aria-live="polite"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function text_field($key, $label, $settings, $placeholder = '', $note = '')
    {
        ?>
        <div class="nhsmtp-field">
            <label for="nhsmtp-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
            <input type="text" id="nhsmtp-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($settings[$key] ?? ''); ?>" placeholder="<?php echo esc_attr($placeholder); ?>" <?php echo in_array($key, ['username'], true) ? 'spellcheck="false" autocomplete="new-password"' : ''; ?>>
            <?php if ($note !== '') : ?>
                <small><?php echo esc_html($note); ?></small>
            <?php endif; ?>
        </div>
        <?php
    }

    private function username_field($key, $label, $settings, $placeholder = '')
    {
        ?>
        <div class="nhsmtp-field">
            <label for="nhsmtp-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
            <input type="text" id="nhsmtp-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($settings[$key] ?? ''); ?>" placeholder="<?php echo esc_attr($placeholder); ?>" spellcheck="false" autocomplete="new-password">
        </div>
        <?php
    }

    private function email_field($key, $label, $settings, $placeholder = '', $note = '')
    {
        ?>
        <div class="nhsmtp-field">
            <label for="nhsmtp-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
            <input type="email" id="nhsmtp-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($settings[$key] ?? ''); ?>" placeholder="<?php echo esc_attr($placeholder); ?>" spellcheck="false" autocomplete="new-password">
            <?php if ($note !== '') : ?>
                <small><?php echo esc_html($note); ?></small>
            <?php endif; ?>
        </div>
        <?php
    }

    private function number_field($key, $label, $settings, $min, $max)
    {
        ?>
        <div class="nhsmtp-field">
            <label for="nhsmtp-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
            <input type="number" id="nhsmtp-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($settings[$key] ?? ''); ?>" min="<?php echo esc_attr($min); ?>" max="<?php echo esc_attr($max); ?>">
        </div>
        <?php
    }

    private function toggle($key, $label, $description, $settings)
    {
        ?>
        <div class="nhsmtp-toggle-row">
            <div>
                <strong><?php echo esc_html($label); ?></strong>
                <p><?php echo esc_html($description); ?></p>
            </div>
            <label class="nhsmtp-switch">
                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[<?php echo esc_attr($key); ?>]" value="1" <?php checked(!empty($settings[$key])); ?>>
                <span></span>
            </label>
        </div>
        <?php
    }

    public static function uninstall()
    {
        $settings = get_option(self::OPTION_KEY, []);

        if (!empty($settings['delete_on_uninstall'])) {
            $uploads = wp_upload_dir();
            $log_file = trailingslashit($uploads['basedir']) . 'namehost-smtp.log';

            delete_option(self::OPTION_KEY);
            delete_option(self::HISTORY_KEY);
            delete_option(self::STATUS_KEY);
            delete_metadata('user', 0, 'nhsmtp_test_recipient', '', true);

            if (file_exists($log_file) && is_writable($log_file)) {
                unlink($log_file);
            }
        }
    }
}

WP_SMTP_Manager::instance();
