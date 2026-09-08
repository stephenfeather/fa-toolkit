<?php
/**
 * Declares this plugin's compatibility with WooCommerce opt-in features.
 *
 * @package    fa-toolkit
 * @since 1.0.9
 */

namespace FAToolkit\Compat;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * Declares HPOS (custom order tables) compatibility.
 *
 * A declaration is a CLAIM that someone checked. It is deliberately narrow:
 * only custom_order_tables is declared, and only because the plugin's order
 * access was audited. See declare_compatibility() for what was and was not
 * covered.
 */
class HposCompatibility {

	/**
	 * WooCommerce's feature registry, resolved by name so it can be swapped in tests.
	 *
	 * @var string
	 */
	private const FEATURES_UTIL = '\Automattic\WooCommerce\Utilities\FeaturesUtil';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
	}

	/**
	 * Absolute path to the plugin bootstrap file.
	 *
	 * WooCommerce keys a declaration by plugin file. Pointing it at this class
	 * file would register the claim against a path that is not a plugin, and
	 * WooCommerce would silently not apply it.
	 *
	 * @return string
	 */
	public static function plugin_file() {
		return dirname( __DIR__, 2 ) . '/fa-toolkit.php';
	}

	/**
	 * Declare compatibility with High-Performance Order Storage.
	 *
	 * DECLARED: custom_order_tables. Audited 2026-09-08 — every *_post_meta()
	 * call in src/ targets a product, attachment, promotion or generic post ID,
	 * never an order, and products and attachments stay in wp_posts under HPOS.
	 * The plugin's only order access is SetupBusinessBloomer's cart-weight pair,
	 * which goes through wc_get_order() / update_meta_data() / save_meta_data()
	 * / get_meta(), plus one filter that returns __return_true without touching
	 * order data.
	 *
	 * NOT DECLARED: cart_checkout_blocks. SetupBusinessBloomer hooks the CLASSIC
	 * checkout action woocommerce_checkout_update_order_meta, which WooCommerce
	 * fires only from includes/class-wc-checkout.php. Block checkout runs
	 * through the Store API and fires woocommerce_store_api_checkout_update_order_meta
	 * instead, so the cart-weight feature does not run there at all. Claiming
	 * block compatibility while that gap exists would assert something nobody
	 * has verified, which is worse than declaring nothing.
	 *
	 * NOT DECLARED: product_block_editor. Never evaluated.
	 *
	 * @param string $features_util Class name to declare through. Injectable for tests.
	 * @return void
	 */
	public function declare_compatibility( $features_util = self::FEATURES_UTIL ) {
		// Guarded so an old WooCommerce, or none at all, cannot white-screen the site.
		if ( false === class_exists( $features_util ) ) {
			return;
		}

		$features_util::declare_compatibility( 'custom_order_tables', self::plugin_file(), true );
	}
}
