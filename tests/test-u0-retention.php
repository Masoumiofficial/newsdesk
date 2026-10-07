<?php
/**
 * U-0 regression tests: B-7 — the retention_* settings had no consumer, so
 * every table grew forever. Also covers the LogRepository::pruneOlderThan()
 * exact-match bug found while wiring it up, and the orphan cron events.
 */

require __DIR__ . '/bootstrap.php';

use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Application\RetentionService;
use NewsDesk\AI\Infrastructure\Database\TableNames;
use NewsDesk\AI\Infrastructure\Repository\LogRepository;
use NewsDesk\AI\Infrastructure\Scheduler\DigestCron;
use NewsDesk\AI\Infrastructure\Scheduler\RetentionCron;
use NewsDesk\AI\Support\Time;

/** Datetime string N days in the past. */
function days_ago( int $n ): string {
	return Time::toDb( Time::now()->modify( '-' . $n . ' days' ) );
}

T::group( 'PHP 7.4 — DB dates accept mutable and immutable inputs' );
$mutable = new \DateTime( '2026-01-02 03:04:05', new \DateTimeZone( '+02:00' ) );
T::eq( '2026-01-02 01:04:05', Time::toDb( $mutable ), 'mutable date converts to the site timezone' );
T::eq( '2026-01-02 03:04:05', $mutable->format( 'Y-m-d H:i:s' ), 'input is not modified' );
T::eq( '2026-01-02 01:04:05', Time::toDb( \DateTimeImmutable::createFromMutable( $mutable ) ), 'immutable date converts the same way' );
T::eq( null, Time::toDb( null ), 'null date stays null' );

function retention_env( array $settings = array() ): array {
	$db     = new FakeWpDb();
	$tables = new TableNames( $db->prefix() );
	$logger = new FakeLogger();
	$svc    = new RetentionService(
		$db,
		$tables,
		new NewsroomSettings( array_merge( NewsroomSettings::defaults(), $settings ) ),
		$logger
	);
	return array( $db, $svc, $logger );
}

/* ══════════════════════════════════════════ B-7 ══════════════════════════ */

T::group( 'B-7 — the retention settings finally have a consumer' );

T::ok( class_exists( RetentionService::class ), 'RetentionService exists' );
T::ok( class_exists( RetentionCron::class ), 'RetentionCron exists' );

$pluginSrc = file_get_contents( NEWSDESK_DIR . 'src/Core/Plugin.php' );
T::contains( 'RetentionService', $pluginSrc, 'registered in the container' );
$activationSrc = file_get_contents( NEWSDESK_DIR . 'src/Core/Activation.php' );
T::contains( 'RetentionCron', $activationSrc, 'scheduled on activation' );

T::group( 'B-7 — old logs are pruned, recent ones are kept' );

list( $db, $svc ) = retention_env( array( 'retention_logs_days' => 90 ) );
$db->seed(
	'wp_newsdesk_logs',
	array(
		array( 'id' => 1, 'timestamp' => days_ago( 200 ), 'message' => 'ancient' ),
		array( 'id' => 2, 'timestamp' => days_ago( 91 ), 'message' => 'just over' ),
		array( 'id' => 3, 'timestamp' => days_ago( 89 ), 'message' => 'just under' ),
		array( 'id' => 4, 'timestamp' => days_ago( 1 ), 'message' => 'fresh' ),
	)
);
$out = $svc->run();
T::eq( 2, $out['logs'], 'two rows older than 90 days were removed' );
$left = array_column( $db->rows( 'wp_newsdesk_logs' ), 'message' );
T::eq( array( 'just under', 'fresh' ), $left, 'only the in-window rows remain' );

T::group( 'B-7 — every configured table is swept' );

list( $db, $svc ) = retention_env(
	array(
		'retention_logs_days'   => 30,
		'retention_events_days' => 30,
		'retention_usage_days'  => 30,
		'retention_news_days'   => 30,
	)
);
$db->seed( 'wp_newsdesk_logs', array( array( 'id' => 1, 'timestamp' => days_ago( 60 ) ) ) );
$db->seed( 'wp_newsdesk_job_events', array( array( 'id' => 1, 'created_at' => days_ago( 60 ) ) ) );
$db->seed( 'wp_newsdesk_ai_usage', array( array( 'id' => 1, 'created_at' => days_ago( 60 ) ) ) );
$db->seed( 'wp_newsdesk_news_items', array( array( 'id' => 1, 'created_at' => days_ago( 60 ) ) ) );
$db->seed( 'wp_newsdesk_story_sources', array() );

$out = $svc->run();
T::eq( 1, $out['logs'], 'logs swept' );
T::eq( 1, $out['job_events'], 'job events swept' );
T::eq( 1, $out['ai_usage'], 'ai usage swept' );
T::eq( 1, $out['news_items'], 'news items swept' );

T::group( 'B-7 — news items attached to a story are never deleted' );

list( $db, $svc ) = retention_env( array( 'retention_news_days' => 30 ) );
$db->seed(
	'wp_newsdesk_news_items',
	array(
		array( 'id' => 10, 'created_at' => days_ago( 60 ), 'title' => 'orphan' ),
		array( 'id' => 11, 'created_at' => days_ago( 60 ), 'title' => 'used by a story' ),
	)
);
$db->seed( 'wp_newsdesk_story_sources', array( array( 'story_id' => 1, 'news_item_id' => 11 ) ) );

$out = $svc->run();
T::eq( 1, $out['news_items'], 'only the orphan was deleted' );
$remaining = $db->rows( 'wp_newsdesk_news_items' );
T::eq( 1, count( $remaining ), 'one row survives' );
T::eq( 'used by a story', $remaining[0]['title'], 'the story evidence is the survivor' );

T::group( 'B-7 — 0 days means keep forever' );

list( $db, $svc ) = retention_env(
	array(
		'retention_logs_days'   => 0,
		'retention_events_days' => 0,
		'retention_usage_days'  => 0,
		'retention_news_days'   => 0,
	)
);
$db->seed( 'wp_newsdesk_logs', array( array( 'id' => 1, 'timestamp' => days_ago( 5000 ) ) ) );
$out = $svc->run();
T::eq( 0, $out['logs'], 'nothing is pruned when retention is disabled' );
T::eq( 1, count( $db->rows( 'wp_newsdesk_logs' ) ), 'the ancient row survives' );

T::group( 'B-7 — deletes are batched so a huge table cannot lock up' );

list( $db, $svc ) = retention_env( array( 'retention_logs_days' => 1 ) );
$rows = array();
for ( $i = 1; $i <= RetentionService::BATCH_LIMIT + 250; $i++ ) {
	$rows[] = array( 'id' => $i, 'timestamp' => days_ago( 10 ) );
}
$db->seed( 'wp_newsdesk_logs', $rows );
$first = $svc->run();
T::eq( RetentionService::BATCH_LIMIT, $first['logs'], 'the first run stops at the batch limit' );
T::eq( 250, count( $db->rows( 'wp_newsdesk_logs' ) ), 'the remainder is left for the next run' );
$second = $svc->run();
T::eq( 250, $second['logs'], 'the next run finishes the job' );
T::eq( 0, count( $db->rows( 'wp_newsdesk_logs' ) ), 'table is now clear' );

T::group( 'B-7 — the run fires a hook and logs' );

list( $db, $svc, $logger ) = retention_env( array( 'retention_logs_days' => 1 ) );
$db->seed( 'wp_newsdesk_logs', array( array( 'id' => 1, 'timestamp' => days_ago( 10 ) ) ) );
$seen = array();
add_action(
	'newsdesk_retention_finished',
	static function ( $deleted ) use ( &$seen ) {
		$seen = $deleted;
	}
);
$svc->run();
T::ok( $logger->hasEvent( 'RETENTION_DONE' ), 'a summary is logged' );
T::eq( 1, $seen['logs'] ?? 0, 'newsdesk_retention_finished receives the counts' );
WpTestState::$actions = array();

/* ───────────────────── LogRepository::pruneOlderThan ─────────────────── */

T::group( 'B-7b — pruneOlderThan() uses a range, not an exact match' );

$db2  = new FakeWpDb();
$repo = new LogRepository( $db2, new TableNames( $db2->prefix() ) );
$db2->seed(
	'wp_newsdesk_logs',
	array(
		array( 'id' => 1, 'timestamp' => days_ago( 100 ) ),
		array( 'id' => 2, 'timestamp' => days_ago( 50 ) ),
		array( 'id' => 3, 'timestamp' => days_ago( 1 ) ),
	)
);
$n = $repo->pruneOlderThan( Time::now()->modify( '-30 days' ) );
T::eq( 2, $n, 'both rows older than the cutoff are deleted (pre-fix: 0)' );
T::eq( 1, count( $db2->rows( 'wp_newsdesk_logs' ) ), 'the recent row remains' );

/* ─────────────────────────── cron teardown ───────────────────────────── */

T::group( 'B-7c — daily cron events are torn down on deactivate/uninstall' );

$deactivationSrc = file_get_contents( NEWSDESK_DIR . 'src/Core/Deactivation.php' );
T::contains( 'DigestCron', $deactivationSrc, 'the digest event is unscheduled on deactivation (was orphaned)' );
T::contains( 'RetentionCron', $deactivationSrc, 'the retention event is unscheduled too' );

$uninstallSrc = file_get_contents( NEWSDESK_DIR . 'src/Core/Uninstaller.php' );
T::contains( 'wp_clear_scheduled_hook', $uninstallSrc, 'uninstall clears ALL occurrences of each hook' );
T::contains( 'RetentionCron::HOOK', $uninstallSrc, 'including the new retention hook' );

T::group( 'B-7c — RetentionCron schedules exactly one daily event' );

WpTestState::reset();
$cron = new RetentionCron(
	new RetentionService( new FakeWpDb(), new TableNames( 'wp_' ), new NewsroomSettings( NewsroomSettings::defaults() ), new FakeLogger() ),
	new FakeLogger()
);
T::ok( $cron->schedule(), 'first schedule() succeeds' );
T::ok( ! $cron->schedule(), 'a second call does not double-book' );
T::ok( false !== wp_next_scheduled( RetentionCron::HOOK ), 'the event is registered' );
$cron->unschedule();
T::ok( false === wp_next_scheduled( RetentionCron::HOOK ), 'unschedule() removes it' );

T::group( 'B-7c — a failing sweep never bubbles out of cron' );

// RetentionService is final, so simulate the failure through the DB layer:
// a db that throws on query() must not take down the cron callback.
final class ExplodingDb extends FakeWpDb {
	public function query( string $sql ) {
		throw new \RuntimeException( 'db gone' );
	}
}

$logger2 = new FakeLogger();
$cron2   = new RetentionCron(
	new RetentionService( new ExplodingDb(), new TableNames( 'wp_' ), new NewsroomSettings( NewsroomSettings::defaults() ), new FakeLogger() ),
	$logger2
);
$threw = false;
try {
	$cron2->run();
} catch ( \Throwable $e ) {
	$threw = true;
}
T::ok( ! $threw, 'RetentionCron::run() swallows the exception' );
T::ok( $logger2->hasEvent( 'RETENTION_FAILED' ), 'and logs it as an error' );

exit( T::summary() );
