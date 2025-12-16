<?php

namespace MS\Core;

interface ModuleInterface
{
    /** Unique slug for registry */
    public function slug();

    /** Semantic version. */
    public function version();

    /** Register hooks/services when module is active. */
    public function boot(ServiceContainer $container);

    /** Install database tables or defaults. */
    public function activate(ServiceContainer $container);

    /** Tear down scheduled tasks or listeners. */
    public function deactivate(ServiceContainer $container);
}
