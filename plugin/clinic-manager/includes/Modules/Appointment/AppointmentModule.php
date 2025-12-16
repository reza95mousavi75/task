<?php

namespace MS\Modules\Appointment;

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
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'menu_icon'    => 'dashicons-calendar-alt',
        ]);

        register_post_type('ms_patient', [
            'label'        => __('Patients', 'clinic-manager'),
            'public'       => false,
            'show_ui'      => true,
            'supports'     => ['title', 'custom-fields'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'menu_icon'    => 'dashicons-id',
        ]);
    }

    public function registerRoutes()
    {
        register_rest_route('ms/v1', '/appointments', [
            'methods'             => 'POST',
            'callback'            => [$this, 'bookAppointment'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function bookAppointment($request)
    {
        $patientName = sanitize_text_field($request['patient_name'] ?? '');
        $phone       = sanitize_text_field($request['phone'] ?? '');
        $serviceId   = absint($request['service_id'] ?? 0);
        $slotTime    = sanitize_text_field($request['slot_time'] ?? '');

        if (! $patientName || ! $phone || ! $slotTime) {
            return new \WP_Error('ms_invalid', __('Missing required fields', 'clinic-manager'), ['status' => 400]);
        }

        $appointmentId = wp_insert_post([
            'post_type'   => 'ms_appointment',
            'post_status' => 'publish',
            'post_title'  => sprintf(__('Appointment for %s', 'clinic-manager'), $patientName),
            'meta_input'  => [
                'ms_patient_name' => $patientName,
                'ms_phone'        => $phone,
                'ms_service_id'   => $serviceId,
                'ms_slot_time'    => $slotTime,
                'ms_status'       => 'reserved',
            ],
        ]);

        if (is_wp_error($appointmentId)) {
            return $appointmentId;
        }

        return [
            'id'        => $appointmentId,
            'status'    => 'reserved',
            'slot_time' => $slotTime,
        ];
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
}
