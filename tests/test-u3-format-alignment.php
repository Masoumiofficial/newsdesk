<?php
/**
 * Repository format specifiers must line up with the entity's columns.
 *
 * `$wpdb->insert()` / `->update()` pair the $format array with $data
 * POSITIONALLY. If an entity gains a column but the repository's formats()
 * list is not updated, every specifier after the insertion point shifts by
 * one — a string column gets written with %d, a float with %s, and so on.
 *
 * This is a silent corruption, not an error: wpdb casts rather than complains,
 * the insert reports success, and the damage only surfaces later as a zeroed
 * timestamp or a truncated value. It is exactly what happened when A-12 added
 * `source_type` and `tier` to Source::toDbRow() without touching
 * SourceRepository::formats().
 *
 * The check is generic on purpose: it will catch the next one too.
 */

require __DIR__ . '/bootstrap.php';

use NewsDesk\AI\Domain\Entity\Job;
use NewsDesk\AI\Domain\Entity\NewsItem;
use NewsDesk\AI\Domain\Entity\Source;
use NewsDesk\AI\Domain\Entity\Story;

/**
 * Read the literal specifier count out of a repository's formats() method.
 * Parsing the source is deliberate: formats() is private, and reflection on a
 * private static would not prove what the INSERT path actually passes.
 */
function format_count( string $repoFile ): int {
	$src = (string) file_get_contents( NEWSDESK_DIR . 'src/Infrastructure/Repository/' . $repoFile );
	$pos = strpos( $src, 'function formats()' );
	if ( false === $pos ) {
		return -1;
	}
	// Take the body up to the closing brace of the return array.
	$body = substr( $src, $pos, 1800 );
	$end  = strpos( $body, ');' );
	if ( false !== $end ) {
		$body = substr( $body, 0, $end );
	}
	// Strip trailing // comments so column names inside them are not counted.
	$body = preg_replace( '#//[^\n]*#', '', $body );
	return preg_match_all( "/'%[sdf]'/", (string) $body );
}

/** Columns actually sent on insert(): toDbRow() minus the primary key. */
function insert_columns( array $row, string $pk ): int {
	unset( $row[ $pk ] );
	return count( $row );
}

T::group( 'Source — the A-12 columns are covered by the format list' );

$source = new Source();
$cols   = insert_columns( $source->toDbRow(), 'id' );
$fmts   = format_count( 'SourceRepository.php' );
T::eq( $cols, $fmts, "SourceRepository::formats() has one specifier per column (cols=$cols, formats=$fmts)" );

T::group( 'Source — tier is written as an integer, not a string' );

// Position matters as much as count: tier is tinyint, so it must be %d.
$row  = $source->toDbRow();
unset( $row['id'] );
$keys = array_keys( $row );
$src  = (string) file_get_contents( NEWSDESK_DIR . 'src/Infrastructure/Repository/SourceRepository.php' );
$pos  = strpos( $src, 'function formats()' );
$body = substr( $src, $pos, 1800 );
$body = substr( $body, 0, (int) strpos( $body, ');' ) );
$body = preg_replace( '#//[^\n]*#', '', (string) $body );
preg_match_all( "/'(%[sdf])'/", (string) $body, $m );
$specs = $m[1];

$tierIndex = array_search( 'tier', $keys, true );
T::ok( false !== $tierIndex, 'tier is part of the insert payload' );
T::eq( '%d', $specs[ $tierIndex ] ?? '?', 'tier is written with %d' );

$typeIndex = array_search( 'source_type', $keys, true );
T::eq( '%s', $specs[ $typeIndex ] ?? '?', 'source_type is written with %s' );

T::group( 'Source — timestamps did not get shifted onto the wrong specifier' );

foreach ( array( 'created_at', 'updated_at', 'settings' ) as $col ) {
	$i = array_search( $col, $keys, true );
	T::eq( '%s', $specs[ $i ] ?? '?', "$col is still written with %s" );
}

T::group( 'every other repository with a formats() list is aligned too' );

// A-3 added seven columns to Story; this is the check that proves the
// StoryRepository was not left behind in the same way Source was.
$cases = array(
	array( 'StoryRepository.php', ( new Story() )->toDbRow(), 'story_id' ),
	array( 'NewsItemRepository.php', ( new NewsItem() )->toDbRow(), 'id' ),
	array( 'JobRepository.php', ( new Job() )->toDbRow(), 'id' ),
);
foreach ( $cases as $case ) {
	list( $file, $entityRow, $pk ) = $case;
	$expected = insert_columns( $entityRow, $pk );
	$actual   = format_count( $file );
	if ( -1 === $actual ) {
		T::ok( true, "$file has no formats() list — nothing to misalign" );
		continue;
	}
	T::eq( $expected, $actual, "$file: one specifier per column (cols=$expected, formats=$actual)" );
}

T::group( 'the A-3 story columns really are in the insert payload' );

// If these were missing from toDbRow(), the extractor would run and the
// results would never reach the database — silently.
$storyKeys = array_keys( ( new Story() )->toDbRow() );
foreach ( array( 'is_security', 'cve_ids', 'cvss_score', 'severity', 'affected_versions', 'fixed_versions', 'exploited' ) as $col ) {
	T::ok( in_array( $col, $storyKeys, true ), "Story::toDbRow() includes '$col'" );
}

exit( T::summary() );
