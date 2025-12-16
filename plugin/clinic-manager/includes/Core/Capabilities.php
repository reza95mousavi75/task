<?php

namespace MS\Core;

/**
 * Registers custom roles and capabilities for the Clinic Manager plugin.
 */
class Capabilities
{
    public const MANAGE_APPOINTMENTS = 'ms_manage_appointments';
    public const MANAGE_EMR          = 'ms_manage_emr';
    public const MANAGE_FINANCE      = 'ms_manage_finance';
    public const MANAGE_DIRECTORY    = 'ms_manage_directory';
    public const MANAGE_GROWTH       = 'ms_manage_growth';
    public const MANAGE_SUPPORT      = 'ms_manage_support';
    public const MANAGE_SETTINGS     = 'ms_manage_settings';
    public const MANAGE_GATEWAY      = 'ms_manage_gateway';

    /**
     * Ensure roles and capabilities exist on activation or init.
     */
    public static function register()
    {
        $caps = [
            self::MANAGE_APPOINTMENTS => true,
            self::MANAGE_EMR          => true,
            self::MANAGE_FINANCE      => true,
            self::MANAGE_DIRECTORY    => true,
            self::MANAGE_GROWTH       => true,
            self::MANAGE_SUPPORT      => true,
            self::MANAGE_SETTINGS     => true,
            self::MANAGE_GATEWAY      => true,
        ];

        // Full access role.
        add_role('clinic_manager', __('Clinic Manager', 'clinic-manager'), $caps + ['read' => true]);

        // Provider role: clinical and engagement tools.
        add_role('clinic_provider', __('Clinic Provider', 'clinic-manager'), [
            self::MANAGE_APPOINTMENTS => true,
            self::MANAGE_EMR          => true,
            self::MANAGE_DIRECTORY    => true,
            self::MANAGE_GROWTH       => true,
            self::MANAGE_SUPPORT      => true,
            'read'                    => true,
        ]);

        // Receptionist role: scheduling, patient intake, tickets.
        add_role('clinic_receptionist', __('Clinic Receptionist', 'clinic-manager'), [
            self::MANAGE_APPOINTMENTS => true,
            self::MANAGE_EMR          => true,
            self::MANAGE_DIRECTORY    => true,
            self::MANAGE_SUPPORT      => true,
            'read'                    => true,
        ]);

        // Ensure admins inherit all capabilities.
        $admin = get_role('administrator');
        if ($admin) {
            foreach (array_keys($caps) as $cap) {
                if (! $admin->has_cap($cap)) {
                    $admin->add_cap($cap);
                }
            }
        }
    }
}
