<?php
/**
 * Assertion helper for the ACF-dependency defect tracked in issue #77.
 *
 * @package FA-Toolkit
 */

namespace FAToolkit\Tests\Support;

/**
 * Asserts that a PHP file calls no Advanced Custom Fields function.
 *
 * Background (issue #77): the plugin moved off ACF but ten get_field() calls
 * survived. ACF is not installed, so each is a fatal waiting for its hook to
 * fire. The Vendor product-list column fired on every wp-admin product list
 * load and emptied the table. This net makes any reintroduction fail in CI.
 *
 * Uses the tokenizer so that function names inside comments, strings and
 * inline HTML cannot produce false positives: only a T_STRING token that is
 * immediately followed by `(` and NOT preceded by `->`, `?->`, `::` or
 * `function` counts as a call.
 */
trait AssertsNoAcfFunctionCalls {

	/**
	 * ACF public API functions the plugin must not call.
	 *
	 * @var string[]
	 */
	private static $acf_functions = array(
		'get_field',
		'the_field',
		'get_fields',
		'get_field_object',
		'get_field_objects',
		'update_field',
		'delete_field',
		'have_rows',
		'the_row',
		'get_row',
		'get_sub_field',
		'the_sub_field',
		'have_sub_field',
		'acf_add_local_field_group',
		'acf_register_block_type',
	);

	/**
	 * Assert that the given PHP file contains no call to an ACF function.
	 *
	 * @param string $file Absolute path to the PHP file to inspect.
	 * @return void
	 */
	protected function assertNoAcfFunctionCalls( $file ) {
		$this->assertFileExists( $file );

		$tokens = token_get_all( file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$found  = array();
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
				continue;
			}
			if ( ! in_array( $token[1], self::$acf_functions, true ) ) {
				continue;
			}
			if ( ! $this->is_function_call( $tokens, $i ) ) {
				continue;
			}
			$found[] = $token[1] . '() at line ' . $token[2];
		}

		$this->assertSame(
			array(),
			$found,
			sprintf(
				'%s calls an ACF function (%s). ACF is not installed; read the value with '
				. 'get_post_meta( $post_id, \'<meta key>\', true ) instead. See issue #77.',
				basename( $file ),
				implode( ', ', $found )
			)
		);
	}

	/**
	 * Whether the T_STRING at $index is a plain function call.
	 *
	 * Skips whitespace/comments to find the next significant token (must be
	 * `(`) and the previous one (must not be `->`, `?->`, `::` or `function`).
	 *
	 * @param array $tokens Full token stream.
	 * @param int   $index  Index of the T_STRING token.
	 * @return bool
	 */
	private function is_function_call( array $tokens, $index ) {
		$next = $this->significant_token( $tokens, $index, 1 );
		if ( '(' !== $next ) {
			return false;
		}

		$prev = $this->significant_token( $tokens, $index, -1 );
		if ( is_array( $prev ) ) {
			return ! in_array(
				$prev[0],
				array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ),
				true
			);
		}

		return true;
	}

	/**
	 * Return the next (or previous) non-whitespace, non-comment token.
	 *
	 * @param array $tokens Full token stream.
	 * @param int   $index  Starting index.
	 * @param int   $step   +1 or -1.
	 * @return array|string|null Token, or null at the boundary.
	 */
	private function significant_token( array $tokens, $index, $step ) {
		$i = $index + $step;
		while ( isset( $tokens[ $i ] ) ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				return $token;
			}
			$i += $step;
		}
		return null;
	}
}
