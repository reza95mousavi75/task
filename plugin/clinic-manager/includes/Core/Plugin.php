<?php

namespace MS\Core;

/**
 * Core plugin bootstrapper.
 */
class Plugin
{
    /** @var ServiceContainer */
    private $container;

    /** @var ModuleRegistry */
    private $modules;

    public function __construct(ServiceContainer $container, ModuleRegistry $modules)
    {
        $this->container = $container;
        $this->modules   = $modules;
    }

    public function boot()
    {
        register_activation_hook(dirname(__DIR__, 2) . '/clinic-manager.php', [$this, 'activate']);
        register_deactivation_hook(dirname(__DIR__, 2) . '/clinic-manager.php', [$this, 'deactivate']);

        add_action('init', function () {
            $this->modules->bootActiveModules();
        });
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
