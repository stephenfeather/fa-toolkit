<?php
/**
 * The small-order handling fee.
 *
 * @package    fa-toolkit
 * @since 1.2.7
 */

namespace FAToolkit\Shipping;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * +$7.50 once per order when the cart subtotal is under $100 (operations
 * #650, "Rate basis"): Zanders bills $7.50 under $100 and CSSI $7.50 under
 * $50, and the order pays the worst case. One fee per order, not per
 * package, so a split cart is not charged twice (WD-4).
 *
 * The subtotal is the cart's pre-discount, pre-tax line total, which is what
 * a distributor sees as the order value.
 */
class SmallOrderFee {

	/**
	 * Subtotal below which the fee applies.
	 *
	 * @var float
	 */
	private const THRESHOLD = 100.0;

	/**
	 * The fee.
	 *
	 * @var float
	 */
	private const FEE = 7.5;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add' ), 20, 1 );
	}

	/**
	 * Add the fee to a cart that ships and is under the threshold.
	 *
	 * @param object $cart The WC_Cart.
	 * @return void
	 */
	public function add( $cart ) {
		if ( is_admin() && true !== wp_doing_ajax() ) {
			return;
		}

		if ( true !== is_object( $cart ) || true !== (bool) $cart->needs_shipping() ) {
			return;
		}

		$subtotal  = (float) $cart->get_subtotal();
		$threshold = (float) apply_filters( 'fa_toolkit_shipping_small_order_threshold', self::THRESHOLD );
		$fee       = (float) apply_filters( 'fa_toolkit_shipping_small_order_fee', self::FEE );

		if ( $subtotal <= 0 || $subtotal >= $threshold || $fee <= 0 ) {
			return;
		}

		$cart->add_fee( __( 'Small order fee', 'fa-toolkit' ), $fee, false );
	}
}
