# CLI Module - Untestable with Current Approach

## Status: 0% Coverage (0 of 8 files testable)

All CLI command files are **untestable** using the current pure unit testing approach with Brain Monkey.

## The Problem

ALL 8 CLI command files follow the same problematic pattern:

1. **Auto-Registration at File Load**: Commands register themselves when the file is loaded:
   - Function-based: `\WP_CLI::add_command('name', __NAMESPACE__ . '\function_name');`
   - Class-based: `\WP_CLI::add_command('name', array(new Class(), 'method'));`
   - Constructor-based: `new Class()` where constructor calls `\WP_CLI::add_command()`

2. **Brain Monkey Callback Validation**: When PHPUnit loads these files, Brain Monkey attempts to validate the callback functions/methods, causing the test suite to hang indefinitely.

3. **Cannot Load Without Registration**: Unlike the Debug class where we can test individual functions, CLI commands are tightly coupled to WP-CLI registration.

## Files Affected

### CLI/Tools (2 files)
- **class-tools.php**: 3 function-based commands
  - Bug: Lines 100, 134, 143 - Assignment operator in wrong position: `false !== ( fgetcsv(...) === $row )` should be `false !== ( $row = fgetcsv(...) )`

- **class-exportacffield.php**: Class with auto-instantiation
  - Pattern: `new ExportACFField();` at line 114 triggers `\WP_CLI::add_command()` in constructor (line 34)

### CLI/Media (5 files)
- **class-findmediaforproduct.php**: Function-based command + helpers
  - Bug: Line 111 - undefined variable `$success`
  - Bug: Lines 153, 155 - undefined variables `$matches`, `$fails` (scoped to outer function)

- **class-scrapeproductmedia.php**: Class with auto-instantiation
  - Pattern: `\WP_CLI::add_command('fa:media scrape-product-media', array(new ScrapeProductMedia(), 'wp_cli_scrape_product_media'));` at line 442
  - Debug code: Line 320 - `die( 'Security (fhi4d6): File addressed directly.' );` statement in middle of `import_media()` method

- **class-exportdraftproductimagesources.php**: Function-based command
  - Bug: Line 62 - undefined variable `$wp_filesystem` (should be declared global)

- **class-fetchimportproductimage.php**: Function-based command + helpers
  - Bug: Line 17 - Bitwise AND `( defined( 'WP_CLI' ) & WP_CLI )` should be `&&`
  - Bug: Line 75 - undefined variable `$filename` (used before definition)
  - Bug: Line 104 - Logic error in hash comparison: `if ( 'hash1' || 'hash2' === $hash )` should be `if ( 'hash1' === $hash || 'hash2' === $hash )`

- **class-attachmediatodraftproducts.php**: Function-based command + helpers
  - Cleanest implementation, no obvious bugs

### CLI/Commands (1 file)
- **class-scrapeproductdata.php**: Class extending `\WP_CLI_Command`
  - Pattern: `\WP_CLI::add_command('scrape_product_data', __NAMESPACE__ . '\Scrape_Product_Data_Command');` at line 155
  - Bug: Line 131 - calls `$this->import_media()` but method is never defined

## Why Standard Approaches Don't Work

### Approach 1: Load and Test (Current Attempt)
```php
require_once 'src/CLI/Tools/class-tools.php';
// Result: Test hangs indefinitely during Brain Monkey callback validation
```

### Approach 2: Mock WP_CLI::add_command
```php
Brain\Monkey\Functions\expect('WP_CLI::add_command')->zeroOrMoreTimes();
// Result: Brain Monkey cannot mock static class methods reliably
```

### Approach 3: ReflectionClass
```php
// Cannot work - auto-registration happens at file load, before reflection can intervene
```

### Approach 4: Stub WP_CLI Class
```php
// Already implemented in tests/bootstrap.php
// Result: Doesn't prevent callback validation hang
```

## What Would Make This Testable

### Option 1: Refactor to Lazy Registration
```php
// Instead of auto-registering at file load
\WP_CLI::add_command('name', __NAMESPACE__ . '\function_name');

// Use a registration function called by a bootstrap file
function register_commands() {
    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::add_command('name', __NAMESPACE__ . '\function_name');
    }
}
```

### Option 2: Dependency Injection
```php
class ExportACFField {
    public function __construct($cli = null) {
        $this->cli = $cli ?? \WP_CLI::class;
    }

    public function register() {
        $this->cli::add_command('name', [$this, 'method']);
    }
}

// In tests, inject a mock CLI
```

### Option 3: Integration Tests
Use WP-CLI's test framework instead of pure unit tests with Brain Monkey.

## Coverage Impact

- **Total CLI files**: 8
- **Testable files**: 0
- **Untestable files**: 8 (100%)
- **Phase 10 coverage**: 0%

## Related Modules with Same Issue

- **Admin Module**: 6 files (auto-instantiation with hook callbacks)
- **Rest Module**: 1 file (auto-instantiation with REST registration)
- **Utilities/Debug**: 1 file (internal function mocking causes hangs)

**Total untestable files across project**: 16 of ~36 classes (44%)

## Recommendation

**Accept 0% coverage** for CLI module with current testing approach. Document bugs for future refactoring:

1. Fix syntax errors (class-tools.php)
2. Fix undefined variables (multiple files)
3. Fix logic errors (class-fetchimportproductimage.php)
4. Remove debug code (class-scrapeproductmedia.php)
5. Refactor to remove auto-registration pattern
6. Add integration tests using WP-CLI test framework

## Test Artifacts

- `tests/CLI/README.md`: This documentation
- No test files created (all would hang test suite)
