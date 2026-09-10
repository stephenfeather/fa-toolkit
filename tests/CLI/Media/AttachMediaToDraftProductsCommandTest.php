<?php
/**
 * Tests for AttachMediaToDraftProductsCommand.
 *
 * @package FAToolkit\Tests\CLI\Media
 */

namespace FAToolkit\Tests\CLI\Media;

use Brain\Monkey\Functions;
use FAToolkit\CLI\Media\AttachMediaToDraftProductsCommand;
use FAToolkit\Tests\TestCase;

/**
 * AttachMediaToDraftProductsCommand: `wp fa:media attach-media-to-draft-products`.
 *
 * Issue #24.
 */
class AttachMediaToDraftProductsCommandTest extends TestCase {

	/**
	 * Reset the WP_CLI recorder.
	 */
	protected function setUp(): void {
		parent::setUp();
		\WP_CLI::reset_calls();
	}

	/**
	 * An attachment stub shaped like WP_Post for this command's needs.
	 *
	 * @param int    $id    Attachment id.
	 * @param string $title Post title.
	 * @return object
	 */
	private function attachment( $id, $title ) {
		return new class( $id, $title ) {
			/**
			 * Post id.
			 *
			 * @var int
			 */
			public $ID;
			/**
			 * Post title.
			 *
			 * @var string
			 */
			public $post_title;
			/**
			 * Constructor.
			 *
			 * @param int    $id    Id.
			 * @param string $title Title.
			 */
			public function __construct( $id, $title ) {
				$this->ID         = $id;
				$this->post_title = $title;
			}
			/**
			 * WP_Post::to_array() shape.
			 *
			 * @return array
			 */
			public function to_array() {
				return array(
					'ID'         => $this->ID,
					'post_title' => $this->post_title,
				);
			}
		};
	}

	/**
	 * Stub get_posts() to answer the product query then the attachment query,
	 * recording the arguments of each.
	 *
	 * @param array $product_ids Draft product ids.
	 * @param array $attachments Attachment stubs.
	 * @param array $seen        Receives the two argument arrays.
	 */
	private function stub_get_posts( array $product_ids, array $attachments, array &$seen ) {
		Functions\when( 'get_posts' )->alias(
			function ( $args ) use ( $product_ids, $attachments, &$seen ) {
				$seen[] = $args;
				return 'product' === $args['post_type'] ? $product_ids : $attachments;
			}
		);
	}

	/**
	 * Logged lines, in order.
	 *
	 * @return array
	 */
	private function logs() {
		return array_column( array_column( \WP_CLI::get_calls( 'log' ), 'args' ), 0 );
	}

	/**
	 * The constructor registers the command on this instance.
	 */
	public function test_constructor_registers_command() {
		$command = new AttachMediaToDraftProductsCommand();

		$calls = \WP_CLI::get_calls( 'add_command' );

		$this->assertCount( 1, $calls );
		$this->assertSame( 'fa:media attach-media-to-draft-products', $calls[0]['args'][0] );
		$this->assertSame( array( $command, 'execute' ), $calls[0]['args'][1] );
	}

	/**
	 * Both queries use the default DESC order, jpg and png attachments of any
	 * status, and every draft product.
	 */
	public function test_queries_draft_products_and_image_attachments_in_desc_order() {
		$seen = array();
		$this->stub_get_posts( array(), array(), $seen );

		( new AttachMediaToDraftProductsCommand() )->execute( array(), array() );

		$this->assertSame(
			array(
				'post_type'      => 'product',
				'post_status'    => 'draft',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			),
			$seen[0]
		);
		$this->assertSame(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'post_mime_type' => array( 'image/jpeg', 'image/png' ),
				'posts_per_page' => -1,
				'orderby'        => 'post_title',
				'order'          => 'DESC',
			),
			$seen[1]
		);
		$this->assertSame( array( 'Draft Products: 0', 'Attachments: 0', 'Products with existing attachments: 0', '0 products had attachments added' ), $this->logs() );
	}

	/**
	 * `--sortorder` applies to both queries.
	 */
	public function test_sortorder_flag_applies_to_both_queries() {
		$seen = array();
		$this->stub_get_posts( array(), array(), $seen );

		( new AttachMediaToDraftProductsCommand() )->execute( array(), array( 'sortorder' => 'ASC' ) );

		$this->assertSame( 'ASC', $seen[0]['order'] );
		$this->assertSame( 'ASC', $seen[1]['order'] );
	}

	/**
	 * A dry run previews each match without setting a thumbnail or publishing.
	 * The FA- prefix is stripped from the SKU when building the filename.
	 */
	public function test_dry_run_previews_matches_without_writing() {
		$seen = array();
		$this->stub_get_posts(
			array( 1, 3 ),
			array( $this->attachment( 50, '100.jpg' ), $this->attachment( 51, 'other.jpg' ) ),
			$seen
		);
		Functions\when( 'get_post_meta' )->alias(
			fn( $id, $key ) => '_sku' === $key ? array( 1 => 'FA-100', 3 => 'X-3' )[ $id ] : ''
		);
		Functions\expect( 'set_post_thumbnail' )->never();
		Functions\expect( 'wp_update_post' )->never();

		( new AttachMediaToDraftProductsCommand() )->execute( array(), array( 'dry-run' => true ) );

		$logs = $this->logs();
		$this->assertContains( 'Preview: Attachment 50: (100.jpg) will be attached to Product 1: (FA-100)', $logs );
		$this->assertContains( 'Draft Products: 2', $logs );
		$this->assertContains( '0 products had attachments added', $logs );
		$this->assertCount( 0, \WP_CLI::get_calls( 'success' ) );
	}

	/**
	 * A real run sets the thumbnail, publishes the product, and reports it.
	 */
	public function test_match_sets_thumbnail_and_publishes_product() {
		$seen = array();
		$this->stub_get_posts( array( 1 ), array( $this->attachment( 50, '100.jpg' ) ), $seen );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => '_sku' === $key ? 'FA-100' : '' );
		Functions\expect( 'set_post_thumbnail' )->once()->with( 1, 50 );
		Functions\expect( 'wp_update_post' )
			->once()
			->with(
				array(
					'ID'          => 1,
					'post_status' => 'publish',
				)
			)
			->andReturn( 1 );

		( new AttachMediaToDraftProductsCommand() )->execute( array(), array() );

		$this->assertSame( 'Product 1 now parent of Attachment 50', \WP_CLI::get_calls( 'success' )[0]['args'][0] );
		$this->assertContains( '1 products had attachments added', $this->logs() );
	}

	/**
	 * `--suffix` and `--extension` shape the filename the SKU must match.
	 */
	public function test_suffix_and_extension_flags_shape_the_filename() {
		$seen = array();
		$this->stub_get_posts(
			array( 1 ),
			array( $this->attachment( 60, '100_1.png' ), $this->attachment( 50, '100.jpg' ) ),
			$seen
		);
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => '_sku' === $key ? 'FA-100' : '' );
		Functions\expect( 'set_post_thumbnail' )->once()->with( 1, 60 );
		Functions\when( 'wp_update_post' )->justReturn( 1 );

		( new AttachMediaToDraftProductsCommand() )->execute(
			array(),
			array(
				'suffix'    => '_1',
				'extension' => 'png',
			)
		);
	}

	/**
	 * An attachment that is already the thumbnail is counted, not re-attached.
	 *
	 * Defect, pinned rather than fixed: the comparison at :109 is strict
	 * (`===`) between get_post_meta()'s string and the attachment's int ID, so
	 * with the string WordPress actually returns the branch never matches and
	 * the product is re-attached and re-published. This test feeds an int to
	 * prove the branch itself; the companion test below pins the string case.
	 */
	public function test_already_attached_product_is_counted_when_ids_compare_equal() {
		$seen = array();
		$this->stub_get_posts( array( 1 ), array( $this->attachment( 50, '100.jpg' ) ), $seen );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => '_sku' === $key ? 'FA-100' : 50 );
		Functions\expect( 'set_post_thumbnail' )->never();
		Functions\expect( 'wp_update_post' )->never();

		( new AttachMediaToDraftProductsCommand() )->execute( array(), array() );

		$this->assertContains( 'Products with existing attachments: 1', $this->logs() );
	}

	/**
	 * With the string get_post_meta() really returns, the already-attached
	 * check misses and the product is re-attached. See the docblock above;
	 * fixing the comparison flips exactly this test.
	 */
	public function test_already_attached_check_misses_when_meta_is_a_string() {
		$seen = array();
		$this->stub_get_posts( array( 1 ), array( $this->attachment( 50, '100.jpg' ) ), $seen );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => '_sku' === $key ? 'FA-100' : '50' );
		Functions\expect( 'set_post_thumbnail' )->once()->with( 1, 50 );
		Functions\when( 'wp_update_post' )->justReturn( 1 );

		( new AttachMediaToDraftProductsCommand() )->execute( array(), array() );

		$this->assertContains( 'Products with existing attachments: 0', $this->logs() );
	}
}
