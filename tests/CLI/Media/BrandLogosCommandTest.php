<?php
/**
 * Tests for BrandLogosCommand.
 *
 * @package FAToolkit\Tests\CLI\Media
 */

namespace FAToolkit\Tests\CLI\Media;

use FAToolkit\CLI\Media\BrandLogosCommand;
use FAToolkit\Media\BrandLogoCreator;
use FAToolkit\Media\BrandLogoStore;
use FAToolkit\Tests\TestCase;
use Mockery;

/**
 * BrandLogosCommand: `wp fa:media brand-logos`. The decision is
 * BrandLogoPlan's and the writes are BrandLogoCreator's; these tests cover
 * what the command owns: flags, dry run by default, and the report.
 */
class BrandLogosCommandTest extends TestCase {

	/**
	 * Reset the WP_CLI recorder.
	 */
	protected function setUp(): void {
		parent::setUp();
		\WP_CLI::reset_calls();
	}

	/**
	 * The fixture map, in #621's shape.
	 *
	 * @return string
	 */
	private function map() {
		return dirname( __DIR__, 2 ) . '/fixtures/brand-logos/brand_logo_map.json';
	}

	/**
	 * A store over the fixture: a_zoom matches by slug, glock by code,
	 * glock_inc by code with a thumbnail uploaded by hand, nope_brand has no
	 * term.
	 *
	 * @param array $codes    Codes the store must be asked for.
	 * @param array $term_ids Term ids whose thumbnails must be read.
	 * @return \Mockery\MockInterface&BrandLogoStore
	 */
	private function store( array $codes = array( 'a_zoom', 'glock', 'glock_inc', 'nope_brand' ), array $term_ids = array( 8, 7, 12 ) ) {
		$terms = array(
			'a_zoom'    => array(
				'term_id'    => 8,
				'name'       => 'A-Zoom',
				'matched_by' => 'slug',
			),
			'glock'     => array(
				'term_id'    => 7,
				'name'       => 'Glock',
				'matched_by' => 'code',
			),
			'glock_inc' => array(
				'term_id'    => 12,
				'name'       => 'Glock, Inc.',
				'matched_by' => 'code',
			),
		);

		$store = Mockery::mock( BrandLogoStore::class );
		$store->shouldReceive( 'terms_for_codes' )->once()->with( $codes )->andReturn( array_intersect_key( $terms, array_flip( $codes ) ) );
		$store->shouldReceive( 'logo_attachments' )->once()->andReturn( array() );
		$store->shouldReceive( 'thumbnail_states' )->once()->with( $term_ids )->andReturn(
			array_intersect_key(
				array(
					8  => array( 'id' => 0, 'state' => 'empty' ),
					7  => array( 'id' => 0, 'state' => 'empty' ),
					12 => array( 'id' => 300, 'state' => 'manual' ),
				),
				array_flip( $term_ids )
			)
		);

		return $store;
	}

	/**
	 * A creator that must never run.
	 *
	 * @return \Mockery\MockInterface&BrandLogoCreator
	 */
	private function idle_creator() {
		$creator = Mockery::mock( BrandLogoCreator::class );
		$creator->shouldReceive( 'apply' )->never();

		return $creator;
	}

	/**
	 * Messages logged at a level, in order.
	 *
	 * @param string $level WP_CLI method.
	 * @return array<int, string>
	 */
	private function messages( $level ) {
		return array_map( fn( $call ) => $call['args'][0], \WP_CLI::get_calls( $level ) );
	}

	/**
	 * The constructor registers the command on this instance.
	 */
	public function test_constructor_registers_command() {
		$command = new BrandLogosCommand( Mockery::mock( BrandLogoStore::class ), $this->idle_creator() );

		$calls = \WP_CLI::get_calls( 'add_command' );

		$this->assertSame( 'fa:media brand-logos', $calls[0]['args'][0] );
		$this->assertSame( array( $command, 'run' ), $calls[0]['args'][1] );
	}

	/**
	 * Without --map the command stops before reading anything.
	 */
	public function test_a_missing_map_flag_is_an_error() {
		$store = Mockery::mock( BrandLogoStore::class );
		$store->shouldReceive( 'terms_for_codes' )->never();

		$this->expectExceptionMessage( '--map=<path> is required.' );

		( new BrandLogosCommand( $store, $this->idle_creator() ) )->run( array(), array() );
	}

	/**
	 * An unreadable map is an error naming the path.
	 */
	public function test_an_unreadable_map_is_an_error() {
		$this->expectExceptionMessage( 'Cannot read map: /nonexistent/brand_logo_map.json' );

		( new BrandLogosCommand( Mockery::mock( BrandLogoStore::class ), $this->idle_creator() ) )->run( array(), array( 'map' => '/nonexistent/brand_logo_map.json' ) );
	}

	/**
	 * A map with no usable entry warns its errors and stops.
	 */
	public function test_a_map_with_no_usable_entry_is_an_error() {
		$path = sys_get_temp_dir() . '/fa-toolkit-brand-logos-' . getmypid() . '.json';
		file_put_contents( $path, '{"broken":{"status":"logo"}}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		try {
			( new BrandLogosCommand( Mockery::mock( BrandLogoStore::class ), $this->idle_creator() ) )->run( array(), array( 'map' => $path ) );
			$this->fail( 'Expected an error.' );
		} catch ( \Exception $error ) {
			$this->assertSame( 'The map has no usable entries.', $error->getMessage() );
		} finally {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		$this->assertSame( array( 'map: broken: logo entry needs s3_key and url' ), $this->messages( 'warning' ) );
	}

	/**
	 * Without --execute nothing is applied; the report says what would be.
	 */
	public function test_dry_run_is_the_default_and_reports_the_plan() {
		( new BrandLogosCommand( $this->store(), $this->idle_creator() ) )->run( array(), array( 'map' => $this->map() ) );

		$this->assertSame(
			array(
				'brand a_zoom | matched by slug | term 8',
				'brand glock_inc | thumbnail kept: attachment 300 was not set by this command; --replace overwrites it',
				'brand nope_brand | no product_brand term',
				'TOTAL brands 7 | logos 4 | attachments to create 2 | to reuse 0 | thumbnails to set 2 | already set 0 | kept manual 1 | no term 1 | matched by slug 1 | skipped missing 1 | conflict 1 | near_identical 1 | map errors 1',
			),
			$this->messages( 'log' )
		);
		$this->assertSame( array( 'map: broken: logo entry needs s3_key and url' ), $this->messages( 'warning' ) );
		$this->assertSame( array( 'Dry run: nothing was written. Re-run with --execute to apply.' ), $this->messages( 'success' ) );
	}

	/**
	 * --dry-run wins over --execute.
	 */
	public function test_dry_run_wins_over_execute() {
		( new BrandLogosCommand( $this->store(), $this->idle_creator() ) )->run(
			array(),
			array(
				'map'     => $this->map(),
				'execute' => true,
				'dry-run' => true,
			)
		);

		$this->assertSame( array( 'Dry run: nothing was written. Re-run with --execute to apply.' ), $this->messages( 'success' ) );
	}

	/**
	 * --execute hands the plan to the creator and reports what it did,
	 * warning about brands left without a logo.
	 */
	public function test_execute_applies_the_plan_and_reports() {
		$creator = Mockery::mock( BrandLogoCreator::class );
		$creator->shouldReceive( 'apply' )
			->once()
			->with( Mockery::on( fn( $plan ) => array( 'files/product_brands/A-Zoom-Logo.jpg', 'files/product_brands/Glock-Logo.jpg' ) === array_keys( $plan['attachments'] ) ) )
			->andReturn(
				array(
					'created'        => 1,
					'refreshed'      => 0,
					'thumbnails_set' => 1,
					'dead'           => 0,
					'fetch_failed'   => 1,
					'not_image'      => 0,
					'insert_failed'  => 0,
					'write_failed'   => 0,
					'blocked'        => array( 'glock' ),
				)
			);

		( new BrandLogosCommand( $this->store(), $creator ) )->run(
			array(),
			array(
				'map'     => $this->map(),
				'execute' => true,
			)
		);

		$this->assertSame( 'APPLIED created 1 | refreshed 0 | thumbnails set 1 | dead urls 0 | fetch failures 1 | not images 0 | insert failures 0 | write failures 0', array_slice( $this->messages( 'log' ), -1 )[0] );
		$this->assertContains( '1 brands got no logo this run; re-running retries them: glock', $this->messages( 'warning' ) );
		$this->assertSame( array( 'Done.' ), $this->messages( 'success' ) );
	}

	/**
	 * --brand limits the run to the named codes and warns about any not in
	 * the map.
	 */
	public function test_brand_flag_limits_the_run() {
		( new BrandLogosCommand( $this->store( array( 'glock' ), array( 7 ) ), $this->idle_creator() ) )->run(
			array(),
			array(
				'map'   => $this->map(),
				'brand' => 'glock, unknown',
			)
		);

		$this->assertContains( 'Not in map: unknown', $this->messages( 'warning' ) );
		$this->assertStringStartsWith( 'TOTAL brands 1 | logos 1 | attachments to create 1 |', array_slice( $this->messages( 'log' ), -1 )[0] );
	}

	/**
	 * --replace overwrites a thumbnail uploaded by hand.
	 */
	public function test_replace_overwrites_manual_thumbnails() {
		( new BrandLogosCommand( $this->store(), $this->idle_creator() ) )->run(
			array(),
			array(
				'map'     => $this->map(),
				'replace' => true,
			)
		);

		$this->assertStringContainsString( '| thumbnails to set 3 | already set 0 | kept manual 0 |', array_slice( $this->messages( 'log' ), -1 )[0] );
	}
}
