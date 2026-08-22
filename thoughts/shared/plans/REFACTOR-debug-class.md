# Refactoring Plan: Debug Class

**File:** `src/Utilities/class-debug.php`
**Current State:** Untestable, 0% coverage
**Goal:** Make testable with dependency injection and proper separation of concerns

## Problems to Fix

### 1. Auto-Instantiation (Line 120)

**Current:**
```php
// Instantiate to register hooks.
new Debug();
```

**Problem:**
- Class instantiates when file is loaded
- Prevents testing (can't load without triggering hooks)
- Violates single responsibility (file loading ≠ hook registration)

**Fix:**
```php
// Remove this line entirely
// Move instantiation to plugin bootstrap (fa-toolkit.php)
```

### 2. Hard-Coded Internal Function Calls

**Current (lines 32-38):**
```php
public static function write_log( $log ) {
    if ( TRUE === is_array( $log ) || TRUE === is_object( $log ) ) {
        error_log( wp_json_encode( $log ) ); // Hard-coded
    } else {
        error_log( $log ); // Hard-coded
    }
}
```

**Problem:**
- Cannot mock `error_log()` reliably in pure unit tests
- Patchwork causes hangs
- No way to verify output in tests

**Fix Option A - Dependency Injection:**
```php
public static function write_log( $log, callable $logger = null ) {
    $logger = $logger ?? 'error_log';

    if ( TRUE === is_array( $log ) || TRUE === is_object( $log ) ) {
        $logger( wp_json_encode( $log ) );
    } else {
        $logger( $log );
    }
}
```

**Fix Option B - Instance Method with Injected Logger:**
```php
class Debug {
    private $logger;

    public function __construct( callable $logger = null ) {
        $this->logger = $logger ?? 'error_log';
    }

    public function write_log( $log ) {
        if ( TRUE === is_array( $log ) || TRUE === is_object( $log ) ) {
            call_user_func( $this->logger, wp_json_encode( $log ) );
        } else {
            call_user_func( $this->logger, $log );
        }
    }
}
```

### 3. Global State Access (Lines 45-48)

**Current:**
```php
public static function wpdb() {
    global $wpdb;
    return $wpdb;
}
```

**Problem:**
- Direct global access
- Method serves no purpose (just returns global)
- Could return different values in tests vs runtime

**Fix:**
```php
// Remove this method entirely
// Users can access global $wpdb directly
// Or inject $wpdb as dependency where needed
```

### 4. Mixed Responsibilities

**Current:** Single class handles:
- Error logging (`write_log`)
- Database debugging (`var_dump_database`, `wpdb`)
- New Relic tracing (`add_custom_tracer`)
- JavaScript console output (`debug_to_console`)
- Shutdown hooks (`shutdown_handler`)

**Problem:**
- Violates Single Responsibility Principle
- Hard to test as unit
- Hard to maintain/extend

**Fix - Split into Focused Classes:**

```php
// 1. Logger class
class Logger {
    private $writer;

    public function __construct( callable $writer = null ) {
        $this->writer = $writer ?? 'error_log';
    }

    public function log( $message ) {
        if ( is_array( $message ) || is_object( $message ) ) {
            call_user_func( $this->writer, wp_json_encode( $message ) );
        } else {
            call_user_func( $this->writer, $message );
        }
    }
}

// 2. Database debugger
class DatabaseDebugger {
    private $wpdb;
    private $logger;

    public function __construct( $wpdb, Logger $logger ) {
        $this->wpdb = $wpdb;
        $this->logger = $logger;
    }

    public function log_queries() {
        $this->logger->log(
            array(
                'num_queries' => $this->wpdb->num_queries,
                'queries'     => $this->wpdb->queries,
            )
        );
    }
}

// 3. New Relic integration
class NewRelicTracer {
    public function add_tracer( string $function_name ): bool {
        if ( extension_loaded( 'newrelic' ) ) {
            newrelic_add_custom_tracer( $function_name );
            return true;
        }
        return false;
    }
}

// 4. Console debugger
class ConsoleDebugger {
    public function log( $data, string $context = 'Debug in Console' ): void {
        $script  = 'console.info(' . wp_json_encode( $context . ':' ) . ');';
        $script .= 'console.log(' . wp_json_encode( $data ) . ');';

        if ( function_exists( 'wp_print_inline_script_tag' ) ) {
            wp_print_inline_script_tag( $script );
        } else {
            printf(
                '%s',
                wp_kses(
                    sprintf( '<script>%s</script>', $script ),
                    array( 'script' => array() )
                )
            );
        }
    }
}

// 5. Debug coordinator (only if hooks needed)
class DebugCoordinator {
    private $db_debugger;

    public function __construct( DatabaseDebugger $db_debugger ) {
        $this->db_debugger = $db_debugger;
    }

    public function register_hooks(): void {
        add_action( 'shutdown', array( $this, 'shutdown_handler' ) );
    }

    public function shutdown_handler(): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'manage_options' ) ) {
            // Uncomment when needed:
            // $this->db_debugger->log_queries();
        }
    }
}
```

### 5. Hook Registration in Constructor (Lines 21-23)

**Current:**
```php
public function __construct() {
    add_action( 'shutdown', array( $this, 'shutdown_handler' ) );
}
```

**Problem:**
- Constructor has side effects
- Cannot instantiate without registering hooks
- Prevents testing

**Fix:**
```php
// Option A: Separate method
public function register_hooks(): void {
    add_action( 'shutdown', array( $this, 'shutdown_handler' ) );
}

// Option B: Constructor parameter
public function __construct( bool $register_hooks = true ) {
    if ( $register_hooks ) {
        add_action( 'shutdown', array( $this, 'shutdown_handler' ) );
    }
}

// Option C (Best): Move to bootstrap
// In fa-toolkit.php:
$debug_coordinator = new DebugCoordinator( $db_debugger );
$debug_coordinator->register_hooks();
```

## Recommended Approach

### Phase 1: Minimum Viable Refactor (Make Testable)

1. **Remove auto-instantiation** (line 120)
2. **Add optional logger parameter** to `write_log()`
3. **Make constructor hook registration optional**
4. **Move instantiation** to plugin bootstrap

**Result:** Class becomes testable with ~50 lines changed

### Phase 2: Full Refactor (Best Practices)

1. **Split into focused classes** (Logger, DatabaseDebugger, etc.)
2. **Use dependency injection** throughout
3. **Remove static methods** (make instance-based)
4. **Create factory/builder** for easy instantiation

**Result:** Clean architecture, 100% testable, follows SOLID principles

## Migration Path

### Step 1: Make Tests Pass (Minimum Refactor)

```php
class Debug {
    private $logger;
    private $register_hooks;

    public function __construct( callable $logger = null, bool $register_hooks = true ) {
        $this->logger = $logger ?? 'error_log';

        if ( $register_hooks ) {
            add_action( 'shutdown', array( $this, 'shutdown_handler' ) );
        }
    }

    public function write_log( $log ) {
        if ( is_array( $log ) || is_object( $log ) ) {
            call_user_func( $this->logger, wp_json_encode( $log ) );
        } else {
            call_user_func( $this->logger, $log );
        }
    }

    // Keep other methods unchanged for now
    // ...
}

// In tests/bootstrap.php or fa-toolkit.php:
// Only instantiate if not in test mode
if ( ! defined( 'PHPUNIT_RUNNING' ) ) {
    new Debug();
}
```

### Step 2: Write Tests

```php
class DebugTest extends TestCase {
    public function test_write_log_with_string() {
        $logged = [];
        $logger = function( $msg ) use ( &$logged ) {
            $logged[] = $msg;
        };

        $debug = new Debug( $logger, false );
        $debug->write_log( 'test message' );

        $this->assertEquals( ['test message'], $logged );
    }

    public function test_write_log_with_array() {
        $logged = [];
        $logger = function( $msg ) use ( &$logged ) {
            $logged[] = $msg;
        };

        Functions\expect( 'wp_json_encode' )
            ->once()
            ->with( ['key' => 'value'] )
            ->andReturn( '{"key":"value"}' );

        $debug = new Debug( $logger, false );
        $debug->write_log( ['key' => 'value'] );

        $this->assertEquals( ['{"key":"value"}'], $logged );
    }
}
```

### Step 3: Full Refactor (Optional)

Split into multiple focused classes as shown in "Fix - Split into Focused Classes" above.

## Estimated Impact

### Minimum Refactor
- **Lines Changed:** ~50
- **New Tests:** ~10
- **Coverage Gain:** 0% → ~85%
- **Risk:** Low (backward compatible)
- **Time:** 1-2 hours

### Full Refactor
- **Lines Changed:** ~200 (complete rewrite)
- **New Tests:** ~25
- **Coverage Gain:** 0% → 100%
- **Risk:** Medium (API changes)
- **Time:** 4-6 hours

## Recommendation

**Start with Minimum Refactor:**
1. Make testable with dependency injection
2. Write comprehensive tests
3. Get to 85%+ coverage
4. Ship it

**Later (if needed):**
- Full refactor to separate classes
- Only if Debug functionality expands significantly

The current class is small enough that full refactoring may be premature optimization.
