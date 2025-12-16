<?php

namespace MS\Modules\EMR;

use MS\Core\ModuleInterface;
use MS\Core\ServiceContainer;

class EMRModule implements ModuleInterface
{
    public function slug()
    {
        return 'emr';
    }

    public function version()
    {
        return '0.1.0';
    }

    public function boot(ServiceContainer $container)
    {
        add_action('init', [$this, 'registerPostTypes']);
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function activate(ServiceContainer $container)
    {
        $this->registerPostTypes();
        flush_rewrite_rules();
    }

    public function deactivate(ServiceContainer $container)
    {
        flush_rewrite_rules();
    }

    public function registerPostTypes()
    {
        register_post_type('ms_patient', [
            'label'        => __('Patients', 'clinic-manager'),
            'public'       => false,
            'show_ui'      => true,
            'supports'     => ['title', 'custom-fields'],
            'show_in_rest' => true,
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'menu_icon'    => 'dashicons-id',
        ]);

        register_post_type('ms_visit', [
            'label'        => __('Visits', 'clinic-manager'),
            'public'       => false,
            'show_ui'      => true,
            'supports'     => ['title', 'editor', 'custom-fields'],
            'show_in_rest' => true,
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'menu_icon'    => 'dashicons-clipboard',
        ]);
    }

    public function registerRoutes()
    {
        register_rest_route('ms/v1', '/patients', [
            'methods'             => 'POST',
            'callback'            => [$this, 'createPatient'],
            'permission_callback' => '__return_true',
            'args'                => [
                'full_name'     => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'phone'         => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'national_code' => ['sanitize_callback' => 'sanitize_text_field'],
                'meta'          => [],
            ],
        ]);

        register_rest_route('ms/v1', '/patients/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getPatient'],
            'permission_callback' => function () {
                return current_user_can('edit_posts');
            },
        ]);

        register_rest_route('ms/v1', '/visits', [
            'methods'             => 'POST',
            'callback'            => [$this, 'createVisit'],
            'permission_callback' => function () {
                return current_user_can('edit_posts');
            },
            'args'                => [
                'patient_id'  => ['required' => true, 'sanitize_callback' => 'absint'],
                'provider_id' => ['sanitize_callback' => 'absint'],
                'summary'     => ['sanitize_callback' => 'sanitize_text_field'],
                'diagnosis'   => ['sanitize_callback' => 'sanitize_text_field'],
                'medications' => [],
            ],
        ]);

        register_rest_route('ms/v1', '/visits/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getVisit'],
            'permission_callback' => function () {
                return current_user_can('edit_posts');
            },
        ]);
    }

    public function createPatient($request)
    {
        $fullName     = sanitize_text_field($request['full_name'] ?? '');
        $phone        = sanitize_text_field($request['phone'] ?? '');
        $nationalCode = sanitize_text_field($request['national_code'] ?? '');
        $meta         = $request['meta'] ?? [];

        if (! $fullName || ! $phone) {
            return new \WP_Error('ms_missing_fields', __('Full name and phone are required.', 'clinic-manager'), ['status' => 400]);
        }

        $patientId = wp_insert_post([
            'post_type'   => 'ms_patient',
            'post_title'  => $fullName,
            'post_status' => 'publish',
            'meta_input'  => [
                'ms_phone'         => $phone,
                'ms_national_code' => $nationalCode,
                'ms_meta'          => is_array($meta) ? wp_json_encode($meta) : '{}',
            ],
        ]);

        if (is_wp_error($patientId)) {
            return $patientId;
        }

        return [
            'id'           => $patientId,
            'full_name'    => $fullName,
            'phone'        => $phone,
            'national_code'=> $nationalCode,
            'meta'         => is_array($meta) ? $meta : [],
        ];
    }

    public function getPatient($request)
    {
        $patientId = absint($request['id'] ?? 0);
        $post      = get_post($patientId);

        if (! $post || $post->post_type !== 'ms_patient') {
            return new \WP_Error('ms_not_found', __('Patient not found.', 'clinic-manager'), ['status' => 404]);
        }

        return [
            'id'            => $post->ID,
            'full_name'     => $post->post_title,
            'phone'         => get_post_meta($post->ID, 'ms_phone', true),
            'national_code' => get_post_meta($post->ID, 'ms_national_code', true),
            'meta'          => json_decode(get_post_meta($post->ID, 'ms_meta', true) ?: '{}', true),
        ];
    }

    public function createVisit($request)
    {
        $patientId  = absint($request['patient_id'] ?? 0);
        $providerId = absint($request['provider_id'] ?? 0);
        $summary    = sanitize_text_field($request['summary'] ?? '');
        $diagnosis  = sanitize_text_field($request['diagnosis'] ?? '');
        $meds       = $request['medications'] ?? [];

        if (! $patientId || ! get_post($patientId)) {
            return new \WP_Error('ms_patient_missing', __('Patient is required for a visit.', 'clinic-manager'), ['status' => 400]);
        }

        $visitId = wp_insert_post([
            'post_type'   => 'ms_visit',
            'post_status' => 'publish',
            'post_title'  => sprintf(__('Visit for patient #%d', 'clinic-manager'), $patientId),
            'post_content'=> $summary,
            'meta_input'  => [
                'ms_patient_id'  => $patientId,
                'ms_provider_id' => $providerId,
                'ms_diagnosis'   => $diagnosis,
                'ms_medications' => is_array($meds) ? wp_json_encode($meds) : '[]',
            ],
        ]);

        if (is_wp_error($visitId)) {
            return $visitId;
        }

        return [
            'id'          => $visitId,
            'patient_id'  => $patientId,
            'provider_id' => $providerId,
            'summary'     => $summary,
            'diagnosis'   => $diagnosis,
            'medications' => is_array($meds) ? $meds : [],
        ];
    }

    public function getVisit($request)
    {
        $visitId = absint($request['id'] ?? 0);
        $post    = get_post($visitId);

        if (! $post || $post->post_type !== 'ms_visit') {
            return new \WP_Error('ms_not_found', __('Visit not found.', 'clinic-manager'), ['status' => 404]);
        }

        return [
            'id'           => $post->ID,
            'patient_id'   => (int) get_post_meta($post->ID, 'ms_patient_id', true),
            'provider_id'  => (int) get_post_meta($post->ID, 'ms_provider_id', true),
            'summary'      => $post->post_content,
            'diagnosis'    => get_post_meta($post->ID, 'ms_diagnosis', true),
            'medications'  => json_decode(get_post_meta($post->ID, 'ms_medications', true) ?: '[]', true),
        ];
    }
}
