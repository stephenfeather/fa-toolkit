<?php
/**
 * Class containing modifications from Business Bloomer
 * re: businessbloomer.com
 *
 * @package    fa-toolkit
 * @since 1.0.8
 */

namespace FAToolkit\Site;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * Class to setup business bloomer modifications
 */
class SetupBusinessBloomer {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'woocommerce_single_product_summary', 'bloomer_echo_product_date', 25 );
		add_filter( 'woocommerce_get_price_html', 'bbloomer_hide_price_if_out_stock_frontend', 9999, 2 );
		add_action( 'woocommerce_checkout_update_order_meta', 'bbloomer_save_weight_order' );
		add_action( 'woocommerce_admin_order_data_after_billing_address', 'bbloomer_delivery_weight_display_admin_order_meta', 10, 1 );
	}

	/**
	 * WooCommerce: Show Product Published Date
	 *
	 * @author        Rodolfo Melogli
	 * @compatible    WooCommerce 5
	 * @donate $9     https://businessbloomer.com/bloomer-armada/
	 */
	public function bloomer_echo_product_date() {
		if ( true === is_product() ) {
			printf( '%s', esc_html( the_modified_date( '', '<span class="single_product_date_published">Updated: ', '</span>', false ) ) );
		}
	}

	/**
	 * Hide Price If Out of Stock @ WooCommerce Frontend
	 *
	 * @author        Rodolfo Melogli
	 * @testedwith    WooCommerce 6
	 * @param string $price Price.
	 * @param object $product Product.
	 */
	public function bbloomer_hide_price_if_out_stock_frontend( $price, $product ) {
		if ( true === is_admin() ) {
			return $price; // BAIL IF BACKEND.
		}
		if ( false === $product->is_in_stock() ) {
			$price = apply_filters( 'woocommerce_empty_price_html', '', $product );
		}
		return $price;
	}

	/**
	 * Save Order Total Weight - WooCommerce Order
	 *
	 * @author        Rodolfo Melogli
	 * @compatible    WooCommerce 3.6.4
	 * @param int $order_id Order ID.
	 */
	public function bbloomer_save_weight_order( $order_id ) {
		$weight = WC()->cart->get_cart_contents_weight();
		update_post_meta( $order_id, '_cart_weight', $weight );
	}

	/**
	 * Save Order Total Weight - WooCommerce Order
	 *
	 * @author        Rodolfo Melogli
	 * @compatible    WooCommerce 3.6.4
	 * @p
	 * @param int $order Order.
	 */
	public function bbloomer_delivery_weight_display_admin_order_meta( $order ) {
		printf( '<p><strong>Order Weight:</strong> %s %s</p>', esc_html( get_post_meta( $order->get_id(), '_cart_weight', true ) ), esc_html( get_option( 'woocommerce_weight_unit' ) ) );
	}
}
