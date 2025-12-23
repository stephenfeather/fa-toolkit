<?php
/**
 * Formatter functions for standardizing user input data.
 *
 * @package FA\Includes
 */

if ( defined( 'ABSPATH' ) === false ) {
	exit; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.exit
}



// Rewrite certain customer data to standard formats during checkout and update from account page
// add_filter( 'woocommerce_process_checkout_field_billing_first_name', 'trim_and_uppercase', 10, 1 );
// add_filter( 'woocommerce_process_myaccount_field_billing_first_name', 'trim_and_uppercase', 10, 1 );
// add_filter( 'woocommerce_process_checkout_field_billing_last_name', 'trim_and_uppercase', 10, 1 );
// add_filter( 'woocommerce_process_myaccount_field_billing_last_name', 'trim_and_uppercase', 10, 1 );
// add_filter( 'woocommerce_process_checkout_field_billing_company', 'trim_and_uppercase', 10, 1 );
// add_filter( 'woocommerce_process_myaccount_field_billing_company', 'trim_and_uppercase', 10, 1 );
// add_filter( 'woocommerce_process_checkout_field_billing_vat', 'format_tax', 10, 1 );
// add_filter( 'woocommerce_process_myaccount_field_billing_vat', 'format_tax', 10, 1 );
// add_filter( 'woocommerce_process_checkout_field_billing_address_1', 'format_place', 10, 1 );
// add_filter( 'woocommerce_process_myaccount_field_billing_address_1', 'format_place', 10, 1 );
// add_filter( 'woocommerce_process_checkout_field_billing_postcode', 'format_zipcode', 10, 1 );
// add_filter( 'woocommerce_process_myaccount_field_billing_postcode', 'format_zipcode', 10, 1 );
// add_filter( 'woocommerce_process_checkout_field_billing_city', 'format_city', 10, 1 );
// add_filter( 'woocommerce_process_myaccount_field_billing_city', 'format_city', 10, 1 );
// add_filter( 'woocommerce_process_checkout_field_billing_phone', 'format_phone_number', 10, 1 );
// add_filter( 'woocommerce_process_myaccount_field_billing_phone', 'format_phone_number', 10, 1 );
// add_filter( 'woocommerce_process_checkout_field_billing_email', 'format_mail', 10, 1 );
// add_filter( 'woocommerce_process_myaccount_field_billing_email', 'format_mail', 10, 1 );
// add_filter( 'woocommerce_process_checkout_field_billing_birthday', 'format_date', 10, 1 );
// add_filter( 'woocommerce_process_checkout_field_shipping_first_name', 'trim_and_uppercase', 10, 1 );
// add_filter( 'woocommerce_process_myaccount_field_shipping_first_name', 'trim_and_uppercase', 10, 1 );
// add_filter( 'woocommerce_process_checkout_field_shipping_last_name', 'trim_and_uppercase', 10, 1 );
// add_filter( 'woocommerce_process_myaccount_field_shipping_last_name', 'trim_and_uppercase', 10, 1 );
// add_filter( 'woocommerce_process_checkout_field_shipping_address_1', 'format_place', 10, 1 );
// add_filter( 'woocommerce_process_myaccount_field_shipping_address_1', 'format_place', 10, 1 );
// add_filter( 'woocommerce_process_checkout_field_shipping_postcode', 'format_zipcode', 10, 1 );
// add_filter( 'woocommerce_process_myaccount_field_shipping_postcode', 'format_zipcode', 10, 1 );
// add_filter( 'woocommerce_process_checkout_field_shipping_city', 'format_city', 10, 1 );
// add_filter( 'woocommerce_process_myaccount_field_shipping_city', 'format_city', 10, 1 );

/**
 * Normalize a string by trimming whitespace, lowercasing, and uppercasing each word.
 *
 * @param string $value Raw customer input.
 * @return string
 */
function trim_and_uppercase( $value ) {
	add_custom_tracer( 'fa_trim_and_uppercase' );
	add_custom_tracer( 'fa_trim_and_uppercase' );
	return str_replace( 'Oww ', 'OWW ', implode( '.', array_map( 'ucwords', explode( '.', implode( '(', array_map( 'ucwords', explode( '(', implode( '-', array_map( 'ucwords', explode( '-', mb_strtolower( trim( $value ) ) ) ) ) ) ) ) ) ) ) );
}

/**
 * Format a street address segment by trimming spaces and uppercasing each word.
 *
 * @param string $value Raw address input.
 * @return string
 */
function format_place( $value ) {
	add_custom_tracer( 'format_place' );
	return trim_and_uppercase( $value );
}

/**
 * Sanitize a zipcode by trimming surrounding whitespace.
 *
 * @param string $value Raw zipcode input.
 * @return string
 */
function format_zipcode( $value ) {
	add_custom_tracer( 'format_zipcode' );
	return trim( $value );
}

/**
 * Normalize a city name by trimming whitespace and uppercasing each word.
 *
 * @param string $value Raw city input.
 * @return string
 */
function format_city( $value ) {
	add_custom_tracer( 'format_city' );
	return trim_and_uppercase( $value );
}

/**
 * Normalize an email address by trimming whitespace and lowercasing it.
 *
 * @param string $value Raw email input.
 * @return string
 */
function format_mail( $value ) {
	add_custom_tracer( 'format_mail' );
	return mb_strtolower( trim( $value ) );
}

/**
 * Normalize a headquarter name by trimming whitespace and uppercasing each word.
 *
 * @param string $value Raw headquarter input.
 * @return string
 */
function format_headquarter( $value ) {
	add_custom_tracer( 'format_headquater' );
	return trim_and_uppercase( $value );
}
