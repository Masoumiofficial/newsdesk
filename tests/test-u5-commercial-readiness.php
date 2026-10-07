<?php
/**
 * U-5 — marketplace readiness.
 *
 * A commercial listing is rejected for things that have nothing to do with code
 * quality: a documentation file that does not exist, a version that disagrees
 * with itself across four files, a changelog that forgot the release. These are
 * cheap to assert and expensive to discover during review, so they are pinned
 * here rather than left to a human checklist.
 *
 * Every expectation is derived from the package, never hardcoded, so bumping the
 * version does not require editing this file.
 *
 * @package NewsDesk\AI\Tests
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$dir = rtrim( NEWSDESK_DIR, '/' );

$read = static function ( string $rel ) use ( $dir ): string {
	$path = $dir . '/' . ltrim( $rel, '/' );
	return is_file( $path ) ? (string) file_get_contents( $path ) : '';
};

/* ───────────────────────────────────────────────────────────────────── */
T::group( 'U-5 — the version agrees with itself everywhere' );

$main = $read( 'newsdesk-ai.php' );

preg_match( '/^\s*\*\s*Version:\s*(.+)$/m', $main, $m );
$headerVersion = isset( $m[1] ) ? trim( $m[1] ) : '';
T::ok( '' !== $headerVersion, "the plugin header declares a version ($headerVersion)" );

preg_match( "/define\(\s*'NEWSDESK_VERSION',\s*'([^']+)'/", $main, $m );
$constVersion = $m[1] ?? '';
T::eq( $headerVersion, $constVersion, 'NEWSDESK_VERSION matches the plugin header' );

// This exact divergence shipped once: the header said 2.0.0, the constant said
// 1.0.0, and WordPress reads the header. It was invisible until the zip was
// extracted, because bootstrap.php reads the define() rather than the header.

$readme = $read( 'readme.txt' );
preg_match( '/^Stable tag:\s*(.+)$/m', $readme, $m );
T::eq( $headerVersion, trim( $m[1] ?? '' ), 'readme.txt Stable tag matches the header' );

T::ok(
	false !== strpos( $readme, '= ' . $headerVersion . ' ' ),
	'readme.txt changelog has an entry for this version'
);
T::ok(
	false !== strpos( $read( 'CHANGELOG.md' ), $headerVersion ),
	'CHANGELOG.md has an entry for this version'
);

preg_match( "/define\(\s*'NEWSDESK_DB_VERSION',\s*'([^']+)'/", $main, $m );
T::ok( '' !== ( $m[1] ?? '' ), 'a database version is declared (' . ( $m[1] ?? '?' ) . ')' );

/* ───────────────────────────────────────────────────────────────────── */
T::group( 'U-5 — the files a marketplace requires actually exist' );

foreach ( array(
	'readme.txt'                     => 'wordpress.org listing',
	'LICENSE'                        => 'GPL text',
	'README.md'                      => 'repository readme',
	'CHANGELOG.md'                   => 'changelog',
	'INSTALL.md'                     => 'install guide',
	'uninstall.php'                  => 'uninstall routine',
	'composer.json'                  => 'package metadata',
	'documentation/index.html'       => 'CodeCanyon documentation',
	'languages/newsdesk-ai.pot'      => 'translation template',
	'languages/newsdesk-ai-fa_IR.mo' => 'compiled Persian catalogue',
) as $rel => $why ) {
	T::ok( is_file( $dir . '/' . $rel ), "$rel exists — $why" );
}

/* ───────────────────────────────────────────────────────────────────── */
T::group( 'U-5 — the documentation is self-contained and covers the product' );

$docs = $read( 'documentation/index.html' );

T::ok( strlen( $docs ) > 8000, 'the documentation has real content (' . strlen( $docs ) . ' bytes)' );
T::ok(
	false === stripos( $docs, '<link rel="stylesheet"' ) && false === stripos( $docs, '<script src=' ),
	'it loads no external CSS or JS — it must open offline from the zip'
);
T::contains( '<style', $docs, 'styling is inlined' );

// A buyer hits these subjects first; a gap in any of them generates a refund
// request rather than a support ticket.
foreach ( array(
	'Requirements'  => 'requirements',
	'Installation'  => 'installation',
	'Troubleshoot'  => 'troubleshooting',
	'Support'       => 'support contact',
	'hook'          => 'extensibility',
	'uninstall'     => 'data removal',
	'API key'       => 'provider credentials',
	'draft'         => 'the drafts-only guarantee',
) as $needle => $topic ) {
	T::ok( false !== stripos( $docs, $needle ), "the documentation covers $topic" );
}

T::contains( 'etehadwp.com', $docs, 'the documentation carries the vendor support URL' );
T::ok(
	false !== strpos( $docs, $headerVersion ),
	'the documentation states the version it describes'
);

/* ───────────────────────────────────────────────────────────────────── */
T::group( 'U-5 — the readme does not promise what the package omits' );

// wordpress.org serves screenshots from the SVN assets/ directory rather than
// from the zip, so a missing PNG is not a packaging failure. What IS a failure
// is a numbering gap, because the captions would then attach to wrong images.
preg_match_all( '/^(\d+)\.\s+(.+)$/m', (string) strstr( $readme, '== Screenshots ==' ), $shots );
$numbers = array_map( 'intval', $shots[1] ?? array() );

if ( $numbers ) {
	T::eq( range( 1, count( $numbers ) ), $numbers, 'screenshot captions are numbered 1..n with no gaps' );
	foreach ( $shots[2] as $i => $caption ) {
		T::ok( strlen( trim( $caption ) ) > 15, 'screenshot ' . ( $i + 1 ) . ' has a descriptive caption' );
	}
}

// Required readme.txt headers. wordpress.org rejects a listing that lacks them.
foreach ( array( 'Contributors', 'Tags', 'Requires at least', 'Tested up to', 'Requires PHP', 'Stable tag', 'License' ) as $field ) {
	T::ok( (bool) preg_match( '/^' . preg_quote( $field, '/' ) . ':\s*\S/m', $readme ), "readme.txt declares '$field'" );
}

$short = '';
if ( preg_match( '/^License URI:.*$\s*^\s*$\s*^(.+)$/m', $readme, $m ) ) {
	$short = trim( $m[1] );
}
T::ok( '' !== $short && strlen( $short ) <= 150, 'the short description fits the 150-char limit (' . strlen( $short ) . ')' );

/* ───────────────────────────────────────────────────────────────────── */
T::group( 'U-5 — vendor attribution survives a rebuild' );

// Plugin headers are whitespace-aligned, so match the field, not a fixed gap.
T::ok(
	(bool) preg_match( '/^\s*\*\s*Author:\s*EtehadWP\s*$/m', $main ),
	'the plugin header credits the vendor'
);
T::ok(
	(bool) preg_match( '#^\s*\*\s*Author URI:\s*https://etehadwp\.com/?\s*$#m', $main ),
	'the header carries the vendor URL'
);
T::ok(
	(bool) preg_match( '#^\s*\*\s*Plugin URI:\s*https://etehadwp\.com/#m', $main ),
	'the header carries the product URL'
);

T::contains( 'EtehadWP', $read( 'LICENSE' ), 'the LICENSE carries a copyright line' );
T::contains( 'etehadwp', $readme, 'readme.txt names the contributor' );

$composer = json_decode( $read( 'composer.json' ), true );
T::ok( is_array( $composer ), 'composer.json is valid JSON' );
T::ok( ! empty( $composer['authors'][0]['name'] ), 'composer.json names an author' );
T::ok( ! empty( $composer['homepage'] ), 'composer.json has a homepage' );
T::ok( ! empty( $composer['license'] ), 'composer.json declares a licence' );

// Branding must stay centralised: a second hardcoded domain is how a rebrand
// rots. Only Branding.php is allowed to contain the vendor URL.
$offenders = array();
$rii       = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir . '/src' ) );
foreach ( $rii as $file ) {
	if ( 'php' !== strtolower( $file->getExtension() ) || 'Branding.php' === $file->getFilename() ) {
		continue;
	}
	if ( false !== strpos( (string) file_get_contents( $file->getPathname() ), 'etehadwp.com' ) ) {
		$offenders[] = $file->getFilename();
	}
}
T::eq( array(), $offenders, 'only Branding.php hardcodes the vendor domain' );

exit( T::summary() );
