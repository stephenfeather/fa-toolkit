<?php
/**
 * WooCommerce Settings.
 *
 * @package FA-Toolkit
 * @since 1.2.0
 */

namespace FAToolkit\Modules;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * WooCommerce Settings.
 */
class WooCommerceSettings {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'setup' ) );
	}

	/**
	 * Setup the WooCommerce configuration.
	 */
	public function setup() {
		add_filter( 'woocommerce_subcategory_count_html', '__return_false' );
		add_filter( 'woocommerce_background_image_regeneration', '__return_false' );
		add_filter( 'woocommerce_ship_to_different_address_checked', '__return_true' );
		add_filter( 'woocommerce_states', array( $this, 'sell_only_states' ) );
		add_filter( 'get_terms', array( $this, 'custom_product_categories_order' ), 10, 3 );
		add_filter( 'wc_order_attribution_use_base64_cookies', '__return_true' );
	}

	/**
	 * Modify List of US States in checkout drop down.
	 *
	 * @param array $states Existing states grouped by country.
	 *
	 * @return array
	 */
	public function sell_only_states( $states ) {
		$states['US'] = array(
			'AL' => __( 'Alabama', 'woocommerce' ),
			'AZ' => __( 'Arizona', 'woocommerce' ),
			'AR' => __( 'Arkansas', 'woocommerce' ),
			'CO' => __( 'Colorado', 'woocommerce' ),
			'FL' => __( 'Florida', 'woocommerce' ),
			'GA' => __( 'Georgia', 'woocommerce' ),
			'ID' => __( 'Idaho', 'woocommerce' ),
			'IN' => __( 'Indiana', 'woocommerce' ),
			'IA' => __( 'Iowa', 'woocommerce' ),
			'KS' => __( 'Kansas', 'woocommerce' ),
			'KY' => __( 'Kentucky', 'woocommerce' ),
			'LA' => __( 'Louisiana', 'woocommerce' ),
			'ME' => __( 'Maine', 'woocommerce' ),
			'MI' => __( 'Michigan', 'woocommerce' ),
			'MN' => __( 'Minnesota', 'woocommerce' ),
			'MS' => __( 'Mississippi', 'woocommerce' ),
			'MO' => __( 'Missouri', 'woocommerce' ),
			'MT' => __( 'Montana', 'woocommerce' ),
			'NE' => __( 'Nebraska', 'woocommerce' ),
			'NV' => __( 'Nevada', 'woocommerce' ),
			'NH' => __( 'New Hampshire', 'woocommerce' ),
			'NM' => __( 'New Mexico', 'woocommerce' ),
			'NC' => __( 'North Carolina', 'woocommerce' ),
			'ND' => __( 'North Dakota', 'woocommerce' ),
			'OH' => __( 'Ohio', 'woocommerce' ),
			'OK' => __( 'Oklahoma', 'woocommerce' ),
			'OR' => __( 'Oregon', 'woocommerce' ),
			'PA' => __( 'Pennsylvania', 'woocommerce' ),
			'SC' => __( 'South Carolina', 'woocommerce' ),
			'SD' => __( 'South Dakota', 'woocommerce' ),
			'TN' => __( 'Tennessee', 'woocommerce' ),
			'TX' => __( 'Texas', 'woocommerce' ),
			'UT' => __( 'Utah', 'woocommerce' ),
			'VT' => __( 'Vermont', 'woocommerce' ),
			'VA' => __( 'Virginia', 'woocommerce' ),
			'WV' => __( 'West Virginia', 'woocommerce' ),
			'WI' => __( 'Wisconsin', 'woocommerce' ),
			'WY' => __( 'Wyoming', 'woocommerce' ),
		);

		return $states;
	}

	/**
	 * Handle catalog-only logic for a given state.
	 *
	 * @param string $state Two-letter state code.
	 */
	public function catalog_only( $state ) {
	}

	/**
	 * Reorder WooCommerce product categories according to a predefined list.
	 *
	 * @param array $terms      Retrieved terms.
	 * @param array $taxonomies Requested taxonomies.
	 * @param array $args       Query arguments.
	 *
	 * @return array
	 */
	public function custom_product_categories_order( $terms, $taxonomies, $args ) {
		if ( isset( $args['taxonomy'] ) && 'product_cat' === $args['taxonomy'] ) {
			// Define your custom order here. Replace these slugs with your actual product category slugs.
			$custom_order = array(
				'firearms',
				'ammunition',
				'optics',
				'game-processing',
				'suppressors',
				'muzzleloaders',
				'reloading',
				'archery',
				'gun-parts-tools',
				'hunting',
				'shooting',
				'knives',
				'apparel',
				'outdoors',
			// Add more categories as needed.
			);

			usort(
				$terms,
				function ( $a, $b ) use ( $custom_order ) {
					$pos_a = array_search( $a->slug, $custom_order, true );
					$pos_b = array_search( $b->slug, $custom_order, true );

					if ( false === $pos_a ) {
						$pos_a = count( $custom_order );
					}
					if ( false === $pos_b ) {
						$pos_b = count( $custom_order );
					}

					return $pos_a - $pos_b;
				}
			);
		}
		return $terms;
	}
}
