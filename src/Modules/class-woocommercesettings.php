<?php
/**
 * WooCommerce Settings.
 *
 * @package FA-Toolkit
 * @since 1.0.9
 */

namespace FAToolkit\Modules;

if ( defined( 'ABSPATH' ) === false ) {
	exit; // Exit if accessed directly.
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
		// $this->customer_data_filter();
		add_filter( 'woocommerce_states', array( $this, 'sell_only_states' ) );
		add_filter( 'get_terms', array( $this, 'custom_product_categories_order' ), 10, 3 );
		add_filter( 'wc_order_attribution_use_base64_cookies', '__return_true' );
	}

	/**
	 * Format customer data.
	 */
	public function customer_data_filter() {
		// Rewrite certain customer data to standard formats during checkout and update from account page.
		add_filter( 'woocommerce_process_checkout_field_billing_first_name', 'trim_and_uppercase', 10, 1 );
		add_filter( 'woocommerce_process_myaccount_field_billing_first_name', 'trim_and_uppercase', 10, 1 );
		add_filter( 'woocommerce_process_checkout_field_billing_last_name', 'trim_and_uppercase', 10, 1 );
		add_filter( 'woocommerce_process_myaccount_field_billing_last_name', 'trim_and_uppercase', 10, 1 );
		add_filter( 'woocommerce_process_checkout_field_billing_company', 'trim_and_uppercase', 10, 1 );
		add_filter( 'woocommerce_process_myaccount_field_billing_company', 'trim_and_uppercase', 10, 1 );
		// add_filter( 'woocommerce_process_checkout_field_billing_vat', 'format_tax', 10, 1 );
		// add_filter( 'woocommerce_process_myaccount_field_billing_vat', 'format_tax', 10, 1 );
		add_filter( 'woocommerce_process_checkout_field_billing_address_1', 'format_place', 10, 1 );
		add_filter( 'woocommerce_process_myaccount_field_billing_address_1', 'format_place', 10, 1 );
		add_filter( 'woocommerce_process_checkout_field_billing_postcode', 'format_zipcode', 10, 1 );
		add_filter( 'woocommerce_process_myaccount_field_billing_postcode', 'format_zipcode', 10, 1 );
		add_filter( 'woocommerce_process_checkout_field_billing_city', 'format_city', 10, 1 );
		add_filter( 'woocommerce_process_myaccount_field_billing_city', 'format_city', 10, 1 );
		// add_filter( 'woocommerce_process_checkout_field_billing_phone', 'format_phone_number', 10, 1 );
		// add_filter( 'woocommerce_process_myaccount_field_billing_phone', 'format_phone_number', 10, 1 );
		add_filter( 'woocommerce_process_checkout_field_billing_email', 'format_mail', 10, 1 );
		add_filter( 'woocommerce_process_myaccount_field_billing_email', 'format_mail', 10, 1 );
		// add_filter( 'woocommerce_process_checkout_field_billing_birthday', 'format_date', 10, 1 );
		add_filter( 'woocommerce_process_checkout_field_shipping_first_name', 'trim_and_uppercase', 10, 1 );
		add_filter( 'woocommerce_process_myaccount_field_shipping_first_name', 'trim_and_uppercase', 10, 1 );
		add_filter( 'woocommerce_process_checkout_field_shipping_last_name', 'trim_and_uppercase', 10, 1 );
		add_filter( 'woocommerce_process_myaccount_field_shipping_last_name', 'trim_and_uppercase', 10, 1 );
		add_filter( 'woocommerce_process_checkout_field_shipping_address_1', 'format_place', 10, 1 );
		add_filter( 'woocommerce_process_myaccount_field_shipping_address_1', 'format_place', 10, 1 );
		// add_filter( 'woocommerce_process_checkout_field_shipping_postcode', 'format_zipcode', 10, 1 );
		// add_filter( 'woocommerce_process_myaccount_field_shipping_postcode', 'format_zipcode', 10, 1 );
		add_filter( 'woocommerce_process_checkout_field_shipping_city', 'format_city', 10, 1 );
		add_filter( 'woocommerce_process_myaccount_field_shipping_city', 'format_city', 10, 1 );
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
			'AL'             => __( 'Alabama', 'woocommerce' ),
			// 'AK' => __( 'Alaska', 'woocommerce' ),
						'AZ' => __( 'Arizona', 'woocommerce' ),
			'AR'             => __( 'Arkansas', 'woocommerce' ),
			// 'CA' => __( 'California', 'woocommerce' ),
						'CO' => __( 'Colorado', 'woocommerce' ),
			// 'CT' => __( 'Connecticut', 'woocommerce' ),
			// 'DE' => __( 'Delaware', 'woocommerce' ),
			// 'DC' => __( 'District Of Columbia', 'woocommerce' ),
						'FL' => __( 'Florida', 'woocommerce' ),
			'GA'             => __( 'Georgia', 'woocommerce' ),
			// 'HI' => __( 'Hawaii', 'woocommerce' ),
						'ID' => __( 'Idaho', 'woocommerce' ),
			// 'IL' => __( 'Illinois', 'woocommerce' ),
						'IN' => __( 'Indiana', 'woocommerce' ),
			'IA'             => __( 'Iowa', 'woocommerce' ),
			'KS'             => __( 'Kansas', 'woocommerce' ),
			'KY'             => __( 'Kentucky', 'woocommerce' ),
			'LA'             => __( 'Louisiana', 'woocommerce' ),
			'ME'             => __( 'Maine', 'woocommerce' ),
			// 'MD' => __( 'Maryland', 'woocommerce' ),
			// 'MA' => __( 'Massachusetts', 'woocommerce' ),
						'MI' => __( 'Michigan', 'woocommerce' ),
			'MN'             => __( 'Minnesota', 'woocommerce' ),
			'MS'             => __( 'Mississippi', 'woocommerce' ),
			'MO'             => __( 'Missouri', 'woocommerce' ),
			'MT'             => __( 'Montana', 'woocommerce' ),
			'NE'             => __( 'Nebraska', 'woocommerce' ),
			'NV'             => __( 'Nevada', 'woocommerce' ),
			'NH'             => __( 'New Hampshire', 'woocommerce' ),
			// 'NJ' => __( 'New Jersey', 'woocommerce' ),
						'NM' => __( 'New Mexico', 'woocommerce' ),
			// 'NY' => __( 'New York', 'woocommerce' ),
						'NC' => __( 'North Carolina', 'woocommerce' ),
			'ND'             => __( 'North Dakota', 'woocommerce' ),
			'OH'             => __( 'Ohio', 'woocommerce' ),
			'OK'             => __( 'Oklahoma', 'woocommerce' ),
			'OR'             => __( 'Oregon', 'woocommerce' ),
			'PA'             => __( 'Pennsylvania', 'woocommerce' ),
			'RI'             => __( 'Rhode Island', 'woocommerce' ),
			'SC'             => __( 'South Carolina', 'woocommerce' ),
			'SD'             => __( 'South Dakota', 'woocommerce' ),
			'TN'             => __( 'Tennessee', 'woocommerce' ),
			'TX'             => __( 'Texas', 'woocommerce' ),
			'UT'             => __( 'Utah', 'woocommerce' ),
			'VT'             => __( 'Vermont', 'woocommerce' ),
			'VA'             => __( 'Virginia', 'woocommerce' ),
			'WA'             => __( 'Washington', 'woocommerce' ),
			'WV'             => __( 'West Virginia', 'woocommerce' ),
			'WI'             => __( 'Wisconsin', 'woocommerce' ),
			'WY'             => __( 'Wyoming', 'woocommerce' ),
		// 'AA' => __( 'Armed Forces (AA)', 'woocommerce' ),
		// 'AE' => __( 'Armed Forces (AE)', 'woocommerce' ),
		// 'AP' => __( 'Armed Forces (AP)', 'woocommerce' ),
		// 'AS' => __( 'American Samoa', 'woocommerce' ),
		// 'GU' => __( 'Guam', 'woocommerce' ),
		// 'MP' => __( 'Northern Mariana Islands', 'woocommerce' ),
		// 'PR' => __( 'Puerto Rico', 'woocommerce' ),
		// 'UM' => __( 'US Minor Outlying Islands', 'woocommerce' ),
		// 'VI' => __( 'US Virgin Islands', 'woocommerce' ),
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





	private function trim_and_uppercase( $value ) {
		return str_replace( 'Oww ', 'OWW ', implode( '.', array_map( 'ucwords', explode( '.', implode( '(', array_map( 'ucwords', explode( '(', implode( '-', array_map( 'ucwords', explode( '-', mb_strtolower( trim( $value ) ) ) ) ) ) ) ) ) ) ) );
	}

	/**
	 * Format a place string by trimming and uppercasing.
	 *
	 * @param string $value The input value.
	 * @return string The formatted value.
	 */
	private function format_place( $value ) {
		return trim_and_uppercase( $value );
	}

	/**
	 * Format a zipcode string by trimming whitespace.
	 *
	 * @param string $value The input value.
	 * @return string The formatted value.
	 */
	private function format_zipcode( $value ) {
		return trim( $value );
	}

	/**
	 * Format a city string by trimming and uppercasing.
	 *
	 * @param string $value The input value.
	 * @return string The formatted value.
	 */
	private function format_city( $value ) {
		return trim_and_uppercase( $value );
	}

	/**
	 * Format an email address by trimming whitespace and converting to lowercase.
	 *
	 * @param string $value The input email address.
	 * @return string The formatted email address.
	 */
	private function format_mail( $value ) {
		return mb_strtolower( trim( $value ) );
	}

	/**
	 * Format a headquarter string by trimming and uppercasing.
	 *
	 * @param string $value The input value.
	 * @return string The formatted value.
	 */
	private function format_headquarter( $value ) {
		return trim_and_uppercase( $value );
	}
}
