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
		add_action( 'woocommerce_single_product_summary', array( $this, 'bloomer_echo_product_date' ), 25 );
		add_filter( 'woocommerce_get_price_html', array( $this, 'bbloomer_hide_price_if_out_stock_frontend' ), 9999, 2 );
		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'bbloomer_save_weight_order' ) );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'bbloomer_delivery_weight_display_admin_order_meta' ), 10, 1 );
	}

	/**
	 * WooCommerce: Show Product Published Date
	 *
	 * @author        Rodolfo Melogli
	 * @compatible    WooCommerce 5
	 * @donate $9     https://businessbloomer.com/bloomer-armada/
	 */
	public function bloomer_echo_product_date() {
		if ( true !== is_product() ) {
			return;
		}

		// Ask for the bare date and build the markup in the format string.
		// Passing the <span> to the_modified_date() and then running the whole
		// thing through esc_html() escapes our own tags, which renders the
		// literal markup to the shopper.
		$modified_date = the_modified_date( '', '', '', false );

		if ( empty( $modified_date ) ) {
			return;
		}

		printf(
			'<span class="single_product_date_published">Updated: %s</span>',
			esc_html( $modified_date )
		);
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
		// Written through the order object rather than update_post_meta() so it
		// works under High-Performance Order Storage. With custom order tables
		// enabled, post meta written against an order id is not where
		// WooCommerce looks for it, and the value silently goes missing.
		$order = wc_get_order( $order_id );

		if ( false === $order ) {
			return;
		}

		$weight = WC()->cart->get_cart_contents_weight();
		$order->update_meta_data( '_cart_weight', $weight );
		$order->save();
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
		// Read through the order object for the same HPOS reason as the write.
		$weight = $order->get_meta( '_cart_weight' );

		// Every order placed before this hook worked has no stored weight, so
		// print nothing rather than a bare "Order Weight:  lb". Zero is a real
		// measurement and must still render, which rules out empty().
		if ( '' === $weight || null === $weight ) {
			return;
		}

		printf(
			'<p><strong>Order Weight:</strong> %s %s</p>',
			esc_html( $weight ),
			esc_html( get_option( 'woocommerce_weight_unit' ) )
		);
	}
}
