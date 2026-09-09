<?php
/**
 * Install verification for featherarms/fa-toolkit. No WordPress required.
 *
 * Proves what a real `composer install` produced:
 *   1. the package landed at the installer-paths target, not in vendor/
 *   2. it ships no vendor/ of its own
 *   3. its classmap merged into the CONSUMING project's root autoloader
 *   4. every mapped path resolves inside the install target
 *
 * No WordPress function is called and none is stubbed. Nothing here loads a
 * fa-toolkit class - see the note above check 4 for why that is deliberate.
 *
 * Authored by docker-dev, who ran it against a real composer install.
 *
 * @package FA-Toolkit
 */

// Consumer project root. Defaults to this file's directory, so it works when
// copied into the consumer; pass an explicit path as argv[1] if it is run from
// anywhere else. Resolved rather than assumed, because a wrong root would make
// every check below fail for the wrong reason.
$root = rtrim( $argv[1] ?? __DIR__, '/' );
if ( ! is_file( $root . '/vendor/autoload.php' ) ) {
	fwrite(
		STDERR,
		"No vendor/autoload.php under '$root'.\n"
		. "Run `composer install` in the consumer first, or pass its path as argv[1].\n"
	);
	exit( 2 );
}

$pkg   = $root . '/web/app/plugins/fa-toolkit';
$fail  = 0;
$check = function ( string $label, bool $ok ) use ( &$fail ): void {
	printf( "%-58s %s\n", $label, $ok ? 'PASS' : 'FAIL' );
	if ( ! $ok ) {
		++$fail;
	}
};

$check( '1. installed at web/app/plugins/fa-toolkit/', is_file( $pkg . '/fa-toolkit.php' ) );

// Tests for the package's CODE under vendor/, not merely for the directory.
//
// Composer stages a dist download through vendor/<vendor>/<name>, and an EMPTY
// directory can be left there after composer/installers relocates the package.
// Measured across three routes:
//
// dist attempted and FAILED, fell back to source  -> leftover PRESENT
// dist attempted and succeeded                    -> no leftover
// local path repo (no dist URL, source only)      -> no leftover
//
// So the leftover tracks a dist attempt that did not complete - which is a
// property of the install ROUTE and of network conditions, not of whether the
// install is correct. An is_dir() check here is therefore red on a perfectly
// good install whenever a dist download happens to fail and fall back, and
// green on the identical install when it does not. That is a flaky assertion
// wearing a correctness costume.
//
// So is_dir() here fails on CORRECT installs. The failure actually worth
// catching is installer-paths not being honoured, which leaves fa-toolkit.php
// in vendor/ where WordPress cannot see it. The directory is an implementation
// detail of Composer's download staging; the code being there is the defect.
// Do not simplify back to is_dir().
$check(
	'1. NOT installed into vendor/',
	! is_file( $root . '/vendor/featherarms/fa-toolkit/fa-toolkit.php' )
);

$check( '2. package ships no vendor/autoload.php', ! is_file( $pkg . '/vendor/autoload.php' ) );

$loader = require $root . '/vendor/autoload.php';
$map    = array_filter(
	$loader->getClassMap(),
	fn( string $c ): bool => str_starts_with( $c, 'FAToolkit\\' ),
	ARRAY_FILTER_USE_KEY
);
$check( '3. FAToolkit classes present in root classmap', count( $map ) > 0 );
printf( "   (%d classes mapped)\n", count( $map ) );

// Deliberately a static path check rather than a class_exists() probe. fa-toolkit's class files are
// not side-effect-free on load: 13 of them instantiate themselves at file scope
// (constructors call add_action), and the CLI ones call WP_CLI::add_command().
// So *loading* a class needs WordPress; verifying the mapping does not.
// If that self-instantiation is ever removed, this can and should be upgraded
// to a real resolution probe (class_exists on every mapped class), which would
// be a strictly stronger assertion than the path check below.
//
// realpath() returns false for a missing dir, and false coerces to '' in a
// string comparison - which would make every path "inside" a target that does
// not exist and pass this check vacuously. Resolve the target first and fail
// loudly if it is absent. Found independently by two negative controls, on
// both sides of this file's authorship. Do not simplify away.
$pkg_real = realpath( $pkg );
$bad      = array();
if ( false === $pkg_real ) {
	$bad[] = 'install target does not exist: ' . $pkg;
} else {
	// The trailing separator is load-bearing. Without it this is a string
	// prefix test rather than a path containment test, so a SIBLING directory
	// whose name merely starts with the target - web/app/plugins/fa-toolkit-legacy
	// against a target of web/app/plugins/fa-toolkit - would count as "inside".
	// Not reachable with today's single-package classmap, but this check exists
	// to be precise about paths, and it is the same shape as the realpath bug
	// above: an expression that looks like containment and isn't.
	$prefix = $pkg_real . DIRECTORY_SEPARATOR;
	foreach ( $map as $class => $file ) {
		$real = realpath( $file );
		if ( false === $real || ! str_starts_with( $real, $prefix ) ) {
			$bad[] = $class . ' -> ' . $file;
		}
	}
}
$check( '4. every mapped class points inside the install target', array() === $bad );
printf( "   (%d paths checked)\n", false === $pkg_real ? 0 : count( $map ) );
foreach ( $bad as $b ) {
	echo "   BAD: $b\n";
}

printf( "\n%s\n", 0 === $fail ? 'ALL CHECKS PASSED' : "$fail CHECK(S) FAILED" );
exit( 0 === $fail ? 0 : 1 );
