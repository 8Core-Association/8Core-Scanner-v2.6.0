<?php
/**
 * 8Core Integrity — module manifest
 *
 * Ovaj fajl se učitava via `include` u Module Manageru.
 * Mora vraćati PHP array — bez echo, bez side effecta.
 */
return [
    'module_key'  => '8core-integrity',
    'name'        => '8Core Integrity',
    'version'     => '0.1.1',
    'description' => 'Core and file integrity checker using trusted repository comparison.',
    'admin_menu'  => [
        [
            'label' => 'Integrity',
            'url'   => 'module.php?module=8core-integrity&page=module_integrity',
        ],
    ],
];
