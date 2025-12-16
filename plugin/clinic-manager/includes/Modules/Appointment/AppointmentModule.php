<?php

namespace MS\Modules\Appointment;

use MS\Core\Capabilities;
use MS\Core\ModuleInterface;
use MS\Core\ServiceContainer;

class AppointmentModule implements ModuleInterface
{
    public function slug()
    {
        return 'appointment';
    }

    public function version()
    {
        return '0.1.0';
    }

    public function boot(ServiceContainer $container)
    {
        add_action('init', [$this, 'registerPostTypes']);
        add_action('init', [$this, 'registerStatuses']);
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function activate(ServiceContainer $container)
    {
        $this->registerPostTypes();
        $this->createTables();
    }

    public function deactivate(ServiceContainer $container)
    {
        // Keep data by default; remove scheduled jobs or listeners here if added later.
    }

    public function registerPostTypes()
    {
        register_post_type('ms_appointment', [
            'label'        => __('Appointments', 'clinic-manager'),
            'public'       => false,
            'show_ui'      => true,
            'supports'     => ['title', 'custom-fields'],
            'show_in_rest' => true,
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'menu_icon'    => 'dashicons-calendar-alt',
        ]);
    }

    public function registerStatuses()
    {
        $statuses = [
            'ms_reserved'  => __('Reserved', 'clinic-manager'),
            'ms_confirmed' => __('Confirmed', 'clinic-manager'),
            'ms_cancelled' => __('Cancelled', 'clinic-manager'),
            'ms_noshow'    => __('No-show', 'clinic-manager'),
        ];

        foreach ($statuses as $key => $label) {
            register_post_status($key, [
                'label'                     => $label,
                'public'                    => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                'label_count'               => _n_noop($label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>', 'clinic-manager'),
            ]);
        }
    }

    public function registerRoutes()
    {
        register_rest_route('ms/v1', '/appointments', [
            'methods'             => 'POST',
            'callback'            => [$this, 'bookAppointment'],
            'permission_callback' => '__return_true',
            'args'                => [
                'patient_name' => ['sanitize_callback' => 'sanitize_text_field'],
                'phone'        => ['sanitize_callback' => 'sanitize_text_field'],
                'service_id'   => ['sanitize_callback' => 'absint'],
                'provider_id'  => ['sanitize_callback' => 'absint'],
                'slot_time'    => ['sanitize_callback' => 'sanitize_text_field'],
                'otp_challenge_id' => ['sanitize_callback' => 'absint'],
                'otp_code'     => ['sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route('ms/v1', '/appointments', [
            'methods'             => 'GET',
            'callback'            => [$this, 'listAppointments'],
            'permission_callback' => function () {
                return current_user_can(Capabilities::MANAGE_APPOINTMENTS);
            },
            'args'                => [
                'provider_id' => ['sanitize_callback' => 'absint'],
                'status'      => ['sanitize_callback' => 'sanitize_text_field'],
                'date_from'   => ['sanitize_callback' => 'sanitize_text_field'],
                'date_to'     => ['sanitize_callback' => 'sanitize_text_field'],
                'per_page'    => ['sanitize_callback' => 'absint'],
                'page'        => ['sanitize_callback' => 'absint'],
            ],
        ]);

        register_rest_route('ms/v1', '/appointments/(?P<id>\\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getAppointment'],
            'permission_callback' => function () {
                return current_user_can(Capabilities::MANAGE_APPOINTMENTS);
            },
        ]);

        register_rest_route('ms/v1', '/availability', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getAvailability'],
            'permission_callback' => '__return_true',
            'args'                => [
                'provider_id' => [
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ],
                'date_from' => ['sanitize_callback' => 'sanitize_text_field'],
                'date_to'   => ['sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route('ms/v1', '/appointments/(?P<id>\d+)/status', [
            'methods'             => 'PATCH',
            'callback'            => [$this, 'updateStatus'],
            'permission_callback' => function () {
                return current_user_can(Capabilities::MANAGE_APPOINTMENTS);
            },
            'args'                => [
                'status' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    public function bookAppointment($request)
    {
        $patientName = sanitize_text_field($request['patient_name'] ?? '');
        $phone       = sanitize_text_field($request['phone'] ?? '');
        $serviceId   = absint($request['service_id'] ?? 0);
        $providerId  = absint($request['provider_id'] ?? 0);
        $slotTime    = sanitize_text_field($request['slot_time'] ?? '');
        $otpId       = absint($request['otp_challenge_id'] ?? 0);
        $otpCode     = sanitize_text_field($request['otp_code'] ?? '');

        $slotTimestamp = strtotime($slotTime) ?: 0;

        if ($slotTimestamp <= 0) {
            return new \WP_Error('ms_invalid_slot', __('Invalid slot time supplied.', 'clinic-manager'), ['status' => 400]);
        }

        if (! $patientName || ! $phone || ! $slotTime) {
            return new \WP_Error('ms_invalid', __('Missing required fields', 'clinic-manager'), ['status' => 400]);
        }

        if ($otpId || $otpCode) {
            if (! $otpId || ! $otpCode) {
                return new \WP_Error('ms_otp_incomplete', __('OTP code and challenge are required together.', 'clinic-manager'), ['status' => 400]);
            }

            $verified = apply_filters('ms_sms_validate_otp', true, $phone, $otpId, $otpCode, 'booking');

            if (is_wp_error($verified)) {
                return $verified;
            }
        }

        if ($this->hasConflict($providerId, $slotTime)) {
            return new \WP_Error('ms_conflict', __('This time slot is no longer available.', 'clinic-manager'), ['status' => 409]);
        }

        $appointmentId = wp_insert_post([
            'post_type'   => 'ms_appointment',
            'post_status' => 'ms_reserved',
            'post_title'  => sprintf(__('Appointment for %s', 'clinic-manager'), $patientName),
            'meta_input'  => [
                'ms_patient_name' => $patientName,
                'ms_phone'        => $phone,
                'ms_service_id'   => $serviceId,
                'ms_provider_id'  => $providerId,
                'ms_slot_time'    => $slotTime,
                'ms_status'       => 'reserved',
                'ms_otp_challenge_id' => $otpId,
            ],
        ]);

        if (is_wp_error($appointmentId)) {
            return $appointmentId;
        }

        do_action('ms_appointment_booked', $providerId);

        return [
            'id'        => $appointmentId,
            'status'    => 'reserved',
            'slot_time' => $slotTime,
        ];
    }

    public function updateStatus($request)
    {
        $appointmentId = absint($request['id'] ?? 0);
        $status        = sanitize_text_field($request['status'] ?? '');
        $allowed       = ['reserved', 'confirmed', 'cancelled', 'noshow'];

        if (! $appointmentId || ! get_post($appointmentId)) {
            return new \WP_Error('ms_not_found', __('Appointment not found.', 'clinic-manager'), ['status' => 404]);
        }

        if (! in_array($status, $allowed, true)) {
            return new \WP_Error('ms_invalid_status', __('Invalid status.', 'clinic-manager'), ['status' => 400]);
        }

        $postStatusMap = [
            'reserved'  => 'ms_reserved',
            'confirmed' => 'ms_confirmed',
            'cancelled' => 'ms_cancelled',
            'noshow'    => 'ms_noshow',
        ];

        wp_update_post([
            'ID'          => $appointmentId,
            'post_status' => $postStatusMap[$status],
        ]);

        update_post_meta($appointmentId, 'ms_status', $status);

        $providerId = (int) get_post_meta($appointmentId, 'ms_provider_id', true);
        do_action('ms_appointment_status_changed', $providerId, $status);

        return [
            'id'     => $appointmentId,
            'status' => $status,
        ];
    }

    public function listAppointments($request)
    {
        $providerId = absint($request['provider_id'] ?? 0);
        $status     = sanitize_text_field($request['status'] ?? '');
        $dateFrom   = sanitize_text_field($request['date_from'] ?? '');
        $dateTo     = sanitize_text_field($request['date_to'] ?? '');
        $perPage    = absint($request['per_page'] ?? 20);
        $page       = absint($request['page'] ?? 1);

        $allowedStatuses = [
            'reserved'  => 'ms_reserved',
            'confirmed' => 'ms_confirmed',
            'cancelled' => 'ms_cancelled',
            'noshow'    => 'ms_noshow',
        ];

        $metaQuery = [];

        if ($providerId) {
            $metaQuery[] = [
                'key'     => 'ms_provider_id',
                'value'   => $providerId,
                'compare' => '=',
            ];
        }

        if ($dateFrom || $dateTo) {
            $range = ['relation' => 'AND'];

            if ($dateFrom) {
                $range[] = [
                    'key'     => 'ms_slot_time',
                    'value'   => $dateFrom,
                    'compare' => '>=',
                    'type'    => 'DATETIME',
                ];
            }

            if ($dateTo) {
                $range[] = [
                    'key'     => 'ms_slot_time',
                    'value'   => $dateTo,
                    'compare' => '<=',
                    'type'    => 'DATETIME',
                ];
            }

            $metaQuery[] = $range;
        }

        $queryArgs = [
            'post_type'      => 'ms_appointment',
            'post_status'    => array_values($allowedStatuses),
            'posts_per_page' => $perPage > 0 ? min($perPage, 50) : 20,
            'paged'          => max(1, $page),
            'meta_query'     => $metaQuery,
            'orderby'        => 'meta_value',
            'meta_key'       => 'ms_slot_time',
            'order'          => 'ASC',
        ];

        if ($status && isset($allowedStatuses[$status])) {
            $queryArgs['post_status'] = [$allowedStatuses[$status]];
            $metaQuery[]              = [
                'key'   => 'ms_status',
                'value' => $status,
            ];
            $queryArgs['meta_query']  = $metaQuery;
        }

        $query = new \WP_Query($queryArgs);
        $data  = array_map([$this, 'formatAppointment'], $query->posts);

        return [
            'data'  => $data,
            'total' => (int) $query->found_posts,
            'page'  => (int) $queryArgs['paged'],
        ];
    }

    public function getAppointment($request)
    {
        $appointmentId = absint($request['id'] ?? 0);
        $post          = get_post($appointmentId);

        if (! $post || $post->post_type !== 'ms_appointment') {
            return new \WP_Error('ms_not_found', __('Appointment not found.', 'clinic-manager'), ['status' => 404]);
        }

        return $this->formatAppointment($post);
    }

    public function getAvailability($request)
    {
        $providerId = absint($request['provider_id'] ?? 0);
        $dateFrom   = sanitize_text_field($request['date_from'] ?? '');
        $dateTo     = sanitize_text_field($request['date_to'] ?? '');

        if (! $providerId) {
            return new \WP_Error('ms_invalid_provider', __('Provider is required.', 'clinic-manager'), ['status' => 400]);
        }

        $from = $dateFrom ?: current_time('mysql');
        $to   = $dateTo ?: gmdate('Y-m-d H:i:s', strtotime('+7 days'));

        $query = new \WP_Query([
            'post_type'      => 'ms_appointment',
            'post_status'    => ['ms_reserved', 'ms_confirmed'],
            'posts_per_page' => -1,
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'     => 'ms_provider_id',
                    'value'   => $providerId,
                    'compare' => '=',
                ],
                [
                    'key'     => 'ms_slot_time',
                    'value'   => [$from, $to],
                    'compare' => 'BETWEEN',
                    'type'    => 'DATETIME',
                ],
            ],
            'orderby'    => 'meta_value',
            'meta_key'   => 'ms_slot_time',
            'order'      => 'ASC',
        ]);

        $busySlots = [];

        foreach ($query->posts as $post) {
            $busySlots[] = get_post_meta($post->ID, 'ms_slot_time', true);
        }

        return [
            'provider_id' => $providerId,
            'date_from'   => $from,
            'date_to'     => $to,
            'busy'        => $busySlots,
        ];
    }

    private function hasConflict($providerId, $slotTime)
    {
        $query = new \WP_Query([
            'post_type'      => 'ms_appointment',
            'post_status'    => ['ms_reserved', 'ms_confirmed'],
            'posts_per_page' => 1,
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'   => 'ms_slot_time',
                    'value' => $slotTime,
                ],
                [
                    'key'     => 'ms_provider_id',
                    'value'   => $providerId,
                    'compare' => '=',
                ],
            ],
        ]);

        return $query->have_posts();
    }

    private function createTables()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $wpdb->get_charset_collate();
        $appointments   = $wpdb->prefix . 'ms_appointments';
        $slots          = $wpdb->prefix . 'ms_time_slots';

        $sql = "CREATE TABLE {$slots} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            provider_id bigint(20) unsigned NOT NULL,
            schedule_id bigint(20) unsigned NOT NULL,
            start_datetime datetime NOT NULL,
            end_datetime datetime NOT NULL,
            capacity smallint unsigned NOT NULL DEFAULT 1,
            status varchar(20) NOT NULL DEFAULT 'open',
            lock_token varchar(64) DEFAULT NULL,
            locked_until datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY schedule_start (schedule_id, start_datetime),
            KEY provider_start (provider_id, start_datetime)
        ) {$charsetCollate};

        CREATE TABLE {$appointments} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            patient_id bigint(20) unsigned DEFAULT NULL,
            provider_id bigint(20) unsigned NOT NULL,
            service_id bigint(20) unsigned DEFAULT NULL,
            slot_id bigint(20) unsigned DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'reserved',
            payment_status varchar(20) NOT NULL DEFAULT 'unpaid',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY provider_created (provider_id, created_at)
        ) {$charsetCollate};";

        dbDelta($sql);
    }

    private function formatAppointment($post)
    {
        return [
            'id'            => $post->ID,
            'status'        => get_post_meta($post->ID, 'ms_status', true) ?: 'reserved',
            'patient_name'  => get_post_meta($post->ID, 'ms_patient_name', true),
            'phone'         => get_post_meta($post->ID, 'ms_phone', true),
            'provider_id'   => (int) get_post_meta($post->ID, 'ms_provider_id', true),
            'service_id'    => (int) get_post_meta($post->ID, 'ms_service_id', true),
            'slot_time'     => get_post_meta($post->ID, 'ms_slot_time', true),
            'otp_reference' => (int) get_post_meta($post->ID, 'ms_otp_challenge_id', true),
        ];
    }
}
