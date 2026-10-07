<?php
/**
 * U-0 hardening tests: direct-access guards, the gmdate() fix (B-3), version
 * consistency (B-11) and the migration/schema invariants that keep upgrades
 * safe.
 */

require __DIR__ . '/bootstrap.php';

use NewsDesk\AI\Infrastructure\Database\Migrations;

/* ───────────────────────── ABSPATH guards ────────────────────────────── */

T::group( 'hardening — every PHP file refuses direct access' );

$root      = rtrim( NEWSDESK_DIR, '/' );
$unguarded = array();
$checked   = 0;
$rii       = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src' ) );
foreach ( $rii as $file ) {
	if ( $file->isDir() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	$checked++;
	$src = file_get_contents( $file->getPathname() );
	if ( false === strpos( $src, "defined( 'ABSPATH' )" ) ) {
		$unguarded[] = str_replace( $root . '/', '', $file->getPathname() );
	}
}
T::ok( $checked > 180, "scanned $checked files under src/" );
T::eq( array(), $unguarded, 'no src/ file is missing its ABSPATH guard (was 187 missing)' );

T::group( 'hardening — admin views are guarded too' );

$viewsUnguarded = array();
foreach ( glob( $root . '/admin/views/*.php' ) as $view ) {
	if ( false === strpos( (string) file_get_contents( $view ), "defined( 'ABSPATH' )" ) ) {
		$viewsUnguarded[] = basename( $view );
	}
}
T::eq( array(), $viewsUnguarded, 'every admin view guards direct access' );

T::group( 'hardening — the guard sits before any executable code' );

// A guard placed after class/function definitions would still let an attacker
// trigger side effects. It must come straight after the namespace line.
$lateGuards = array();
foreach ( array( 'src/Support/Time.php', 'src/Core/Plugin.php', 'src/Application/DiscoveryService.php' ) as $rel ) {
	$src = (string) file_get_contents( $root . '/' . $rel );
	$g   = strpos( $src, "defined( 'ABSPATH' )" );
	$c   = strpos( $src, 'class ' );
	if ( false !== $c && false !== $g && $g > $c ) {
		$lateGuards[] = $rel;
	}
}
T::eq( array(), $lateGuards, 'the guard precedes the class declaration' );

/* ────────────────────────────── B-3 gmdate ───────────────────────────── */

T::group( 'B-3 — no timezone-dependent date() in job keys' );

$manual = (string) file_get_contents( $root . '/src/Application/ManualActions.php' );
T::ok(
	! preg_match( '/[^a-z_]date\(\s*\'Ymd-His\'/', $manual ),
	'ManualActions no longer uses date() for the slot key'
);
T::eq( 2, substr_count( $manual, "gmdate( 'Ymd-His' )" ), 'both call sites use gmdate()' );

// Why it matters: date() follows the server TZ, so two servers in different
// zones produce different job keys for the same instant, breaking the
// one-job-per-slot guarantee.
$tz = date_default_timezone_get();
date_default_timezone_set( 'Pacific/Kiritimati' ); // UTC+14
$a = gmdate( 'Ymd-His' );
date_default_timezone_set( 'Pacific/Niue' ); // UTC-11
$b = gmdate( 'Ymd-His' );
date_default_timezone_set( $tz );
T::eq( substr( $a, 0, 11 ), substr( $b, 0, 11 ), 'gmdate() is stable across server timezones' );

/* ─────────────────────────── B-11 versions ───────────────────────────── */

T::group( 'B-11 — version numbers are internally consistent' );

$bootstrap = (string) file_get_contents( $root . '/newsdesk-ai.php' );

preg_match( "/define\( 'NEWSDESK_DB_VERSION', '([^']+)' \)/", $bootstrap, $m );
$declaredDb = $m[1] ?? '';
T::ok( '' !== $declaredDb, "DB version constant found ($declaredDb)" );

// With an empty migration set latestVersion() reports the 1.0.0 baseline.
// The pair must agree either way: a migration added without bumping the
// constant never runs, and a bump without a migration is a silent no-op.
T::eq( Migrations::latestVersion(), $declaredDb, 'NEWSDESK_DB_VERSION equals the highest migration' );

preg_match( '/Requires PHP:\s*([0-9.]+)/', $bootstrap, $m );
T::eq( '7.4', $m[1] ?? '', 'header still declares PHP 7.4 (the agreed target)' );

preg_match( "/define\( 'NEWSDESK_MIN_PHP', '([^']+)' \)/", $bootstrap, $m );
T::eq( '7.4', $m[1] ?? '', 'the runtime guard matches the header' );

T::group( 'B-11 — AUTO PUBLISH is off and not overridable' );

preg_match( "/define\( 'NEWSDESK_AUTO_PUBLISH',\s*(\w+)/", $bootstrap, $m );
T::eq( 'false', strtolower( $m[1] ?? '' ), 'AUTO_PUBLISH defaults to false' );

/* ──────────────────────── migration invariants ───────────────────────── */

T::group( 'migrations — 1.0.0 ships a baseline, not a chain' );

$all = Migrations::all();
T::eq( array(), $all, 'the inherited migration chain is gone; Schema.php is the baseline' );
T::eq( '1.0.0', Migrations::latestVersion(), 'an empty set reports the 1.0.0 baseline' );

T::group( 'migrations — every version is ordered and templated' );

$bad = array();
foreach ( $all as $version => $statements ) {
	if ( ! preg_match( '/^\d+\.\d+\.\d+$/', (string) $version ) ) {
		$bad[] = "malformed version: $version";
	}
	foreach ( $statements as $sql ) {
		if ( false !== strpos( $sql, 'wp_newsdesk_' ) ) {
			$bad[] = "hardcoded prefix in $version";
		}
	}
}
T::eq( array(), $bad, 'no malformed versions and no hardcoded table prefixes' );

T::group( 'migrations — schema declares the columns the migration adds' );

$schema = (string) file_get_contents( $root . '/src/Infrastructure/Database/Schema.php' );
foreach ( array( 'duplicate_of_id', 'duplicate_level' ) as $col ) {
	T::contains( $col, $schema, "fresh installs get $col from Schema too" );
}
// A column added by migration but missing from Schema means fresh installs
// and upgraded installs diverge — the classic WP schema-drift bug.
T::ok(
	substr_count( $schema, 'duplicate_of_id' ) >= 1 && substr_count( $schema, 'KEY duplicate_of' ) >= 1,
	'the index is declared for fresh installs as well'
);

exit( T::summary() );
