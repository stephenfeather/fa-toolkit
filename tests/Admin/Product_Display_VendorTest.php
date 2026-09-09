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
 * These tests pin the _fa_vendor postmeta read (a vendor slug), the slug to
 * label/URL mapping, and the wc_get_product() guard.
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

		$this->assertSame(
			array(
				'title'  => 'Title',
				'vendor' => 'Vendor',
			),
			$result
		);
	}

	/**
	 * Vendor slug comes from postmeta key _fa_vendor, never from ACF.
	 */
	public function test_generate_vendor_url_reads_vendor_slug_from_postmeta() {
		$instance = $this->create_instance_without_constructor();

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 185, '_fa_vendor', true )
			->andReturn( 'cssi' );
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
	 * The davidsons slug links to the Davidsons catalogue search.
	 */
	public function test_generate_vendor_url_for_davidsons() {
		$instance = $this->create_instance_without_constructor();

		Functions\when( 'get_post_meta' )->justReturn( 'davidsons' );
		Functions\when( 'wc_get_product' )->justReturn( $this->product_with_sku( 'DAV-9' ) );

		$this->assertSame(
			'https://www.davidsonsinc.com/catalogsearch/result/?q=DAV-9',
			$instance->generate_vendor_url( 42 )
		);
	}

	/**
	 * Slugs are matched exactly; the old display-name casing is not a slug.
	 */
	public function test_generate_vendor_url_does_not_match_display_name_casing() {
		$instance = $this->create_instance_without_constructor();

		Functions\when( 'get_post_meta' )->justReturn( 'CSSI' );
		Functions\when( 'wc_get_product' )->justReturn( $this->product_with_sku( 'ABC123' ) );

		$this->assertSame( '', $instance->generate_vendor_url( 42 ) );
	}

	/**
	 * A slug with no catalogue URL yet yields an empty URL rather than the old 'https:// foobar.baz' junk.
	 */
	public function test_generate_vendor_url_returns_empty_for_vendor_without_url() {
		$instance = $this->create_instance_without_constructor();

		Functions\when( 'get_post_meta' )->justReturn( 'rsrgroup' );
		Functions\when( 'wc_get_product' )->justReturn( $this->product_with_sku( 'X' ) );

		$this->assertSame( '', $instance->generate_vendor_url( 42 ) );
	}

	/**
	 * wc_get_product() returns false for a deleted or non-product id; that must not fatal.
	 */
	public function test_generate_vendor_url_returns_empty_when_product_missing() {
		$instance = $this->create_instance_without_constructor();

		Functions\when( 'get_post_meta' )->justReturn( 'cssi' );
		Functions\expect( 'wc_get_product' )->once()->with( 999 )->andReturn( false );

		$this->assertSame( '', $instance->generate_vendor_url( 999 ) );
	}

	/**
	 * Column content is an escaped link wrapping the vendor's display label.
	 */
	public function test_add_vendor_column_content_outputs_link_for_vendor_column() {
		$instance = $this->create_instance_without_constructor();

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 185, '_fa_vendor', true )
			->andReturn( 'cssi' );
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
	 * A known vendor whose product cannot be loaded still shows the label, without a link.
	 */
	public function test_add_vendor_column_content_shows_label_without_link_when_product_missing() {
		$instance = $this->create_instance_without_constructor();

		Functions\when( 'get_post_meta' )->justReturn( 'davidsons' );
		Functions\when( 'wc_get_product' )->justReturn( false );
		Functions\when( 'esc_html' )->returnArg();

		ob_start();
		$instance->add_vendor_column_content( 'vendor', 185 );
		$output = ob_get_clean();

		$this->assertSame( 'Davidsons', $output );
	}

	/**
	 * A slug the plugin has no label or URL for is shown as the slug itself, escaped, without a link.
	 */
	public function test_add_vendor_column_content_shows_unknown_slug_verbatim() {
		$instance = $this->create_instance_without_constructor();

		Functions\when( 'get_post_meta' )->justReturn( 'pawholesale' );
		Functions\when( 'wc_get_product' )->justReturn( $this->product_with_sku( 'X' ) );
		Functions\expect( 'esc_html' )->once()->with( 'pawholesale' )->andReturn( 'pawholesale' );

		ob_start();
		$instance->add_vendor_column_content( 'vendor', 185 );
		$output = ob_get_clean();

		$this->assertSame( 'pawholesale', $output );
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
	 * A product with no vendor meta (out of stock, for example) renders an empty, non-fatal cell.
	 */
	public function test_add_vendor_column_content_handles_missing_vendor_meta() {
		$instance = $this->create_instance_without_constructor();

		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\expect( 'wc_get_product' )->never();

		ob_start();
		$instance->add_vendor_column_content( 'vendor', 185 );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}
}
