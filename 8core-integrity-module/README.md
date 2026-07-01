# 8Core Integrity Module

**module_key:** `8core-integrity`
**version:** `0.1.0`

## Overview

8Core Integrity is a module for 8Core Scanner that performs core and file integrity checks by comparing a trusted repository against a live installation.

## Installation

1. ZIP the contents of this directory (so that `module.php` is at the root of the ZIP).
2. In the 8Core Scanner admin panel, go to **Module Manager → Upload modula (ZIP)**.
3. Upload the ZIP file.
4. Click **Install** in the "Dostupni moduli" section.
5. Click **Enable** to activate. The **Integrity** link will appear in the sidebar.

## Structure

```
8core-integrity-module/
├── module.php                      — manifest
├── admin/
│   └── module_integrity.php        — admin UI
├── includes/
│   └── integrity.php               — helper functions
├── README.md
└── CHANGELOG.md
```

When installed via Module Manager, files are placed at:
```
scanner/modules/8core-integrity/
```

## Repository Structure

Default repo root: `/home/8core_integrity/repo`

Default tree created by "Create repository structure":
```
/home/8core_integrity/repo/
├── joomla/
│   ├── v3x/
│   ├── v4x/
│   └── v5x/
├── wordpress/
│   ├── v6x/
│   └── v7x/
├── whmcs/
└── prestashop/
```

## Integrity Check (planned)

Origin (trusted repo):
```
/home/8core_integrity/repo/joomla/v4x
```

Destination (live installation):
```
/home/buckhr/public_html
```

The check will compare:
- File presence (missing / extra files)
- File hashes (modified core files)

Actions planned for future versions:
- Ignore finding
- Replace from repository
- Quarantine / delete

## Requirements

- PHP 7.4+
- 8Core Scanner v2.6.6+
- Write access to `/home/8core_integrity/repo` for repository creation
