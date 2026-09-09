<?php
/**
 * Hides out-of-stock prices at the Store API boundary.
 *
 * @package    fa-toolkit
 * @since 1.0.9
 */

namespace FAToolkit\Site;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * The API half of "hide the price when out of stock".
 *
 * SetupBusinessBloomer blanks the RENDERED price by filtering
 * `woocommerce_get_price_html`. The Store API serves that string as
 * `price_html`, so the storefront blocks, which render `price_html`
 * (Blocks/BlockTypes/ProductPrice.php), hide correctly. But the same response
 * carries a second, structured copy of the amount in `prices`, and
 * ProductSchema::prepare_product_price_response() builds that straight off
 * the product model with no filter in between. Anything reading `prices`
 * instead of `price_html` sees the number the html filter hid. Issue #66.
 *
 * Today the two fields agree by accident: every out-of-stock product has no
 * price at all. That ends when those products are priced.
 *
 * Why `rest_post_dispatch` and not something narrower: the Store API exposes
 * no filter on the product response. Its routes are registered through
 * register_rest_route(), so core's own post-dispatch filter is the first hook
 * that sees the assembled response, and it runs on every path a request can
 * take: serve_request(), rest_do_request() and batch.
 *
 * Why "0" and not "" or null: the Store API's MoneyFormatter turns an empty
 * product price into the string "0". That is what every out-of-stock product
 * returns today, so consumers already handle this shape and are handed no
 * new one.
 */
class StoreApiPriceHiding {

	/**
	 * Store API product routes, any version, and nothing that merely starts
	 * with the same letters.
	 */
	private const ROUTE_PATTERN = '#^/wc/store(?:/v\d+)?/products(?:/|$)#';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'rest_post_dispatch', array( $this, 'filter_response' ), 10, 3 );
	}

	/**
	 * Blank out-of-stock prices on a Store API product response.
	 *
	 * @param mixed            $response Result to send, normally a WP_REST_Response.
	 * @param \WP_REST_Server  $server   Server instance (unused).
	 * @param \WP_REST_Request $request  Request used to generate the response.
	 * @return mixed The response, rewritten where it carried product prices.
	 */
	public function filter_response( $response, $server, $request ) {
		if ( false === $request instanceof \WP_REST_Request || false === $response instanceof \WP_REST_Response ) {
			return $response;
		}

		if ( false === self::is_store_api_product_route( $request->get_route() ) ) {
			return $response;
		}

		$response->set_data( self::blank_out_of_stock_prices( $response->get_data() ) );

		return $response;
	}

	/**
	 * Whether a REST route is a Store API product route.
	 *
	 * Deliberately excludes `/wc/v3/products`: that is the authenticated
	 * admin REST API, and the html filter's `is_admin()` bail leaves store
	 * managers their prices. This does the same.
	 *
	 * @param string $route Route as WP_REST_Request::get_route() reports it.
	 * @return bool
	 */
	public static function is_store_api_product_route( $route ) {
		return 1 === preg_match( self::ROUTE_PATTERN, (string) $route );
	}

	/**
	 * Blank the prices of every out-of-stock product in a response payload.
	 *
	 * Accepts a single product item or a list of them; anything else is
	 * returned as it came. Whether an item is out of stock is read from the
	 * item's own `is_in_stock` member, so no product is loaded and the answer
	 * is the one WooCommerce itself just gave.
	 *
	 * Pure. Returns new data; the argument is not modified.
	 *
	 * @param mixed $data Response data.
	 * @return mixed
	 */
	public static function blank_out_of_stock_prices( $data ) {
		if ( self::is_product_item( $data ) ) {
			return self::blank_item( $data );
		}

		if ( is_array( $data ) ) {
			return array_map( array( self::class, 'blank_out_of_stock_prices' ), $data );
		}

		return $data;
	}

	/**
	 * Whether a payload is a single product item as ProductSchema emits it.
	 *
	 * @param mixed $data Candidate.
	 * @return bool
	 */
	private static function is_product_item( $data ) {
		return is_array( $data )
			&& array_key_exists( 'prices', $data )
			&& array_key_exists( 'is_in_stock', $data );
	}

	/**
	 * Blank one product item's prices if it is out of stock.
	 *
	 * @param array<string, mixed> $item Product item.
	 * @return array<string, mixed>
	 */
	private static function blank_item( array $item ) {
		if ( false !== $item['is_in_stock'] ) {
			return $item;
		}

		$item['prices'] = self::blank_prices( $item['prices'] );

		return $item;
	}

	/**
	 * Blank the amounts in a `prices` member, keeping its type and currency.
	 *
	 * ProductSchema casts `prices` to an object. A later filter or a block's
	 * server render reading the PHP response is owed the same type back.
	 *
	 * @param object|array<string, mixed> $prices Prices as the schema built them.
	 * @return object|array<string, mixed>
	 */
	private static function blank_prices( $prices ) {
		$blanked = array_merge(
			(array) $prices,
			array(
				'price'         => '0',
				'regular_price' => '0',
				'sale_price'    => '0',
				'price_range'   => null,
			)
		);

		return is_object( $prices ) ? (object) $blanked : $blanked;
	}
}
