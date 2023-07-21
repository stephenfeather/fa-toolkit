<?php
/**
 * Word count updater class.
 *
 * @package FA-Toolkit
 * @since 1.0.4
 */

/**
 * Class to update the fa_word_count meta field for products.
 */
class WordCount {
	/**
	 * Constructor.
	 */
	public function __construct() {
		// Hook into the post save events.
		add_action( 'save_post', array( $this, 'update_word_count_meta' ) );

		// Register WP-CLI command.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'update_word_count', array( $this, 'update_word_count_command' ) );
		}
	}

	/**
	 * Count the words in a string.
	 *
	 * @param string $text The text to count.
	 * @return int The number of words.
	 */
	public function count_words( $text ) {
		$stripped_text = wp_strip_all_tags( $text );
		$word_count    = str_word_count( $stripped_text );
		return $word_count;
	}

	/**
	 * Update the fa_word_count meta field for a product.
	 *
	 * @param int $post_id The ID of the post to update.
	 */
	public function update_word_count_meta( $post_id ) {
		$post = get_post( $post_id );
		if ( $post && 'product' === $post->post_type ) {
			$word_count = $this->count_words( $post->post_content );
			update_post_meta( $post_id, 'fa_word_count', $word_count );
		}
	}

	/**
	 * Update the word count for all products.
	 *
	 * @param array $args The command arguments.
	 * @param array $assoc_args The associative arguments.
	 */
	public function update_word_count_command( $args, $assoc_args ) {
		$args = array(
			'post_type'      => 'product',
			'posts_per_page' => -1,
		);

		$query = new WP_Query( $args );

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id = get_the_ID();
				WP_CLI::line( 'Updating word count for post ID: ' . $post_id );
				$this->update_word_count_meta( $post_id );
			}
		}

		wp_reset_postdata();

		WP_CLI::success( 'Word count updated successfully.' );
	}
}

// Instantiate the class.
new WordCount();
