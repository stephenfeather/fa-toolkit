# Issue #77: Remove ACF `get_field()` Dependency Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore the wp-admin product list (empty on staging because the Vendor column fatals on `get_field()`), and eliminate every remaining ACF call in the plugin so no other hook can die the same way.

**Architecture:** Every `get_field( $name, $post_id )` becomes `get_post_meta( $post_id, $key, true )` where `$key` is the postmeta key the new import writes, as reported by `@wp-import` (see Pre-flight). No shim, no ACF-presence check and no new abstraction. The two `wc_get_product()` results that are dereferenced blind get a `false` guard. A tokenizer-based regression test asserts `src/` contains no ACF function call so the class of bug cannot return.

**Tech Stack:** PHP 8.x, WordPress plugin, WooCommerce, PHPUnit 11, Brain\Monkey 2.6 (`Functions\expect` / `Functions\when`), Mockery, PHPCS (WPCS 3.4.1).

**Spec:** GitHub issue #77 — https://github.com/stephenfeather/fa-toolkit/issues/77 (no separate spec document; the issue body is the spec).

## Global Constraints

- Tests are isolated unit tests: WordPress functions are stubbed per-test with Brain\Monkey. **Never** add `Functions\when()` stubs to `tests/bootstrap.php` (see the note in that file).
- Stubs are declared inside the test that needs them; `Functions\expect( 'get_field' )` must not survive anywhere in `tests/` once this plan is done.
- Commits are made only with `github-agent-commit "<headline>"` on a feature branch off `develop` (the repo's default branch; there is no `main`). Plain `git commit` is forbidden.
- Coding standard: WPCS via `composer lint` must pass on every touched file. Tabs, Yoda conditions, `esc_*` on output.
- File guards, namespaces and class naming follow the existing file exactly; do not restructure files.
- Do not fix unrelated violations noticed along the way (`deferred-fix` rule); flag with a `TODO` and move on.
- `@since` on new public methods/tests: `1.2.1`.

---

## Pre-flight: get the meta keys from wp-import (do this first, no code)

The store is on a new import configuration (Super Speedy Imports, owned by the `wp-import` agent), not ACF. The postmeta keys that now hold the former ACF fields are **not** assumed from in-repo evidence; `src/Media/class-productthumbnailchecker.php:89` querying `meta_key = 'dealer'` is ACF-era code and proves nothing about the new import.

Ask team-lead to get from `@wp-import` the exact `meta_key` strings for:

| Former ACF field | Used by | Meta key (fill in from wp-import) |
|---|---|---|
| `dealer` (vendor: `CSSI`, `Davidsons`) | Vendor column, bulk editor, CLI media commands | `DEALER_KEY` |
| `upc_code` | Bard meta box, bulk editor | `UPC_KEY` |
| `image_source` | CLI media commands | `IMAGE_SOURCE_KEY` |

Every `'dealer'`, `'upc_code'` and `'image_source'` string in the code and test snippets below is a placeholder for the answer. If wp-import reports a field is not written at all, the code path that reads it returns `''` and the PR body says so.

Confirm the answer on the **local staging instance** (not Vanguard, which is only where #77 was reproduced):

```bash
wp post meta get <a product id> <DEALER_KEY>
wp db query "SELECT meta_key, COUNT(*) FROM wp_postmeta WHERE meta_key IN ('<DEALER_KEY>','<UPC_KEY>','<IMAGE_SOURCE_KEY>') GROUP BY meta_key"
```

Expected: the dealer key returns a vendor string such as `CSSI` or `Davidsons`; the count query shows non-zero rows.

If `DEALER_KEY` differs from `dealer`, `class-productthumbnailchecker.php:89` has the same defect and needs its own follow-up issue (added to Task 6 Step 4).

## File Structure

| File | Change | Responsibility |
|---|---|---|
| `src/Admin/class-product-display-vendor.php` | Modify | Vendor column: swap ACF for postmeta, guard `wc_get_product()`, read vendor once |
| `tests/Admin/Product_Display_VendorTest.php` | Create | First tests for this class: column registration, content, URL generation, guard |
| `src/Product/class-bard-meta-box.php` | Modify | `upc_code` from postmeta |
| `tests/Product/Bard_Meta_BoxTest.php` | Modify | Replace `get_field` expectations |
| `src/Modules/class-pwbulkeditorsettings.php` | Modify | `upc_code` and `dealer` from postmeta |
| `tests/Modules/PWBulkEditorSettingsTest.php` | Modify | Replace `get_field` expectations |
| `src/CLI/Media/class-fetchimportproductimagecommand.php` | Modify | `image_source`/`dealer` from postmeta, guard `wc_get_product()` |
| `src/CLI/Media/class-scrapeproductmedia.php` | Modify | `dealer` from postmeta |
| `src/CLI/Media/class-exportdraftproductimagesourcescommand.php` | Modify | `image_source` from postmeta |
| `src/CLI/Tools/class-exportacffield.php` | Modify | Export by meta key; docblocks stop claiming ACF |
| `tests/Plugin/AcfCallSitesTest.php` | Create | Regression net: no ACF function call anywhere under `src/` |
| `tests/Support/AssertsNoAcfFunctionCalls.php` | Create | Tokenizer assertion used by the regression test |

Task order is by blast radius: the admin fatal first (ships alone if needed), then the product edit screen, then the bulk editor, then CLI, then the regression net that proves everything is gone.

---

### Task 0: Branch

**Files:** none

- [ ] **Step 1: Create the feature branch from develop**

```bash
git switch -c fix/77-remove-acf-get-field develop
```

Expected: `Switched to a new branch 'fix/77-remove-acf-get-field'`.

- [ ] **Step 2: Confirm the suite is green before touching anything**

Run: `composer test`
Expected: `OK (N tests, M assertions)` with no failures. Note N for later.

---

### Task 1: Vendor column reads postmeta and survives a missing product

**Files:**
- Modify: `src/Admin/class-product-display-vendor.php:46-74`
- Create: `tests/Admin/Product_Display_VendorTest.php`

**Interfaces:**
- Consumes: WordPress `get_post_meta( int $post_id, string $key, bool $single )`, WooCommerce `wc_get_product( int )` returning `WC_Product|false`.
- Produces: `Product_Display_Vendor::generate_vendor_url( int $post_id ): string`, `Product_Display_Vendor::add_vendor_column_content( string $column, int $post_id ): void`, `Product_Display_Vendor::add_vendor_column( array $columns ): array`. Signatures unchanged; only internals change.

- [ ] **Step 1: Write the failing tests**

Create `tests/Admin/Product_Display_VendorTest.php`:

```php
<?php
/**
 * Tests for Product_Display_Vendor class.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Admin;

use FAToolkit\Admin\Product_Display_Vendor;
use FAToolkit\Tests\TestCase;
use Brain\Monkey\Functions;
use Mockery;
use ReflectionClass;

/**
 * Test Product_Display_Vendor class.
 *
 * Issue #77: this column called ACF's get_field() on every product-list row.
 * ACF is not installed, so the whole wp-admin product list rendered empty.
 * These tests pin the postmeta read and the wc_get_product() guard.
 *
 * @since 1.2.1
 */
class Product_Display_VendorTest extends TestCase {

	/**
	 * Create instance without calling the hook-registering constructor.
	 *
	 * @return Product_Display_Vendor
	 */
	private function create_instance_without_constructor() {
		$reflection = new ReflectionClass( Product_Display_Vendor::class );
		return $reflection->newInstanceWithoutConstructor();
	}

	/**
	 * Build a product double that reports the given SKU.
	 *
	 * @param string $sku SKU to return from get_sku().
	 * @return \Mockery\MockInterface
	 */
	private function product_with_sku( $sku ) {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_sku' )->andReturn( $sku );
		return $product;
	}

	/**
	 * The column is appended under the 'vendor' key with a 'Vendor' label.
	 */
	public function test_add_vendor_column_appends_vendor_column() {
		$instance = $this->create_instance_without_constructor();

		$result = $instance->add_vendor_column( array( 'title' => 'Title' ) );

		$this->assertSame( array( 'title' => 'Title', 'vendor' => 'Vendor' ), $result );
	}

	/**
	 * Vendor comes from postmeta key 'dealer', never from ACF.
	 */
	public function test_generate_vendor_url_reads_dealer_from_postmeta() {
		$instance = $this->create_instance_without_constructor();

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 185, 'dealer', true )
			->andReturn( 'CSSI' );
		Functions\expect( 'wc_get_product' )
			->once()
			->with( 185 )
			->andReturn( $this->product_with_sku( 'ABC123' ) );
		Functions\expect( 'get_field' )->never();

		$url = $instance->generate_vendor_url( 185 );

		$this->assertSame(
			'https://chattanoogashooting.com/catalog/lookup?propertyKey=sku&valueKey=ABC123',
			$url
		);
	}

	/**
	 * Davidsons vendors link to the Davidsons catalogue search.
	 */
	public function test_generate_vendor_url_for_davidsons() {
		$instance = $this->create_instance_without_constructor();

		Functions\when( 'get_post_meta' )->justReturn( 'Davidsons' );
		Functions\when( 'wc_get_product' )->justReturn( $this->product_with_sku( 'DAV-9' ) );

		$this->assertSame(
			'https://www.davidsonsinc.com/catalogsearch/result/?q=DAV-9',
			$instance->generate_vendor_url( 42 )
		);
	}

	/**
	 * An unknown vendor yields an empty URL rather than the old 'https:// foobar.baz' junk.
	 */
	public function test_generate_vendor_url_returns_empty_for_unknown_vendor() {
		$instance = $this->create_instance_without_constructor();

		Functions\when( 'get_post_meta' )->justReturn( 'Some Other Vendor' );
		Functions\when( 'wc_get_product' )->justReturn( $this->product_with_sku( 'X' ) );

		$this->assertSame( '', $instance->generate_vendor_url( 42 ) );
	}

	/**
	 * wc_get_product() returns false for a deleted or non-product id; that must not fatal.
	 */
	public function test_generate_vendor_url_returns_empty_when_product_missing() {
		$instance = $this->create_instance_without_constructor();

		Functions\when( 'get_post_meta' )->justReturn( 'CSSI' );
		Functions\expect( 'wc_get_product' )->once()->with( 999 )->andReturn( false );

		$this->assertSame( '', $instance->generate_vendor_url( 999 ) );
	}

	/**
	 * Column content is an escaped link wrapping the vendor name.
	 */
	public function test_add_vendor_column_content_outputs_link_for_vendor_column() {
		$instance = $this->create_instance_without_constructor();

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 185, 'dealer', true )
			->andReturn( 'CSSI' );
		Functions\when( 'wc_get_product' )->justReturn( $this->product_with_sku( 'ABC123' ) );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\expect( 'get_field' )->never();

		ob_start();
		$instance->add_vendor_column_content( 'vendor', 185 );
		$output = ob_get_clean();

		$this->assertSame(
			'<a href="https://chattanoogashooting.com/catalog/lookup?propertyKey=sku&valueKey=ABC123" target="_blank" rel="noopener noreferrer">CSSI</a>',
			$output
		);
	}

	/**
	 * Nothing is read or printed for other columns.
	 */
	public function test_add_vendor_column_content_outputs_nothing_for_other_column() {
		$instance = $this->create_instance_without_constructor();

		Functions\expect( 'get_post_meta' )->never();
		Functions\expect( 'wc_get_product' )->never();

		ob_start();
		$instance->add_vendor_column_content( 'title', 185 );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * A product with no vendor meta renders an empty, non-fatal cell.
	 */
	public function test_add_vendor_column_content_handles_missing_vendor_meta() {
		$instance = $this->create_instance_without_constructor();

		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'wc_get_product' )->justReturn( $this->product_with_sku( 'ABC123' ) );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		ob_start();
		$instance->add_vendor_column_content( 'vendor', 185 );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}
}
```

Note on `get_post_meta` being called once in `test_generate_vendor_url_reads_dealer_from_postmeta` but the column-content test also expecting `once()`: the implementation below reads the vendor once in `add_vendor_column_content` and passes it into a private URL builder, while the public `generate_vendor_url( $post_id )` keeps its signature and does its own single read. Both paths read exactly once.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Admin/Product_Display_VendorTest.php`
Expected: FAIL. `test_generate_vendor_url_reads_dealer_from_postmeta` fails with Brain\Monkey reporting `get_field` was called (expectation `never()` violated) or an undefined-function error on `get_field`; `test_generate_vendor_url_returns_empty_when_product_missing` errors with `Call to a member function get_sku() on false`; the unknown-vendor test fails on `'https:// foobar.baz'`.

- [ ] **Step 3: Rewrite the two methods**

Replace lines 40-74 of `src/Admin/class-product-display-vendor.php` with:

```php
	/**
	 * Adds the vendor column content to the product list table.
	 *
	 * @param string $column The column name.
	 * @param int    $post_id The post ID.
	 */
	public function add_vendor_column_content( $column, $post_id ) {
		if ( 'vendor' !== $column ) {
			return;
		}

		$vendor = $this->get_vendor( $post_id );
		if ( '' === $vendor ) {
			return;
		}

		$vendor_url = $this->build_vendor_url( $vendor, $this->get_sku( $post_id ) );
		echo '<a href="' . esc_url( $vendor_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $vendor ) . '</a>';
	}

	/**
	 * Generates the vendor URL.
	 *
	 * @param int $post_id The post ID.
	 * @return string $url The vendor URL, or '' when the vendor is unknown or the product is missing.
	 */
	public function generate_vendor_url( $post_id ) {
		$sku = $this->get_sku( $post_id );
		if ( '' === $sku ) {
			return '';
		}

		return $this->build_vendor_url( $this->get_vendor( $post_id ), $sku );
	}

	/**
	 * Reads the vendor name from postmeta.
	 *
	 * The value was written by ACF under the field name 'dealer'. ACF is no
	 * longer installed, so it is read as plain postmeta (issue #77).
	 *
	 * @since 1.2.1
	 *
	 * @param int $post_id The post ID.
	 * @return string Vendor name, or '' when unset.
	 */
	private function get_vendor( $post_id ) {
		return (string) get_post_meta( $post_id, 'dealer', true );
	}

	/**
	 * Reads the product SKU, tolerating a missing product.
	 *
	 * wc_get_product() returns false for a deleted or non-product id.
	 *
	 * @since 1.2.1
	 *
	 * @param int $post_id The post ID.
	 * @return string SKU, or '' when the product cannot be loaded.
	 */
	private function get_sku( $post_id ) {
		$product = wc_get_product( $post_id );
		if ( false === $product || null === $product ) {
			return '';
		}

		return (string) $product->get_sku();
	}

	/**
	 * Builds the vendor catalogue URL for a known vendor.
	 *
	 * @since 1.2.1
	 *
	 * @param string $vendor Vendor name as stored in postmeta.
	 * @param string $sku    Product SKU.
	 * @return string URL, or '' for an unknown vendor.
	 */
	private function build_vendor_url( $vendor, $sku ) {
		if ( 'CSSI' === $vendor ) {
			return 'https://chattanoogashooting.com/catalog/lookup?propertyKey=sku&valueKey=' . $sku;
		}

		if ( 'Davidsons' === $vendor ) {
			return 'https://www.davidsonsinc.com/catalogsearch/result/?q=' . $sku;
		}

		return '';
	}
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Admin/Product_Display_VendorTest.php`
Expected: `OK (8 tests, ...)`.

- [ ] **Step 5: Lint**

Run: `vendor/bin/phpcs src/Admin/class-product-display-vendor.php tests/Admin/Product_Display_VendorTest.php`
Expected: no errors. Fix any reported and re-run.

- [ ] **Step 6: Commit**

```bash
git add src/Admin/class-product-display-vendor.php tests/Admin/Product_Display_VendorTest.php
github-agent-commit "Read the Vendor column from postmeta instead of ACF and guard a missing product (#77)"
```

---

### Task 2: Bard meta box reads `upc_code` from postmeta

**Files:**
- Modify: `src/Product/class-bard-meta-box.php:48`
- Modify: `tests/Product/Bard_Meta_BoxTest.php:64-67,119-122`

**Interfaces:**
- Consumes: `get_post_meta()`.
- Produces: `Bard_Meta_Box::render_meta_box( WP_Post $post ): void` unchanged.

- [ ] **Step 1: Change the tests to expect postmeta**

In `tests/Product/Bard_Meta_BoxTest.php`, replace the block at lines 59-67:

```php
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 123, '_sku', true )
			->andReturn( 'TEST-SKU-123' );

		Functions\expect( 'get_field' )
			->once()
			->with( 'upc_code', 123 )
			->andReturn( '123456789012' );
```

with:

```php
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 123, '_sku', true )
			->andReturn( 'TEST-SKU-123' );

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 123, 'upc_code', true )
			->andReturn( '123456789012' );

		Functions\expect( 'get_field' )->never();
```

And the block at lines 114-122:

```php
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 456, '_sku', true )
			->andReturn( 'SKU-456' );

		Functions\expect( 'get_field' )
			->once()
			->with( 'upc_code', 456 )
			->andReturn( '999999999999' );
```

with:

```php
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 456, '_sku', true )
			->andReturn( 'SKU-456' );

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 456, 'upc_code', true )
			->andReturn( '999999999999' );

		Functions\expect( 'get_field' )->never();
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Product/Bard_Meta_BoxTest.php`
Expected: FAIL in both render tests: `get_field` was called (never() violated) and the `upc_code` `get_post_meta` expectation was not met.

- [ ] **Step 3: Change the source**

In `src/Product/class-bard-meta-box.php` line 48, replace:

```php
		$upc    = get_field( 'upc_code', $post->ID );
```

with:

```php
		$upc    = get_post_meta( $post->ID, 'upc_code', true );
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Product/Bard_Meta_BoxTest.php`
Expected: `OK (3 tests, ...)`.

- [ ] **Step 5: Lint and commit**

Run: `vendor/bin/phpcs src/Product/class-bard-meta-box.php tests/Product/Bard_Meta_BoxTest.php`
Expected: no errors.

```bash
git add src/Product/class-bard-meta-box.php tests/Product/Bard_Meta_BoxTest.php
github-agent-commit "Read the Bard meta box UPC from postmeta instead of ACF (#77)"
```

---

### Task 3: PW Bulk Editor columns read postmeta

**Files:**
- Modify: `src/Modules/class-pwbulkeditorsettings.php:98,113`
- Modify: `tests/Modules/PWBulkEditorSettingsTest.php:146-149,167-170`

**Interfaces:**
- Consumes: `get_post_meta()`.
- Produces: `pwbe_results_product_acf_upc( object $pwbe_product, array $column ): object` and `pwbe_results_product_acf_dealer( object $pwbe_product, array $column ): object` unchanged. Method names keep the `acf_` prefix because the column `field` ids (`acf_dealer`, `acf_upc_code`) are part of the PW Bulk Editor filter contract; renaming them is out of scope.

Note: `pwbe_results_product_acf_upc` assigns to `$pwbe_product->acf_dealer` rather than an upc property. That is a pre-existing bug unrelated to ACF removal. Do not fix it here; add `// TODO: writes upc into acf_dealer; see follow-up issue.` above the assignment and record it in the PR body.

- [ ] **Step 1: Change the tests to expect postmeta**

In `tests/Modules/PWBulkEditorSettingsTest.php`, replace lines 146-149:

```php
		Functions\expect( 'get_field' )
			->once()
			->with( 'upc_code', 123 )
			->andReturn( '1234567890' );
```

with:

```php
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 123, 'upc_code', true )
			->andReturn( '1234567890' );
		Functions\expect( 'get_field' )->never();
```

and lines 167-170:

```php
		Functions\expect( 'get_field' )
			->once()
			->with( 'dealer', 123 )
			->andReturn( 'Test Dealer' );
```

with:

```php
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 123, 'dealer', true )
			->andReturn( 'Test Dealer' );
		Functions\expect( 'get_field' )->never();
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Modules/PWBulkEditorSettingsTest.php`
Expected: FAIL in `test_pwbe_results_product_acf_upc` and `test_pwbe_results_product_acf_dealer`.

- [ ] **Step 3: Change the source**

In `src/Modules/class-pwbulkeditorsettings.php` line 98, replace:

```php
			$result                   = get_field( 'upc_code', $pwbe_product->post_id );
```

with:

```php
			$result                   = get_post_meta( $pwbe_product->post_id, 'upc_code', true );
			// TODO: writes upc into acf_dealer; see follow-up issue.
```

Line 113, replace:

```php
			$result                   = get_field( 'dealer', $pwbe_product->post_id );
```

with:

```php
			$result                   = get_post_meta( $pwbe_product->post_id, 'dealer', true );
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Modules/PWBulkEditorSettingsTest.php`
Expected: `OK (8 tests, ...)`.

- [ ] **Step 5: Lint and commit**

Run: `vendor/bin/phpcs src/Modules/class-pwbulkeditorsettings.php tests/Modules/PWBulkEditorSettingsTest.php`
Expected: no errors.

```bash
git add src/Modules/class-pwbulkeditorsettings.php tests/Modules/PWBulkEditorSettingsTest.php
github-agent-commit "Read PW Bulk Editor distributor and UPC columns from postmeta instead of ACF (#77)"
```

---

### Task 4: CLI media commands read postmeta and guard a missing product

**Files:**
- Modify: `src/CLI/Media/class-fetchimportproductimagecommand.php:87-88,221-224`
- Modify: `src/CLI/Media/class-scrapeproductmedia.php:171`
- Modify: `src/CLI/Media/class-exportdraftproductimagesourcescommand.php:74`
- Modify: `src/CLI/Tools/class-exportacffield.php:26-64`

**Interfaces:**
- Consumes: `get_post_meta()`, `wc_get_product()`.
- Produces: no public signature changes. `wp fa:tools export-acf-field <field_key>` keeps its command name and argument for backward compatibility; `<field_key>` is now documented as the postmeta key.

These four classes have no unit tests today (their existing tests under `tests/Autoload/` only prove autoload behaviour). Writing full command tests is out of scope for a critical fix; the regression net in Task 5 covers the ACF removal for them, and the `wc_get_product()` guard in `get_dealer_image_url()` is private and reached only through the WP-CLI command. Record "needs test coverage" TODOs as shown.

- [ ] **Step 1: `class-fetchimportproductimagecommand.php`**

Lines 87-88, replace:

```php
		// Get image source from ACF or construct from dealer.
		$image_source = get_field( 'image_source', $product_id );
```

with:

```php
		// Get image source from postmeta (written by the former ACF field) or construct from dealer.
		$image_source = get_post_meta( $product_id, 'image_source', true );
```

Lines 221-224, replace:

```php
	private function get_dealer_image_url( $product_id, $extension, $suffix = '' ) {
		$dealer  = strtolower( get_field( 'dealer', $product_id ) );
		$product = wc_get_product( $product_id );
		$sku     = $product->get_sku();
```

with:

```php
	private function get_dealer_image_url( $product_id, $extension, $suffix = '' ) {
		// TODO: needs test coverage (issue #77 follow-up).
		$dealer  = strtolower( (string) get_post_meta( $product_id, 'dealer', true ) );
		$product = wc_get_product( $product_id );
		if ( false === $product || null === $product ) {
			\WP_CLI::debug( "Product {$product_id} could not be loaded; no dealer image URL." );
			return '';
		}
		$sku     = $product->get_sku();
```

- [ ] **Step 2: `class-scrapeproductmedia.php` line 171**

Replace:

```php
		$distributor = get_field( 'dealer', $product_id );
```

with:

```php
		$distributor = get_post_meta( $product_id, 'dealer', true );
```

- [ ] **Step 3: `class-exportdraftproductimagesourcescommand.php` line 74**

Replace:

```php
			$image_source = get_field( 'image_source', $product_id );
```

with:

```php
			$image_source = get_post_meta( $product_id, 'image_source', true );
```

- [ ] **Step 4: `class-exportacffield.php`**

Line 64, replace:

```php
			$acf_value  = get_field( $acf_field_key, $product_id );
```

with:

```php
			$acf_value  = get_post_meta( $product_id, $acf_field_key, true );
```

Lines 39-49, replace the docblock:

```php
	/**
	 * Export ACF field values .
	 *
	 * ## OPTIONS
	 *
	 * <field_key>
	 * : The ACF field key to export.
	 *
	 * // EXAMPLES
	 *
	 * wp fa:tools export-acf-field your_acf_field_key
```

with:

```php
	/**
	 * Export a postmeta value from all WooCommerce products.
	 *
	 * The command keeps its historical name. ACF is no longer installed, so
	 * <field_key> is the postmeta key ACF wrote the field under (its field
	 * name, e.g. `dealer`), read with get_post_meta(). See issue #77.
	 *
	 * ## OPTIONS
	 *
	 * <field_key>
	 * : The postmeta key to export.
	 *
	 * // EXAMPLES
	 *
	 * wp fa:tools export-acf-field dealer
```

Line 26, replace `Export ACF field values from all WooCommerce products.` with `Export a postmeta value (formerly an ACF field) from all WooCommerce products.`

- [ ] **Step 5: Confirm no `get_field` remains in `src/`**

Run: `rg -n "get_field\(" src/`
Expected: no output.

- [ ] **Step 6: Lint, run the full suite, commit**

Run: `vendor/bin/phpcs src/CLI/`
Expected: no new errors on the four touched files (pre-existing warnings elsewhere in `src/CLI/` are not this task's).

Run: `composer test`
Expected: `OK` with the same count as Task 0 plus 8 from Task 1.

```bash
git add src/CLI/Media/class-fetchimportproductimagecommand.php src/CLI/Media/class-scrapeproductmedia.php src/CLI/Media/class-exportdraftproductimagesourcescommand.php src/CLI/Tools/class-exportacffield.php
github-agent-commit "Read dealer and image_source from postmeta in the CLI media and export commands (#77)"
```

---

### Task 5: Regression net — no ACF function may be called from `src/`

**Files:**
- Create: `tests/Support/AssertsNoAcfFunctionCalls.php`
- Create: `tests/Plugin/AcfCallSitesTest.php`
**Interfaces:**
- Produces: trait `FAToolkit\Tests\Support\AssertsNoAcfFunctionCalls` with `protected function assertNoAcfFunctionCalls( string $file ): void`.

Rationale: issue #77 is the third hook-callback-cannot-run fatal in this plugin. A full "every registered callback is callable and calls only defined functions" check needs a WordPress runtime and is a separate issue. This test is the cheap, durable half: it tokenizes every file under `src/` and fails on any call to an ACF function, in the same style as `AssertsNoFileScopeInstantiation`. It is what would have caught #77 before it shipped.

- [ ] **Step 1: Write the assertion trait**

Create `tests/Support/AssertsNoAcfFunctionCalls.php`:

```php
<?php
/**
 * Assertion helper for the ACF-dependency defect tracked in issue #77.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Support;

/**
 * Asserts that a PHP file calls no Advanced Custom Fields function.
 *
 * Background (issue #77): the plugin moved off ACF but ten get_field() calls
 * survived. ACF is not installed, so each is a fatal waiting for its hook to
 * fire. The Vendor product-list column fired on every wp-admin product list
 * load and emptied the table. This net makes any reintroduction fail in CI.
 *
 * Uses the tokenizer so that the function names inside comments, strings and
 * inline HTML cannot produce false positives: only a T_STRING token that is
 * immediately followed by `(` and NOT preceded by `->`, `::` or `function`
 * counts as a call.
 */
trait AssertsNoAcfFunctionCalls {

	/**
	 * ACF public API functions the plugin must not call.
	 *
	 * @var string[]
	 */
	private static $acf_functions = array(
		'get_field',
		'the_field',
		'get_fields',
		'get_field_object',
		'get_field_objects',
		'update_field',
		'delete_field',
		'have_rows',
		'the_row',
		'get_row',
		'get_sub_field',
		'the_sub_field',
		'have_sub_field',
		'acf_add_local_field_group',
		'acf_register_block_type',
	);

	/**
	 * Assert that the given PHP file contains no call to an ACF function.
	 *
	 * @param string $file Absolute path to the PHP file to inspect.
	 * @return void
	 */
	protected function assertNoAcfFunctionCalls( $file ) {
		$this->assertFileExists( $file );

		$tokens = token_get_all( file_get_contents( $file ) );
		$found  = array();
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
				continue;
			}
			if ( ! in_array( $token[1], self::$acf_functions, true ) ) {
				continue;
			}
			if ( ! $this->is_function_call( $tokens, $i ) ) {
				continue;
			}
			$found[] = $token[1] . '() at line ' . $token[2];
		}

		$this->assertSame(
			array(),
			$found,
			sprintf(
				'%s calls an ACF function (%s). ACF is not installed; read the value with '
				. 'get_post_meta( $post_id, \'<field name>\', true ) instead. See issue #77.',
				basename( $file ),
				implode( ', ', $found )
			)
		);
	}

	/**
	 * Whether the T_STRING at $index is a plain function call.
	 *
	 * Skips whitespace/comments to find the next significant token (must be
	 * `(`) and the previous one (must not be `->`, `::`, `?->` or `function`).
	 *
	 * @param array $tokens Full token stream.
	 * @param int   $index  Index of the T_STRING token.
	 * @return bool
	 */
	private function is_function_call( array $tokens, $index ) {
		$next = $this->significant_token( $tokens, $index, 1 );
		if ( '(' !== $next ) {
			return false;
		}

		$prev = $this->significant_token( $tokens, $index, -1 );
		if ( is_array( $prev ) ) {
			return ! in_array( $prev[0], array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true );
		}

		return true;
	}

	/**
	 * Return the next (or previous) non-whitespace, non-comment token.
	 *
	 * @param array $tokens Full token stream.
	 * @param int   $index  Starting index.
	 * @param int   $step   +1 or -1.
	 * @return array|string|null Token, or null at the boundary.
	 */
	private function significant_token( array $tokens, $index, $step ) {
		$i = $index + $step;
		while ( isset( $tokens[ $i ] ) ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				return $token;
			}
			$i += $step;
		}
		return null;
	}
}
```

- [ ] **Step 2: Write the test**

Create `tests/Plugin/AcfCallSitesTest.php`:

```php
<?php
/**
 * Regression net for issue #77: nothing under src/ may call ACF.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Plugin;

use FAToolkit\Tests\Support\AssertsNoAcfFunctionCalls;
use FAToolkit\Tests\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every PHP file under src/ is free of ACF function calls.
 *
 * @since 1.2.1
 */
class AcfCallSitesTest extends TestCase {

	use AssertsNoAcfFunctionCalls;

	/**
	 * Data provider: every .php file under src/.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function source_files() {
		$root  = dirname( __DIR__, 2 ) . '/src';
		$files = array();
		$iter  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
		foreach ( $iter as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$relative           = substr( $file->getPathname(), strlen( $root ) + 1 );
				$files[ $relative ] = array( $file->getPathname() );
			}
		}
		ksort( $files );
		return $files;
	}

	/**
	 * The assertion itself must detect a real call.
	 */
	public function test_assertion_detects_a_bare_get_field_call() {
		$fixture = tempnam( sys_get_temp_dir(), 'acf' );
		file_put_contents( $fixture, "<?php\n\$v = get_field( 'dealer', 1 );\n" );

		try {
			$this->assertNoAcfFunctionCalls( $fixture );
			$this->fail( 'Expected the assertion to fail on a bare get_field() call.' );
		} catch ( \PHPUnit\Framework\AssertionFailedError $e ) {
			$this->assertStringContainsString( 'get_field() at line 2', $e->getMessage() );
		} finally {
			unlink( $fixture );
		}
	}

	/**
	 * Method calls, static calls, definitions and comments are not flagged.
	 */
	public function test_assertion_ignores_non_calls() {
		$fixture = tempnam( sys_get_temp_dir(), 'acf' );
		file_put_contents(
			$fixture,
			"<?php\n// get_field( 'x' ) in a comment\n\$s = 'get_field(';\n\$o->get_field( 1 );\nFoo::get_field( 1 );\nfunction get_field() {}\n"
		);

		try {
			$this->assertNoAcfFunctionCalls( $fixture );
		} finally {
			unlink( $fixture );
		}
	}

	/**
	 * No file under src/ calls an ACF function.
	 *
	 * @dataProvider source_files
	 *
	 * @param string $file Absolute path.
	 */
	public function test_source_file_calls_no_acf_function( $file ) {
		$this->assertNoAcfFunctionCalls( $file );
	}
}
```

- [ ] **Step 3: Prove the net catches the original bug**

Temporarily reintroduce the fatal to see the test fail for the right reason:

```bash
git stash push -- src/Admin/class-product-display-vendor.php
vendor/bin/phpunit tests/Plugin/AcfCallSitesTest.php --filter 'product-display-vendor'
git stash pop
```

Expected while stashed: FAIL with `class-product-display-vendor.php calls an ACF function (get_field() at line 49, get_field() at line 63)`. After `stash pop`, the file is back to the Task 1 version. (`git stash` is on the confirm-first list in the destructive-commands rule; it is scoped to one file here and immediately popped. If the executor prefers, use `git show develop:src/Admin/class-product-display-vendor.php > /tmp/vendor-old.php` and run the trait against that path from a throwaway test instead.)

- [ ] **Step 4: Run the whole regression test**

Run: `vendor/bin/phpunit tests/Plugin/AcfCallSitesTest.php`
Expected: `OK` — two fixture tests plus one case per file under `src/`, all passing because Tasks 1-4 removed every call.

- [ ] **Step 5: Lint and commit**

Run: `vendor/bin/phpcs tests/Support/AssertsNoAcfFunctionCalls.php tests/Plugin/AcfCallSitesTest.php`
Expected: no errors.

```bash
git add tests/Support/AssertsNoAcfFunctionCalls.php tests/Plugin/AcfCallSitesTest.php
github-agent-commit "Add a tokenizer test asserting no file under src/ calls an ACF function (#77)"
```

---

### Task 6: Verify on staging, open the PR

**Files:** none in-repo.

- [ ] **Step 1: Full suite and lint**

Run: `composer test && composer lint`
Expected: suite `OK`; lint reports no errors on touched files.

- [ ] **Step 2: Reproduce the issue's own check against the fixed code**

Deploy the branch to the local staging instance (however the plugin is synced there today), then:

```bash
wp eval 'do_action("manage_product_posts_custom_column", "vendor", 185);'
```

Expected: an `<a href="https://...">CSSI</a>`-style anchor (or nothing, if product 185 has no `dealer` meta) and **no** `PHP Fatal error`. Then load `/wp-admin/edit.php?post_type=product` and confirm rows render with a Vendor column.

- [ ] **Step 3: Open the PR**

```bash
agent-gh pr create --base develop --head fix/77-remove-acf-get-field \
  --title "Remove the ACF get_field() dependency that empties the wp-admin product list" \
  --body-file /private/tmp/claude-501/-Users-stephenfeather-Development-fa-toolkit/97a0b28a-f65f-48fe-b50b-67abf366e88d/scratchpad/pr-77-body.md
```

PR body must contain: `Closes #77`; the one-paragraph cause; the list of ten call sites replaced; the two `wc_get_product()` guards; the note that `pwbe_results_product_acf_upc` writes into `acf_dealer` (pre-existing, TODO left, follow-up issue to be opened); the note that the four CLI classes still lack unit tests (TODO left); the staging verification output from Step 2; and the session link line `https://claude.ai/code/session_01SBRHezxB8NQXy7dXvczNcB`.

- [ ] **Step 4: Open the two follow-up issues**

1. "PWBulkEditorSettings::pwbe_results_product_acf_upc writes the UPC into `acf_dealer`" (bug, low).
2. "Assert every registered hook callback is callable" — the broader CI check the issue suggests; needs a WordPress runtime, so it is deliberately not part of #77.

---

## Self-Review

**Spec coverage.**
- Vendor column fatal (issue "Where", lines 49 and 63): Task 1.
- Latent `wc_get_product()` fatal in `generate_vendor_url()`: Task 1. The identical pattern in `get_dealer_image_url()` (not listed in the issue but the same shape): Task 4.
- Ten surviving `get_field()` calls, per the issue's table: Tasks 1 (2), 3 (2), 4 (2 + 1 + 1 + 1), 2 (1) = 10. `rg` in Task 4 Step 5 confirms zero remain.
- "Shim vs. per-site" decision: per-site `get_post_meta()` with keys supplied by wp-import; nothing is assumed from ACF-era code, as the issue asks.
- CI/test pass asserting callbacks cannot call undefined functions: Task 5 delivers the ACF-specific half; the general half is a follow-up issue (Task 6 Step 4).

**Placeholder scan.** No TBD/TODO-as-instruction. The two `TODO` comments written into source are deliberate deferred-fix markers required by the `deferred-fix` rule, with follow-up issues in Task 6.

**Type consistency.** `get_post_meta( $post_id, $key, true )` argument order is consistent across every task and every test expectation (`with( $post_id, 'key', true )`). `Product_Display_Vendor` private helpers `get_vendor`, `get_sku`, `build_vendor_url` are defined in Task 1 and referenced nowhere else. The trait method `assertNoAcfFunctionCalls` is named identically in Task 5's trait and test.

**Known judgement calls.**
- `generate_vendor_url()` previously returned the literal `'https:// foobar.baz'` for unknown vendors; the plan returns `''`. The column then renders an anchor with an empty href for unknown vendors, which is less wrong than a link to a garbage host. If Stephen wants the old placeholder kept, change the last `return ''` in `build_vendor_url` and the corresponding test expectation.
- The ExportACFField command keeps its name to avoid breaking any operator scripts; only its docs change.
