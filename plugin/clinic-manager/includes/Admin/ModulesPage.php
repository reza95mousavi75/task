<?php

namespace MS\Admin;

use MS\Core\ModuleRegistry;

/**
 * Admin page for enabling/disabling modules.
 */
class ModulesPage
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
        add_action('admin_post_ms_toggle_module', [$this, 'handleToggle']);
    }

    public function registerMenu()
    {
        add_menu_page(
            __('Clinic Manager', 'clinic-manager'),
            __('Clinic Manager', 'clinic-manager'),
            'manage_options',
            'ms-modules',
            [$this, 'renderPage'],
            'dashicons-stethoscope',
            56
        );
    }

    public function renderPage()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $states = $this->modules->states();
        $modules = $this->modules->all();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Clinic Manager Modules', 'clinic-manager') . '</h1>';

        echo '<table class="widefat">';
        echo '<thead><tr><th>' . esc_html__('Module', 'clinic-manager') . '</th><th>' . esc_html__('Version', 'clinic-manager') . '</th><th>' . esc_html__('Status', 'clinic-manager') . '</th><th>' . esc_html__('Actions', 'clinic-manager') . '</th></tr></thead>';
        echo '<tbody>';

        foreach ($modules as $slug => $module) {
            $isActive = $states[$slug]['active'] ?? true;
            echo '<tr>';
            echo '<td>' . esc_html($slug) . '</td>';
            echo '<td>' . esc_html($module->version()) . '</td>';
            echo '<td>' . ($isActive ? '<span class="dashicons dashicons-yes"></span>' : '<span class="dashicons dashicons-minus"></span>') . '</td>';
            echo '<td>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('ms_toggle_module');
            echo '<input type="hidden" name="action" value="ms_toggle_module">';
            echo '<input type="hidden" name="slug" value="' . esc_attr($slug) . '">';
            echo '<input type="hidden" name="op" value="' . ($isActive ? 'deactivate' : 'activate') . '">';
            submit_button($isActive ? __('Deactivate', 'clinic-manager') : __('Activate', 'clinic-manager'), 'secondary', 'submit', false);
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    public function handleToggle()
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('You do not have permission to manage modules.', 'clinic-manager'));
        }

        check_admin_referer('ms_toggle_module');

        $slug = sanitize_text_field($_POST['slug'] ?? '');
        $op   = sanitize_text_field($_POST['op'] ?? '');

        if ($slug && $op === 'activate') {
            $this->modules->activate($slug);
        } elseif ($slug && $op === 'deactivate') {
            $this->modules->deactivate($slug);
        }

        wp_safe_redirect(admin_url('admin.php?page=ms-modules'));
        exit;
    }
}
