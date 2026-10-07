<?php
/**
 * Log quality — reported from a real 2.0.0 run (job #144).
 *
 * Two defects showed up in production logs. Neither was a crash; both made the
 * logs harder to act on, which is its own kind of bug:
 *
 *  1. `EDITORIAL_DONE` reported `gate_hits: 14` with no breakdown, so an
 *     operator seeing `selected: 0` could not tell whether the thresholds were
 *     too strict, the archive check was firing, or capacity was reached —
 *     three problems with three different fixes.
 *
 *  2. "Run now" runs the job synchronously AND leaves it queued, so the async
 *     worker arrives seconds later, finds it COMPLETED, and logged a WARNING.
 *     Routine operation must not emit warnings; operators stop reading them.
 */

require __DIR__ . '/bootstrap.php';

use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Application\Scoring\CannibalizationEngine;
use NewsDesk\AI\Application\Scoring\EditorialSelector;
use NewsDesk\AI\Application\Scoring\StoryClusterer;
use NewsDesk\AI\Application\Scoring\StoryScorer;
use NewsDesk\AI\Application\StoryEditorialService;
use NewsDesk\AI\Domain\Entity\Story;
use NewsDesk\AI\Infrastructure\Database\TableNames;
use NewsDesk\AI\Infrastructure\Repository\NewsItemRepository;
use NewsDesk\AI\Infrastructure\Repository\SourceRepository;
use NewsDesk\AI\Infrastructure\Repository\StoryRepository;

/**
 * Editorial stack with configurable gates, so a rejection reason can be forced.
 */
function diag_stack( array $opts = array() ): array {
	$db       = new FakeWpDb( 'wp_' );
	$tables   = new TableNames( 'wp_' );
	$logger   = new FakeLogger();
	$settings = new NewsroomSettings(
		array_merge(
			NewsroomSettings::defaults(),
			array(
				'editorial_window_hours' => 24,
				'fallback_window_days'   => 7,
				'max_stories_per_window' => $opts['max'] ?? 3,
				'selection_quality_gate' => $opts['gate'] ?? 0,
				'selection_min_trust'    => $opts['trust'] ?? 0,
			)
		)
	);

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
		null,
		null
	);

	return compact( 'svc', 'db', 'logger' );
}

/** Seed a source plus $count items, all recent enough for the 24h window. */
function diag_seed( FakeWpDb $db, int $count = 14 ): void {
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

	$now  = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	$when = $now->modify( '-2 hours' )->format( 'Y-m-d H:i:s' );
	$rows = array();
	for ( $i = 1; $i <= $count; $i++ ) {
		// Distinct titles so each becomes its own cluster.
		$rows[] = array(
			'id'            => $i,
			'source_id'     => 1,
			'guid'          => 'g' . $i,
			'title'         => 'Distinct topic number ' . $i . ' about subject ' . $i,
			'canonical_url' => 'https://a.test/' . $i,
			'content_hash'  => 'h' . $i,
			'excerpt'       => 'Excerpt ' . $i,
			'content_text'  => 'Body text for item ' . $i,
			'language'      => 'en_US',
			'status'        => 'normalized',
			'published_at'  => $when,
			'created_at'    => $when,
			'updated_at'    => $when,
		);
	}
	$db->seed( 'wp_newsdesk_news_items', $rows );
}

/* ══════════ 1. the gate breakdown ══════════ */

T::group( 'EDITORIAL_DONE says WHY stories were rejected, not just how many' );

// Gate of 101 is unreachable, so every story fails the same way — the shape
// job #144 showed: clustered N, selected 0, gate_hits N.
$s = diag_stack( array( 'gate' => 101 ) );
diag_seed( $s['db'], 14 );
$result = $s['svc']->run( 144, 'w-144' );

T::eq( 0, count( $result['selected'] ), 'nothing is selected, as in the real run' );
T::eq( 'NO_PUBLISHABLE_STORY_FOUND', $result['outcome'], 'and the outcome matches' );

$done = null;
foreach ( $s['logger']->lines as $line ) {
	if ( 'EDITORIAL_DONE' === ( $line['event'] ?? '' ) ) {
		$done = $line;
	}
}
T::ok( null !== $done, 'EDITORIAL_DONE was logged' );
T::ok( isset( $done['context']['gate_reasons'] ), 'it now carries a gate_reasons breakdown' );
T::ok( ! empty( $done['context']['gate_reasons'] ), 'the breakdown is not empty when stories were gated' );

T::group( 'the breakdown names the actual reason' );

$reasons = $done['context']['gate_reasons'];
T::ok( isset( $reasons['BELOW_QUALITY_GATE'] ), 'an unreachable quality gate is reported as BELOW_QUALITY_GATE' );
T::eq( (int) $done['context']['gate_hits'], array_sum( $reasons ), 'the breakdown totals to gate_hits' );

T::group( 'a different cause produces a different reason' );

// Same symptom (selected 0), different cause: trust, not quality. The whole
// point of the breakdown is that these are distinguishable.
$s2 = diag_stack( array( 'trust' => 101 ) );
diag_seed( $s2['db'], 6 );
$s2['svc']->run( 145, 'w-145' );

$done2 = null;
foreach ( $s2['logger']->lines as $line ) {
	if ( 'EDITORIAL_DONE' === ( $line['event'] ?? '' ) ) {
		$done2 = $line;
	}
}
T::ok( isset( $done2['context']['gate_reasons']['BELOW_TRUST_GATE'] ), 'a trust failure is reported as BELOW_TRUST_GATE' );
T::ok( ! isset( $done2['context']['gate_reasons']['BELOW_QUALITY_GATE'] ), 'and is NOT confused with the quality gate' );

T::group( 'capacity is distinguished from rejection' );

// max=1 with several viable stories: the rest are not "bad", just surplus.
$s3 = diag_stack( array( 'max' => 1 ) );
diag_seed( $s3['db'], 5 );
$r3 = $s3['svc']->run( 146, 'w-146' );

$done3 = null;
foreach ( $s3['logger']->lines as $line ) {
	if ( 'EDITORIAL_DONE' === ( $line['event'] ?? '' ) ) {
		$done3 = $line;
	}
}
T::eq( 1, count( $r3['selected'] ), 'the cap is honoured' );
T::ok( isset( $done3['context']['gate_reasons']['CAPACITY_REACHED'] ), 'surplus stories are reported as CAPACITY_REACHED' );

T::group( 'the window rung is recorded on the summary line' );

// job #144 logged WINDOW_WIDENED separately; the summary should also say
// which window the result came from, so one line explains the run.
T::ok( isset( $done['context']['window'] ), 'EDITORIAL_DONE records the window used' );
T::ok( in_array( $done['context']['window'], array( '24h', '7d', 'none' ), true ), 'and it is a real window label: ' . $done['context']['window'] );

T::group( 'a clean run reports no gate reasons' );

$s4 = diag_stack();
diag_seed( $s4['db'], 2 );
$s4['svc']->run( 147, 'w-147' );
$done4 = null;
foreach ( $s4['logger']->lines as $line ) {
	if ( 'EDITORIAL_DONE' === ( $line['event'] ?? '' ) ) {
		$done4 = $line;
	}
}
T::eq( array(), $done4['context']['gate_reasons'], 'nothing gated means an empty breakdown, not a fake entry' );

/* ══════════ 2. duplicate dispatch is not a warning ══════════ */

T::group( 'a finished job re-dispatched by the queue is INFO, not WARNING' );

$runner = file_get_contents( NEWSDESK_DIR . 'src/Application/PipelineRunner.php' );
T::contains( 'JOB_ALREADY_FINISHED', $runner, 'a distinct event exists for the benign case' );
T::contains( '$job->isTerminal()', $runner, 'it is chosen by checking whether the job actually finished' );

// The severity split must be real: info for finished, warning otherwise.
$pos      = strpos( $runner, 'JOB_ALREADY_FINISHED' );
$window   = substr( $runner, max( 0, $pos - 300 ), 600 );
T::contains( 'logger->info', $window, 'the finished case logs at INFO' );
T::contains( 'JOB_NOT_RUNNABLE', $runner, 'the genuinely-conflicting case still exists' );

T::group( 'a job caught mid-flight is still a warning' );

// RUNNING-but-not-QUEUED means two workers are racing — that IS worth a
// warning, and must not have been swallowed by the fix above.
$warnPos   = strpos( $runner, 'JOB_NOT_RUNNABLE' );
$warnBlock = substr( $runner, max( 0, $warnPos - 200 ), 400 );
T::contains( 'logger->warning', $warnBlock, 'the non-terminal case still logs at WARNING' );

exit( T::summary() );
