<?php
/** Class for our promotion custom post_type
 *
 * @package FA-Toolkit
 * @since 1.0.6
 */

namespace FAToolkit\Promotion;

if ( defined( 'ABSPATH' ) === false ) {
	exit; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.exit
}

/**
 * Promotion post type.
 *
 * @version 1.0.0
 */
class Promotion_PostType {

	public const POST_TYPE = 'promotion';
	/**
	 * Data stored in postmeta.
	 *
	 * @var array
	 */
	protected $internal_meta_keys = array();

	/**
	 * Hook in methods.
	 */
	public static function init() {
		/** Because we want to share the product_tag taxonomy with the product post type,
		 * we need to register the post type after the product post type, so 999.
		 */

		add_action( 'init', array( __CLASS__, 'register_post_type' ), 999 );
		add_action( 'init', array( __CLASS__, 'register_taxonomies' ), 999 );
		add_filter( 'term_updated_messages', array( __CLASS__, 'updated_term_messages' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_post_status' ) );
		add_filter( 'rest_api_allowed_post_types', array( __CLASS__, 'rest_api_allowed_post_types' ) );
		add_filter( 'gutenberg_can_edit_post_type', array( __CLASS__, 'gutenberg_can_edit_post_type' ), 10, 2 );
		add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'gutenberg_can_edit_post_type' ), 10, 2 );
	}

	/**
	 * Registers taxonomies.
	 */
	public static function register_taxonomies() {
		// By using the product_tag taxonomy, we can use the same taxonomy for both products and promotions.
		register_taxonomy_for_object_type( 'product_tag', 'promotion' );
		register_taxonomy_for_object_type( 'pwb-brand', 'promotion' );
	}

	/**
	 * Registers post type.
	 */
	public static function register_post_type() {

		// Don't register if already registered.
		if ( post_type_exists( 'promotion' ) ) {
			return;
		}

		$supports = array(
			'title',
			'editor',
			'thumbnail',
			'excerpt',
			'custom-fields',
			'revisions',
			'page-attributes',
			'post-formats',
		);

		// Set up labels for this post type.
		$labels = array(
			'name'                  => _x( 'Promotions', 'fa-toolkit' ),
			'singular_name'         => _x( 'Promotion', 'fa-toolkit' ),
			'menu_name'             => _x( 'Promotions', 'fa-toolkit' ),
			'name_admin_bar'        => _x( 'Promotion', 'fa-toolkit' ),
			'add_new'               => __( 'Add New', 'fa-toolkit' ),
			'add_new_item'          => __( 'Add New Promotion', 'fa-toolkit' ),
			'new_item'              => __( 'New Promotion', 'fa-toolkit' ),
			'edit_item'             => __( 'Edit Promotion', 'fa-toolkit' ),
			'view_item'             => __( 'View Promotion', 'fa-toolkit' ),
			'all_items'             => __( 'All Promotions', 'fa-toolkit' ),
			'search_items'          => __( 'Search Promotions', 'fa-toolkit' ),
			'parent_item_colon'     => __( 'Parent Promotions:', 'fa-toolkit' ),
			'not_found'             => __( 'No promotions found.', 'fa-toolkit' ),
			'not_found_in_trash'    => __( 'No promotions found in Trash.', 'fa-toolkit' ),
			'featured_image'        => _x( 'Promotion Cover Image', 'fa-toolkit' ),
			'set_featured_image'    => _x( 'Set promotion image', 'fa-toolkit' ),
			'remove_featured_image' => _x( 'Remove promotion image', 'fa-toolkit' ),
			'use_featured_image'    => _x( 'Use as promotion image', 'fa-toolkit' ),
			'archives'              => _x( 'Promotion archives', 'fa-toolkit' ),
		);

		// Set up arguments for this post type.
		$args = array(
			'labels'              => $labels,
			'description'         => __( 'This is where you can add new promotions.', 'fa-toolkit' ),
			'public'              => true,
			'publicly_queryable'  => true,
			'exclude_from_search' => false,
			'show_in_nav_menus'   => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_admin_bar'   => true,
			'menu_position'       => 20,
			'menu_icon'           => 'dashicons-money-alt',
			'can_export'          => true,
			'delete_with_user'    => false,
			'hierarchical'        => false,
			'has_archive'         => true,
			'query_var'           => true,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			'rewrite'             => array(
				'slug'       => 'promotions',
				'with_front' => true,
				'pages'      => true,
				'feeds'      => true,
			),
			'supports'            => $supports,
			'taxonomies'          => array( 'product_tag' ),
		);

		// Register the post type.
		register_post_type( 'promotion', $args );
	}

	/**
	 * Customize the messages for the promotion taxonomies.
	 *
	 * @param array $messages The existing messages.
	 * @return array $messages The updated messages.
	 */
	public static function updated_term_messages( $messages ) {
		return $messages;
	}

	/**
	 * Adds the promotion post type to the Gutenberg editor.
	 *
	 * @param bool   $can_edit Whether the post type can be edited or not.
	 * @param string $post_type The post type.
	 * @return bool $can_edit Whether the post type can be edited or not.
	 */
	public static function gutenberg_can_edit_post_type( $can_edit, $post_type ) {
		if ( 'promotion' === $post_type ) {
			$can_edit = true;
		}
		return $can_edit;
	}

	/**
	 * Registers post status.
	 */
	public static function register_post_status() {
		register_post_status(
			'promotion-pending',
			array(
				'label'                     => _x( 'Expired', 'fa-toolkit' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: Number of promotions. */
				'label_count'               => _n_noop( 'Expired <span class="count">(%s)</span>', 'Expired <span class="count">(%s)</span>', 'fa-toolkit' ),
			)
		);

		register_post_status(
			'promotion-active',
			array(
				'label'                     => _x( 'Active', 'fa-toolkit' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: Number of promotions. */
				'label_count'               => _n_noop( 'Active <span class="count">(%s)</span>', 'Active <span class="count">(%s)</span>', 'fa-toolkit' ),
			)
		);
	}

	/**
	 * Adds the promotion post type to the REST API.
	 *
	 * @param array $allowed_post_types The allowed post types.
	 * @return array $allowed_post_types The updated allowed post types.
	 */
	public static function rest_api_allowed_post_types( $allowed_post_types ) {
		$allowed_post_types[] = 'promotion';
		return $allowed_post_types;
	}
}

Promotion_PostType::init();
