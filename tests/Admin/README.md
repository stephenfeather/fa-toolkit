# Admin Module - Untestable with Current Approach

## Status: 0% Coverage (0 of 6 files testable)

All 6 Admin module files follow the same pattern that makes them untestable with Brain Monkey unit tests:

1. **Auto-instantiation at file load**: Each file ends with `new ClassName();`
2. **Hook registration in constructor**: Constructors call `add_action()` / `add_filter()` with object method callbacks
3. **Brain Monkey limitation**: Callback validation hangs when loading classes with this pattern

## Affected Files

1. `class-attachment-sha256-hash-meta-box.php` - Test file disabled
2. `class-product-display-id.php` - Test file disabled
3. `class-product-display-vendor.php` - Untested
4. `class-product-category-counts.php` - Untested
5. `class-custom-admin-menu.php` - Untested
6. `class-admin-meta-boxes.php` - Untested

## The Problem

When PHPUnit loads any of these source files to test them:

```php
// Source file structure:
class Product_Display_Id {
    public function __construct() {
        add_action('hook', array($this, 'method'));  // ← Brain Monkey hangs here
    }
}

new Product_Display_Id();  // ← Auto-executes constructor on file load
```

Even using `ReflectionClass::newInstanceWithoutConstructor()` doesn't help because the auto-instantiation at the bottom of each file executes before the test class loads.

## Additional Issues Found

- **class-attachment-sha256-hash-meta-box.php**: Missing `generate_sha256_hash()` method referenced in constructor
- **class-product-display-id.php**: Contains debug `ray()` calls (lines 38-39)

## Recommendations

To make these classes testable:

1. **Refactor**: Remove auto-instantiation and create an initialization function
2. **Dependency Injection**: Pass dependencies instead of using global `add_action`
3. **Integration Tests**: Use WordPress test suite instead of unit tests
4. **Accept Lower Coverage**: Document these files as untestable legacy code

## Similar Issues in Other Modules

- **Utilities/Debug**: Untestable due to PHP internal function mocking issues
- **Rest/ImportMediaImage**: Untestable due to bugs + auto-instantiation + complexity

## Test Files

Disabled test files can be found with `.disabled` extension:
- `Attachment_SHA256_Hash_Meta_BoxTest.php.disabled`
- `Product_Display_IdTest.php.disabled`
