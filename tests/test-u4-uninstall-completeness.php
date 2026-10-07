<?php
/**
 * Uninstall must delete everything the plugin created.
 *
 * "Delete all data" that silently leaves rows behind is the worst kind of
 * wrong: the user believes they are clean, and nothing ever tells them
 * otherwise. The correction log survived uninstall for exactly this reason --
 * it was added as a class constant and never added to the uninstaller's list.
 *
 * So this test does not check a list of names. It derives the expectation from
 * the code: every option-shaped constant anywhere in src/, and every table in
 * Schema.php, has to be reachable from Uninstaller. Adding a new option
 * without adding it here will fail this test on the next run.
 *
 * @package NewsDesk\AI\Tests
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** Every `const *OPTION* = '...'` declared anywhere in the plugin. */
function nd_declared_options(): array {
	$found = array();
	$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( NEWSDESK_DIR . 'src' ) );
	foreach ( $it as $file ) {
		if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		$src = (string) file_get_contents( $file->getPathname() );
		if ( preg_match_all( '/const\s+([A-Z0-9_]*OPTION[A-Z0-9_]*)\s*=\s*\'([a-z0-9_]+)\'/', $src, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $hit ) {
				$found[ $hit[2] ] = basename( $file->getPathname() ) . '::' . $hit[1];
			}
		}
	}
	return $found;
}

$uninstaller = (string) file_get_contents( NEWSDESK_DIR . 'src/Core/Uninstaller.php' );
$declared    = nd_declared_options();

T::group( 'uninstall — every declared option constant is deleted' );

T::ok( count( $declared ) > 0, 'the scan found option constants to check (' . count( $declared ) . ')' );

foreach ( $declared as $value => $where ) {
	// Reachable either by its constant name or by its literal value.
	list( , $constName ) = explode( '::', $where, 2 );
	$byConst   = false !== strpos( $uninstaller, '::' . $constName );
	$byLiteral = false !== strpos( $uninstaller, "'" . $value . "'" );
	T::ok( $byConst || $byLiteral, "Uninstaller deletes '$value' (from $where)" );
}

T::group( 'uninstall — the bare option names are deleted too' );

// These have no constant; they are written directly by Plugin/Migrations.
foreach ( array( 'newsdesk_db_version', 'newsdesk_migrations_applied' ) as $opt ) {
	T::contains( "'" . $opt . "'", $uninstaller, "Uninstaller deletes '$opt'" );
}

T::group( 'uninstall — every table in the schema is dropped' );

$schema = (string) file_get_contents( NEWSDESK_DIR . 'src/Infrastructure/Database/Schema.php' );
preg_match_all( '/CREATE TABLE \{\$prefix\}([a-z_]+)/', $schema, $m );
$tables = array_unique( $m[1] );
T::ok( count( $tables ) > 10, 'the scan found the schema tables (' . count( $tables ) . ')' );

foreach ( $tables as $table ) {
	// Uninstaller may name the table directly or go through TableNames().
	$short = preg_replace( '/^newsdesk_/', '', $table );
	T::ok(
		false !== strpos( $uninstaller, $table ) || false !== strpos( $uninstaller, $short ),
		"Uninstaller drops '$table'"
	);
}

T::group( 'uninstall — keeping data is still the default' );

// The spec is explicit: removal must never destroy data unless asked.
T::ok(
	(bool) preg_match( '/delete_all|deleteAll/', $uninstaller ),
	'deletion is gated behind an explicit delete-all flag'
);

exit( T::summary() );
