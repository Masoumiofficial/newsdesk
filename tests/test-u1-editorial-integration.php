<?php
/**
 * U-1 integration: A-1 + A-2 wired through StoryEditorialService with a real
 * repository stack on the in-memory DB.
 *
 * The unit tests prove the two new objects are correct in isolation; these
 * prove the service actually calls them, persists the result, and logs it.
 */

require __DIR__ . '/bootstrap.php';

use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Application\Scoring\CannibalizationEngine;
use NewsDesk\AI\Application\Scoring\EditorialSelector;
use NewsDesk\AI\Application\Scoring\NewsWindow;
use NewsDesk\AI\Application\Scoring\StoryClusterer;
use NewsDesk\AI\Application\Scoring\StoryScorer;
use NewsDesk\AI\Application\StoryEditorialService;
use NewsDesk\AI\Infrastructure\Database\TableNames;
use NewsDesk\AI\Domain\Entity\NewsItem;
use NewsDesk\AI\Infrastructure\Repository\NewsItemRepository;
use NewsDesk\AI\Infrastructure\Repository\SourceRepository;
use NewsDesk\AI\Infrastructure\Repository\StoryRepository;

/**
 * Build the whole editorial stack over a fresh fake database.
 *
 * @param array $opts hours, days, posts, withEngine
 */
function stack( array $opts = array() ): array {
	$db       = new FakeWpDb( 'wp_' );
	$tables   = new TableNames( 'wp_' );
	$logger   = new FakeLogger();
	$settings = new NewsroomSettings(
		array_merge(
			NewsroomSettings::defaults(),
			array(
				'editorial_window_hours' => $opts['hours'] ?? 24,
				'fallback_window_days'   => $opts['days'] ?? 7,
				'max_stories_per_window' => $opts['max'] ?? 3,
				'selection_quality_gate' => 0,
				'selection_min_trust'    => 0,
			)
		)
	);

	$index        = new FakePostIndex();
	$index->posts = $opts['posts'] ?? array();

	$clusterer = new StoryClusterer();
	$svc       = new StoryEditorialService(
		new NewsItemRepository( $db, $tables ),
		new SourceRepository( $db, $tables ),
		new StoryRepository( $db, $tables ),
		$clusterer,
		new StoryScorer( $settings ),
		new EditorialSelector(),
		$settings,
		$logger,
		( $opts['withEngine'] ?? true ) ? new CannibalizationEngine( $clusterer ) : null,
		( $opts['withEngine'] ?? true ) ? $index : null
	);

	return compact( 'svc', 'db', 'logger', 'index', 'tables' );
}

/** Seed one source plus N news items created at $ago. */
function seed_news( FakeWpDb $db, string $title, string $createdAt, int $count = 2 ): void {
	$db->seed(
		'wp_newsdesk_sources',
		array(
			array(
				'id'               => 1,
				'name'             => 'Primary',
				'type'             => 'rss',
				'url'              => 'https://a.test',
				'feed_url'         => 'https://a.test/feed',
				'language'         => 'en_US',
				'category'         => 'general',
				'priority'         => 50,
				'base_trust_score' => 80.0,
				'trust_score'      => 80.0,
				'trust_override'   => 0,
				'active'           => 1,
				'status'           => 'active',
				'settings'         => '{}',
			),
		)
	);

	$rows = array();
	for ( $i = 1; $i <= $count; $i++ ) {
		$rows[] = array(
			'id'               => $i,
			'source_id'        => 1,
			'guid'             => 'g' . $i,
			'canonical_url'    => 'https://a.test/' . $i,
			'content_hash'     => 'h' . $i,
			'title'            => $title,
			'normalized_title' => strtolower( $title ),
			'excerpt'          => 'Excerpt ' . $i,
			'content_text'     => 'Body text number ' . $i,
			'author'           => 'Reporter',
			'categories'       => '[]',
			'language'         => 'en_US',
			'published_at'     => $createdAt,
			'fetched_at'       => $createdAt,
			'created_at'       => $createdAt,
			'last_seen_at'     => $createdAt,
			'status'           => NewsItem::STATUS_NORMALIZED,
			'duplicate_of_id'  => 0,
			'duplicate_level'  => '',
		);
	}
	$db->seed( 'wp_newsdesk_news_items', $rows );
}

$today  = gmdate( 'Y-m-d H:i:s' );
$oldDay = gmdate( 'Y-m-d H:i:s', time() - ( 3 * 86400 ) );   // inside 7d, outside 24h
$ancient = gmdate( 'Y-m-d H:i:s', time() - ( 40 * 86400 ) ); // outside both

/* ──────────────────── A-1: the ladder inside the service ─────────────── */

T::group( 'A-1 — fresh news is handled on the 24h rung, no widening' );

$s = stack();
seed_news( $s['db'], 'WordPress 6.9 ships new block editor', $today );
$out = $s['svc']->run( 1, 'w-today' );

T::eq( NewsWindow::RUNG_PRIMARY, $out['window_rung'], 'served from the primary window' );
T::ok( ! $s['logger']->hasEvent( 'WINDOW_WIDENED' ), 'the ladder never had to widen' );
T::ok( count( $out['selected'] ) > 0, 'a story was selected' );

T::group( 'A-1 — a quiet 24h widens to 7d instead of publishing nothing' );

$s = stack();
seed_news( $s['db'], 'WordPress 6.9 ships new block editor', $oldDay );
$out = $s['svc']->run( 2, 'w-old' );

T::eq( NewsWindow::RUNG_FALLBACK, $out['window_rung'], 'served from the fallback window' );
T::ok( $s['logger']->hasEvent( 'WINDOW_WIDENED' ), 'the widening is logged' );
T::ok( count( $out['selected'] ) > 0, '3-day-old news is still found' );

T::group( 'A-1 — nothing in either window is an explicit NO NEWS' );

$s = stack();
seed_news( $s['db'], 'Very old story about nothing', $ancient );
$out = $s['svc']->run( 3, 'w-none' );

T::eq( NewsWindow::RUNG_NONE, $out['window_rung'], 'the ladder reached its terminal rung' );
T::eq( EditorialSelector::OUTCOME_NO_PUBLISHABLE_STORY, $out['outcome'], 'outcome says so' );
T::eq( array(), $out['selected'], 'and nothing is selected' );
T::eq( 0, $out['stories_total'], 'no stories were manufactured' );
T::ok( $s['logger']->hasEvent( 'NO_NEWS' ), 'NO_NEWS is logged for the operator' );
T::eq( array(), $s['db']->rows( 'wp_newsdesk_stories' ), 'no story rows were written' );

T::group( 'A-1 — the ladder widens exactly once, it does not loop' );

$widened = 0;
foreach ( $s['logger']->lines as $line ) {
	if ( 'WINDOW_WIDENED' === ( $line['event'] ?? '' ) ) {
		$widened++;
	}
}
T::eq( 1, $widened, 'one widening step: 24h → 7d, then stop' );

/* ─────────────── A-2: decisions persisted through the service ────────── */

T::group( 'A-2 — a story with no matching post is stored as NEW' );

$s = stack( array( 'posts' => array( array( 'post_id' => 4, 'title' => 'Sourdough tips', 'url' => 'https://s.test/4' ) ) ) );
seed_news( $s['db'], 'WordPress 6.9 ships new block editor', $today );
$out = $s['svc']->run( 10, 'w1' );

$rows = $s['db']->rows( 'wp_newsdesk_stories' );
T::eq( 1, count( $rows ), 'one story row' );
T::eq( CannibalizationEngine::DECISION_NEW, $rows[0]['editorial_decision'], 'editorial_decision persisted as NEW' );
T::eq( 0, (int) $rows[0]['existing_article_id'], 'no article referenced' );
T::ok( $s['logger']->hasEvent( 'EDITORIAL_DECISION' ), 'the decision is logged' );

T::group( 'A-2 — an already-covered story is stored as UPDATE with the post id' );

$s = stack(
	array(
		'posts' => array(
			array( 'post_id' => 77, 'title' => 'WordPress 6.9 ships new block editor', 'url' => 'https://s.test/77', 'modified' => gmdate( 'Y-m-d H:i:s', time() - 86400 ) ),
		),
	)
);
seed_news( $s['db'], 'WordPress 6.9 ships new block editor', $today, 4 );
$out = $s['svc']->run( 11, 'w2' );

$rows = $s['db']->rows( 'wp_newsdesk_stories' );
T::eq( CannibalizationEngine::DECISION_UPDATE, $rows[0]['editorial_decision'], 'stored as UPDATE' );
T::eq( 77, (int) $rows[0]['existing_article_id'], 'the existing post id is persisted' );
T::eq( 77, $out['decisions'][ (int) $rows[0]['story_id'] ]['existing_article_id'], 'and returned to the caller' );

T::group( 'A-2 — the selection reason carries the verdict for the audit trail' );

T::contains( 'UPDATE', (string) $rows[0]['selection_reason'], 'the reason records the decision' );

T::group( 'A-2 — NO_ARTICLE stops the story before a draft is ever made' );

$s = stack(
	array(
		'posts' => array(
			array( 'post_id' => 88, 'title' => 'WordPress 6.9 ships new block editor', 'url' => 'https://s.test/88', 'modified' => gmdate( 'Y-m-d H:i:s', time() - 86400 ) ),
		),
	)
);
// A single low-scoring item on a story we already covered = nothing to add.
seed_news( $s['db'], 'WordPress 6.9 ships new block editor', $today, 1 );
$out = $s['svc']->run( 12, 'w3' );

$rows = $s['db']->rows( 'wp_newsdesk_stories' );
T::eq( CannibalizationEngine::DECISION_NO_ARTICLE, $rows[0]['editorial_decision'], 'stored as NO_ARTICLE' );
T::eq( array(), $out['selected'], 'it is removed from the selected list' );
T::eq( EditorialSelector::OUTCOME_NO_PUBLISHABLE_STORY, $out['outcome'], 'the run reports nothing publishable' );
T::contains( 'CANNIBALIZATION_NO_ARTICLE', implode( '|', $out['gates'] ), 'the gate names the reason' );
T::eq( '', (string) ( $rows[0]['selected_at'] ?? '' ), 'the story was never marked selected' );

T::group( 'A-2 — the scan pool is bounded, not the whole site' );

T::eq( StoryEditorialService::CANNIBALIZATION_SCAN, $s['index']->askedFor, 'a fixed scan limit is requested' );
T::ok( StoryEditorialService::CANNIBALIZATION_SCAN >= 100, 'and it is wide enough to be useful' );

/* ───────────────────────── backward compatibility ────────────────────── */

T::group( 'A-2 — the engine is optional: omitting it keeps v1 behaviour' );

$s = stack( array( 'withEngine' => false ) );
seed_news( $s['db'], 'WordPress 6.9 ships new block editor', $today );
$out = $s['svc']->run( 20, 'w4' );

T::ok( count( $out['selected'] ) > 0, 'the service still runs without the engine' );
T::eq( array(), $out['decisions'], 'no decisions are reported' );
T::ok( ! $s['logger']->hasEvent( 'EDITORIAL_DECISION' ), 'and none are logged' );

T::group( 'A-1/A-2 — the run result keeps its documented shape' );

foreach ( array( 'clustered', 'stories_total', 'selected', 'outcome', 'gates', 'window_rung', 'decisions' ) as $key ) {
	T::ok( array_key_exists( $key, $out ), "run() returns '$key'" );
}

exit( T::summary() );
