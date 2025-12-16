<?php

namespace MS\Modules\Settings;

use MS\Core\ModuleInterface;
use MS\Core\ServiceContainer;

class SettingsModule implements ModuleInterface
{
    public function slug()
    {
        return 'settings';
    }

    public function version()
    {
        return '0.1.0';
    }

    public function boot(ServiceContainer $container)
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
        add_action('init', [$this, 'registerSettings']);
    }

    public function activate(ServiceContainer $container)
    {
        $defaults = $this->getDefaultSettings();
        $existing = get_option('ms_settings', []);

        update_option('ms_settings', wp_parse_args($existing, $defaults), false);
    }

    public function deactivate(ServiceContainer $container)
    {
        // keep settings stored for future re-activation
    }

    public function registerSettings()
    {
        register_setting('ms_settings_group', 'ms_settings', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitizeSettings'],
            'default'           => $this->getDefaultSettings(),
        ]);
    }

    public function registerRoutes()
    {
        register_rest_route('ms/v1', '/settings', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getSettings'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('ms/v1', '/settings', [
            'methods'             => ['POST', 'PUT', 'PATCH'],
            'callback'            => [$this, 'updateSettings'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);
    }

    public function getSettings($request)
    {
        $settings = get_option('ms_settings', $this->getDefaultSettings());

        return $this->mergeDefaults($settings);
    }

    public function updateSettings($request)
    {
        $payload  = $request->get_json_params();
        $settings = $this->sanitizeSettings($payload ?? []);

        if (is_wp_error($settings)) {
            return $settings;
        }

        update_option('ms_settings', $settings, false);

        return $this->mergeDefaults($settings);
    }

    private function sanitizeSettings($settings)
    {
        if (! is_array($settings)) {
            return new \WP_Error('ms_invalid_settings', __('Invalid settings payload', 'clinic-manager'), ['status' => 400]);
        }

        $defaults = $this->getDefaultSettings();

        $clinic = isset($settings['clinic']) && is_array($settings['clinic']) ? $settings['clinic'] : [];
        $templates = isset($settings['sms_templates']) && is_array($settings['sms_templates']) ? $settings['sms_templates'] : [];
        $payment = isset($settings['payment']) && is_array($settings['payment']) ? $settings['payment'] : [];

        $sanitized = [
            'clinic' => [
                'name'        => sanitize_text_field($clinic['name'] ?? $defaults['clinic']['name']),
                'phone'       => sanitize_text_field($clinic['phone'] ?? $defaults['clinic']['phone']),
                'address'     => sanitize_text_field($clinic['address'] ?? $defaults['clinic']['address']),
                'timezone'    => sanitize_text_field($clinic['timezone'] ?? $defaults['clinic']['timezone']),
                'working_days'=> array_values(array_map('sanitize_text_field', isset($clinic['working_days']) && is_array($clinic['working_days']) ? $clinic['working_days'] : $defaults['clinic']['working_days'])),
                'working_hours'=> [
                    'start' => sanitize_text_field($clinic['working_hours']['start'] ?? $defaults['clinic']['working_hours']['start']),
                    'end'   => sanitize_text_field($clinic['working_hours']['end'] ?? $defaults['clinic']['working_hours']['end']),
                ],
            ],
            'sms_templates' => [
                'appointment_confirmation' => sanitize_text_field($templates['appointment_confirmation'] ?? $defaults['sms_templates']['appointment_confirmation']),
                'appointment_reminder'     => sanitize_text_field($templates['appointment_reminder'] ?? $defaults['sms_templates']['appointment_reminder']),
                'otp'                      => sanitize_text_field($templates['otp'] ?? $defaults['sms_templates']['otp']),
            ],
            'payment' => [
                'currency'       => sanitize_text_field($payment['currency'] ?? $defaults['payment']['currency']),
                'visit_price'    => floatval($payment['visit_price'] ?? $defaults['payment']['visit_price']),
                'gateway'        => sanitize_text_field($payment['gateway'] ?? $defaults['payment']['gateway']),
                'success_url'    => esc_url_raw($payment['success_url'] ?? $defaults['payment']['success_url']),
                'failure_url'    => esc_url_raw($payment['failure_url'] ?? $defaults['payment']['failure_url']),
            ],
        ];

        return $sanitized;
    }

    private function getDefaultSettings()
    {
        return [
            'clinic' => [
                'name'         => '',
                'phone'        => '',
                'address'      => '',
                'timezone'     => 'Asia/Tehran',
                'working_days' => ['sat', 'sun', 'mon', 'tue', 'wed'],
                'working_hours'=> [
                    'start' => '08:00',
                    'end'   => '18:00',
                ],
            ],
            'sms_templates' => [
                'appointment_confirmation' => __('Your appointment is confirmed for {date} at {time}', 'clinic-manager'),
                'appointment_reminder'     => __('Reminder: appointment on {date} at {time}', 'clinic-manager'),
                'otp'                      => __('Your verification code is {code}', 'clinic-manager'),
            ],
            'payment' => [
                'currency'    => 'IRR',
                'visit_price' => 0,
                'gateway'     => 'default',
                'success_url' => '',
                'failure_url' => '',
            ],
        ];
    }

    private function mergeDefaults($settings)
    {
        return wp_parse_args($settings, $this->getDefaultSettings());
    }
}
