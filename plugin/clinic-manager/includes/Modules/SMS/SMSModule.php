<?php

namespace MS\Modules\SMS;

use MS\Core\ModuleInterface;
use MS\Core\ServiceContainer;

class SMSModule implements ModuleInterface
{
    /** @var ServiceContainer|null */
    private $container;

    public function slug()
    {
        return 'sms';
    }

    public function version()
    {
        return '0.1.0';
    }

    public function boot(ServiceContainer $container)
    {
        $this->container = $container;
        add_action('rest_api_init', [$this, 'registerRoutes']);

        add_filter('ms_sms_validate_otp', [$this, 'validateOtp'], 10, 5);

        $container->bind('sms.sender', function () {
            return new SmsSender();
        });
    }

    public function activate(ServiceContainer $container)
    {
        $this->createTables();
    }

    public function deactivate(ServiceContainer $container)
    {
        // Nothing to deactivate for now.
    }

    public function registerRoutes()
    {
        register_rest_route('ms/v1', '/otp', [
            'methods'             => 'POST',
            'callback'            => [$this, 'requestOtp'],
            'permission_callback' => '__return_true',
            'args'                => [
                'phone'       => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'context'     => ['sanitize_callback' => 'sanitize_text_field'],
                'provider_id' => ['sanitize_callback' => 'absint'],
            ],
        ]);

        register_rest_route('ms/v1', '/otp/verify', [
            'methods'             => 'POST',
            'callback'            => [$this, 'verifyOtp'],
            'permission_callback' => '__return_true',
            'args'                => [
                'challenge_id' => ['required' => true, 'sanitize_callback' => 'absint'],
                'code'         => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'phone'        => ['sanitize_callback' => 'sanitize_text_field'],
                'context'      => ['sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route('ms/v1', '/automation/rules', [
            'methods'             => 'POST',
            'callback'            => [$this, 'createRule'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'args'                => [
                'event'     => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'condition' => [],
                'action'    => [],
                'delay_minutes' => ['sanitize_callback' => 'absint'],
            ],
        ]);
    }

    public function requestOtp($request)
    {
        global $wpdb;

        $phone      = sanitize_text_field($request['phone'] ?? '');
        $context    = sanitize_text_field($request['context'] ?? 'booking');
        $providerId = absint($request['provider_id'] ?? 0);

        if (! $phone || strlen($phone) < 8) {
            return new \WP_Error('ms_invalid_phone', __('Phone is required', 'clinic-manager'), ['status' => 400]);
        }

        $table = $wpdb->prefix . 'ms_otps';

        $recentCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE phone = %s AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)",
            $phone
        ));

        if ($recentCount >= 3) {
            return new \WP_Error('ms_rate_limited', __('Too many OTP requests. Please wait a few minutes.', 'clinic-manager'), ['status' => 429]);
        }

        $code      = wp_rand(100000, 999999);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + 5 * 60);

        $wpdb->insert(
            $table,
            [
                'phone'       => $phone,
                'context'     => $context,
                'provider_id' => $providerId,
                'code_hash'   => wp_hash_password((string) $code),
                'expires_at'  => $expiresAt,
                'created_at'  => current_time('mysql', true),
            ],
            ['%s', '%s', '%d', '%s', '%s', '%s']
        );

        $id = (int) $wpdb->insert_id;

        $sender  = $this->resolveSender();
        $message = sprintf(__('Your verification code is %s', 'clinic-manager'), $code);
        $sender->send($phone, $message, ['context' => $context, 'provider_id' => $providerId]);

        return [
            'challenge_id' => $id,
            'expires_in'   => 300,
        ];
    }

    public function verifyOtp($request)
    {
        $challengeId = absint($request['challenge_id'] ?? 0);
        $code        = sanitize_text_field($request['code'] ?? '');
        $phone       = sanitize_text_field($request['phone'] ?? '');
        $context     = sanitize_text_field($request['context'] ?? '');

        $result = apply_filters('ms_sms_validate_otp', true, $phone, $challengeId, $code, $context);

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'valid'        => true,
            'challenge_id' => $challengeId,
        ];
    }

    public function createRule($request)
    {
        global $wpdb;

        $event     = sanitize_text_field($request['event'] ?? '');
        $condition = $request['condition'] ?? [];
        $action    = $request['action'] ?? [];
        $delay     = absint($request['delay_minutes'] ?? 0);

        if (! $event) {
            return new \WP_Error('ms_invalid_event', __('Event is required.', 'clinic-manager'), ['status' => 400]);
        }

        if (empty($action['type'])) {
            return new \WP_Error('ms_invalid_action', __('Action type is required.', 'clinic-manager'), ['status' => 400]);
        }

        $table = $wpdb->prefix . 'ms_rules';

        $wpdb->insert(
            $table,
            [
                'event'         => $event,
                'condition_json'=> wp_json_encode($condition),
                'action_json'   => wp_json_encode($action),
                'delay_minutes' => $delay,
                'created_at'    => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%d', '%s']
        );

        return [
            'id'            => (int) $wpdb->insert_id,
            'event'         => $event,
            'delay_minutes' => $delay,
        ];
    }

    /**
     * Validate an OTP entry and mark it consumed on success.
     *
     * @param bool|\WP_Error $valid
     * @param string          $phone
     * @param int             $challengeId
     * @param string          $code
     * @param string          $context
     *
     * @return bool|\WP_Error
     */
    public function validateOtp($valid, $phone, $challengeId, $code, $context = '')
    {
        global $wpdb;

        $challengeId = absint($challengeId);
        $code        = trim((string) $code);
        $table       = $wpdb->prefix . 'ms_otps';

        if (! $challengeId || ! $code) {
            return new \WP_Error('ms_otp_invalid', __('Invalid OTP payload.', 'clinic-manager'), ['status' => 400]);
        }

        $otp = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $challengeId
        ));

        if (! $otp) {
            return new \WP_Error('ms_otp_not_found', __('OTP not found.', 'clinic-manager'), ['status' => 404]);
        }

        if ($phone && $otp->phone !== $phone) {
            return new \WP_Error('ms_otp_phone_mismatch', __('Phone does not match OTP request.', 'clinic-manager'), ['status' => 400]);
        }

        if (! empty($otp->context) && $context && $otp->context !== $context) {
            return new \WP_Error('ms_otp_context_mismatch', __('OTP context mismatch.', 'clinic-manager'), ['status' => 400]);
        }

        if ($otp->consumed_at) {
            return new \WP_Error('ms_otp_consumed', __('OTP already used.', 'clinic-manager'), ['status' => 410]);
        }

        if (strtotime($otp->expires_at) < time()) {
            return new \WP_Error('ms_otp_expired', __('OTP expired.', 'clinic-manager'), ['status' => 410]);
        }

        if (! wp_check_password($code, $otp->code_hash)) {
            return new \WP_Error('ms_otp_invalid', __('Invalid OTP code.', 'clinic-manager'), ['status' => 401]);
        }

        $wpdb->update(
            $table,
            ['consumed_at' => current_time('mysql', true)],
            ['id' => $challengeId],
            ['%s'],
            ['%d']
        );

        return true;
    }

    private function resolveSender()
    {
        if ($this->container && $this->container->has('sms.sender')) {
            return $this->container->get('sms.sender');
        }

        return new SmsSender();
    }

    private function createTables()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $wpdb->get_charset_collate();
        $otpTable       = $wpdb->prefix . 'ms_otps';
        $rulesTable     = $wpdb->prefix . 'ms_rules';

        $sql = "CREATE TABLE {$otpTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            phone varchar(32) NOT NULL,
            context varchar(50) NOT NULL,
            provider_id bigint(20) unsigned DEFAULT NULL,
            code_hash varchar(255) NOT NULL,
            expires_at datetime NOT NULL,
            created_at datetime NOT NULL,
            consumed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY phone_context (phone, context)
        ) {$charsetCollate};

        CREATE TABLE {$rulesTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event varchar(100) NOT NULL,
            condition_json longtext DEFAULT NULL,
            action_json longtext DEFAULT NULL,
            delay_minutes int unsigned DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY event_idx (event)
        ) {$charsetCollate};";

        dbDelta($sql);
    }
}

class SmsSender
{
    public function send($phone, $message, $meta = [])
    {
        $log = get_option('ms_sms_log', []);
        $log[] = [
            'phone'    => $phone,
            'message'  => $message,
            'meta'     => $meta,
            'sent_at'  => current_time('mysql', true),
        ];

        update_option('ms_sms_log', $log, false);

        return true;
    }
}
