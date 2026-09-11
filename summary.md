# FA-Toolkit WordPress Plugin Overview

This is a **custom WordPress plugin** for the Feather Arms e-commerce business - a management utility collection for WordPress and WooCommerce administration.

## Project Details
- **Name**: Feather Arms Toolkit
- **Version**: 1.2.4
- **Type**: WordPress Plugin (WooCommerce-focused)
- **License**: GPL v3.0
- **Author**: Stephen Feather

## Core Technologies
- **PHP** (66 files) with WordPress 5.x+
- **WooCommerce** integration
- **WordPress REST API** (custom endpoints)
- **WP-CLI** (command-line tools)
- **Composer** dependencies (jjgrainger/posttypes)

## Main Directory Structure
```
fa-toolkit/
├── src/                    # PSR-4 namespaced classes (8 directories)
│   ├── Admin/             # Admin UI customizations (8 files)
│   ├── Product/           # Product management (3 files)
│   ├── Promotion/         # Custom promotion post type (4 files)
│   ├── Rest/              # REST API endpoints
│   ├── Media/             # Media handling
│   ├── Modules/           # Plugin settings (5 files)
│   └── Utilities/         # General utilities (3 files)
├── includes/cli/          # WP-CLI commands (9 files)
├── fa_includes/           # Feature includes (10 files)
├── vendor/                # Composer dependencies
└── [config files]         # doxygen, phpstan, eslint
```

## Key Functionality

### Admin Interface
- Custom admin menu ordering for workflow efficiency
- Vendor column in product list (CSSI, Davidsons links)
- Product ID and category count displays
- SHA256 hash display for media attachments

### Product Management
- Auto word count calculation (`fa_word_count` meta field)
- Vendor/dealer tracking
- Custom product status support
- Batch operations via WP-CLI

### Media Management
- Remote image import via REST API (`fa-toolkit/v1/import-media-image/`)
- SHA256 hash verification for file integrity
- Automatic featured/gallery image assignment
- Media scraping from external sources

### Promotion System
- Custom "Promotion" post type (Gutenberg-compatible)
- Shares taxonomies with products (tags, brands)
- Custom meta boxes and functions

### WooCommerce Customizations
- State-based sales restrictions (US states only)
- Customer data formatting (uppercase names, formatted addresses)
- Checkout flow modifications
- Subcategory display control

### Plugin Integrations
Settings management for:
- WooCommerce, UpdraftPlus
- Perfect WooCommerce Bulk Editor
- Action Scheduler, Rank Math SEO
- ILab Media Cloud fixes

### CLI Tools
WP-CLI commands (`wp fa:*`):
- `wp fa:media product-thumbnail-check` - Find products without thumbnails
- `wp fa:media fetch-remote-media` - Import images
- `wp fa:tools merge-files` - Merge CSV/TSV files
- `wp fa:tools sort-csv-by-column` - Sort CSV data
- Product data scraping commands

### REST API Endpoint
- `POST fa-toolkit/v1/import-media-image/` (class-importmediaimage.php:1-327)
  - Downloads images from URLs
  - Generates SHA256 hashes
  - Assigns to products as featured/gallery images
  - Handles vendor URL parameter scrubbing

## Development Tools
- **PHPStan** (level 0) - Static analysis
- **ESLint** - JavaScript linting (complexity limit: 10)
- **Doxygen** - API documentation generation
- **GitHub Actions** - CI/CD workflows

## Architecture Patterns
- PSR-4 namespace convention (`FAToolkit\`, `FA\Toolkit\`)
- Two-level loader system (main + conditional CLI loading)
- Hook-based WordPress integration (actions/filters)
- Class auto-instantiation for hook registration
- REST API integration for remote operations

## Summary

This is a mature, production plugin built specifically for managing an e-commerce operation with advanced product management, media handling, data import/export, and extensive WooCommerce customizations.
