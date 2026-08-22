<?php
/**
 * Tests for Product_Display_Id class.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Admin;

use FAToolkit\Admin\Product_Display_Id;
use FAToolkit\Tests\TestCase;
use ReflectionClass;

/**
 * Test Product_Display_Id class.
 *
 * Note: Constructor tests skipped due to Brain Monkey callback validation issues
 * with auto-instantiated classes. The class auto-instantiates at file load.
 */
class Product_Display_IdTest extends TestCase {

	/**
	 * Create instance without calling constructor.
	 *
	 * @return Product_Display_Id
	 */
	private function create_instance_without_constructor() {
		$reflection = new ReflectionClass( Product_Display_Id::class );
		return $reflection->newInstanceWithoutConstructor();
	}

	/**
	 * Test add_id_column method adds ID column.
	 */
	public function test_add_id_column_adds_id_column() {
		$instance = $this->create_instance_without_constructor();

		$input_columns = array(
			'cb'    => '<input type="checkbox">',
			'title' => 'Title',
			'price' => 'Price',
		);

		// Mock global wpdb.
		global $wpdb;
		$wpdb          = new \stdClass();
		$wpdb->queries = array();

		// Mock ray() function (debugging tool).
		\Brain\Monkey\Functions\when( 'ray' )->justReturn( null );

		$result = $instance->add_id_column( $input_columns );

		// Verify ID column was added.
		$this->assertArrayHasKey( 'ID', $result );
		$this->assertEquals( 'ID', $result['ID'] );

		// Verify original columns are preserved.
		$this->assertArrayHasKey( 'cb', $result );
		$this->assertArrayHasKey( 'title', $result );
		$this->assertArrayHasKey( 'price', $result );
		$this->assertCount( 4, $result );
	}

	/**
	 * Test add_id_column_content outputs post ID for ID column.
	 */
	public function test_add_id_column_content_outputs_id_for_id_column() {
		$instance = $this->create_instance_without_constructor();

		// The source escapes with absint(), not esc_html(). These tests were
		// written against an esc_html() implementation that no longer exists.
		\Brain\Monkey\Functions\expect( 'absint' )
			->once()
			->with( 123 )
			->andReturn( 123 );

		ob_start();
		$instance->add_id_column_content( 'ID', 123 );
		$output = ob_get_clean();

		$this->assertEquals( '123', $output );
	}

	/**
	 * Test add_id_column_content outputs nothing for non-ID column.
	 */
	public function test_add_id_column_content_outputs_nothing_for_other_column() {
		$instance = $this->create_instance_without_constructor();

		// absint should not be called.
		\Brain\Monkey\Functions\expect( 'absint' )
			->never();

		ob_start();
		$instance->add_id_column_content( 'title', 123 );
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test add_id_column_content handles different post IDs.
	 */
	public function test_add_id_column_content_handles_different_post_ids() {
		$instance = $this->create_instance_without_constructor();

		$test_ids = array( 1, 999, 12345 );

		foreach ( $test_ids as $test_id ) {
			// See the note above: the source uses absint(), not esc_html().
			\Brain\Monkey\Functions\expect( 'absint' )
				->once()
				->with( $test_id )
				->andReturn( $test_id );

			ob_start();
			$instance->add_id_column_content( 'ID', $test_id );
			$output = ob_get_clean();

			$this->assertEquals( (string) $test_id, $output );
		}
	}
}
