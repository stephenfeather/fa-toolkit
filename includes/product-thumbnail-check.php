<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
} 

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
    exit;
} 

if (!function_exists('WP_CLI_product_thumbnail_check')) {
    
    /**
     * Find WooCommerce products that don't have a thumbnail and move them to drafts.
     *
     * ## OPTIONS
     *
     *  [--vendor=<vendor>]
     * : The vendor name to filter the results by.
     *
     * [--result_count=<result_count>]
     * : The number of records to process (default is 20)
     * 
     * [--order=<ASC,DEC>]
     * : Change the order of records
     * 
     * ## EXAMPLES
     *
     *     wp fa:media product-thumbnail-check --vendor=ACME
     *     wp fa:media product-thumbnail-check --result_count=5
     * 
     * @param array $args
     * @param array $assoc_args
     * @return void
    */

    function WP_CLI_product_thumbnail_check($args, $assoc_args)
    {
        $order = isset($assoc_args['order']) ? $assoc_args['order'] : 'ASC';
        $vendor = isset($assoc_args['vendor']) ? $assoc_args['vendor'] : '';
        $result_count = isset($assoc_args['result_count']) ? absint($assoc_args['result_count']) : 100;

        $processed_count = 0;
        $error_count = 0;

        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => '_thumbnail_id',
                    'compare' => 'NOT EXISTS'
                ),
                array(
                    'key' => '_thumbnail_id',
                    'value' => '',
                    'compare' => '='
                )
            ),
//            'meta_key' => 'dealer',
//            'meta_value' => $vendor,
            'posts_per_page' => $result_count,
            'orderby' => 'ID',
            'order' => $order,
            'fields' => 'ids',
        );

        $query = new WP_Query($args);
        if ($query->have_posts()) {
            foreach ($query->posts as $post_id) {
                WP_CLI::debug('Product ID ' . $post_id . ' does not have a thumbnail.');
                //$status = WP_CLI::runcommand( "post update {$post_id} --post_status=draft --user=1" );
                // UnPublish the product.
                $status = wp_update_post(array(
                    'ID'          => $post_id,
                    'post_status' => 'draft',
                ));

                if ($status = 0) {
                    WP_CLI::warning("Product {$post_id} NOT moved to drafts.");
                    $error_count++;
                } else {
                    WP_CLI::success("Product {$post_id} moved to drafts.");
                    $processed_count++;
                }
            }
        } else {
            WP_CLI::success('All WooCommerce products have a thumbnail.');
        }

        WP_CLI::line("{$processed_count} products moved to drafts.");
    }
    
    WP_CLI::add_command( 'fa:media product-thumbnail-check', 'WP_CLI_product_thumbnail_check' );
}