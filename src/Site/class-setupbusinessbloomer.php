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
		add_filter( 'woocommerce_get_price_html', array( $this, 'bbloomer_hide_price_if_out_stock_frontend' ), 9999, 2 );
		// Both checkouts, deliberately. WooCommerce fires
		// `woocommerce_checkout_update_order_meta` only from
		// includes/class-wc-checkout.php — the classic path. Block checkout
		// goes through the Store API and fires
		// `woocommerce_store_api_checkout_update_order_meta` instead, which
		// WooCommerce's own docblock describes as "similar to existing core
		// hook woocommerce_checkout_update_order_meta. We're using a new
		// action". This store checks out through blocks, so the classic
		// registration alone recorded no weight on any order ever placed.
		//
		// Registered rather than swapped: a store that later switches back to
		// the shortcode checkout, or an order created programmatically through
		// the classic path, must not be newly broken by the fix.
		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'bbloomer_save_weight_order' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'save_weight_from_order' ) );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'bbloomer_delivery_weight_display_admin_order_meta' ), 10, 1 );
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

		$this->write_cart_weight( $order );
	}

	/**
	 * Write the cart's total weight onto an order.
	 *
	 * @param \WC_Order $order Order to write to.
	 * @return void
	 */
	private function write_cart_weight( $order ) {
		$weight = WC()->cart->get_cart_contents_weight();
		$order->update_meta_data( '_cart_weight', $weight );

		// save_meta_data(), not save(). By the time this hook fires the order
		// has already been created and persisted, so a full save() would issue
		// a second order write and fire woocommerce_update_order — which can
		// dispatch order.updated webhooks to integrations while payment is
		// still being processed. Only the meta is dirty, so only the meta is
		// written. This is HPOS-safe in the same way update_meta_data() is.
		$order->save_meta_data();
	}

	/**
	 * Save Order Total Weight - block checkout.
	 *
	 * The Store API hands over the WC_Order OBJECT, where the classic hook
	 * passes an order id. That difference is the whole reason this is a second
	 * entry point rather than the same callback on both hooks: passing an
	 * order object to wc_get_order() would be wrong, and passing an id to a
	 * method expecting an object would fatal.
	 *
	 * Both paths end in the same write, so whichever checkout the shopper
	 * used, the order carries the same meta stored the same HPOS-safe way.
	 *
	 * @param \WC_Order $order Order object supplied by the Store API.
	 * @return void
	 */
	public function save_weight_from_order( $order ) {
		if ( false === $order instanceof \WC_Order ) {
			return;
		}

		$this->write_cart_weight( $order );
	}

	/**
	 * Display Order Total Weight - WooCommerce Admin Order Screen
	 *
	 * @author        Rodolfo Melogli
	 * @compatible    WooCommerce 3.6.4
	 * @param \WC_Order $order Order object passed by the admin order data hook.
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
