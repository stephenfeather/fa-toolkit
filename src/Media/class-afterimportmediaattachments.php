<?php
/**
 * Creates pointer attachments for newly imported `_fa_media` after an SSI run.
 *
 * @package    fa-toolkit
 * @since 1.2.1
 */

namespace FAToolkit\Media;

if ( defined( 'ABSPATH' ) === false ) {
	die( 'Security (fhi4d6): File addressed directly.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.die
}

/**
 * After-import listener for the content import.
 *
 * Super Speedy Imports writes postmeta with direct SQL, so no per-product
 * WordPress hook fires while it runs. Its `superspeedyimports_after_import_stages`
 * action fires once at the end of every run (SSI 2.88.12, run-import.php:1206)
 * and is the seam this class uses. It fires for the price import too; that
 * import does not map `_fa_media`, so the selection below finds nothing new
 * and the run costs one query.
 *
 * Scope: products whose current `_fa_media` cell has not been applied (no
 * applied marker, or a marker for an older cell), capped per run (issue #93).
 * Dead-URL healing, which unwires a stranded product, stays with the
 * operator's `wp fa:media create-remote-attachments` run.
 */
class AfterImportMediaAttachments {

	/**
	 * Default per-run product cap.
	 */
	private const DEFAULT_LIMIT = 200;

	/**
	 * The shared runner.
	 *
	 * @var RemoteAttachmentRunner
	 */
	private $runner;

	/**
	 * Constructor.
	 *
	 * @param RemoteAttachmentRunner|null $runner Runner; built when omitted.
	 */
	public function __construct( ?RemoteAttachmentRunner $runner = null ) {
		$this->runner = $runner ?? new RemoteAttachmentRunner();

		add_action( 'superspeedyimports_after_import_stages', array( $this, 'run' ), 10, 1 );
	}

	/**
	 * Attach new media once the import's stages have finished.
	 *
	 * @param object $template The SSI import template; unused beyond the signature.
	 * @return array|null The run summary, or null when disabled.
	 */
	public function run( $template ) {
		unset( $template );

		if ( true !== (bool) apply_filters( 'fa_toolkit_after_import_media_enabled', true ) ) {
			return null;
		}

		$limit       = (int) apply_filters( 'fa_toolkit_after_import_media_limit', self::DEFAULT_LIMIT );
		$product_ids = $this->runner->products_with_unapplied_media( $limit );

		$summary = array(
			'products'     => 0,
			'created'      => 0,
			'existing'     => 0,
			'unreachable'  => 0,
			'probe_failed' => 0,
			'failed'       => 0,
			'write_failed' => 0,
			'no_media'     => 0,
			'stranded'     => array(),
		);

		if ( array() !== $product_ids ) {
			$summary = $this->runner->run( $product_ids, false, false, null );
		}

		// Reported separately, always. An unmapped profile leaves the key absent
		// on every product, and that must read as a number, not as silence.
		$summary['no_media_cell'] = $this->runner->products_without_media_cell();
		$summary['limit']         = $limit;

		$this->log( $summary );

		/**
		 * Fires after the after-import media pass with its summary.
		 *
		 * @param array $summary Counts: products, created, existing, unreachable,
		 *                       probe_failed, failed, write_failed, no_media,
		 *                       stranded, no_media_cell, limit.
		 */
		do_action( 'fa_toolkit_after_import_media_run', $summary );

		return $summary;
	}

	/**
	 * Write the summary where the operator running the import will see it.
	 *
	 * @param array $summary Run summary.
	 * @return void
	 */
	private function log( array $summary ) {
		$line = 'fa-toolkit after-import media: ' . wp_json_encode( $summary );

		if ( class_exists( '\WP_CLI' ) ) {
			\WP_CLI::log( $line );
			return;
		}

		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
