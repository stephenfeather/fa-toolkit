# Admin Module — testing notes

## Status

Two of the six Admin classes now have running tests. The blocker this file used to
describe is gone.

| File | State |
|---|---|
| `class-attachment-sha256-hash-meta-box.php` | Tested — 3 tests, 66.67% methods / 94.59% lines |
| `class-product-display-id.php` | Tested — 4 tests, 66.67% methods / 77.78% lines |
| `class-product-display-vendor.php` | No test file |
| `class-product-category-counts.php` | No test file |
| `class-custom-admin-menu.php` | No test file |
| `class-admin-meta-boxes.php` | No test file |

## What changed

This document previously said all six files were untestable, for three stated reasons.
Two of the three no longer hold:

1. **Auto-instantiation at file load** — gone. Issue #18 removed every file-scope
   `new ClassName();` from `src/`. Verified: no file-scope instantiation remains in
   `src/Admin/` or `src/Rest/`.
2. **Brain Monkey callback validation hangs when loading these classes** — does not
   reproduce. Both test files load and run with no hang, no Patchwork loop.
3. **Hook registration in constructor** — still true, and still fine. Tests construct
   via `ReflectionClass::newInstanceWithoutConstructor()` where they want to bypass it.

The two test files sat parked as `*.php.disabled` from January 2026 (commit `daf9ab3`)
until #35. They were never re-checked after #18 landed.

## The real blocker, once the tests were enabled

Not Brain Monkey. Two mundane causes:

- **Stale expectations.** `Product_Display_IdTest` mocked `esc_html()`; the source
  escapes with `absint()`. The tests described an implementation that no longer existed.
- **A poisoned function name.** `tests/bootstrap.php` used to call
  `Functions\when( 'add_meta_box' )` at bootstrap scope. That binds the stub to the
  bootstrap-era Brain Monkey container while the defined function outlives every later
  `setUp()`/`tearDown()`. Calls to it then succeed and return the bootstrap value while
  Brain Monkey records nothing — so a later `Functions\expect( 'add_meta_box' )` reports
  "called 0 times" even though the code under test called it. Those bootstrap stubs are
  removed; see the comment in `tests/bootstrap.php` for the full explanation.

**Do not add `Functions\when()` calls at bootstrap scope.** Declare stubs inside the
test that needs them.

## Still open

- `class-attachment-sha256-hash-meta-box.php` registers
  `wp_ajax_generate_sha256_hash` → `array( $this, 'generate_sha256_hash' )`, but the
  class defines no `generate_sha256_hash()` method. The AJAX endpoint cannot work.
- `class-product-display-id.php` calls `ray()` at lines 38-39 — leftover debug output
  in production code.

Neither is fixed here; both predate #35 and neither is a test problem.

## Related

#24 (test strategy for Admin, Rest and CLI), #35 (this work), #18 (the refactor that
removed the auto-instantiation).
