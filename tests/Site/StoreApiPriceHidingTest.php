<?php
/**
 * Tests for StoreApiPriceHiding.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Site;

use FAToolkit\Site\StoreApiPriceHiding;
use FAToolkit\Tests\TestCase;
use Brain\Monkey\Filters;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Test case for StoreApiPriceHiding.
 *
 * The storefront hides out-of-stock prices by filtering the rendered
 * `price_html` string. The Store API serves a second, structured copy of the
 * same amount in `prices`, built straight off the product model with no
 * filter in between (issue #66). These tests pin the API-boundary half of
 * the hiding.
 */
class StoreApiPriceHidingTest extends TestCase {

	/**
	 * A product item as the Store API emits it, before this plugin touches it.
	 *
	 * @param bool $in_stock Whether the product is in stock.
	 * @param int  $id       Product id.
	 * @return array<string, mixed>
	 */
	private function product( $in_stock, $id = 1 ) {
		return array(
			'id'          => $id,
			'name'        => 'Accurate AICS Long Action SSSF Magazine',
			'prices'      => (object) array(
				'currency_code'  => 'USD',
				'currency_minor_unit' => 2,
				'price'          => '10037',
				'regular_price'  => '10037',
				'sale_price'     => '10037',
				'price_range'    => null,
			),
			'price_html'  => $in_stock ? '<span class="amount">$100.37</span>' : '',
			'is_in_stock' => $in_stock,
		);
	}

	/**
	 * The three amounts the schema exposes, all blanked to WooCommerce's own
	 * "no price" representation.
	 *
	 * @param object|array $prices Prices as returned.
	 * @return void
	 */
	private function assert_blanked( $prices ) {
		$prices = (array) $prices;
		$this->assertSame( '0', $prices['price'] );
		$this->assertSame( '0', $prices['regular_price'] );
		$this->assertSame( '0', $prices['sale_price'] );
		$this->assertNull( $prices['price_range'] );
	}

	/**
	 * An out-of-stock product loses its amounts.
	 *
	 * "0" rather than "" or null: the Store API's MoneyFormatter turns an
	 * empty product price into the string "0", so this is the shape every
	 * out-of-stock product on the store returns TODAY, before those products
	 * are given a price. Consumers already handle it.
	 *
	 * @return void
	 */
	public function test_out_of_stock_product_has_prices_blanked() {
		$result = StoreApiPriceHiding::blank_out_of_stock_prices( $this->product( false ) );

		$this->assert_blanked( $result['prices'] );
	}

	/**
	 * Currency metadata survives: only the amounts are secret.
	 *
	 * @return void
	 */
	public function test_blanking_keeps_currency_fields() {
		$result = StoreApiPriceHiding::blank_out_of_stock_prices( $this->product( false ) );

		$this->assertSame( 'USD', $result['prices']->currency_code );
		$this->assertSame( 2, $result['prices']->currency_minor_unit );
	}

	/**
	 * The `prices` member stays an object, as the schema declares it.
	 *
	 * ProductSchema casts it with `(object)`; a consumer decoding JSON gets
	 * `{}` either way, but anything reading the PHP response in-process (a
	 * later filter, a block's server render) gets the type it was promised.
	 *
	 * @return void
	 */
	public function test_blanking_preserves_object_type() {
		$result = StoreApiPriceHiding::blank_out_of_stock_prices( $this->product( false ) );

		$this->assertIsObject( $result['prices'] );
	}

	/**
	 * An in-stock product is returned exactly as given.
	 *
	 * @return void
	 */
	public function test_in_stock_product_is_untouched() {
		$product = $this->product( true );

		$this->assertEquals( $product, StoreApiPriceHiding::blank_out_of_stock_prices( $product ) );
	}

	/**
	 * A collection response is handled item by item.
	 *
	 * @return void
	 */
	public function test_collection_blanks_only_out_of_stock_items() {
		$items = array( $this->product( true, 1 ), $this->product( false, 2 ), $this->product( true, 3 ) );

		$result = StoreApiPriceHiding::blank_out_of_stock_prices( $items );

		$this->assertSame( '10037', ( (array) $result[0]['prices'] )['price'] );
		$this->assert_blanked( $result[1]['prices'] );
		$this->assertSame( '10037', ( (array) $result[2]['prices'] )['price'] );
	}

	/**
	 * The input is not mutated.
	 *
	 * @return void
	 */
	public function test_blanking_does_not_mutate_input() {
		$product = $this->product( false );

		StoreApiPriceHiding::blank_out_of_stock_prices( $product );

		$this->assertSame( '10037', $product['prices']->price );
	}

	/**
	 * Shapes that are not a product, or not an array of products, pass through.
	 *
	 * The products namespace also serves /products/attributes,
	 * /products/collection-data and similar, none of which carry a `prices`
	 * member; and an error response is an array without `is_in_stock`. None
	 * of these may be reshaped.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function passthrough_provider() {
		return array(
			'error payload'      => array( array( 'code' => 'woocommerce_rest_product_invalid_id', 'message' => 'Invalid product ID.' ) ),
			'attribute list'     => array( array( array( 'id' => 1, 'name' => 'Color', 'taxonomy' => 'pa_color' ) ) ),
			'collection data'    => array( array( 'price_range' => null, 'attribute_counts' => null ) ),
			'stock without prices' => array( array( 'id' => 9, 'is_in_stock' => false ) ),
			'empty list'         => array( array() ),
			'null'               => array( null ),
			'string'             => array( 'not json' ),
		);
	}

	/**
	 * Test that non-product payloads are returned unchanged.
	 *
	 * @param mixed $data Response data.
	 * @return void
	 */
	#[DataProvider( 'passthrough_provider' )]
	public function test_non_product_payloads_pass_through( $data ) {
		$this->assertSame( $data, StoreApiPriceHiding::blank_out_of_stock_prices( $data ) );
	}

	/**
	 * Store API routes that must be filtered, and near-misses that must not.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function route_provider() {
		return array(
			'products list'         => array( '/wc/store/v1/products', true ),
			'single product'        => array( '/wc/store/v1/products/42', true ),
			'products, no version'  => array( '/wc/store/products', true ),
			'future version'        => array( '/wc/store/v2/products/42', true ),
			'attributes'            => array( '/wc/store/v1/products/attributes', true ),
			'cart'                  => array( '/wc/store/v1/cart', false ),
			'wc rest products'      => array( '/wc/v3/products/42', false ),
			'core posts'            => array( '/wp/v2/posts', false ),
			'prefix collision'      => array( '/wc/store/v1/productsx', false ),
		);
	}

	/**
	 * Test that only Store API product routes are matched.
	 *
	 * The `/wc/v3/products` case matters: that is the authenticated admin
	 * REST API, which the classic `is_admin()` bail in the html filter also
	 * leaves alone. Store managers keep their prices.
	 *
	 * @param string $route    Request route.
	 * @param bool   $expected Whether the route is a Store API product route.
	 * @return void
	 */
	#[DataProvider( 'route_provider' )]
	public function test_route_matching( $route, $expected ) {
		$this->assertSame( $expected, StoreApiPriceHiding::is_store_api_product_route( $route ) );
	}

	/**
	 * Test that the constructor registers the filter as a bound callback.
	 *
	 * The plugin once registered four hooks as bare function-name strings
	 * that fataled on first dispatch under 100% coverage. Assert the shape.
	 *
	 * @return void
	 */
	public function test_constructor_registers_rest_post_dispatch_filter() {
		$captured = array();

		Filters\expectAdded( 'rest_post_dispatch' )->once()->whenHappen(
			function ( $callback, $priority, $accepted_args ) use ( &$captured ) {
				$captured = array( $callback, $priority, $accepted_args );
			}
		);

		$hiding = new StoreApiPriceHiding();

		$this->assertSame( array( $hiding, 'filter_response' ), $captured[0] );
		$this->assertIsCallable( $captured[0] );
		$this->assertSame( 3, $captured[2], 'The filter needs the request to read the route.' );
	}

	/**
	 * Test the full dispatch path: a Store API product response is rewritten.
	 *
	 * @return void
	 */
	public function test_filter_response_blanks_store_api_product_response() {
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_route' )->andReturn( '/wc/store/v1/products/42' );

		$response = Mockery::mock( 'WP_REST_Response' );
		$response->shouldReceive( 'get_data' )->once()->andReturn( $this->product( false, 42 ) );
		$response->shouldReceive( 'set_data' )->once()->with(
			Mockery::on(
				function ( $data ) {
					return '0' === ( (array) $data['prices'] )['price'];
				}
			)
		);

		$hiding = new StoreApiPriceHiding();

		$this->assertSame( $response, $hiding->filter_response( $response, Mockery::mock( 'WP_REST_Server' ), $request ) );
	}

	/**
	 * Test that a response on any other route is not even read.
	 *
	 * @return void
	 */
	public function test_filter_response_ignores_other_routes() {
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_route' )->andReturn( '/wc/v3/products/42' );

		$response = Mockery::mock( 'WP_REST_Response' );
		$response->shouldNotReceive( 'get_data' );
		$response->shouldNotReceive( 'set_data' );

		$hiding = new StoreApiPriceHiding();

		$this->assertSame( $response, $hiding->filter_response( $response, Mockery::mock( 'WP_REST_Server' ), $request ) );
	}

	/**
	 * Test that a non-response result passes through.
	 *
	 * Core wraps results with rest_ensure_response() before this filter, but
	 * an earlier filter at the same hook may hand on anything.
	 *
	 * @return void
	 */
	public function test_filter_response_passes_through_a_non_response() {
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_route' )->andReturn( '/wc/store/v1/products' );

		$hiding = new StoreApiPriceHiding();
		$error  = new \WP_Error( 'x', 'y' );

		$this->assertSame( $error, $hiding->filter_response( $error, Mockery::mock( 'WP_REST_Server' ), $request ) );
	}
}
