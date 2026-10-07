<?php
/**
 * A-14 — documentation must exist AND agree with the code.
 *
 * Docs that drift are worse than missing docs, because a reader trusts them.
 * These assertions pin the handful of facts that would mislead someone if they
 * went stale: version numbers, the PHP/WP floors, table count, and the claim
 * that generation is off by default.
 */

require __DIR__ . '/bootstrap.php';

use NewsDesk\AI\Application\Audit\ArticleAuditor;
use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Domain\NewsCategory;
use NewsDesk\AI\Domain\Entity\Source;

$root = NEWSDESK_DIR;

T::group( 'A-14 — the documents the spec requires are all shipped' );

$required = array( 'README.md', 'INSTALL.md', 'DEVELOPMENT.md', 'ARCHITECTURE.md', 'CHANGELOG.md', 'readme.txt', 'composer.json', 'uninstall.php' );
foreach ( $required as $file ) {
	T::ok( file_exists( $root . $file ), "$file exists" );
}

T::group( 'A-14 — none of them is a stub' );

foreach ( array( 'README.md', 'INSTALL.md', 'DEVELOPMENT.md', 'ARCHITECTURE.md' ) as $file ) {
	T::ok( filesize( $root . $file ) > 2000, "$file has real content (" . filesize( $root . $file ) . ' bytes)' );
}

T::group( 'B-10 — no document reference points at a file that does not ship' );

// The 1.6.0 tree cited docs/02, docs/06, docs/07 and docs/09, none of which
// were ever in the zip. A reader following those references found nothing.
// A blanket ban on the substring 'docs/' also forbids linking to a docs SITE,
// so match local paths only and assert the file is actually in the package.
$dangling = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . 'src' ) ) as $file ) {
	if ( 'php' !== $file->getExtension() ) {
		continue;
	}
	$src = (string) file_get_contents( $file->getPathname() );
	// Strip absolute URLs first; https://example.com/docs/ is not a file path.
	$src = preg_replace( '#https?://\S+#i', '', $src );
	if ( preg_match_all( '#(?<![\w/.])docs/([A-Za-z0-9._/-]+)#', (string) $src, $m ) ) {
		foreach ( $m[1] as $ref ) {
			if ( ! file_exists( $root . 'docs/' . $ref ) ) {
				$dangling[] = str_replace( $root, '', $file->getPathname() ) . ' -> docs/' . $ref;
			}
		}
	}
}
T::eq( array(), $dangling, 'no source file references a missing docs/ path' );

T::group( 'A-14 — the plugin header and the version constant agree' );

// WordPress reads the version from the FILE HEADER, not from the constant.
// Bumping only the constant ships a plugin that reports the wrong version to
// the updater, to support, and to every compatibility check -- silently.
$mainFile = (string) file_get_contents( NEWSDESK_DIR . 'newsdesk-ai.php' );
preg_match( '/^\s*\*\s*Version:\s*(\S+)/mi', $mainFile, $hdr );
T::eq( NEWSDESK_VERSION, (string) ( $hdr[1] ?? '' ), 'the Version: header equals NEWSDESK_VERSION' );

preg_match( '/^\s*\*\s*Requires PHP:\s*(\S+)/mi', $mainFile, $hdrPhp );
T::eq( '7.4', (string) ( $hdrPhp[1] ?? '' ), 'the Requires PHP header is the documented floor' );

preg_match( '/^\s*\*\s*Text Domain:\s*(\S+)/mi', $mainFile, $hdrTd );
T::eq( 'newsdesk-ai', (string) ( $hdrTd[1] ?? '' ), 'the Text Domain header matches the slug' );

T::group( 'A-14 — the version in the docs matches the code' );

$readmeTxt = (string) file_get_contents( $root . 'readme.txt' );
$readmeMd  = (string) file_get_contents( $root . 'README.md' );
T::contains( NEWSDESK_VERSION, $readmeMd, 'README states the shipped version' );
T::contains( 'Stable tag: ' . NEWSDESK_VERSION, $readmeTxt, 'readme.txt stable tag matches' );

T::group( 'A-14 — the stated requirements match the plugin header' );

$header = (string) file_get_contents( $root . 'newsdesk-ai.php' );
preg_match( '/Requires PHP:\s*([0-9.]+)/', $header, $php );
preg_match( '/Requires at least:\s*([0-9.]+)/', $header, $wp );
$install = (string) file_get_contents( $root . 'INSTALL.md' );
T::contains( $php[1], $install, 'INSTALL states the real PHP floor (' . $php[1] . ')' );
T::contains( $wp[1], $install, 'INSTALL states the real WP floor (' . $wp[1] . ')' );
T::contains( $php[1], (string) file_get_contents( $root . 'DEVELOPMENT.md' ), 'DEVELOPMENT states the same PHP floor' );

T::group( 'A-14 — the architecture doc matches the real schema' );

$schema = (string) file_get_contents( $root . 'src/Infrastructure/Database/Schema.php' );
// Anchored on the prefix placeholder: a loose /nd_[a-z_]+/ also matched the
// column 'has_brand_composition' and under-counted the schema by sixteen.
$tables = count( array_unique( preg_match_all( '/CREATE TABLE \{\$prefix\}([a-z_]+)/', $schema, $m ) ? $m[1] : array() ) );
$arch   = (string) file_get_contents( $root . 'ARCHITECTURE.md' );
T::contains( (string) $tables . ' tables', $arch, "ARCHITECTURE states the real table count ($tables)" );
T::contains( (string) $tables . ' tables', $install, 'INSTALL states the same count' );

T::group( 'A-14 — documented defaults are the actual defaults' );

$defaults = NewsroomSettings::defaults();
// The docs promise image generation is off; a silent flip would be a real
// behavioural change hidden behind a stale sentence.
T::eq( false, $defaults['image_generate_enabled'], 'image generation really is off by default' );
T::contains( 'off', strtolower( $install ), 'INSTALL documents the off-by-default posture' );
T::eq( 24, $defaults['editorial_window_hours'], 'the documented 24h window is real' );
T::eq( 7, $defaults['fallback_window_days'], 'the documented 7d fallback is real' );
T::eq( 3, $defaults['max_stories_per_window'], 'the documented per-window cap is real' );

T::group( 'A-14 — documented taxonomies match the code' );

T::eq( 15, count( ArticleAuditor::AXES ), 'the audit really has 15 axes as documented' );
T::eq( 15, count( NewsCategory::ALL ), 'there really are 15 categories' );
T::eq( 5, count( Source::SOURCE_TYPES ), 'there really are 5 source types' );
T::eq( 4, count( Source::TIERS ), 'there really are 4 tiers' );

// Every axis named in the README table must exist in the code.
$readmeAxes = array();
if ( preg_match_all( '/`([a-z_]+)`\s*\|\s*\d+\s*\|/', $readmeMd, $m2 ) ) {
	$readmeAxes = $m2[1];
}
$unknown = array_diff( $readmeAxes, array_keys( ArticleAuditor::AXES ) );
T::eq( array(), $unknown, 'every audit axis named in README exists in the code' );

T::group( 'A-14 — documented hooks exist in the source' );

$dev = (string) file_get_contents( $root . 'DEVELOPMENT.md' );
$src = '';
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . 'src' ) ) as $file ) {
	if ( 'php' === $file->getExtension() ) {
		$src .= (string) file_get_contents( $file->getPathname() );
	}
}
foreach ( array( 'newsdesk_news_score', 'newsdesk_news_selected', 'newsdesk_filler_phrases', 'newsdesk_seo_adapters', 'newsdesk_newsroom_capability', 'newsdesk_newsroom_job_finished' ) as $hook ) {
	T::contains( $hook, $dev, "DEVELOPMENT documents $hook" );
	T::contains( "'" . $hook . "'", $src, "$hook actually exists in the source" );
}

T::group( 'A-14 — the docs do not promise auto-publish' );

// The single most important thing the documentation must not get wrong.
foreach ( array( 'README.md', 'INSTALL.md', 'ARCHITECTURE.md' ) as $file ) {
	$text = strtolower( (string) file_get_contents( $root . $file ) );
	T::ok( false === strpos( $text, 'automatically publish' ) && false === strpos( $text, 'auto-publishes' ), "$file does not promise automatic publishing" );
}

exit( T::summary() );
