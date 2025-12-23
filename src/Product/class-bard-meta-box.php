<?php
/**
 * Class to display metabox with data to be pasted into bard.
 *
 * @package FA-Toolkit
 * @since 1.0.8
 */

namespace FA_Toolkit\Product;

if ( defined( 'ABSPATH' ) === false ) {
	exit; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.exit
};

/**
 * Class to display metabox with data to be pasted into bard.
 */
class Bard_Meta_Box {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
	}

	/**
	 * Register the metabox.
	 */
	public function register_meta_box() {
		add_meta_box(
			'bard_meta_box',
			__( 'Bard Prompt', 'fa-toolkit' ),
			array( $this, 'render_meta_box' ),
			'product',
			'side',
			'high'
		);
	}

	/**
	 * Render the metabox.
	 *
	 * @param WP_Post $post The post object.
	 */
	public function render_meta_box( $post ) {
		$title  = $post->post_title;
		$sku    = get_post_meta( $post->ID, '_sku', true );
		$upc    = get_field( 'upc_code', $post->ID );
		$brands = wp_get_post_terms( $post->ID, 'pwb-brand' );
		$brand  = $brands[0]->name;

		// Output the HTML.
		?>
		The following information is for a product that exists but we dont have product details and need to write a description. The title also needs to be rewritten to match the manufacturers.<br />
		Title: <?php printf( '%s', esc_html( $title ) ); ?><br />
		SKU: <?php printf( '%s', esc_html( $sku ) ); ?><br />
		UPC: <?php printf( '%s', esc_html( $upc ) ); ?></br />
		Brand: <?php printf( '%s', esc_html( $brand ) ); ?></br />
		<?php

	}
}

new Bard_Meta_Box();
