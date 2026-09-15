<?php
/**
 * WP-CLI command giving product_brand terms their logo.
 *
 * @package    fa-toolkit
 * @since 1.2.6
 */

namespace FAToolkit\CLI\Media;

use FAToolkit\Media\BrandLogoCreator;
use FAToolkit\Media\BrandLogoMap;
use FAToolkit\Media\BrandLogoPlan;
use FAToolkit\Media\BrandLogoStore;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * Sets WooCommerce's native brand image from brand_logo_map.json (issue #105).
 *
 * The decision is BrandLogoPlan's, the reads BrandLogoStore's and the writes
 * BrandLogoCreator's. This class owns the flags and the report.
 */
class BrandLogosCommand {

	/**
	 * The reads.
	 *
	 * @var BrandLogoStore
	 */
	private $store;

	/**
	 * The writes.
	 *
	 * @var BrandLogoCreator
	 */
	private $creator;

	/**
	 * Constructor.
	 *
	 * @param BrandLogoStore|null   $store   Store; built when omitted.
	 * @param BrandLogoCreator|null $creator Creator; built when omitted.
	 */
	public function __construct( ?BrandLogoStore $store = null, ?BrandLogoCreator $creator = null ) {
		$this->store   = $store ?? new BrandLogoStore();
		$this->creator = $creator ?? new BrandLogoCreator();

		\WP_CLI::add_command( 'fa:media brand-logos', array( $this, 'run' ) );
	}

	/**
	 * Give product_brand terms their logo from brand_logo_map.json.
	 *
	 * Dry run unless --execute is given. Only `logo` entries are applied;
	 * MISSING, conflict, ambiguous and near_identical entries are counted and
	 * skipped.
	 * One unparented remote attachment is created per s3_key and shared by
	 * every brand mapping to it. A thumbnail uploaded by hand is kept unless
	 * --replace is given. Rerunning an unchanged map writes nothing.
	 *
	 * ## OPTIONS
	 *
	 * --map=<path>
	 * : Path to brand_logo_map.json.
	 *
	 * [--brand=<code>]
	 * : Only these brand codes. Comma-separated.
	 *
	 * [--execute]
	 * : Write. Without it nothing is written.
	 *
	 * [--dry-run]
	 * : Report without writing (the default; wins over --execute).
	 *
	 * [--replace]
	 * : Overwrite thumbnails that this command did not set.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function run( $args, $assoc_args ) {
		unset( $args );

		list( $entries, $error_count ) = $this->entries( $assoc_args );

		if ( null === $entries ) {
			return;
		}

		$execute = isset( $assoc_args['execute'] ) && ! isset( $assoc_args['dry-run'] );
		$logos   = array_filter( $entries, fn( $entry ) => 'logo' === $entry['status'] );
		$terms   = $this->store->terms_for_codes( array_map( 'strval', array_keys( $logos ) ) );

		$attachments = $this->store->logo_attachments();
		$term_ids    = array_values( array_unique( array_map( fn( $term ) => (int) $term['term_id'], array_values( array_intersect_key( $terms, $logos ) ) ) ) );
		$thumbnails  = array() === $term_ids ? array() : $this->store->thumbnail_states( $term_ids );

		$plan = BrandLogoPlan::build( $entries, $terms, $attachments, $thumbnails, isset( $assoc_args['replace'] ) );

		$this->report_plan( $plan, count( $entries ), count( $logos ), $error_count );

		if ( true !== $execute ) {
			\WP_CLI::success( 'Dry run: nothing was written. Re-run with --execute to apply.' );
			return;
		}

		$this->report_applied( $this->creator->apply( $plan ) );

		\WP_CLI::success( 'Done.' );
	}

	/**
	 * Read, parse and filter the map.
	 *
	 * @param array $assoc_args Flags.
	 * @return array{0:array<string,array>|null,1:int} Entries (null when stopped) and the map error count.
	 */
	private function entries( array $assoc_args ) {
		$path = $assoc_args['map'] ?? '';

		if ( true !== is_string( $path ) || '' === $path ) {
			\WP_CLI::error( '--map=<path> is required.' );
			return array( null, 0 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file named by the operator.
		$json = true === is_readable( $path ) ? file_get_contents( $path ) : false;

		if ( false === $json ) {
			\WP_CLI::error( 'Cannot read map: ' . $path );
			return array( null, 0 );
		}

		$map = BrandLogoMap::parse( $json );

		foreach ( $map['errors'] as $error ) {
			\WP_CLI::warning( 'map: ' . $error );
		}

		$entries = $this->only_brands( $map['entries'], $assoc_args['brand'] ?? null );

		if ( array() === $entries ) {
			\WP_CLI::error( 'The map has no usable entries.' );
			return array( null, 0 );
		}

		return array( $entries, count( $map['errors'] ) );
	}

	/**
	 * Entries limited to a --brand list, warning about codes not in the map.
	 *
	 * @param array<string, array> $entries Entries.
	 * @param mixed                $flag    Raw --brand value, or null.
	 * @return array<string, array>
	 */
	private function only_brands( array $entries, $flag ) {
		if ( true !== is_string( $flag ) ) {
			return $entries;
		}

		$wanted = array_values( array_filter( array_map( 'trim', explode( ',', $flag ) ), fn( $code ) => '' !== $code ) );

		foreach ( array_diff( $wanted, array_map( 'strval', array_keys( $entries ) ) ) as $code ) {
			\WP_CLI::warning( 'Not in map: ' . $code );
		}

		return array_intersect_key( $entries, array_flip( $wanted ) );
	}

	/**
	 * Per-brand lines worth reading, then the TOTAL line.
	 *
	 * @param array $plan        Plan.
	 * @param int   $brands      Entries in scope.
	 * @param int   $logos       Logo entries in scope.
	 * @param int   $error_count Map errors.
	 * @return void
	 */
	private function report_plan( array $plan, $brands, $logos, $error_count ) {
		foreach ( $plan['rows'] as $row ) {
			if ( 'slug' === $row['matched_by'] ) {
				\WP_CLI::log( sprintf( 'brand %s | matched by slug | term %d', $row['code'], $row['term_id'] ) );
			}

			if ( 'kept_manual' === $row['action'] ) {
				\WP_CLI::log( sprintf( 'brand %s | thumbnail kept: attachment %d was not set by this command; --replace overwrites it', $row['code'], $row['current_id'] ) );
			}
		}

		foreach ( $plan['no_term'] as $code ) {
			\WP_CLI::log( sprintf( 'brand %s | no product_brand term', $code ) );
		}

		foreach ( $plan['attachments'] as $attachment ) {
			if ( array() !== $attachment['unused_names'] ) {
				\WP_CLI::log( sprintf( 'logo %s | alt "%s" shared by terms %s; other names: %s', $attachment['s3_key'], $attachment['alt'], implode( ',', $attachment['term_ids'] ), implode( ', ', $attachment['unused_names'] ) ) );
			}
		}

		$actions = array_count_values( array_column( $plan['rows'], 'action' ) );
		$created = count( array_filter( $plan['attachments'], fn( $attachment ) => 0 === $attachment['attachment_id'] ) );

		\WP_CLI::log(
			sprintf(
				'TOTAL brands %d | logos %d | attachments to create %d | to reuse %d | thumbnails to set %d | already set %d | kept manual %d | no term %d | matched by slug %d | skipped missing %d | conflict %d | ambiguous %d | near_identical %d | map errors %d',
				$brands,
				$logos,
				$created,
				count( $plan['attachments'] ) - $created,
				$actions['set'] ?? 0,
				$actions['already_set'] ?? 0,
				$actions['kept_manual'] ?? 0,
				count( $plan['no_term'] ),
				count( array_filter( $plan['rows'], fn( $row ) => 'slug' === $row['matched_by'] ) ),
				count( $plan['skipped']['MISSING'] ),
				count( $plan['skipped']['conflict'] ),
				count( $plan['skipped']['ambiguous'] ),
				count( $plan['skipped']['near_identical'] ),
				$error_count
			)
		);
	}

	/**
	 * The APPLIED line, and a warning for brands left without a logo.
	 *
	 * @param array $totals Creator totals.
	 * @return void
	 */
	private function report_applied( array $totals ) {
		\WP_CLI::log(
			sprintf(
				'APPLIED created %d | refreshed %d | thumbnails set %d | insert failures %d | write failures %d',
				$totals['created'],
				$totals['refreshed'],
				$totals['thumbnails_set'],
				$totals['insert_failed'],
				$totals['write_failed']
			)
		);

		if ( array() !== $totals['blocked'] ) {
			\WP_CLI::warning( sprintf( '%d brands got no logo this run; re-running retries them: %s', count( $totals['blocked'] ), implode( ',', $totals['blocked'] ) ) );
		}
	}
}
