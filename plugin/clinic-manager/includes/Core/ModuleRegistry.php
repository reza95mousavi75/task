<?php

namespace MS\Core;

/**
 * Registers and manages modules with lifecycle hooks.
 */
class ModuleRegistry
{
    /** @var array<string, ModuleInterface> */
    private $modules = [];

    /** @var ServiceContainer */
    private $container;

    public function __construct(ServiceContainer $container)
    {
        $this->container = $container;
    }

    /**
     * Register a module instance.
     */
    public function register(ModuleInterface $module)
    {
        $this->modules[$module->slug()] = $module;
    }

    /**
     * Boot active modules.
     */
    public function bootActiveModules()
    {
        $stored = get_option('ms_modules', []);

        foreach ($this->modules as $slug => $module) {
            $isActive = $stored[$slug]['active'] ?? true; // default active on first install

            if ($isActive) {
                $module->boot($this->container);
            }
        }
    }

    /**
     * Activate a module and persist state.
     */
    public function activate($slug)
    {
        $stored = get_option('ms_modules', []);
        if (! isset($this->modules[$slug])) {
            return;
        }

        $module = $this->modules[$slug];
        $module->activate($this->container);
        $stored[$slug] = ['active' => true, 'version' => $module->version()];
        update_option('ms_modules', $stored, false);
    }

    /**
     * Deactivate a module and persist state.
     */
    public function deactivate($slug)
    {
        $stored = get_option('ms_modules', []);
        if (! isset($this->modules[$slug])) {
            return;
        }

        $module = $this->modules[$slug];
        $module->deactivate($this->container);
        $stored[$slug] = ['active' => false, 'version' => $module->version()];
        update_option('ms_modules', $stored, false);
    }

    /**
     * Get modules for admin UI.
     *
     * @return array<string, ModuleInterface>
     */
    public function all()
    {
        return $this->modules;
    }
}
