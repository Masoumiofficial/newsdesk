<?php
/**
 * U-1 tests: A-2 — NEW/UPDATE/REWRITE/MERGE/CORRECT/REPLACE/NO_ARTICLE.
 *
 * v1.6.0 stored `editorial_decision` and `existing_article_id` and never
 * computed either, so every story was implicitly NEW and the plugin could
 * cannibalize its own keywords.
 */

require __DIR__ . '/bootstrap.php';

use NewsDesk\AI\Application\Scoring\CannibalizationEngine;
use NewsDesk\AI\Application\Scoring\StoryClusterer;
use NewsDesk\AI\Domain\Entity\Story;

function engine(): CannibalizationEngine {
	return new CannibalizationEngine( new StoryClusterer() );
}

function story( string $title, array $over = array() ): Story {
	$s                  = new Story();
	$s->storyId         = $over['id'] ?? 1;
	$s->canonicalTitle  = $title;
	$s->importanceScore = $over['score'] ?? 65.0;
	$s->itemCount       = $over['items'] ?? 3;
	$s->contradictionCount = $over['contradictions'] ?? 0;
	return $s;
}

function post( int $id, string $title, $modified = null ): array {
	return array(
		'post_id'  => $id,
		'title'    => $title,
		'url'      => 'https://site.test/' . $id,
		'modified' => $modified,
	);
}

$now = new DateTimeImmutable( '2026-09-20 12:00:00', new DateTimeZone( 'UTC' ) );

/* ────────────────────────────── NEW ──────────────────────────────────── */

T::group( 'A-2 — NEW when nothing on the site covers the story' );

$r = engine()->decide(
	story( 'WordPress 6.9 released with new block editor' ),
	array(
		post( 1, 'How to bake sourdough bread' ),
		post( 2, 'Our team retreat in Shiraz' ),
	),
	array( 'now' => $now )
);
T::eq( CannibalizationEngine::DECISION_NEW, $r['decision'], 'decision is NEW' );
T::eq( 0, $r['existing_article_id'], 'no existing article is referenced' );
T::ok( '' !== $r['reason'], 'a reason is always given' );

T::group( 'A-2 — NEW when the site has no posts at all' );

$r = engine()->decide( story( 'WordPress 6.9 released' ), array(), array( 'now' => $now ) );
T::eq( CannibalizationEngine::DECISION_NEW, $r['decision'], 'empty site yields NEW' );

T::group( 'A-2 — a story with no usable title never guesses' );

$r = engine()->decide( story( '' ), array( post( 1, 'WordPress 6.9 released' ) ), array( 'now' => $now ) );
T::eq( CannibalizationEngine::DECISION_NEW, $r['decision'], 'empty title falls back to NEW' );
T::eq( 0, $r['existing_article_id'], 'and references nothing' );

/* ──────────────────────────── UPDATE ─────────────────────────────────── */

T::group( 'A-2 — UPDATE when we covered it and there is more to add' );

$r = engine()->decide(
	story( 'WordPress 6.9 released with new block editor', array( 'items' => 4 ) ),
	array( post( 7, 'WordPress 6.9 released with new block editor', '2026-09-18 10:00:00' ) ),
	array( 'now' => $now )
);
T::eq( CannibalizationEngine::DECISION_UPDATE, $r['decision'], 'decision is UPDATE' );
T::eq( 7, $r['existing_article_id'], 'it points at the post we already have' );
T::ok( $r['overlap'] >= CannibalizationEngine::OVERLAP_STRONG, 'overlap is strong' );

/* ─────────────────────────── NO_ARTICLE ──────────────────────────────── */

T::group( 'A-2 — NO_ARTICLE when the same story has nothing new' );

$r = engine()->decide(
	story( 'WordPress 6.9 released with new block editor', array( 'score' => 55.0, 'items' => 1 ) ),
	array( post( 7, 'WordPress 6.9 released with new block editor', '2026-09-19 10:00:00' ) ),
	array( 'now' => $now )
);
T::eq( CannibalizationEngine::DECISION_NO_ARTICLE, $r['decision'], 'decision is NO_ARTICLE' );
T::ok( CannibalizationEngine::blocksDraft( $r['decision'] ), 'and it blocks draft creation' );
T::ok( ! CannibalizationEngine::blocksDraft( CannibalizationEngine::DECISION_NEW ), 'NEW does not block' );
T::ok( ! CannibalizationEngine::blocksDraft( CannibalizationEngine::DECISION_UPDATE ), 'UPDATE does not block' );

/* ──────────────────────────── REPLACE ────────────────────────────────── */

T::group( 'A-2 — REPLACE when our coverage is stale and the story is major' );

$r = engine()->decide(
	story( 'WordPress 6.9 released with new block editor', array( 'score' => 92.0 ) ),
	array( post( 9, 'WordPress 6.9 released with new block editor', '2024-01-01 10:00:00' ) ),
	array( 'now' => $now )
);
T::eq( CannibalizationEngine::DECISION_REPLACE, $r['decision'], 'decision is REPLACE' );
T::eq( 9, $r['existing_article_id'], 'it names the obsolete post' );

T::group( 'A-2 — a stale post but a minor story is not REPLACE' );

$r = engine()->decide(
	story( 'WordPress 6.9 released with new block editor', array( 'score' => 50.0, 'items' => 3 ) ),
	array( post( 9, 'WordPress 6.9 released with new block editor', '2024-01-01 10:00:00' ) ),
	array( 'now' => $now )
);
T::ok( CannibalizationEngine::DECISION_REPLACE !== $r['decision'], 'a minor story does not replace a post' );

T::group( 'A-2 — a major story on fresh coverage is UPDATE, not REPLACE' );

$r = engine()->decide(
	story( 'WordPress 6.9 released with new block editor', array( 'score' => 95.0 ) ),
	array( post( 9, 'WordPress 6.9 released with new block editor', '2026-09-19 10:00:00' ) ),
	array( 'now' => $now )
);
T::eq( CannibalizationEngine::DECISION_UPDATE, $r['decision'], 'recent coverage is updated, not replaced' );

/* ──────────────────────────── CORRECT ────────────────────────────────── */

T::group( 'A-2 — CORRECT outranks everything when evidence contradicts us' );

$r = engine()->decide(
	story( 'WordPress 6.9 released with new block editor', array( 'score' => 95.0 ) ),
	array( post( 5, 'WordPress 6.9 released with new block editor', '2024-01-01 10:00:00' ) ),
	array( 'now' => $now, 'contradictions' => 2 )
);
T::eq( CannibalizationEngine::DECISION_CORRECT, $r['decision'], 'CORRECT beats REPLACE' );
T::eq( 5, $r['existing_article_id'], 'the article needing correction is named' );
T::contains( '2 contradiction', $r['reason'], 'the reason states how many contradictions' );

T::group( 'A-2 — contradictions on an unrelated post do not trigger CORRECT' );

$r = engine()->decide(
	story( 'WordPress 6.9 released with new block editor', array( 'contradictions' => 3 ) ),
	array( post( 5, 'Best coffee shops in Nuremberg' ) ),
	array( 'now' => $now )
);
T::eq( CannibalizationEngine::DECISION_NEW, $r['decision'], 'no overlap means no correction' );

T::group( 'A-2 — contradictions are read from the story when not passed' );

$r = engine()->decide(
	story( 'WordPress 6.9 released with new block editor', array( 'contradictions' => 1 ) ),
	array( post( 5, 'WordPress 6.9 released with new block editor' ) ),
	array( 'now' => $now )
);
T::eq( CannibalizationEngine::DECISION_CORRECT, $r['decision'], 'story->contradictionCount is honoured' );

/* ───────────────────────────── MERGE ─────────────────────────────────── */

T::group( 'A-2 — MERGE when our coverage is fragmented across posts' );

$r = engine()->decide(
	story( 'WordPress 6.9 released with new block editor' ),
	array(
		post( 11, 'WordPress 6.9 released with new block editor', '2026-09-18 10:00:00' ),
		post( 12, 'WordPress 6.9 released with new block editor today', '2026-09-18 12:00:00' ),
	),
	array( 'now' => $now )
);
T::eq( CannibalizationEngine::DECISION_MERGE, $r['decision'], 'decision is MERGE' );
T::ok( $r['existing_article_id'] > 0, 'a consolidation target is named' );
T::eq( 2, count( $r['matches'] ), 'both overlapping posts are reported' );

/* ──────────────────────────── REWRITE ────────────────────────────────── */

T::group( 'A-2 — REWRITE when related coverage exists but the story is major' );

$r = engine()->decide(
	story( 'WordPress 6.9 security release patches critical flaw', array( 'score' => 90.0 ) ),
	array( post( 20, 'WordPress 6.9 release notes', '2026-09-18 10:00:00' ) ),
	array( 'now' => $now )
);
T::eq( CannibalizationEngine::DECISION_REWRITE, $r['decision'], 'decision is REWRITE' );
T::eq( 20, $r['existing_article_id'], 'the related post is referenced' );
T::ok(
	$r['overlap'] < CannibalizationEngine::OVERLAP_STRONG && $r['overlap'] >= CannibalizationEngine::OVERLAP_NONE,
	'overlap is partial, not identical'
);

/* ─────────────────────── ranking and contract ────────────────────────── */

T::group( 'A-2 — the strongest match wins, not the first one seen' );

$r = engine()->decide(
	story( 'WordPress 6.9 released with new block editor' ),
	array(
		post( 30, 'WordPress plugins roundup', '2026-09-18 10:00:00' ),
		post( 31, 'WordPress 6.9 released with new block editor', '2026-09-18 10:00:00' ),
	),
	array( 'now' => $now )
);
T::eq( 31, $r['existing_article_id'], 'the best-overlapping post is chosen' );
T::ok( $r['matches'][0]['overlap'] >= $r['matches'][1]['overlap'], 'matches are sorted by overlap' );

T::group( 'A-2 — every decision that names an article actually has one' );

$cases = array(
	array( story( 'WordPress 6.9 released with new block editor', array( 'items' => 4 ) ), array( post( 1, 'WordPress 6.9 released with new block editor' ) ), array() ),
	array( story( 'WordPress 6.9 released with new block editor', array( 'score' => 95.0 ) ), array( post( 2, 'WordPress 6.9 released with new block editor', '2023-01-01' ) ), array() ),
	array( story( 'WordPress 6.9 released with new block editor' ), array( post( 3, 'WordPress 6.9 released with new block editor' ) ), array( 'contradictions' => 1 ) ),
	array( story( 'WordPress 6.9 security release patches critical flaw', array( 'score' => 90.0 ) ), array( post( 4, 'WordPress 6.9 release notes' ) ), array() ),
);
$violations = array();
foreach ( $cases as $i => $case ) {
	$res = engine()->decide( $case[0], $case[1], array_merge( array( 'now' => $now ), $case[2] ) );
	if ( CannibalizationEngine::requiresExistingArticle( $res['decision'] ) && $res['existing_article_id'] <= 0 ) {
		$violations[] = $res['decision'];
	}
}
T::eq( array(), $violations, 'UPDATE/REWRITE/MERGE/CORRECT/REPLACE always carry an article id' );

T::group( 'A-2 — the engine only ever returns known decisions' );

$known = CannibalizationEngine::all();
T::eq( 7, count( $known ), 'exactly the seven decisions from the spec' );
$seen = array();
foreach ( $cases as $case ) {
	$seen[] = engine()->decide( $case[0], $case[1], array_merge( array( 'now' => $now ), $case[2] ) )['decision'];
}
$seen[] = engine()->decide( story( 'Totally unrelated headline here' ), array( post( 1, 'Nothing alike' ) ), array( 'now' => $now ) )['decision'];
foreach ( $seen as $d ) {
	T::ok( in_array( $d, $known, true ), "returned decision '$d' is in the documented set" );
}

T::group( 'A-2 — malformed post rows are skipped, not fatal' );

$r = engine()->decide(
	story( 'WordPress 6.9 released with new block editor' ),
	array(
		array( 'post_id' => 0, 'title' => 'WordPress 6.9 released with new block editor' ),
		array( 'title' => 'no id at all' ),
		array( 'post_id' => 5 ),
		post( 6, 'WordPress 6.9 released with new block editor' ),
	),
	array( 'now' => $now )
);
T::eq( 6, $r['existing_article_id'], 'only the well-formed row is considered' );

T::group( 'A-2 — an unparseable modified date degrades to UPDATE, not a crash' );

$r = engine()->decide(
	story( 'WordPress 6.9 released with new block editor', array( 'score' => 95.0 ) ),
	array( post( 8, 'WordPress 6.9 released with new block editor', 'not-a-date' ) ),
	array( 'now' => $now )
);
T::ok( in_array( $r['decision'], CannibalizationEngine::all(), true ), 'still a valid decision' );
T::ok( CannibalizationEngine::DECISION_REPLACE !== $r['decision'], 'unknown age never claims staleness' );

T::group( 'A-2 — no AI is involved in this decision' );

$src = file_get_contents( NEWSDESK_DIR . 'src/Application/Scoring/CannibalizationEngine.php' );
foreach ( array( 'AiGateway', 'AIProvider', 'chat(', 'generateStructured' ) as $needle ) {
	T::ok( false === strpos( $src, $needle ), "engine does not reference $needle (deterministic by design)" );
}

exit( T::summary() );
