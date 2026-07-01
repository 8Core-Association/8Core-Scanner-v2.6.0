<?php
/**
 * 8Core Integrity — module manifest
 *
 * Loaded via `include` by Module Manager and sidebar.
 * Must return a PHP array — no echo, no side effects.
 */
return [
    'module_key'  => '8core-integrity',
    'name'        => '8Core Integrity',
    'version'     => '0.9.0',
    'description' => 'Core and file integrity checker using trusted repository comparison.',
    'admin_menu'  => [
        'label' => 'Integrity',
        'url'   => 'module.php?module=8core-integrity&page=module_integrity',
    ],
];
