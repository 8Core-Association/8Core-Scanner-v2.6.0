<?php
/**
 * 8Core Integrity — module manifest
 *
 * This file is loaded via `include` by the Module Manager and sidebar.
 * Must return a PHP array — no echo, no side effects.
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
