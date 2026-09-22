<?php
/**
 * Tests for ShippingClasses.
 *
 * @package FAToolkit\Tests\Shipping
 */

namespace FAToolkit\Tests\Shipping;

use Brain\Monkey\Functions;
use FAToolkit\Shipping\ShippingClasses;
use FAToolkit\Tests\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * ShippingClasses: the five class names, the method each one may ship by,
 * and the class a product carries. Issue #650 (operations).
 */
class ShippingClassesTest extends TestCase {

	/**
	 * A product mock carrying a shipping class id.
	 *
	 * @param int $class_id Term id, 0 for none.
	 * @return \Mockery\MockInterface
	 */
	private function product( $class_id ) {
		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_shipping_class_id' )->andReturn( $class_id );

		return $product;
	}

	/**
	 * The five names, exactly as the terms are named in WooCommerce.
	 */
	public function test_names_are_the_five_ruled_classes() {
		$this->assertSame(
			array( 'Handguns', 'Long Guns', 'Ammunition', 'Hazmat', 'Standard' ),
			ShippingClasses::names()
		);
	}

	/**
	 * Which method each class may ship by.
	 *
	 * @return array<string, array{0:string,1:string|null}>
	 */
	public static function class_methods() {
		return array(
			'handguns air only' => array( 'Handguns', '2-Day Air' ),
			'long guns ground'  => array( 'Long Guns', 'Ground' ),
			'ammunition ground' => array( 'Ammunition', 'Ground' ),
			'hazmat ground'     => array( 'Hazmat', 'Ground' ),
			'standard ground'   => array( 'Standard', 'Ground' ),
			'unknown none'      => array( 'Heavy', null ),
			'empty none'        => array( '', null ),
		);
	}

	/**
	 * Handguns may only go 2-Day Air (carrier rule, WD-1); every other class
	 * only Ground; a name outside the table has no method at all.
	 *
	 * @param string      $class_name Class name.
	 * @param string|null $method     Expected method label.
	 */
	#[DataProvider( 'class_methods' )]
	public function test_method_for_maps_each_class_to_one_method( $class_name, $method ) {
		$this->assertSame( $method, ShippingClasses::method_for( $class_name ) );
	}

	/**
	 * A product with no shipping class resolves to '' without a term lookup.
	 */
	public function test_name_for_product_is_empty_without_a_class() {
		Functions\expect( 'get_term_by' )->never();

		$this->assertSame( '', ShippingClasses::name_for_product( $this->product( 0 ) ) );
	}

	/**
	 * The class is resolved by the term's name, matched against the table.
	 */
	public function test_name_for_product_reads_the_term_name() {
		Functions\expect( 'get_term_by' )->once()->with( 'id', 30, 'product_shipping_class' )->andReturn( (object) array( 'name' => 'Handguns' ) );

		$this->assertSame( 'Handguns', ShippingClasses::name_for_product( $this->product( 30 ) ) );
	}

	/**
	 * A term whose name is not one of the five is treated as no class: an
	 * import-created "Heavy" must not ship by any method.
	 */
	public function test_name_for_product_rejects_a_name_outside_the_table() {
		Functions\when( 'get_term_by' )->justReturn( (object) array( 'name' => 'Heavy' ) );

		$this->assertSame( '', ShippingClasses::name_for_product( $this->product( 99 ) ) );
	}

	/**
	 * A term lookup that fails (deleted term, WP_Error) is no class.
	 */
	public function test_name_for_product_is_empty_when_the_term_is_gone() {
		Functions\when( 'get_term_by' )->justReturn( false );

		$this->assertSame( '', ShippingClasses::name_for_product( $this->product( 30 ) ) );
	}
}
