<?php
if ( class_exists( 'WP_CLI_Command' ) ) {
	class Scrape_Product_Data_Command extends WP_CLI_Command {

		/**
		 * Scrapes data from a URL and adds it to the product URL.
		 *
		 * ## OPTIONS
		 *
		 * <product_id>
		 * : The ID of the product.
		 *
		 * <url>
		 * : The URL to scrape data from.
		 *
		 * ## EXAMPLES
		 *
		 * wp scrape_product_data 123 https://example.com/product
		 *
		 * @param array $args
		 * @param array $assoc_args
		 */
		public function scrape_product_data( $args, $assoc_args ) {
			$product_id = $args[0];
			$url        = $args[1];

			WP_CLI::line( 'Scraping data from URL: ' . $url );

			// Perform scraping and data extraction here
			// You can use libraries like DOMDocument or SimpleHTMLDomParser to parse HTML and extract data

			// Example code to extract product title
			$html = wp_remote_get( $url );
			$dom  = new \DOMDocument();

			@$dom->loadHTML( $html['body'] );
			$titleElement  = $dom->getElementsByTagName( 'h2' )->item( 0 );
			$product_title = 'Case ' . $titleElement->textContent;

			// Example code to extract gallery images
			$galleryImagesContainer = $dom->getElementById( 'gallery-container' );
			$galleryImages          = $galleryImagesContainer->getElementsByTagName( 'a' );
			$gallery_image_urls     = array();
			foreach ( $galleryImages as $galleryImage ) {
				$image_src            = 'https:' . $galleryImage->getElementsByTagName( 'img' )->item( 0 )->getAttribute( 'src' );
				$gallery_image_urls[] = $image_src;
			}

			// Example code to extract post content

			$postContentElement = $dom->getElementsByTagName( 'div' );
			foreach ( $postContentElement as $element ) {
				$itemprop = $element->getAttribute( 'itemprop' );
				if ( $itemprop === 'description' ) {
					$post_content = $element->textContent;
					break;
				}
			}

			// Output the scraped data
			WP_CLI::line( 'Product ID: ' . $product_id );
			WP_CLI::line( 'Product Title: ' . $product_title );
			WP_CLI::line( 'Gallery Images:' );
			foreach ( $gallery_image_urls as $image_url ) {
				WP_CLI::line( $image_url );
			}
			WP_CLI::line( 'Post Content: ' . $post_content );

			// Example code to download images and update product data
			$gallery_image_ids = array();
			foreach ( $gallery_image_urls as $image_url ) {
				$image_name = basename( $image_url );
				$upload_dir = wp_upload_dir();
				$image_path = $upload_dir['path'] . '/' . $image_name;

				// Download the image
				file_put_contents( $image_path, file_get_contents( $image_url ) );

				// Associate the image with the product gallery
				$attachment          = array(
					'guid'           => $upload_dir['url'] . '/' . $image_name,
					'post_mime_type' => 'image/jpeg', // Adjust the mime type based on the image type
					'post_title'     => $image_name,
					'post_content'   => '',
					'post_status'    => 'inherit',
				);
				$attachment_id       = wp_insert_attachment( $attachment, $image_path );
				$gallery_image_ids[] = $attachment_id;
			}

			// Update the product title and post_content
			$product_data = array(
				'ID'           => $product_id,
				'post_title'   => 'Case ' . $titleElement->textContent,
				'post_content' => $post_content,
			);
			wp_update_post( $product_data );

			// Associate the gallery images with the product
			update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_image_ids ) );

		}

		/**
		 * Import media files from URLs and attach them to a product.
		 *
		 * @param int    $product_id  The product ID.
		 * @param string $featured_image_url       The media URL.
		 * @param array  $gallery_urls     The gallery URLs.
		 * @return int $success
		 * @since 1.0.4
		 * @access private
		 */
		private function import_and_attach_media( $product_id, $featured_image_url, $gallery_urls ) {
			// if we have a main image, import it and set it as the product featured image.
			if ( ! empty( $featured_image_url ) ) {
				$featured_image_id = $this->import_media( $featured_image_url, $product_id );
				WP_CLI::debug( 'Featured Image ID: ' . $featured_image_id );
				if ( ! is_wp_error( $featured_image_id ) ) {
					$success = set_post_thumbnail( $product_id, $featured_image_id );
				}
			} else {
				$success = false;
			}

			// if we have a gallery, import each item and set it as the product gallery.
			if ( ! empty( $gallery_urls ) && is_array( $gallery_urls ) ) {
				$gallery_ids = array();
				foreach ( $gallery_urls as $image ) {
					$gallery_ids[] = $this->import_media( $image, $product_id );
				}
				WP_CLI::debug( 'Gallery IDs: ' . implode( ', ', $gallery_ids ) );
				if ( ! is_wp_error( $gallery_ids ) ) {
					$success = update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
				}
			}
			return $success;
		}
	}

	WP_CLI::add_command( 'scrape_product_data', 'Scrape_Product_Data_Command' );
}
