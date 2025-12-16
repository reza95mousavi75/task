<?php

namespace MS\Core;

use MS\Admin\ModulesPage;
use MS\Admin\DashboardPage;

/**
 * Core plugin bootstrapper.
 */
class Plugin
{
    /** @var ServiceContainer */
    private $container;

    /** @var ModuleRegistry */
    private $modules;

    /** @var ModulesPage */
    private $modulesPage;

    /** @var DashboardPage */
    private $dashboardPage;

    public function __construct(ServiceContainer $container, ModuleRegistry $modules)
    {
        $this->container = $container;
        $this->modules   = $modules;
        $this->modulesPage = new ModulesPage($modules);
        $this->dashboardPage = new DashboardPage($modules);
    }

    public function boot()
    {
        register_activation_hook(dirname(__DIR__, 2) . '/clinic-manager.php', [$this, 'activate']);
        register_deactivation_hook(dirname(__DIR__, 2) . '/clinic-manager.php', [$this, 'deactivate']);

        add_action('init', function () {
            $this->modules->bootActiveModules();
        });

        $this->modulesPage->hooks();
        $this->dashboardPage->hooks();
    }

    /**
     * Activate all registered modules (first install) to provision schema.
     */
    public function activate()
    {
        foreach ($this->modules->all() as $slug => $module) {
            $module->activate($this->container);
        }
    }

    /**
     * Deactivate modules to clean up schedules or listeners.
     */
    public function deactivate()
    {
        foreach ($this->modules->all() as $slug => $module) {
            $module->deactivate($this->container);
        }
    }
}
