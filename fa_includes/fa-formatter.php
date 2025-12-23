<?php
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

function trim_and_uppercase( $value ) {
	add_custom_tracer( 'fa_trim_and_uppercase' );
	add_custom_tracer( 'fa_trim_and_uppercase' );
	return str_replace( 'Oww ', 'OWW ', implode( '.', array_map( 'ucwords', explode( '.', implode( '(', array_map( 'ucwords', explode( '(', implode( '-', array_map( 'ucwords', explode( '-', mb_strtolower( trim( $value ) ) ) ) ) ) ) ) ) ) ) );
}

function format_place( $value ) {
	add_custom_tracer( 'format_place' );
	return trim_and_uppercase( $value );
}

function format_zipcode( $value ) {
	add_custom_tracer( 'format_zipcode' );
	return trim( $value );
}

function format_city( $value ) {
	add_custom_tracer( 'format_city' );
	return trim_and_uppercase( $value );
}

function format_mail( $value ) {
	add_custom_tracer( 'format_mail' );
	return mb_strtolower( trim( $value ) );
}

function format_headquarter( $value ) {
	add_custom_tracer( 'format_headquater' );
	return trim_and_uppercase( $value );
}
