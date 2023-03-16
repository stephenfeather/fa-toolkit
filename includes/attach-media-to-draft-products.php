<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
} 

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
    exit;
}

if (!function_exists( 'wp_cli_attach_media_to_draft_products' )) {
    
    /**
     * Attach media to draft products based on SKU.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Preview which attachments will be attached to which products, but do not make any changes.
     *
     * [--suffix=<suffix>]
     * : Allows the addition of a suffix for matching.
     *
     * [--extension=<ext>]
     * : Allows modification of the filename extension.
     * ## EXAMPLES
     *
     *     wp fa:media attach-media-to-draft-products
     *     wp fa:media attach-media-to-draft-products --suffix='_1'
     *     wp fa:media attach-media-to-draft-products --extension=png
     *
     * @when after_wp_load
     */
    function wp_cli_attach_media_to_draft_products($args, $assoc_args)
    {
        $suffix = isset($assoc_args['suffix']) ? $assoc_args['suffix'] : null;
        $extension = isset($assoc_args['extension']) ? $assoc_args['extension'] : 'jpg';
        // Get a list of draft product IDs.
        WP_CLI::debug("Loading Products..");
        $draft_product_ids = get_posts(array(
            'post_type'      => 'product',
            'post_status'    => 'draft',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC'
        ));

        // Load all of our attachments into memory
        WP_CLI::debug("Loading Attachments..");
        $attachments = get_posts(array(
            'post_type'      => 'attachment',
            'post_status'    => 'any',
            'post_mime_type' => 'image/jpeg',
            'posts_per_page' => -1,
            'orderby'        => 'post_title',
            'order'          => 'ASC'
        ));

        // Initialize variables to keep track of the number of products processed and the number of products with attachments.
        $matching_attachments = 0;
        $num_with_attachments = 0;
        $attachments_count = count($attachments);
        $products_count = count($draft_product_ids);

        // Loop through each draft product ID.
        foreach ($draft_product_ids as $product_id) {
            // Get the SKU for the product.
            $sku = get_post_meta($product_id, '_sku', true);
            // Generate a filename to match from the sku
            $filename_to_match = sku_to_filename($sku, $suffix, $extension);


            // Get attachment with the same file name as the SKU.
            $attachment = find_filename_in_attachment_array($attachments, $filename_to_match, $product_id);

            if ($attachment) {
                $attachment = $attachment->to_array();

                $is_attached = get_post_meta($product_id, '_thumbnail_id', true);

                if (! empty($is_attached) && $is_attached == $attachment['ID']) {
                    WP_CLI::debug(sprintf('Attachment ID %d is already attached to product ID %d', $product_id, $attachment['ID']));
                    $matching_attachments++;
                } else {
                    if (! isset($assoc_args['dry-run'])) {
                        set_post_thumbnail( $product_id, $attachment['ID'] );
                        //update_post_meta($product_id, '_thumbnail_id', $attachment['ID']);
                        WP_CLI::success(sprintf('Product %d now parent of Attachment %d', $product_id, $attachment['ID']));
                        $num_with_attachments++;

                        // Publish the product.
                        $publish_response = wp_update_post(array(
                            'ID'          => $product_id,
                            'post_status' => 'publish',
                        ));
                        WP_CLI::debug(sprintf('Product ID %s: %s', $product_id, $publish_response));
                    } else {
                        WP_CLI::line(sprintf('Preview: Attachment %d: (%s) will be attached to Product %d: (%s)', $attachment['ID'], $attachment['post_title'], $product_id, $sku));
                    }
                }
            } else {
                //WP_CLI::debug("No Matching Attachment!");
            }
        }
        WP_CLI::line("Draft Products: {$products_count}");
        WP_CLI::line("Attachments: {$attachments_count}");
        WP_CLI::line(sprintf('Products with existing attachments: %d', $matching_attachments));
        WP_CLI::line(sprintf('%d products had attachments added', $num_with_attachments));
    }
    
    WP_CLI::add_command( 'fa:media attach-media-to-draft-products', 'wp_cli_attach_media_to_draft_products' );
}

if ( !function_exists( 'sku_to_filename' ) ) {
    function sku_to_filename($sku, $basename_suffix = '', $extension)
    {
        $prefix = substr($sku, 0, 3);
        if ($prefix === 'FA-') {
            $numeric_part = substr($sku, 3);
            $image_filename = $numeric_part . $basename_suffix . '.' . $extension;
            return $image_filename;
        } else {
            return false;
        }
    }
}

if ( !function_exists( 'find_filename_in_attachment_array' ) ) {
    function find_filename_in_attachment_array($attachment_array = array(), $filename = "", $product_id = "")
    {
        $result = null;

        $extensions = array('.jpg', '.png', '.webp');
        foreach ($attachment_array as $object) {
            if ($object->post_title === $filename) {
                WP_CLI::debug(sprintf('Matching sku>%s to post_title %s for product: %s attachment: %s', $filename, $object->post_title, $product_id, $object->ID));
                $result = $object;
                break;
            }
        }
        unset($object);
        return $result ?? false;
    }
}