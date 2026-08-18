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
 * Authored by docker-dev, who ran it against a real composer install before
 * it was adopted here.
 */
$root = __DIR__;
$pkg  = $root . '/web/app/plugins/fa-toolkit';
$fail = 0;
$check = function (string $label, bool $ok) use (&$fail): void {
    printf("%-58s %s\n", $label, $ok ? 'PASS' : 'FAIL');
    if (!$ok) { $fail++; }
};

$check('1. installed at web/app/plugins/fa-toolkit/', is_file($pkg . '/fa-toolkit.php'));

// Tests for the package's CODE under vendor/, not merely for the directory.
// Composer creates vendor/<vendor>/<name> as the download target and
// composer/installers then relocates the package, which can leave an EMPTY
// directory behind - observed on a real source install. An is_dir() check
// fails on that harmless leftover and would make this job red for the wrong
// reason. The failure actually worth catching is installer-paths not being
// honoured, which puts fa-toolkit.php in vendor/ where WordPress cannot see it.
$check(
    '1. NOT installed into vendor/',
    !is_file($root . '/vendor/featherarms/fa-toolkit/fa-toolkit.php')
);
$check('2. package ships no vendor/autoload.php', !is_file($pkg . '/vendor/autoload.php'));

$loader = require $root . '/vendor/autoload.php';
$map = array_filter(
    $loader->getClassMap(),
    fn(string $c): bool => str_starts_with($c, 'FAToolkit\\'),
    ARRAY_FILTER_USE_KEY
);
$check('3. FAToolkit classes present in root classmap', count($map) > 0);
printf("   (%d classes mapped)\n", count($map));

// Deliberately a STATIC check, not class_exists(). fa-toolkit's class files are
// not side-effect-free on load: 13 of them instantiate themselves at file scope
// (constructors call add_action), and the CLI ones call WP_CLI::add_command().
// So *loading* a class needs WordPress; verifying the mapping does not. This
// asserts every mapped path resolves inside the install target.
//
// If those file-scope instantiations are ever removed, this can be upgraded to
// a real resolution probe (class_exists on each mapped class), which would make
// the check strictly stronger. The static form is a constraint the package
// imposes on its own test, not a shortcut.
// Resolve the target ONCE and fail loudly if it does not exist. Inlining
// realpath($pkg) into the comparison made this check pass vacuously whenever
// the package was missing from the expected location: realpath() returns false,
// false coerces to '', and str_starts_with($anything, '') is true - so the
// check silently passed in precisely the case it exists to catch. Found by
// running the negative control below; it is not hypothetical.
$pkgReal = realpath($pkg);
$bad = [];
if ($pkgReal === false) {
    $bad[] = 'install target does not exist: ' . $pkg;
} else {
    foreach ($map as $class => $file) {
        $real = realpath($file);
        if ($real === false || !str_starts_with($real, $pkgReal)) {
            $bad[] = $class . ' -> ' . $file;
        }
    }
}
$check('4. every mapped class points inside the install target', $bad === []);
printf("   (%d paths checked)\n", count($map));
foreach ($bad as $b) { echo "   BAD: $b\n"; }

printf("\n%s\n", $fail === 0 ? 'ALL CHECKS PASSED' : "$fail CHECK(S) FAILED");
exit($fail === 0 ? 0 : 1);
