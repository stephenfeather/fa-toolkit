<?php
/**
 * Creates an admin menu item that lists prduct categories and associated product count.
 *
 * @package FA-Toolkit
 * @since 1.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

add_action( 'admin_menu', 'my_plugin_menu' );

/** Adds the submenu to access the page. */
function my_plugin_menu() {

		add_submenu_page( 'edit.php?post_type=product', 'Product Categories', 'Product Categories', 'manage_options', 'product-categories', 'product_categories_page' );

}
/** Builds the page with table of categoriees. */
function product_categories_page() {

	$nonce_action = 'fa_toolkit_pcp_sort_action';
	$nonce_name   = 'fa_toolkit_pcp_sort_nonce';
	$categories   = get_terms( 'product_cat' );

	// Avoid nonce check on first load but then check nonce when submitting sorting.
	if ( ( isset( $_REQUEST['sort_by'] ) && isset( $_REQUEST['sort_order'] ) ) && ( ! isset( $_REQUEST[ $nonce_name ] ) || ! wp_verify_nonce( sanitize_key( $_REQUEST[ $nonce_name ] ), $nonce_action ) ) ) {
		return;
	}

	// Check if sorting parameter is set and valid.
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
	$sort_by = isset( $_REQUEST['sort_by'] ) && in_array( $_REQUEST['sort_by'], array( 'name', 'count' ), true ) ? sanitize_text_field( $_REQUEST['sort_by'] ) : 'name';
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
	$sort_order = isset( $_REQUEST['sort_order'] ) && in_array( $_REQUEST['sort_order'], array( 'ASC', 'DESC' ), true ) ? sanitize_text_field( $_REQUEST['sort_order'] ) : 'DESC';

	// Sort categories array.
	if ( 'name' === $sort_by ) {
		usort(
			$categories,
			function ( $a, $b ) use ( $sort_order ) {
				return 'ASC' === $sort_order ? strcasecmp( $a->name, $b->name ) : strcasecmp( $b->name, $a->name );
			}
		);
	} else {
		usort(
			$categories,
			function ( $a, $b ) use ( $sort_order ) {
				return 'ASC' === $sort_order ? $a->count - $b->count : $b->count - $a->count;
			}
		);
	}

	// Get total categories and products count.
	$total_categories = count( $categories );
	$total_products   = wp_count_posts( 'product' )->publish;

	?>
	<div class="wrap">
		<h1>Product Categories</h1>
		<p>Total Categories: <?php echo esc_html( $total_categories ); ?></p>
		<p>Total Products: <?php echo esc_html( $total_products ); ?></p>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><a href="
					<?php
					echo esc_url(
						wp_nonce_url(
							add_query_arg(
								array(
									'sort_by'    => 'name',
									'sort_order' => 'name' === $sort_by && 'ASC' === $sort_order ? 'desc' : 'asc',
								)
							),
							$nonce_action,
							$nonce_name
						)
					);
					?>
									">Category Name <?php echo 'name' === $sort_by ? '<span class="dashicons dashicons-arrow-' . ( 'ASC' === $sort_order ? 'down' : 'up' ) . '"></span>' : ''; ?></a></th>
					<th><a href="
					<?php
					echo esc_url(
						wp_nonce_url(
							add_query_arg(
								array(
									'sort_by'    => 'count',
									'sort_order' => 'count' === $sort_by && 'ASC' === $sort_order ? 'desc' : 'asc',
								)
							),
							$nonce_action,
							$nonce_name
						)
					);
					?>
									">Number of Products <?php echo 'count' === $sort_by ? '<span class="dashicons dashicons-arrow-' . ( 'ASC' === $sort_order ? 'down' : 'up' ) . '"></span>' : ''; ?></a></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $categories as $category ) : ?>
					<tr>
						<td><?php echo esc_html( $category->name ); ?></td>
						<td><?php echo esc_html( $category->count ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}
