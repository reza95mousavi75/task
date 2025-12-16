<?php
/**
 * Plugin Name: Clinic Manager
 * Description: Modular clinic/office management plugin with appointments, EMR, payments, and automation.
 * Version: 0.1.0
 * Author: Clinic Team
 */

if (! defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/Core/ServiceContainer.php';
require_once plugin_dir_path(__FILE__) . 'includes/Core/ModuleInterface.php';
require_once plugin_dir_path(__FILE__) . 'includes/Core/ModuleRegistry.php';
require_once plugin_dir_path(__FILE__) . 'includes/Core/Plugin.php';
require_once plugin_dir_path(__FILE__) . 'includes/Admin/ModulesPage.php';
require_once plugin_dir_path(__FILE__) . 'includes/Modules/Appointment/AppointmentModule.php';
require_once plugin_dir_path(__FILE__) . 'includes/Modules/EMR/EMRModule.php';
require_once plugin_dir_path(__FILE__) . 'includes/Modules/Finance/FinanceModule.php';
require_once plugin_dir_path(__FILE__) . 'includes/Modules/SMS/SMSModule.php';
require_once plugin_dir_path(__FILE__) . 'includes/Modules/Growth/GrowthModule.php';

add_action('plugins_loaded', function () {
    $container = new \MS\Core\ServiceContainer();
    $registry  = new \MS\Core\ModuleRegistry($container);

    $plugin = new \MS\Core\Plugin($container, $registry);

    // Register modules here.
    $registry->register(new \MS\Modules\Appointment\AppointmentModule());
    $registry->register(new \MS\Modules\EMR\EMRModule());
    $registry->register(new \MS\Modules\Finance\FinanceModule());
    $registry->register(new \MS\Modules\SMS\SMSModule());
    $registry->register(new \MS\Modules\Growth\GrowthModule());

    $plugin->boot();
});
