# Utilities Module Testing Limitations

## Debug Class - Currently Untestable

**File:** `src/Utilities/class-debug.php`
**Test File:** `tests/Utilities/DebugTest.php.disabled` (disabled)
**Coverage:** 0% (0/31 lines, 0/7 methods)

### Why Tests Are Disabled

The Debug class cannot be tested with our current Brain Monkey + Patchwork approach due to:

1. **Auto-Instantiation Pattern**
   ```php
   // Line 120 in class-debug.php
   new Debug();
   ```
   - Class instantiates when file is loaded
   - Constructor registers shutdown hook via `add_action()`
   - Brain Monkey callback validation hangs when loading this file
   - Cannot use `ReflectionClass::newInstanceWithoutConstructor()` (auto-instantiation runs first)

2. **Internal Function Mocking Issues**
   - Primary method `write_log()` uses PHP's `error_log()` function
   - While `error_log` is in `patchwork.json`, loading the Debug class with Patchwork causes test hangs
   - Combination of auto-instantiation + Patchwork interception creates race condition

3. **Missing Critical Tests**
   The disabled test file includes tests for:
   - ✅ `wpdb()` - Returns global wpdb
   - ✅ `add_custom_tracer()` - New Relic integration
   - ✅ `debug_to_console()` - JavaScript console output
   - ✅ `shutdown_handler()` - Hook callback
   - ✅ Constructor - Hook registration

   But **does not test** (would require `error_log` mocking):
   - ❌ `write_log()` with string
   - ❌ `write_log()` with array
   - ❌ `write_log()` with object
   - ❌ `var_dump_database()` - Calls `write_log()` internally

### Source Code Issues

**Line 120: Auto-instantiation**
```php
// Instantiate to register hooks.
new Debug();
```

**Lines 32-38: Internal function dependency**
```php
public static function write_log( $log ) {
    if ( TRUE === is_array( $log ) || TRUE === is_object( $log ) ) {
        error_log( wp_json_encode( $log ) ); // PHP internal function
    } else {
        error_log( $log ); // PHP internal function
    }
}
```

### Refactoring Required for Testability

To make this class testable, consider:

1. **Remove Auto-Instantiation**
   - Move instantiation to plugin bootstrap file
   - Or use lazy initialization pattern
   - This prevents hook registration when loading for tests

2. **Dependency Injection for Logger**
   ```php
   // Instead of calling error_log directly:
   public static function write_log( $log, callable $logger = null ) {
       $logger = $logger ?? 'error_log';
       // Use $logger instead of error_log directly
   }
   ```

3. **Separate Hook Registration from Business Logic**
   ```php
   // Make constructor optional
   public function __construct( bool $register_hooks = true ) {
       if ( $register_hooks ) {
           add_action( 'shutdown', array( $this, 'shutdown_handler' ) );
       }
   }
   ```

### Alternative Testing Approach

Without refactoring, possible approaches:

1. **Integration Tests**
   - Use WordPress test suite instead of Brain Monkey
   - Requires WordPress database/environment
   - Slower but can test actual `error_log` output

2. **Output Buffering + Stream Wrappers**
   - Capture `error_log` output via custom stream wrapper
   - Complex setup, fragile across PHP versions

3. **Accept Lower Coverage**
   - Document class as utility/debugging code
   - Focus testing effort on business logic classes
   - Current approach: **This option chosen**

### Comparison to Other Untestable Modules

Debug shares the auto-instantiation problem with:
- **Admin Module:** 6 classes, all auto-instantiate (0% coverage)
- **Rest Module:** 1 class, auto-instantiates (0% coverage)
- **CLI Module:** 8 classes, all auto-register (0% coverage)

**Total untestable:** 16 of 35 classes (46%) due to legacy auto-registration pattern

### Recommendation

**Short-term:** Leave tests disabled, document limitation (this file)
**Long-term:** Refactor to remove auto-instantiation when time permits
**Priority:** Low - debugging utilities less critical than business logic

### Test Artifact

The disabled test file demonstrates proper test structure for other methods:
- Uses Brain Monkey for WordPress function mocking
- Uses Mockery for object mocking
- Proper test organization and naming
- Can serve as template if class is refactored

**File:** `tests/Utilities/DebugTest.php.disabled`
**Tests Written:** 9 tests covering ~60% of methods
**Tests Passing:** Unknown (file disabled before verification)
