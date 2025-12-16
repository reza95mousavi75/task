<?php

namespace MS\Admin;

use MS\Core\Capabilities;
use MS\Core\ModuleRegistry;

/**
 * Receptionist dashboard with upcoming appointments and quick status updates.
 */
class DashboardPage
{
    /** @var ModuleRegistry */
    private $modules;

    public function __construct(ModuleRegistry $modules)
    {
        $this->modules = $modules;
    }

    public function hooks()
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_post_ms_update_appointment_status', [$this, 'handleStatusChange']);
    }

    public function registerMenu()
    {
        add_submenu_page(
            'ms-modules',
            __('Clinic Dashboard', 'clinic-manager'),
            __('Dashboard', 'clinic-manager'),
            Capabilities::MANAGE_APPOINTMENTS,
            'ms-dashboard',
            [$this, 'renderPage']
        );
    }

    public function renderPage()
    {
        if (! current_user_can(Capabilities::MANAGE_APPOINTMENTS)) {
            return;
        }

        $appointments = $this->upcomingAppointments();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Clinic Dashboard', 'clinic-manager') . '</h1>';

        $this->renderStats();

        echo '<h2>' . esc_html__('Upcoming Appointments', 'clinic-manager') . '</h2>';
        echo '<table class="widefat">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Patient', 'clinic-manager') . '</th>';
        echo '<th>' . esc_html__('Provider', 'clinic-manager') . '</th>';
        echo '<th>' . esc_html__('Service', 'clinic-manager') . '</th>';
        echo '<th>' . esc_html__('Slot', 'clinic-manager') . '</th>';
        echo '<th>' . esc_html__('Status', 'clinic-manager') . '</th>';
        echo '<th>' . esc_html__('Actions', 'clinic-manager') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($appointments)) {
            echo '<tr><td colspan="6">' . esc_html__('No upcoming appointments found.', 'clinic-manager') . '</td></tr>';
        }

        foreach ($appointments as $appointment) {
            $patient   = get_post_meta($appointment->ID, 'ms_patient_name', true);
            $provider  = (int) get_post_meta($appointment->ID, 'ms_provider_id', true);
            $service   = (int) get_post_meta($appointment->ID, 'ms_service_id', true);
            $slot      = get_post_meta($appointment->ID, 'ms_slot_time', true);
            $status    = get_post_meta($appointment->ID, 'ms_status', true) ?: 'reserved';
            $statusLabel = ucfirst($status);

            echo '<tr>';
            echo '<td>' . esc_html($patient) . '</td>';
            echo '<td>' . esc_html($provider ?: '-') . '</td>';
            echo '<td>' . esc_html($service ?: '-') . '</td>';
            echo '<td>' . esc_html($slot) . '</td>';
            echo '<td>' . esc_html($statusLabel) . '</td>';
            echo '<td>' . $this->statusButtons($appointment->ID, $status) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    public function handleStatusChange()
    {
        if (! current_user_can(Capabilities::MANAGE_APPOINTMENTS)) {
            wp_die(__('You do not have permission to change appointment status.', 'clinic-manager'));
        }

        check_admin_referer('ms_update_appointment_status');

        $appointmentId = absint($_POST['id'] ?? 0);
        $status        = sanitize_text_field($_POST['status'] ?? '');
        $allowed       = [
            'reserved'  => 'ms_reserved',
            'confirmed' => 'ms_confirmed',
            'cancelled' => 'ms_cancelled',
            'noshow'    => 'ms_noshow',
        ];

        if ($appointmentId && isset($allowed[$status])) {
            wp_update_post([
                'ID'          => $appointmentId,
                'post_status' => $allowed[$status],
            ]);

            update_post_meta($appointmentId, 'ms_status', $status);

            $providerId = (int) get_post_meta($appointmentId, 'ms_provider_id', true);
            do_action('ms_appointment_status_changed', $providerId, $status);
        }

        wp_safe_redirect(admin_url('admin.php?page=ms-dashboard'));
        exit;
    }

    private function upcomingAppointments()
    {
        $now  = current_time('mysql');
        $args = [
            'post_type'      => 'ms_appointment',
            'post_status'    => ['ms_reserved', 'ms_confirmed'],
            'posts_per_page' => 10,
            'meta_query'     => [
                [
                    'key'     => 'ms_slot_time',
                    'value'   => $now,
                    'compare' => '>=',
                    'type'    => 'DATETIME',
                ],
            ],
            'orderby'        => 'meta_value',
            'meta_key'       => 'ms_slot_time',
            'order'          => 'ASC',
        ];

        $query = new \WP_Query($args);

        return $query->posts;
    }

    private function renderStats()
    {
        $todayStart = gmdate('Y-m-d 00:00:00', current_time('timestamp'));
        $todayEnd   = gmdate('Y-m-d 23:59:59', current_time('timestamp'));

        $counts = [
            'confirmed' => $this->countAppointments('confirmed', $todayStart, $todayEnd),
            'reserved'  => $this->countAppointments('reserved', $todayStart, $todayEnd),
            'noshow'    => $this->countAppointments('noshow', $todayStart, $todayEnd),
        ];

        echo '<div class="ms-dashboard-metrics">';
        foreach ($counts as $label => $count) {
            echo '<div class="ms-metric">';
            echo '<strong>' . esc_html(ucfirst($label)) . '</strong><br>';
            echo '<span>' . esc_html($count) . '</span>';
            echo '</div>';
        }
        echo '</div>';
    }

    private function countAppointments($status, $from, $to)
    {
        $statusMap = [
            'reserved'  => 'ms_reserved',
            'confirmed' => 'ms_confirmed',
            'cancelled' => 'ms_cancelled',
            'noshow'    => 'ms_noshow',
        ];

        $query = new \WP_Query([
            'post_type'      => 'ms_appointment',
            'post_status'    => [$statusMap[$status]],
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'     => 'ms_status',
                    'value'   => $status,
                ],
                [
                    'key'     => 'ms_slot_time',
                    'value'   => [$from, $to],
                    'compare' => 'BETWEEN',
                    'type'    => 'DATETIME',
                ],
            ],
        ]);

        return (int) $query->found_posts;
    }

    private function statusButtons($id, $current)
    {
        $statuses = [
            'confirmed' => __('Mark confirmed', 'clinic-manager'),
            'cancelled' => __('Mark cancelled', 'clinic-manager'),
            'noshow'    => __('Mark no-show', 'clinic-manager'),
        ];

        $html = '<div class="ms-status-actions">';
        foreach ($statuses as $status => $label) {
            if ($status === $current) {
                continue;
            }
            $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:6px;">';
            wp_nonce_field('ms_update_appointment_status');
            $html .= '<input type="hidden" name="action" value="ms_update_appointment_status">';
            $html .= '<input type="hidden" name="id" value="' . esc_attr($id) . '">';
            $html .= '<input type="hidden" name="status" value="' . esc_attr($status) . '">';
            $html .= '<button class="button button-small" type="submit">' . esc_html($label) . '</button>';
            $html .= '</form>';
        }
        $html .= '</div>';

        return $html;
    }
}
