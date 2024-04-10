<?php
/**
 * Promotion Data
 *
 * Displays the promotion data meta box.
 *
 * @package FA-Toolkit
 * @since 1.0.7
 */

namespace FAToolkit\Promotion;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Promotion Data class.
 */
class Promotion_Meta_Box {
	/**
	 * - removing and adding under a different name is essentially a rename
	 * remove_meta_box( 'commentsdiv', 'product', 'normal' );
	 * add_meta_box( 'commentsdiv', __( 'Reviews', 'woocommerce' ), 'post_comment_meta_box', 'product', 'normal' );
	 *
	 * Need to setup sort order of meta boxes see woocommerce class-wc-admin-meta-boxes.php line 154
	 */



	/**
	 * Output the promotion data meta box.
	 *
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	public static function output( $post ) {
		wp_nonce_field( 'fatoolkit_save_data', 'fatoolkit_meta_nonce' );
		$promotion_id = absint( $post->ID );
		// $promotion    = new Promotion( $promotion_id );

		// Retrieve the current values for the custom fields.
		$promotion_date_begins = get_post_meta( $promotion_id, 'promotion_date_begins', true );
		$promotion_date_ends   = get_post_meta( $promotion_id, 'promotion_date_ends', true );
		$promotion_url         = get_post_meta( $promotion_id, 'promotion_url', true );

		// Add a nonce field to check for the validity of the data.
		wp_nonce_field( 'custom_promotion_nonce', 'custom_promotion_nonce' );

		?>

	<label for="promotion_date_begins">Beginning Date:</label>
	<input id="promotion_date_begins" type="date" name="promotion_date_begins" value="<?php echo esc_attr( $promotion_date_begins ); ?>" />
<br />
	<label for="promotion_date_ends">Ending Date:</label>
	<input id="promotion_date_ends" type="date" name="promotion_date_ends" value="<?php echo esc_attr( $promotion_date_ends ); ?>" />
<br />
	<label for="promotion_url">Promotion URL:</label>
	<input id="promotion_url" type="url" name="promotion_url" value="<?php echo esc_attr( $promotion_url ); ?>" />

		<?php
	}


	/**
	 * Save the promotion data.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public static function save_custom_promotion_fields( $post_id ) {

		$promotion_id = absint( $post_id );

		// Check if this is a promotion post.
		if ( 'promotion' !== get_post_type( $post_id ) ) {
			return;
		}

		// Check if this is an autosave or the data came from our meta box.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check if the current user has permission to edit the post.
		if ( ! current_user_can( 'edit_post', $promotion_id ) ) {
			return;
		}

		// Check if the nonce value is valid.
		if ( ! isset( $_POST['custom_promotion_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['custom_promotion_nonce'] ) ), 'custom_promotion_nonce' ) ) {
			return;
		}

		// Save the custom fields.
		if ( isset( $_POST['promotion_date_begins'] ) ) {
			update_post_meta( $promotion_id, 'promotion_date_begins', sanitize_text_field( wp_unslash( $_POST['promotion_date_begins'] ) ) );
		}

		if ( isset( $_POST['promotion_date_ends'] ) ) {
			update_post_meta( $promotion_id, 'promotion_date_ends', sanitize_text_field( wp_unslash( $_POST['promotion_date_ends'] ) ) );
		}

		if ( isset( $_POST['promotion_url'] ) ) {
			update_post_meta( $promotion_id, 'promotion_url', sanitize_text_field( wp_unslash( $_POST['promotion_url'] ) ) );
		}

		// Save the description as post_excerpt.
		if ( isset( $_POST['description'] ) ) {
			// Update the post excerpt with the provided description.
			$excerpt = sanitize_textarea_field( wp_unslash( $_POST['description'] ) );
			$post    = array(
				'ID'           => $promotion_id,
				'post_excerpt' => $excerpt,
			);
			wp_update_post( $post );
		}
	}

}
