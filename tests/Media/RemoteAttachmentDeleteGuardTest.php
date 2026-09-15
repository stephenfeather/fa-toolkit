<?php
/**
 * Tests for RemoteAttachmentDeleteGuard.
 *
 * @package FAToolkit\Tests\Media
 */

namespace FAToolkit\Tests\Media;

use Brain\Monkey\Functions;
use FAToolkit\Media\RemoteAttachmentDeleteGuard;
use FAToolkit\Tests\TestCase;

/**
 * RemoteAttachmentDeleteGuard: deleting a pointer attachment unlinks no local
 * file (issue #106).
 *
 * Core deletes every postmeta row before it unlinks files, so the pointer is
 * recognised at `pre_delete_attachment`, and the meta core derives paths from
 * is removed there. With no attached file, wp_delete_attachment_files() has
 * nothing to unlink; the post and the rest of its meta delete normally.
 */
class RemoteAttachmentDeleteGuardTest extends TestCase {

	/**
	 * Meta keyed by attachment id then key, as the stubs read it.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $meta = array();

	/**
	 * Keys deleted, as "id:key".
	 *
	 * @var array<int, string>
	 */
	private $deleted = array();

	/**
	 * Stub post meta over $this->meta.
	 *
	 * @param bool $deletes Whether delete_post_meta actually removes the key.
	 * @return void
	 */
	private function stub_meta( $deletes = true ) {
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key, $single ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'delete_post_meta' )->alias(
			function ( $id, $key ) use ( $deletes ) {
				$this->deleted[] = $id . ':' . $key;

				if ( true !== $deletes || false === isset( $this->meta[ $id ][ $key ] ) ) {
					return false;
				}

				unset( $this->meta[ $id ][ $key ] );

				return true;
			}
		);
	}

	/**
	 * An attachment post.
	 *
	 * @param int $id Id.
	 * @return object
	 */
	private function post( $id ) {
		return (object) array(
			'ID'        => $id,
			'post_type' => 'attachment',
		);
	}

	/**
	 * The constructor hooks pre_delete_attachment last and filters no file.
	 */
	public function test_constructor_registers_pre_delete_attachment_last() {
		$guard = new RemoteAttachmentDeleteGuard();

		$this->assertSame( PHP_INT_MAX, has_filter( 'pre_delete_attachment', array( $guard, 'forget_local_paths' ) ) );
		$this->assertFalse( has_filter( 'wp_delete_file' ) );
	}

	/**
	 * A delete already short-circuited by another filter is passed through untouched.
	 */
	public function test_a_short_circuited_delete_is_left_alone() {
		$this->meta = array( 70 => array( '_fa_remote_url' => 'https://ik.example/a.jpg', '_wp_attached_file' => 'a.jpg' ) );
		$this->stub_meta();

		$result = ( new RemoteAttachmentDeleteGuard() )->forget_local_paths( false, $this->post( 70 ), true );

		$this->assertFalse( $result );
		$this->assertSame( array(), $this->deleted );
	}

	/**
	 * Something that is not a post is passed through untouched.
	 */
	public function test_a_value_that_is_not_a_post_is_left_alone() {
		$this->stub_meta();

		$result = ( new RemoteAttachmentDeleteGuard() )->forget_local_paths( null, 'not a post', true );

		$this->assertNull( $result );
		$this->assertSame( array(), $this->deleted );
	}

	/**
	 * A pointer whose attached file cannot be removed refuses the delete.
	 */
	public function test_a_pointer_whose_path_cannot_be_removed_is_not_deleted() {
		$this->meta = array( 71 => array( '_fa_remote_url' => 'https://ik.example/b.jpg', '_wp_attached_file' => 'b.jpg' ) );
		$this->stub_meta( false );

		$result = ( new RemoteAttachmentDeleteGuard() )->forget_local_paths( null, $this->post( 71 ), true );

		$this->assertFalse( $result );
	}

	/**
	 * A pointer whose backup sizes cannot be removed refuses the delete.
	 */
	public function test_a_pointer_whose_backup_sizes_cannot_be_removed_is_not_deleted() {
		$this->meta = array(
			72 => array(
				'_fa_remote_url'              => 'https://ik.example/c.jpg',
				'_wp_attachment_backup_sizes' => array( 'full-orig' => array( 'file' => 'c.jpg' ) ),
			),
		);
		$this->stub_meta( false );

		$result = ( new RemoteAttachmentDeleteGuard() )->forget_local_paths( null, $this->post( 72 ), true );

		$this->assertFalse( $result );
	}

	/**
	 * An ordinary attachment keeps its paths, so core deletes its files.
	 */
	public function test_an_ordinary_attachment_keeps_its_paths() {
		$this->meta = array( 73 => array( '_wp_attached_file' => '2026/09/real.jpg' ) );
		$this->stub_meta();

		$result = ( new RemoteAttachmentDeleteGuard() )->forget_local_paths( null, $this->post( 73 ), true );

		$this->assertNull( $result );
		$this->assertSame( array(), $this->deleted );
		$this->assertSame( '2026/09/real.jpg', $this->meta[73]['_wp_attached_file'] );
	}

	/**
	 * A pointer loses the meta core derives unlink paths from, and the delete proceeds.
	 */
	public function test_a_pointer_loses_its_local_paths_and_the_delete_proceeds() {
		$this->meta = array(
			74 => array(
				'_fa_remote_url'              => 'https://ik.example/d.jpg',
				'_wp_attached_file'           => 'd.jpg',
				'_wp_attachment_backup_sizes' => array( 'full-orig' => array( 'file' => 'd.jpg' ) ),
				'_wp_attachment_metadata'     => array( 'file' => 'd.jpg' ),
			),
		);
		$this->stub_meta();

		$result = ( new RemoteAttachmentDeleteGuard() )->forget_local_paths( null, $this->post( 74 ), true );

		$this->assertNull( $result );
		$this->assertSame( array( '_fa_remote_url', '_wp_attachment_metadata' ), array_keys( $this->meta[74] ) );
	}

	/**
	 * A strip whose delete a later filter cancels leaves a recoverable pointer.
	 *
	 * The strip succeeds, then a pre_delete_attachment filter running after the
	 * guard returns false, so core deletes nothing. The pointer still renders
	 * from S3. Exactly one thing is lost, `_wp_attached_file`, and it is not
	 * self-healing; its value is the basename of the `_fa_remote_url` path.
	 */
	public function test_a_strip_whose_delete_is_cancelled_leaves_the_pointer_recoverable() {
		$remote     = 'https://ik.example/products/Glock-19.jpg?v=2';
		$this->meta = array(
			76 => array(
				'_fa_remote_url'          => $remote,
				'_fa_remote_width'        => 800,
				'_fa_remote_height'       => 600,
				'_wp_attached_file'       => 'Glock-19.jpg',
				'_wp_attachment_metadata' => array(
					'width'  => 800,
					'height' => 600,
					'file'   => 'Glock-19.jpg',
				),
			),
		);
		$before = $this->meta[76];
		$this->stub_meta();

		// The pre_delete_attachment chain: the guard, then a later filter that cancels.
		$stripped  = ( new RemoteAttachmentDeleteGuard() )->forget_local_paths( null, $this->post( 76 ), true );
		$cancelled = ( fn( $delete ) => false )( $stripped );

		$this->assertNull( $stripped, 'the strip succeeded' );
		$this->assertFalse( $cancelled, 'core returns before deleting anything' );

		// Exactly what is lost.
		$lost = $before;
		unset( $lost['_wp_attached_file'] );
		$this->assertSame( $lost, $this->meta[76] );
		$this->assertSame( array( '76:_wp_attached_file', '76:_wp_attachment_backup_sizes' ), $this->deleted );

		// Still renders from S3.
		$urls = new \FAToolkit\Media\RemoteAttachmentUrls();
		$this->assertSame( $remote, $urls->attachment_url( 'https://shop.example/wp-content/uploads/Glock-19.jpg', 76 ) );
		$this->assertSame( array( $remote . '&tr=w-400', 400, 300, true ), $urls->downsize( false, 76, array( 400, 300 ) ) );

		// Recoverable: the lost value is derivable from what survives.
		$this->assertSame( $before['_wp_attached_file'], basename( (string) parse_url( $this->meta[76]['_fa_remote_url'], PHP_URL_PATH ) ) );
	}

	/**
	 * An unsized pointer, which never had an attached file, still deletes.
	 */
	public function test_a_pointer_without_an_attached_file_still_deletes() {
		$this->meta = array( 75 => array( '_fa_remote_url' => 'https://ik.example/e.jpg' ) );
		$this->stub_meta();

		$result = ( new RemoteAttachmentDeleteGuard() )->forget_local_paths( null, $this->post( 75 ), true );

		$this->assertNull( $result );
	}
}
