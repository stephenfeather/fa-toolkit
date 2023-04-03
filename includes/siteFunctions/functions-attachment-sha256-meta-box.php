<?php
/**
 * Creates a block on the attachment page for the SHA hash.
 *
 * @package FA-Toolkit
 * @since 1.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Add a meta box to the attachment edit page.
function add_attachment_sha256_hash_meta_box() {
	add_meta_box(
		'attachment_sha256_hash_meta_box', // Unique ID.
		'SHA256 Hash', // Box title.
		'display_attachment_sha256_hash_meta_box', // Callback function.
		'attachment', // Post type.
		'side', // Context.
		'default' // Priority.
	);
}
add_action( 'add_meta_boxes_attachment', 'add_attachment_sha256_hash_meta_box' );

// Display the contents of the meta box.
function display_attachment_sha256_hash_meta_box( $post ) {
	// Retrieve the value of the sha256_hash field for the current attachment.
	$sha256_hash = get_post_meta( $post->ID, 'sha256_hash', true );

	?>
	<input type="text" value="<?php echo esc_attr( $sha256_hash ); ?>" readonly="readonly" style="width:100%;">
	<?php
}
