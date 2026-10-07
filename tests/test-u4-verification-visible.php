<?php
/**
 * The verification work has to be VISIBLE.
 *
 * This plugin's whole claim is that it checks things. It extracted CVE data,
 * scored every claim for risk and ran a fifteen-axis audit -- and then showed
 * an editor none of it. Analysis found security intelligence, claim risk and
 * audit results in zero files under admin/views.
 *
 * A feature the buyer cannot see is a feature they did not buy. These
 * assertions keep the evidence on screen.
 *
 * @package NewsDesk\AI\Tests
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$preview = (string) file_get_contents( NEWSDESK_DIR . 'admin/views/draft_preview.php' );

T::group( 'claim risk is shown next to each claim, not just stored' );

T::contains( 'Risk / action', $preview, 'the evidence map has a risk column' );
T::contains( '$c->risk', $preview, 'it renders the assessed risk level' );
T::contains( '$c->action', $preview, 'and the action the assessment mandates' );

// All four levels need a visual distinction or the column is noise.
foreach ( array( 'CRITICAL', 'HIGH', 'MEDIUM', 'LOW' ) as $level ) {
	T::contains( "'" . $level . "'", $preview, "the '$level' risk level has its own styling" );
}

T::group( 'security intelligence reaches the screen' );

T::contains( 'isSecurity', $preview, 'the panel is gated on the security flag' );
foreach ( array( 'cveIds', 'cvssScore', 'severity', 'affectedVersions', 'fixedVersions', 'exploited' ) as $field ) {
	T::contains( $field, $preview, "'$field' is rendered" );
}

T::group( 'the extractor loses nothing on the way to the database' );

// vendor_advisory was extracted on every security story and then dropped:
// no column, no entity property, no write. The link to the official advisory
// is the single most useful thing in a security write-up.
$story  = (string) file_get_contents( NEWSDESK_DIR . 'src/Domain/Entity/Story.php' );
$schema = (string) file_get_contents( NEWSDESK_DIR . 'src/Infrastructure/Database/Schema.php' );
$repo   = (string) file_get_contents( NEWSDESK_DIR . 'src/Infrastructure/Repository/StoryRepository.php' );

T::contains( 'vendor_advisory', $schema, 'the schema has a column for the advisory URL' );
T::contains( 'vendorAdvisory', $story, 'the entity carries it' );
T::contains( "'vendor_advisory'", $repo, 'updateSecurityIntel() persists it' );

// Every key the extractor promises should survive to the story.
$extractor = (string) file_get_contents( NEWSDESK_DIR . 'src/Application/Security/SecurityIntelExtractor.php' );
preg_match_all( "/'([a-z_]+)'\s*=>/", substr( $extractor, (int) strpos( $extractor, 'public function extract' ), 2000 ), $m );
$ignored = array( 'confidence', 'is_security' );
foreach ( array_unique( $m[1] ) as $key ) {
	if ( in_array( $key, $ignored, true ) || 1 === preg_match( '/^(high|medium|low|critical)$/', $key ) ) {
		continue;
	}
	T::ok(
		false !== strpos( $repo, "'" . $key . "'" ) || false !== strpos( $story, $key ),
		"extracted field '$key' is stored somewhere, not discarded"
	);
}

T::group( 'the audit result is not silently swallowed' );

$content = (string) file_get_contents( NEWSDESK_DIR . 'src/Application/Content/StoryContentService.php' );
T::contains( 'AUDIT_CRITICAL_FAIL', $content, 'a blocking audit failure is logged with a named reason' );

exit( T::summary() );
