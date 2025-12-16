<?php

namespace MS\Modules\Finance;

use MS\Core\ModuleInterface;
use MS\Core\ServiceContainer;

class FinanceModule implements ModuleInterface
{
    public function slug()
    {
        return 'finance';
    }

    public function version()
    {
        return '0.1.0';
    }

    public function boot(ServiceContainer $container)
    {
        add_action('init', [$this, 'registerTables']);
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function activate(ServiceContainer $container)
    {
        $this->registerTables();
    }

    public function deactivate(ServiceContainer $container)
    {
        // keep data by default
    }

    public function registerRoutes()
    {
        register_rest_route('ms/v1', '/payments', [
            'methods'             => 'POST',
            'callback'            => [$this, 'createPayment'],
            'permission_callback' => '__return_true',
            'args'                => [
                'provider_id'   => ['required' => true, 'sanitize_callback' => 'absint'],
                'appointment_id'=> ['sanitize_callback' => 'absint'],
                'amount'        => ['required' => true],
                'method'        => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'gateway_ref'   => ['sanitize_callback' => 'sanitize_text_field'],
                'meta'          => [],
            ],
        ]);

        register_rest_route('ms/v1', '/payments/webhook', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handleWebhook'],
            'permission_callback' => [$this, 'verifyWebhook'],
        ]);

        register_rest_route('ms/v1', '/wallets/(?P<provider_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getWallet'],
            'permission_callback' => function () {
                return current_user_can('edit_posts');
            },
        ]);
    }

    public function createPayment($request)
    {
        global $wpdb;

        $providerId   = absint($request['provider_id'] ?? 0);
        $appointmentId= absint($request['appointment_id'] ?? 0);
        $amount       = floatval($request['amount'] ?? 0);
        $method       = sanitize_text_field($request['method'] ?? '');
        $gatewayRef   = sanitize_text_field($request['gateway_ref'] ?? '');
        $meta         = $request['meta'] ?? [];

        if (! $providerId || $amount <= 0 || ! $method) {
            return new \WP_Error('ms_finance_invalid', __('Provider, amount, and method are required.', 'clinic-manager'), ['status' => 400]);
        }

        $table = $wpdb->prefix . 'ms_payments';
        $data  = [
            'provider_id'    => $providerId,
            'appointment_id' => $appointmentId ?: null,
            'amount'         => $amount,
            'method'         => $method,
            'status'         => 'pending',
            'gateway_ref'    => $gatewayRef,
            'meta_json'      => is_array($meta) ? wp_json_encode($meta) : '{}',
            'created_at'     => current_time('mysql', true),
        ];

        $inserted = $wpdb->insert($table, $data, ['%d', '%d', '%f', '%s', '%s', '%s', '%s']);

        if (! $inserted) {
            return new \WP_Error('ms_finance_db', __('Unable to record payment.', 'clinic-manager'), ['status' => 500]);
        }

        $id = (int) $wpdb->insert_id;

        return [
            'id'            => $id,
            'status'        => 'pending',
            'provider_id'   => $providerId,
            'appointment_id'=> $appointmentId ?: null,
            'amount'        => $amount,
            'method'        => $method,
        ];
    }

    public function handleWebhook($request)
    {
        global $wpdb;

        $paymentId = absint($request['payment_id'] ?? 0);
        $status    = sanitize_text_field($request['status'] ?? '');
        $allowed   = ['paid', 'failed', 'refunded'];

        if (! $paymentId || ! in_array($status, $allowed, true)) {
            return new \WP_Error('ms_finance_invalid', __('Invalid webhook payload.', 'clinic-manager'), ['status' => 400]);
        }

        $payments = $wpdb->prefix . 'ms_payments';
        $updated  = $wpdb->update(
            $payments,
            [
                'status'     => $status,
                'updated_at' => current_time('mysql', true),
            ],
            ['id' => $paymentId],
            ['%s', '%s'],
            ['%d']
        );

        if (! $updated) {
            return new \WP_Error('ms_finance_db', __('Payment not found or not updated.', 'clinic-manager'), ['status' => 404]);
        }

        if ($status === 'paid') {
            $payment = $wpdb->get_row($wpdb->prepare("SELECT provider_id, amount FROM {$payments} WHERE id = %d", $paymentId));
            if ($payment) {
                $this->creditWallet((int) $payment->provider_id, (float) $payment->amount);
            }
        }

        return ['id' => $paymentId, 'status' => $status];
    }

    public function getWallet($request)
    {
        global $wpdb;

        $providerId = absint($request['provider_id'] ?? 0);
        $wallets    = $wpdb->prefix . 'ms_wallets';

        $row = $wpdb->get_row($wpdb->prepare("SELECT balance, pending_balance FROM {$wallets} WHERE provider_id = %d", $providerId));

        if (! $row) {
            return [
                'provider_id'     => $providerId,
                'balance'         => 0,
                'pending_balance' => 0,
            ];
        }

        return [
            'provider_id'     => $providerId,
            'balance'         => (float) $row->balance,
            'pending_balance' => (float) $row->pending_balance,
        ];
    }

    public function verifyWebhook()
    {
        $secret = get_option('ms_payment_webhook_secret', '');
        $provided = $_SERVER['HTTP_X_MS_SIGNATURE'] ?? '';

        return $secret && hash_equals($secret, $provided);
    }

    public function registerTables()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $wpdb->get_charset_collate();
        $payments       = $wpdb->prefix . 'ms_payments';
        $wallets        = $wpdb->prefix . 'ms_wallets';

        $sql = "CREATE TABLE {$payments} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            provider_id bigint(20) unsigned NOT NULL,
            appointment_id bigint(20) unsigned DEFAULT NULL,
            amount decimal(12,2) NOT NULL,
            method varchar(50) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            gateway_ref varchar(100) DEFAULT '',
            meta_json longtext,
            created_at datetime NOT NULL,
            updated_at datetime NULL,
            PRIMARY KEY  (id),
            KEY provider_status (provider_id, status)
        ) {$charsetCollate};

        CREATE TABLE {$wallets} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            provider_id bigint(20) unsigned NOT NULL,
            balance decimal(12,2) NOT NULL DEFAULT 0,
            pending_balance decimal(12,2) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY provider (provider_id)
        ) {$charsetCollate};";

        dbDelta($sql);
    }

    private function creditWallet($providerId, $amount)
    {
        global $wpdb;
        $wallets = $wpdb->prefix . 'ms_wallets';

        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wallets} WHERE provider_id = %d", $providerId));

        if ($existing) {
            $wpdb->query($wpdb->prepare("UPDATE {$wallets} SET balance = balance + %f WHERE provider_id = %d", $amount, $providerId));
            return;
        }

        $wpdb->insert($wallets, [
            'provider_id' => $providerId,
            'balance'     => $amount,
            'pending_balance' => 0,
        ], ['%d', '%f', '%f']);
    }
}
