<?php
/**
 * A-12 — the source taxonomy has to DO something.
 *
 * Before this, `tier` and `source_type` were stored on the entity, carried in
 * the schema, round-tripped by the repository... and read by nothing. The
 * admin form did not even expose them, so a column documented as "default
 * trust ceiling per tier" could never influence a single score.
 *
 * Dead configuration is worse than missing configuration: it tells the user a
 * lie about what their input does. These assertions pin the wiring.
 *
 * @package NewsDesk\AI\Tests
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/stubs/FakeWpDb.php';
require_once __DIR__ . '/stubs/fixtures.php';

use NewsDesk\AI\Application\SourceService;
use NewsDesk\AI\Domain\Entity\Source;

/** Run the service's validation/normalisation and hand back the clean array. */
function nd_prepare( array $input ): array {
	$ref    = new ReflectionClass( SourceService::class );
	$method = null;
	foreach ( $ref->getMethods() as $m ) {
		if ( in_array( $m->getName(), array( 'validate', 'prepare', 'sanitize' ), true ) ) {
			$method = $m;
			break;
		}
	}
	if ( null === $method ) {
		return array();
	}
	$method->setAccessible( true );
	return (array) $method->invoke( $ref->newInstanceWithoutConstructor(), $input );
}

$base = array(
	'name'     => 'Example',
	'type'     => 'rss',
	'feed_url' => 'https://example.com/feed',
);

T::group( 'A-12 — the tier supplies the default trust score' );

$seen = array();
foreach ( Source::TIERS as $tier ) {
	$out  = nd_prepare( $base + array( 'tier' => $tier ) );
	$got  = (float) ( $out['base_trust_score'] ?? -1.0 );
	$want = (float) Source::TIER_TRUST[ $tier ];
	T::eq( $want, $got, "tier $tier defaults base trust to $want" );
	$seen[] = $got;
}

// The original bug in one assertion: every tier produced the same 50.0.
T::eq( count( Source::TIERS ), count( array_unique( $seen ) ), 'the four tiers produce four different scores' );

T::group( 'A-12 — an explicit score still wins over the tier default' );

$out = nd_prepare( $base + array( 'tier' => 1, 'base_trust_score' => 12.5 ) );
T::eq( 12.5, (float) ( $out['base_trust_score'] ?? 0.0 ), 'an operator override is not overwritten by the tier' );

$out = nd_prepare( $base + array( 'tier' => 1, 'base_trust_score' => '' ) );
T::eq( 95.0, (float) ( $out['base_trust_score'] ?? 0.0 ), 'but a blank field falls back to the tier' );

T::group( 'A-12 — both fields survive validation and reach the entity' );

$out = nd_prepare( $base + array( 'tier' => 2, 'source_type' => 'security' ) );
T::eq( 2, (int) ( $out['tier'] ?? 0 ), 'the tier is carried through' );
T::eq( 'SECURITY', (string) ( $out['source_type'] ?? '' ), 'the source type is upper-cased and carried through' );

T::group( 'A-12 — nonsense input falls back to the documented defaults' );

$out = nd_prepare( $base + array( 'tier' => 99, 'source_type' => 'NOT_A_TYPE' ) );
T::eq( 3, (int) ( $out['tier'] ?? 0 ), 'an out-of-range tier becomes 3' );
T::eq( Source::SOURCE_MEDIA, (string) ( $out['source_type'] ?? '' ), 'an unknown type becomes MEDIA' );

T::group( 'A-12 — the admin form actually exposes them' );

// A field the UI never renders is indistinguishable from a field that does
// not exist, which is how this stayed invisible through two releases.
$form = (string) file_get_contents( NEWSDESK_DIR . 'admin/views/sources.php' );
T::contains( 'name="tier"', $form, 'the sources form renders a tier control' );
T::contains( 'name="source_type"', $form, 'the sources form renders a source-type control' );

exit( T::summary() );
