<?php

namespace MS\Modules\API;

use MS\Core\Capabilities;
use MS\Core\ModuleInterface;
use MS\Core\ServiceContainer;

class ApiGatewayModule implements ModuleInterface
{
    public function slug()
    {
        return 'api_gateway';
    }

    public function version()
    {
        return '0.1.0';
    }

    public function boot(ServiceContainer $container)
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
        add_action('ms_gateway_event', [$this, 'dispatchEvent'], 10, 2);

        add_action('ms_appointment_booked', function ($providerId) {
            $this->dispatchEvent('appointment.booked', [
                'provider_id' => (int) $providerId,
                'occurred_at' => current_time('mysql'),
            ]);
        });

        add_action('ms_appointment_status_changed', function ($providerId, $status) {
            $this->dispatchEvent('appointment.status_changed', [
                'provider_id' => (int) $providerId,
                'status'      => sanitize_text_field($status),
                'occurred_at' => current_time('mysql'),
            ]);
        }, 10, 2);
    }

    public function activate(ServiceContainer $container)
    {
        $this->createTables();
    }

    public function deactivate(ServiceContainer $container)
    {
        // Keep webhook registrations by default.
    }

    public function registerRoutes()
    {
        register_rest_route('ms/v1', '/webhooks', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'listWebhooks'],
                'permission_callback' => function () {
                    return current_user_can(Capabilities::MANAGE_GATEWAY);
                },
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'createWebhook'],
                'permission_callback' => function () {
                    return current_user_can(Capabilities::MANAGE_GATEWAY);
                },
                'args'                => [
                    'name'       => ['sanitize_callback' => 'sanitize_text_field'],
                    'target_url' => ['sanitize_callback' => 'esc_url_raw'],
                    'event'      => ['sanitize_callback' => 'sanitize_text_field'],
                    'secret'     => ['sanitize_callback' => 'sanitize_text_field'],
                    'status'     => ['sanitize_callback' => 'sanitize_text_field'],
                ],
            ],
        ]);

        register_rest_route('ms/v1', '/webhooks/(?P<id>\\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'deleteWebhook'],
            'permission_callback' => function () {
                return current_user_can(Capabilities::MANAGE_GATEWAY);
            },
        ]);

        register_rest_route('ms/v1', '/webhooks/test', [
            'methods'             => 'POST',
            'callback'            => [$this, 'sendTestEvent'],
            'permission_callback' => function () {
                return current_user_can(Capabilities::MANAGE_GATEWAY);
            },
            'args'                => [
                'event'   => ['sanitize_callback' => 'sanitize_text_field'],
                'payload' => [],
            ],
        ]);
    }

    public function listWebhooks()
    {
        global $wpdb;
        $table = $this->webhookTable();

        $rows = $wpdb->get_results("SELECT id, name, target_url, event, status, created_at FROM {$table} ORDER BY id DESC", ARRAY_A);

        return [
            'data' => array_map(function ($row) {
                $row['id'] = (int) $row['id'];

                return $row;
            }, $rows),
        ];
    }

    public function createWebhook($request)
    {
        global $wpdb;
        $table = $this->webhookTable();

        $name      = sanitize_text_field($request['name'] ?? '');
        $targetUrl = esc_url_raw($request['target_url'] ?? '');
        $event     = sanitize_text_field($request['event'] ?? '');
        $secret    = sanitize_text_field($request['secret'] ?? '');
        $status    = sanitize_text_field($request['status'] ?? 'active');

        if (! $name || ! $targetUrl || ! $event) {
            return new \WP_Error('ms_invalid', __('Name, target_url and event are required.', 'clinic-manager'), ['status' => 400]);
        }

        $inserted = $wpdb->insert(
            $table,
            [
                'name'       => $name,
                'target_url' => $targetUrl,
                'event'      => $event,
                'secret'     => $secret ?: wp_generate_password(24, false),
                'status'     => $status === 'disabled' ? 'disabled' : 'active',
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );

        if (! $inserted) {
            return new \WP_Error('ms_create_failed', __('Could not create webhook.', 'clinic-manager'), ['status' => 500]);
        }

        return [
            'id' => (int) $wpdb->insert_id,
        ];
    }

    public function deleteWebhook($request)
    {
        global $wpdb;
        $table = $this->webhookTable();
        $id    = absint($request['id'] ?? 0);

        if (! $id) {
            return new \WP_Error('ms_invalid', __('Invalid webhook id.', 'clinic-manager'), ['status' => 400]);
        }

        $deleted = $wpdb->delete($table, ['id' => $id], ['%d']);

        if (! $deleted) {
            return new \WP_Error('ms_not_found', __('Webhook not found or already deleted.', 'clinic-manager'), ['status' => 404]);
        }

        return ['deleted' => true];
    }

    public function sendTestEvent($request)
    {
        $event   = sanitize_text_field($request['event'] ?? 'gateway.test');
        $payload = $request['payload'] ?? ['message' => 'Test from Clinic Manager'];

        $result = $this->dispatchEvent($event, $payload, true);

        return ['dispatched' => $result];
    }

    public function dispatchEvent($event, $payload, $returnReport = false)
    {
        global $wpdb;
        $table = $this->webhookTable();

        $webhooks = $wpdb->get_results(
            $wpdb->prepare("SELECT id, target_url, secret FROM {$table} WHERE status = %s AND event = %s", 'active', $event),
            ARRAY_A
        );

        if (! $webhooks) {
            return $returnReport ? [] : null;
        }

        $body = wp_json_encode([
            'event'   => $event,
            'payload' => $payload,
            'sent_at' => current_time('mysql'),
        ]);

        $report = [];

        foreach ($webhooks as $hook) {
            $signature = hash_hmac('sha256', $body, $hook['secret']);

            $response = wp_remote_post($hook['target_url'], [
                'headers' => [
                    'Content-Type'      => 'application/json',
                    'X-MS-Event'        => $event,
                    'X-MS-Signature'    => $signature,
                    'X-MS-Webhook-ID'   => (int) $hook['id'],
                ],
                'body'    => $body,
                'timeout' => 5,
            ]);

            if (is_wp_error($response)) {
                error_log('Clinic Manager webhook failed: ' . $response->get_error_message());
                $report[] = ['id' => (int) $hook['id'], 'status' => 'error'];
                continue;
            }

            $report[] = ['id' => (int) $hook['id'], 'status' => wp_remote_retrieve_response_code($response)];
        }

        return $returnReport ? $report : null;
    }

    private function createTables()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $wpdb->get_charset_collate();
        $table          = $this->webhookTable();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL,
            target_url text NOT NULL,
            event varchar(100) NOT NULL,
            secret varchar(191) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_status (event, status)
        ) {$charsetCollate};";

        dbDelta($sql);
    }

    private function webhookTable()
    {
        global $wpdb;

        return $wpdb->prefix . 'ms_webhooks';
    }
}
