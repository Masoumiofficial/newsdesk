<?php
/**
 * U-0 regression tests: B-4 (link scan pool collapsed to $limit) and
 * B-5 (linkify() break-after-first-match, plus two latent defects found
 * in the same helper).
 */

require __DIR__ . '/bootstrap.php';

use NewsDesk\AI\Application\Content\DraftService;
use NewsDesk\AI\Application\Linking\InternalLinkEngine;
use NewsDesk\AI\Domain\Entity\InternalLinkSuggestion;

/* ══════════════════════════════════════════ B-4 ══════════════════════════ */

T::group( 'B-4 — the scan pool is no longer capped by the suggestion limit' );

$index = new FakePostIndex();
// 60 posts. The ONLY match sits at position 50 — far outside the old
// 10-post window, so pre-fix this returned nothing at all.
for ( $i = 1; $i <= 60; $i++ ) {
	$index->posts[] = array(
		'post_id' => $i,
		'title'   => 50 === $i ? 'Everything about WordPress Security' : 'Unrelated post ' . $i,
		'url'     => 'https://site.test/p/' . $i,
	);
}
$repo   = new FakeLinkRepo();
$engine = new InternalLinkEngine( $index, $repo );

$res = $engine->suggest( 'WordPress Security', array(), 10 );
T::ok( $index->askedFor > 10, 'existingPosts() is asked for more than the suggestion limit (got ' . $index->askedFor . ')' );
T::eq( InternalLinkEngine::DEFAULT_SCAN_POOL, $index->askedFor, 'default scan pool is used' );
T::eq( 1, count( $res ), 'the deep match is found' );
T::eq( 50, $res[0]->targetPostId, 'it is the right post' );

T::group( 'B-4 — limit caps the OUTPUT, not the search' );

$index2 = new FakePostIndex();
for ( $i = 1; $i <= 40; $i++ ) {
	$index2->posts[] = array(
		'post_id' => $i,
		'title'   => 'Guide to Caching part ' . $i,
		'url'     => 'https://site.test/c/' . $i,
	);
}
$repo2   = new FakeLinkRepo();
$engine2 = new InternalLinkEngine( $index2, $repo2 );
$res2    = $engine2->suggest( 'Caching', array(), 5 );
T::eq( 5, count( $res2 ), 'exactly $limit suggestions are returned' );
T::ok( $index2->askedFor >= 40, 'the whole 40-post corpus was in scan range (asked for ' . $index2->askedFor . ')' );
T::eq( 5, count( $repo2->internal ), 'only the returned ones are persisted' );

T::group( 'B-4 — the primary entity outranks topic matches' );

$index3 = new FakePostIndex();
$index3->posts = array(
	array( 'post_id' => 1, 'title' => 'A note on Caching', 'url' => 'https://site.test/1' ),
	array( 'post_id' => 2, 'title' => 'Deep dive: WordPress Security', 'url' => 'https://site.test/2' ),
);
$repo3   = new FakeLinkRepo();
$engine3 = new InternalLinkEngine( $index3, $repo3 );
$res3    = $engine3->suggest( 'WordPress Security', array( 'Caching' ), 10 );
T::eq( 2, count( $res3 ), 'both match' );
T::eq( 2, $res3[0]->targetPostId, 'primary-entity match is ranked first' );
T::ok( $res3[0]->confidence > $res3[1]->confidence, 'and carries higher confidence' );

T::group( 'B-4 — no duplicate target posts' );

$index4 = new FakePostIndex();
$index4->posts = array(
	array( 'post_id' => 7, 'title' => 'Security and Caching together', 'url' => 'https://site.test/7' ),
);
$repo4 = new FakeLinkRepo();
$res4  = ( new InternalLinkEngine( $index4, $repo4 ) )->suggest( 'Security', array( 'Caching' ), 10 );
T::eq( 1, count( $res4 ), 'a post matching two terms yields one suggestion, not two' );

/* ══════════════════════════════════════════ B-5 ══════════════════════════ */

T::group( 'B-5 — more than one internal link per article' );

/**
 * Drive the private linkify() through the public render() path.
 *
 * @param array<int, array{id:int,url:string,anchor:string}> $links
 * @param string[] $paragraphs
 */
function render_with_links( array $links, array $paragraphs ): string {
	$index = new FakePostIndex();
	$sugs  = array();
	foreach ( $links as $l ) {
		$index->posts[] = array( 'post_id' => $l['id'], 'title' => $l['anchor'], 'url' => $l['url'] );
		$s               = new InternalLinkSuggestion();
		$s->targetPostId = $l['id'];
		$s->anchor       = $l['anchor'];
		$sugs[]          = $s;
	}
	$svc = make_draft_service( $index );
	$article = array(
		'lead'     => 'Lead paragraph.',
		'sections' => array(
			array( 'heading' => 'H', 'paragraphs' => $paragraphs ),
		),
	);
	return $svc->render( $article, array(), array(), $sugs, make_story() );
}

$html = render_with_links(
	array(
		array( 'id' => 1, 'url' => 'https://site.test/security', 'anchor' => 'security' ),
		array( 'id' => 2, 'url' => 'https://site.test/caching', 'anchor' => 'caching' ),
	),
	array( 'We discuss security and caching in this paragraph.' )
);
T::eq( 2, substr_count( $html, '<a href=' ), 'two distinct anchors are inserted (pre-fix: 1)' );
T::contains( 'href="https://site.test/security"', $html, 'first link present' );
T::contains( 'href="https://site.test/caching"', $html, 'second link present' );

T::group( 'B-5 — each URL is linked at most once per article' );

$html = render_with_links(
	array( array( 'id' => 1, 'url' => 'https://site.test/security', 'anchor' => 'security' ) ),
	array(
		'First paragraph mentions security.',
		'Second paragraph mentions security again.',
		'Third paragraph mentions security once more.',
	)
);
T::eq( 1, substr_count( $html, 'href="https://site.test/security"' ), 'the same target is not linked in every paragraph' );

T::group( 'B-5 — links never nest' );

$html = render_with_links(
	array(
		array( 'id' => 1, 'url' => 'https://site.test/a', 'anchor' => 'WordPress security' ),
		array( 'id' => 2, 'url' => 'https://site.test/b', 'anchor' => 'security' ),
	),
	array( 'A piece about WordPress security today.' )
);
T::ok( false === strpos( $html, '<a href="https://site.test/b"><a' ), 'no directly nested anchor' );
T::ok( ! preg_match( '/<a [^>]*>(?:(?!<\/a>).)*<a /s', $html ), 'no anchor opened inside another anchor' );
T::contains( '>WordPress security</a>', $html, 'the longer, more specific anchor wins' );

T::group( 'B-5 — replacement is literal, not a regex template' );

// "$1" in the URL used to be interpreted as a backreference by preg_replace
// and silently replaced with the matched text.
$html = render_with_links(
	array( array( 'id' => 1, 'url' => 'https://site.test/p?a=$1&b=\\2', 'anchor' => 'topic' ) ),
	array( 'This topic matters.' )
);
T::contains( 'a=$1', $html, 'literal $1 survives in the href' );
T::contains( 'b=\\2', $html, 'literal backslash-2 survives in the href' );

T::group( 'B-5 — paragraph link budget is respected' );

$html = render_with_links(
	array(
		array( 'id' => 1, 'url' => 'https://site.test/1', 'anchor' => 'alpha' ),
		array( 'id' => 2, 'url' => 'https://site.test/2', 'anchor' => 'beta' ),
		array( 'id' => 3, 'url' => 'https://site.test/3', 'anchor' => 'gamma' ),
		array( 'id' => 4, 'url' => 'https://site.test/4', 'anchor' => 'delta' ),
	),
	array( 'Here we mention alpha, beta, gamma and delta together.' )
);
T::eq(
	DraftService::MAX_LINKS_PER_PARAGRAPH,
	substr_count( $html, '<a href=' ),
	'at most 2 links land in a single paragraph'
);

T::group( 'B-5 — short and empty anchors are ignored' );

$html = render_with_links(
	array( array( 'id' => 1, 'url' => 'https://site.test/x', 'anchor' => 'ab' ) ),
	array( 'The word ab is too short to link.' )
);
T::eq( 0, substr_count( $html, '<a href=' ), 'anchors under 3 chars are skipped' );

T::group( 'B-5 — escaping still holds' );

$html = render_with_links(
	array( array( 'id' => 1, 'url' => 'https://site.test/x"onmouseover="alert(1)', 'anchor' => 'topic' ) ),
	array( 'A <script>alert(1)</script> topic here.' )
);
T::ok( false === strpos( $html, '<script>' ), 'paragraph HTML is still escaped' );
T::ok( false === strpos( $html, '"onmouseover="' ), 'quote in the URL cannot break out of the attribute' );

exit( T::summary() );
