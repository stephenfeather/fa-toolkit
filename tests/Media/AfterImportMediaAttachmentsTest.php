<?php
/**
 * Tests for AfterImportMediaAttachments.
 *
 * @package FAToolkit\Tests\Media
 */

namespace FAToolkit\Tests\Media;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use FAToolkit\Media\AfterImportMediaAttachments;
use FAToolkit\Media\RemoteAttachmentRunner;
use FAToolkit\Tests\TestCase;
use Mockery;

/**
 * AfterImportMediaAttachments: creates pointer attachments for newly imported
 * `_fa_media` once a Super Speedy Imports run finishes.
 *
 * SSI writes postmeta with direct SQL, so no per-product WordPress hook fires
 * during the import; `superspeedyimports_after_import_stages` fires once at
 * the end of every run (run-import.php:1206 in SSI 2.88.12) and is the seam.
 */
class AfterImportMediaAttachmentsTest extends TestCase {

	/**
	 * A runner mock.
	 *
	 * @return \Mockery\MockInterface&RemoteAttachmentRunner
	 */
	private function runner() {
		return Mockery::mock( RemoteAttachmentRunner::class );
	}

	/**
	 * A completed-run result.
	 *
	 * @param array $overrides Fields to override.
	 * @return array
	 */
	private function run_result( array $overrides = array() ) {
		return array_merge(
			array(
				'products'    => 0,
				'created'     => 0,
				'existing'    => 0,
				'unreachable' => 0,
				'failed'      => 0,
				'no_media'    => 0,
				'stranded'    => array(),
			),
			$overrides
		);
	}

	/**
	 * The constructor hooks the run onto SSI's after-stages action.
	 */
	public function test_constructor_registers_on_ssi_after_import_stages() {
		$listener = new AfterImportMediaAttachments( $this->runner() );

		$this->assertNotFalse( has_action( 'superspeedyimports_after_import_stages', array( $listener, 'run' ) ) );
	}

	/**
	 * The `fa_toolkit_after_import_media_enabled` filter, returning false,
	 * turns the listener into a no-op that touches no product.
	 */
	public function test_run_does_nothing_when_disabled_by_filter() {
		$runner = $this->runner();
		$runner->shouldReceive( 'unattached_products_with_media' )->never();
		$runner->shouldReceive( 'run' )->never();
		Filters\expectApplied( 'fa_toolkit_after_import_media_enabled' )->once()->with( true )->andReturn( false );

		$this->assertNull( ( new AfterImportMediaAttachments( $runner ) )->run( new \stdClass() ) );
	}

	/**
	 * With nothing new to attach the listener still reports, so an unmapped
	 * profile shows up as a count of products with no cell rather than silence.
	 */
	public function test_run_reports_even_when_no_product_needs_work() {
		$runner = $this->runner();
		$runner->shouldReceive( 'unattached_products_with_media' )->once()->with( 200 )->andReturn( array() );
		$runner->shouldReceive( 'products_without_media_cell' )->once()->andReturn( 70619 );
		$runner->shouldReceive( 'run' )->never();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		$reported = array();
		Functions\when( 'do_action' )->alias(
			function ( $hook, ...$args ) use ( &$reported ) {
				if ( 'fa_toolkit_after_import_media_run' === $hook ) {
					$reported[] = $args[0];
				}
			}
		);

		$summary = ( new AfterImportMediaAttachments( $runner ) )->run( new \stdClass() );

		$this->assertSame( 0, $summary['products'] );
		$this->assertSame( 70619, $summary['no_media_cell'] );
		$this->assertSame( array( $summary ), $reported );
	}

	/**
	 * Products carrying media with no attachment yet are run through the
	 * creator, never in dry-run, never probing safe URLs, and the result is
	 * merged with the no-cell count into one summary.
	 */
	public function test_run_attaches_unattached_products_and_summarises() {
		$runner = $this->runner();
		$runner->shouldReceive( 'unattached_products_with_media' )->once()->with( 200 )->andReturn( array( 4, 8 ) );
		$runner->shouldReceive( 'run' )
			->once()
			->with( array( 4, 8 ), false, false, null )
			->andReturn( $this->run_result( array( 'products' => 2, 'created' => 3, 'stranded' => array( 8 ) ) ) );
		$runner->shouldReceive( 'products_without_media_cell' )->once()->andReturn( 5 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'do_action' )->justReturn( null );

		$summary = ( new AfterImportMediaAttachments( $runner ) )->run( new \stdClass() );

		$this->assertSame( 2, $summary['products'] );
		$this->assertSame( 3, $summary['created'] );
		$this->assertSame( array( 8 ), $summary['stranded'] );
		$this->assertSame( 5, $summary['no_media_cell'] );
	}

	/**
	 * The per-run product cap is filterable, so an operator can raise it for a
	 * bulk catch-up or lower it on a slow host.
	 */
	public function test_run_limit_is_filterable() {
		$runner = $this->runner();
		$runner->shouldReceive( 'unattached_products_with_media' )->once()->with( 25 )->andReturn( array() );
		$runner->shouldReceive( 'products_without_media_cell' )->andReturn( 0 );
		Filters\expectApplied( 'fa_toolkit_after_import_media_limit' )->once()->with( 200 )->andReturn( 25 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'do_action' )->justReturn( null );

		( new AfterImportMediaAttachments( $runner ) )->run( new \stdClass() );
	}
}
