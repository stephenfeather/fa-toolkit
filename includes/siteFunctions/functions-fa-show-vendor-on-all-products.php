<?php

// Add custom column to the product list table.
function add_custom_dealer_column_to_product_list( $columns ) {
	$columns['custom_field_column'] = 'Dealer';
	return $columns;
}
add_filter( 'manage_edit-product_columns', 'add_custom_dealer_column_to_product_list', 20 );

// Populate custom column with the value of the advanced custom field.
function populate_custom_dealer_column( $column, $post_id ) {
	if ( 'custom_field_column' === $column ) {
		$dealer_url         = generate_dealer_url( $post_id );
		$custom_field_value = get_field( 'dealer', $post_id );
		echo "<a href='" . esc_url( $url ) . "' target='_blank' rel='noopener noreferrer'>" . esc_html( $custom_field_value ) . "</a>";
	}
}
add_action( 'manage_product_posts_custom_column', 'populate_custom_dealer_column', 10, 2 );

function generate_dealer_url( $post_id ) {
	$url = '';
	$dealer = get_field( 'dealer', $post_id );

	if ( 'CSSI' === $dealer ) {
		$url = 'https://chattanoogashooting.com/catalog/lookup?propertyKey=sku&valueKey=' . $url;
	}

	if ( 'Davidsons' === $dealer ) {
	
	}
	return $url;
}
