<?php
/**
 * Creates a block on the attachment page for the SHA hash.
 *
 * @package FA-Toolkit
 * @since 1.0.1
 */

namespace FAToolkit\Admin;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.exit
}

/**
 * Attachment SHA256 Hash Meta Box.
 */
class Attachment_SHA256_Hash_Meta_Box {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes_attachment', array( $this, 'add_attachment_sha256_hash_meta_box' ) );
		add_action( 'wp_ajax_generate_sha256_hash', array( $this, 'generate_sha256_hash' ) );
	}

	/**
	 * Add a meta box to the attachment edit page.
	 */
	public function add_attachment_sha256_hash_meta_box() {
		add_meta_box(
			'attachment_sha256_hash_meta_box', // Unique ID.
			'SHA256 Hash', // Box title.
			array( $this, 'display_attachment_sha256_hash_meta_box' ), // Callback function.
			'attachment', // Post type.
			'side', // Context.
			'default' // Priority.
		);
	}

	/**
	 * Display the contents of the meta box.
	 *
	 * @param object $post The post object.
	 */
	public function display_attachment_sha256_hash_meta_box( $post ) {
		// Retrieve the value of the sha256_hash field for the current attachment.
		$sha256_hash = get_post_meta( $post->ID, 'sha256_hash', true );

		?>
		<input type="text" value="<?php printf( '%s', esc_attr( $sha256_hash ) ); ?>" readonly="readonly" style="width:100%;">

		<?php if ( true ===empty( $sha256_hash ) ) : ?>
			<button id="generate_sha256_hash" type="button">Generate SHA256 Hash</button>
			<p id="generate_sha256_hash_status"></p>
			<script>
				jQuery(document).ready(function($) {
					$('#generate_sha256_hash').click(function() {
						$('#generate_sha256_hash_status').text('Generating SHA256 hash...');
						$.ajax({
							url: '<?php printf( '%s', esc_js( admin_url( 'admin-ajax.php' ) ) ); ?>',
							type: 'POST',
							dataType: 'json',
							data: {
								action: 'generate_sha256_hash',
								post_id: <?php printf( '%s', esc_js( $post->ID ) ); ?>
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
}
