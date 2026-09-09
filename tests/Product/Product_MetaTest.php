<?php
/**
 * Tests for Product_Meta.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Product;

use FAToolkit\Product\Product_Meta;
use FAToolkit\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * The single place the plugin reads import-written product meta keys from.
 *
 * Issue #77: the keys were ACF field names read through get_field(). ACF is
 * not installed; the import now writes plain postmeta under these keys.
 *
 * @since 1.2.1
 */
class Product_MetaTest extends TestCase {

	/**
	 * The vendor key is the one wp-import writes.
	 */
	public function test_vendor_key_is_fa_vendor() {
		$this->assertSame( '_fa_vendor', Product_Meta::VENDOR );
	}

	/**
	 * The GTIN key is WooCommerce's own global unique id.
	 */
	public function test_gtin_key_is_global_unique_id() {
		$this->assertSame( '_global_unique_id', Product_Meta::GTIN );
	}

	/**
	 * vendor() reads the vendor key as a single value and returns it verbatim.
	 */
	public function test_vendor_returns_stored_name_verbatim() {
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 185, '_fa_vendor', true )
			->andReturn( 'davidsons' );

		$this->assertSame( 'davidsons', Product_Meta::vendor( 185 ) );
	}

	/**
	 * A product with no vendor meta (e.g. out of stock) yields '' rather than false or null.
	 */
	public function test_vendor_returns_empty_string_when_meta_missing() {
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$this->assertSame( '', Product_Meta::vendor( 185 ) );
	}

	/**
	 * Non-string meta (corrupt or serialized) is coerced to a string, never returned raw.
	 */
	public function test_vendor_coerces_non_string_to_string() {
		Functions\when( 'get_post_meta' )->justReturn( false );

		$this->assertSame( '', Product_Meta::vendor( 185 ) );
	}
}
