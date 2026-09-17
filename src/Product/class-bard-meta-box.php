<?php
/**
 * Class to display metabox with data to be pasted into bard.
 *
 * @package FA-Toolkit
 * @since 1.0.8
 */

namespace FAToolkit\Product;

use FAToolkit\Media\BrandLogoStore;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

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
		$title = $post->post_title;
		$sku   = get_post_meta( $post->ID, '_sku', true );
		$upc   = get_post_meta( $post->ID, Product_Meta::GTIN, true );
		$brand = self::first_term_name( wp_get_post_terms( $post->ID, BrandLogoStore::TAXONOMY ) );

		// Output the HTML.
		?>
		The following information is for a product that exists but we dont have product details and need to write a description. The title also needs to be rewritten to match the manufacturers.<br />
		Title: <?php printf( '%s', esc_html( $title ) ); ?><br />
		SKU: <?php printf( '%s', esc_html( $sku ) ); ?><br />
		UPC: <?php printf( '%s', esc_html( $upc ) ); ?></br />
		Brand: <?php printf( '%s', esc_html( $brand ) ); ?></br />
		<?php
	}

	/**
	 * The first term's name, or '' when the lookup has none to give.
	 *
	 * `wp_get_post_terms()` returns a WP_Error for an unregistered taxonomy and
	 * an empty array for a product with no term, which every new auto-draft is.
	 * Indexing the WP_Error was a fatal on every product edit screen once the
	 * site stopped registering `pwb-brand`: "Cannot use object of type WP_Error
	 * as array". A fatal in a metabox callback ends the page, so the box
	 * degrades to no brand instead.
	 *
	 * @param mixed $terms What `wp_get_post_terms()` returned.
	 * @return string
	 */
	private static function first_term_name( $terms ) {
		if ( true !== is_array( $terms ) || true !== isset( $terms[0]->name ) ) {
			return '';
		}

		return (string) $terms[0]->name;
	}
}
