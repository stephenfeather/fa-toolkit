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
 */

// Consumer project root. Defaults to this file's directory, so it works when
// copied into the consumer; pass an explicit path as argv[1] if it is run from
// anywhere else. Resolved rather than assumed, because a wrong root would make
// every check below fail for the wrong reason.
$root = rtrim($argv[1] ?? __DIR__, '/');
if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "No vendor/autoload.php under '$root'.\n"
        . "Run `composer install` in the consumer first, or pass its path as argv[1].\n");
    exit(2);
}

$pkg  = $root . '/web/app/plugins/fa-toolkit';
$fail = 0;
$check = function (string $label, bool $ok) use (&$fail): void {
    printf("%-58s %s\n", $label, $ok ? 'PASS' : 'FAIL');
    if (!$ok) { $fail++; }
};

$check('1. installed at web/app/plugins/fa-toolkit/', is_file($pkg . '/fa-toolkit.php'));

// Tests for the package's CODE under vendor/, not merely for the directory.
//
// Whenever Composer ATTEMPTS a dist download it creates vendor/<vendor>/<name>
// as the extraction target; composer/installers then relocates the package to
// installer-paths and the now-empty directory is left behind. This happens even
// when the dist download FAILS and Composer falls back to a source clone, so it
// is the attempt that creates it, not a successful extraction. A pure source
// install never creates it, and a VCS repository pointed at a LOCAL path
// exposes no dist URL, so it always installs from source. That is the trap:
// whether this leftover exists depends on the install ROUTE, not on whether
// the install is correct. An is_dir() check therefore passes against a local
// path repo and fails against the remote - so it can look fine in one job and
// break in another while nothing is actually wrong.
//
// So is_dir() here fails on CORRECT installs. The failure actually worth
// catching is installer-paths not being honoured, which leaves fa-toolkit.php
// in vendor/ where WordPress cannot see it. The directory is an implementation
// detail of Composer's download staging; the code being there is the defect.
// (Do not simplify back to is_dir.)
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
// So *loading* a class needs WordPress; verifying the mapping does not.
// If that self-instantiation is ever removed, this can and should be upgraded
// to a real resolution probe (class_exists on every mapped class), which would
// be a strictly stronger assertion than the path check below.
//
// realpath() returns false for a missing dir, and false coerces to '' in a
// string comparison - which would make every path "inside" a target that does
// not exist and pass this check vacuously. Resolve the target first and fail
// loudly if it is absent. (Found independently by two negative controls, on
// both sides of this file's authorship. Do not simplify away.)
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
printf("   (%d paths checked)\n", $pkgReal === false ? 0 : count($map));
foreach ($bad as $b) { echo "   BAD: $b\n"; }

printf("\n%s\n", $fail === 0 ? 'ALL CHECKS PASSED' : "$fail CHECK(S) FAILED");
exit($fail === 0 ? 0 : 1);
