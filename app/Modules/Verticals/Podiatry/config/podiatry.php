<?php

// Vertical-specific settings (color/icon/slot). Display name & entity labels are
// NOT here — they live in lang/{tr,en}/vertical.php (i18n). Vertical-agnostic
// settings belong in config/platform.php, never here.
return [
    'color' => '#0d9488',
    'icon' => 'IconShoe',
    'default_slot_duration_minutes' => 30,

    // Default appointment types provisioned for every new podiatry clinic.
    // Both the runtime listener (ClinicRegistered) and the demo seeder read from this list.
    'appointment_types' => [
        ['name' => 'Muayene', 'color' => '#0d9488', 'default_duration_minutes' => 30],
        ['name' => 'Kontrol', 'color' => '#0891b2', 'default_duration_minutes' => 20],
        ['name' => 'Seans', 'color' => '#7c3aed', 'default_duration_minutes' => 45],
    ],
];
