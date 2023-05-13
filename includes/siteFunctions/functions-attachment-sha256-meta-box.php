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

/**  Add a meta box to the attachment edit page. */
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

/** Display the contents of the meta box. */
function display_attachment_sha256_hash_meta_box( $post ) {
	// Retrieve the value of the sha256_hash field for the current attachment.
	$sha256_hash = get_post_meta( $post->ID, 'sha256_hash', true );

	?>
	<input type="text" value="<?php echo esc_attr( $sha256_hash ); ?>" readonly="readonly" style="width:100%;">

	<?php if ( empty( $sha256_hash ) ) : ?>
		<button id="generate_sha256_hash" type="button">Generate SHA256 Hash</button>
		<p id="generate_sha256_hash_status"></p>
		<script>
			jQuery(document).ready(function($) {
				$('#generate_sha256_hash').click(function() {
					$('#generate_sha256_hash_status').text('Generating SHA256 hash...');
					$.ajax({
						url: '<?php echo admin_url( 'admin-ajax.php' ); ?>',
						type: 'POST',
						dataType: 'json',
						data: {
							action: 'generate_sha256_hash',
							post_id: <?php echo $post->ID; ?>
						},
						success: function(response) {
							if ( response.success ) {
								$('#generate_sha256_hash_status').text('SHA256 hash generated: ' + response.sha256_hash);
								$('input[name="sha256_hash"]').val(response.sha256_hash);
							} else {
								$('#generate_sha256_hash_status').text('Error generating SHA256 hash.');
							}
						}
					});
				});
			});
		</script>
	<?php endif; ?>
	<?php
}

/** Generate SHA256 hash for attachment. */
function generate_sha256_hash() {
	if ( ! isset( $_POST['post_id'] ) ) {
		wp_send_json_error( 'Invalid post ID.' );
	}

	$post_id        = intval( $_POST['post_id'] );
	$attachment_url = wp_get_attachment_url( $post_id );

	if ( ! $attachment_url ) {
		wp_send_json_error( 'Invalid attachment ID.' );
	}

	$attachment_path = get_attached_file( $post_id );
	$sha256_hash     = hash_file( 'sha256', $attachment_path );

	if ( ! $sha256_hash ) {
		wp_send_json_error( 'Error generating SHA256 hash.' );
	}

	update_post_meta( $post_id, 'sha256_hash', $sha256_hash );
	wp_send_json_success( array( 'sha256_hash' => $sha256_hash ) );
}

add_action( 'wp_ajax_generate_sha256_hash', 'generate_sha256_hash' );
