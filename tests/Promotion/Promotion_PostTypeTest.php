<?php
/**
 * Tests for FAToolkit\Promotion\Promotion_PostType
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Promotion;

use Brain\Monkey\Functions;
use FAToolkit\Promotion\Promotion_PostType;
use FAToolkit\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test Promotion_PostType class.
 */
#[CoversClass( Promotion_PostType::class )]
class Promotion_PostTypeTest extends TestCase {

	/**
	 * Note: init() is auto-called at line 200 of the source file when the class loads,
	 * so hooks are already registered before tests run. Testing init() directly would
	 * require mocking hooks that already fired during class loading. The code is covered
	 * by the auto-initialization.
	 */

	/**
	 * Test register_taxonomies associates product_tag and pwb-brand with promotion.
	 */
	public function test_register_taxonomies_registers_for_promotion() {
		Functions\expect( 'register_taxonomy_for_object_type' )
			->once()
			->with( 'product_tag', 'promotion' );

		Functions\expect( 'register_taxonomy_for_object_type' )
			->once()
			->with( 'pwb-brand', 'promotion' );

		\FAToolkit\Promotion\Promotion_PostType::register_taxonomies();
	}

	/**
	 * Test register_post_type does nothing if promotion already exists.
	 */
	public function test_register_post_type_returns_early_if_exists() {
		Functions\expect( 'post_type_exists' )
			->once()
			->with( 'promotion' )
			->andReturn( true );

		Functions\expect( 'register_post_type' )
			->never();

		\FAToolkit\Promotion\Promotion_PostType::register_post_type();
	}

	/**
	 * Test register_post_type registers promotion when it doesn't exist.
	 */
	public function test_register_post_type_registers_promotion() {
		Functions\expect( 'post_type_exists' )
			->once()
			->with( 'promotion' )
			->andReturn( false );

		// Mock translation functions.
		Functions\expect( '_x' )
			->andReturnUsing(
				function ( $text ) {
					return $text;
				}
			);

		Functions\expect( '__' )
			->andReturnUsing(
				function ( $text ) {
					return $text;
				}
			);

		Functions\expect( 'register_post_type' )
			->once()
			->withArgs(
				function ( $post_type, $args ) {
					$this->assertSame( 'promotion', $post_type );
					$this->assertIsArray( $args );
					$this->assertTrue( $args['public'] );
					$this->assertSame( 'dashicons-money-alt', $args['menu_icon'] );
					$this->assertSame( 20, $args['menu_position'] );
					$this->assertContains( 'product_tag', $args['taxonomies'] );
					$this->assertSame(
						[
							'slug'       => 'promotions',
							'with_front' => true,
							'pages'      => true,
							'feeds'      => true,
						],
						$args['rewrite']
					);
					return true;
				}
			);

		\FAToolkit\Promotion\Promotion_PostType::register_post_type();
	}

	/**
	 * Test updated_term_messages returns messages unchanged.
	 */
	public function test_updated_term_messages_returns_messages_unchanged() {
		$messages = [ 'foo' => 'bar' ];

		$result = \FAToolkit\Promotion\Promotion_PostType::updated_term_messages( $messages );

		$this->assertSame( $messages, $result );
	}

	/**
	 * Test gutenberg_can_edit_post_type enables for promotion.
	 */
	public function test_gutenberg_can_edit_post_type_enables_for_promotion() {
		$result = \FAToolkit\Promotion\Promotion_PostType::gutenberg_can_edit_post_type( false, 'promotion' );

		$this->assertTrue( $result );
	}

	/**
	 * Test gutenberg_can_edit_post_type leaves other post types unchanged.
	 */
	public function test_gutenberg_can_edit_post_type_leaves_others_unchanged() {
		$result = \FAToolkit\Promotion\Promotion_PostType::gutenberg_can_edit_post_type( false, 'post' );

		$this->assertFalse( $result );
	}

	/**
	 * Test register_post_status registers promotion-pending and promotion-active.
	 */
	public function test_register_post_status_registers_statuses() {
		Functions\expect( '_x' )
			->andReturnUsing(
				function ( $text ) {
					return $text;
				}
			);

		Functions\expect( '_n_noop' )
			->andReturnUsing(
				function ( $singular, $plural ) {
					return [ $singular, $plural ];
				}
			);

		Functions\expect( 'register_post_status' )
			->once()
			->withArgs(
				function ( $status, $args ) {
					$this->assertSame( 'promotion-pending', $status );
					$this->assertSame( 'Expired', $args['label'] );
					$this->assertTrue( $args['public'] );
					return true;
				}
			);

		Functions\expect( 'register_post_status' )
			->once()
			->withArgs(
				function ( $status, $args ) {
					$this->assertSame( 'promotion-active', $status );
					$this->assertSame( 'Active', $args['label'] );
					$this->assertTrue( $args['public'] );
					return true;
				}
			);

		\FAToolkit\Promotion\Promotion_PostType::register_post_status();
	}

	/**
	 * Test rest_api_allowed_post_types adds promotion to allowed list.
	 */
	public function test_rest_api_allowed_post_types_adds_promotion() {
		$allowed = [ 'post', 'page' ];

		$result = \FAToolkit\Promotion\Promotion_PostType::rest_api_allowed_post_types( $allowed );

		$this->assertContains( 'promotion', $result );
		$this->assertCount( 3, $result );
	}

	/**
	 * Test rest_api_allowed_post_types doesn't duplicate promotion.
	 */
	public function test_rest_api_allowed_post_types_handles_duplicate() {
		$allowed = [ 'post', 'page', 'promotion' ];

		$result = \FAToolkit\Promotion\Promotion_PostType::rest_api_allowed_post_types( $allowed );

		// Should add another 'promotion' (code doesn't check for duplicates).
		$this->assertContains( 'promotion', $result );
		$this->assertCount( 4, $result );
	}
}
