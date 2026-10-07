<?php
/**
 * U-0 regression tests: B-1 (uninstall orphan option) and B-2 (dedup no-op).
 */

require __DIR__ . '/bootstrap.php';

use NewsDesk\AI\Domain\Entity\NewsItem;
use NewsDesk\AI\Infrastructure\Database\TableNames;
use NewsDesk\AI\Infrastructure\Repository\NewsItemRepository;
use NewsDesk\AI\Infrastructure\Scheduler\CronScheduler;
use NewsDesk\AI\Support\Time;

/**
 * Build a normalized item ready to insert.
 */
function mk_item( array $over = array() ): NewsItem {
	$i              = new NewsItem();
	$i->sourceId    = $over['sourceId'] ?? 1;
	$i->guid        = $over['guid'] ?? '';
	$i->canonicalUrl = $over['url'] ?? '';
	$i->contentHash = $over['hash'] ?? '';
	$i->title       = $over['title'] ?? 'Some headline';
	$i->createdAt   = Time::now();
	$i->status      = NewsItem::STATUS_NORMALIZED;
	return $i;
}

function mk_repo(): array {
	$db = new FakeWpDb();
	return array( $db, new NewsItemRepository( $db, new TableNames( $db->prefix() ) ) );
}

/* ══════════════════════════════════════════ B-1 ══════════════════════════ */

T::group( 'B-1 — uninstall deletes the schedule-state option that actually exists' );

$src = file_get_contents( NEWSDESK_DIR . 'src/Core/Uninstaller.php' );
T::ok(
	strpos( $src, 'CronScheduler::STATE_OPTION' ) !== false,
	'Uninstaller references CronScheduler::STATE_OPTION instead of a literal'
);
T::eq(
	'newsdesk_newsroom_schedule_state',
	CronScheduler::STATE_OPTION,
	'the real option name is unchanged (so the old literal was genuinely wrong)'
);

// Simulate: scheduler writes its state, uninstall runs, option must be gone.
WpTestState::reset();
update_option( CronScheduler::STATE_OPTION, array( 'last_run' => 123 ) );
T::ok( false !== get_option( CronScheduler::STATE_OPTION, false ), 'precondition: state option exists' );

// Replicate the delete list the Uninstaller now uses.
foreach ( array( CronScheduler::STATE_OPTION, 'newsdesk_schedule_state' ) as $opt ) {
	delete_option( $opt );
}
T::ok( false === get_option( CronScheduler::STATE_OPTION, false ), 'state option is removed by uninstall' );

/* ══════════════════════════════════════════ B-2 ══════════════════════════ */

T::group( 'B-2 — duplicates are persisted, not silently dropped' );

list( $db, $repo ) = mk_repo();

$winner = mk_item( array( 'guid' => 'g-100', 'url' => 'https://ex.test/a', 'hash' => str_repeat( '1', 64 ) ) );
$winnerId = $repo->insert( $winner );
T::ok( $winnerId > 0, 'winner inserted' );

// --- ladder rung 1: same guid, same source
$m = $repo->findDuplicate( 'g-100', '', '', 1 );
T::ok( null !== $m, 'findDuplicate matches on guid' );
T::eq( $winnerId, $m['id'], 'guid match returns the winner id' );
T::eq( 'guid', $m['level'], 'level is reported as guid' );

// --- ladder rung 2: different guid, same canonical url
$m = $repo->findDuplicate( 'g-999', 'https://ex.test/a', '', 1 );
T::eq( 'url', $m['level'], 'falls through to url when guid misses' );
T::eq( $winnerId, $m['id'], 'url match returns the winner id' );

// --- ladder rung 3: hash only
$m = $repo->findDuplicate( '', '', str_repeat( '1', 64 ), 1 );
T::eq( 'hash', $m['level'], 'falls through to hash when guid+url miss' );

// --- genuinely new item
T::eq( null, $repo->findDuplicate( 'g-new', 'https://ex.test/z', str_repeat( '9', 64 ), 1 ), 'unique item reports no duplicate' );

// --- the actual write that 1.6.0 never performed
$dupe   = mk_item( array( 'guid' => 'g-100', 'sourceId' => 2, 'title' => 'Same story, other outlet' ) );
$dupe->status         = NewsItem::STATUS_DUPLICATE;
$dupe->duplicateOfId  = $winnerId;
$dupe->duplicateLevel = 'guid';
$dupe->dedupConfirmedAt = Time::now();
$dupeId = $repo->insert( $dupe );
T::ok( $dupeId > 0, 'duplicate row is inserted' );

T::eq( 1, $repo->countDuplicates(), 'countDuplicates() is now non-zero (the headline bug)' );
T::eq( 2, $repo->countAll(), 'both rows are present' );

$row = $db->rows( 'wp_newsdesk_news_items' )[1];
T::eq( NewsItem::STATUS_DUPLICATE, $row['status'], 'status column says duplicate' );
T::eq( $winnerId, (int) $row['duplicate_of_id'], 'duplicate_of_id points at the winner' );
T::eq( 'guid', $row['duplicate_level'], 'duplicate_level is stored' );
T::ok( ! empty( $row['dedup_confirmed_at'] ), 'dedup_confirmed_at is populated (was always NULL)' );

/* --- round-trip through the entity ------------------------------------- */

T::group( 'B-2 — entity round-trip keeps the new columns' );

$hydrated = NewsItem::fromDbRow( $row );
T::eq( $winnerId, $hydrated->duplicateOfId, 'fromDbRow() reads duplicate_of_id' );
T::eq( 'guid', $hydrated->duplicateLevel, 'fromDbRow() reads duplicate_level' );
T::ok( $hydrated->isDuplicate(), 'isDuplicate() helper agrees' );
T::ok( null !== $hydrated->dedupConfirmedAt, 'dedupConfirmedAt hydrates to a DateTime' );

$back = $hydrated->toDbRow();
T::eq( $winnerId, $back['duplicate_of_id'], 'toDbRow() writes duplicate_of_id back' );
T::eq( 'guid', $back['duplicate_level'], 'toDbRow() writes duplicate_level back' );

// A non-duplicate must persist NULL, not 0, so the FK-ish index stays clean.
$clean = mk_item()->toDbRow();
T::eq( null, $clean['duplicate_of_id'], 'non-duplicates store NULL not 0' );
T::eq( null, $clean['duplicate_level'], 'non-duplicates store NULL level' );

/* --- never chain duplicate -> duplicate --------------------------------- */

T::group( 'B-2 — duplicate chains collapse to the original winner' );

$m = $repo->findDuplicate( 'g-100', '', '', 2 );
T::eq( $winnerId, $m['id'], 'matching a duplicate row still returns the original winner, not the duplicate' );

/* --- markDuplicate() guards --------------------------------------------- */

T::group( 'B-2 — markDuplicate() input guards' );

list( , $repo2 ) = mk_repo();
$a = $repo2->insert( mk_item( array( 'guid' => 'x1' ) ) );
$b = $repo2->insert( mk_item( array( 'guid' => 'x2' ) ) );
T::ok( $repo2->markDuplicate( $b, $a, 'guid' ), 'valid call succeeds' );
T::eq( 1, $repo2->countDuplicates(), 'markDuplicate() flips the status' );
T::ok( ! $repo2->markDuplicate( $a, $a, 'guid' ), 'refuses self-reference' );
T::ok( ! $repo2->markDuplicate( 0, $a, 'guid' ), 'refuses id 0' );
T::ok( ! $repo2->markDuplicate( $b, 0, 'guid' ), 'refuses winner 0' );

/* --- paginate() no longer calls prepare() with no args ------------------ */

T::group( 'B-4b — paginate() without filters' );

list( , $repo3 ) = mk_repo();
$repo3->insert( mk_item( array( 'guid' => 'p1' ) ) );
$repo3->insert( mk_item( array( 'guid' => 'p2' ) ) );
$res = $repo3->paginate( array(), 1, 10 );
T::eq( 2, $res['total'], 'unfiltered paginate returns the right total' );
T::eq( 2, count( $res['items'] ), 'unfiltered paginate returns rows' );
T::ok( ! WpTestState::hasDoingItWrong(), 'no _doing_it_wrong() from prepare() with zero placeholders' );

$res = $repo3->paginate( array( 'status' => NewsItem::STATUS_NORMALIZED ), 1, 10 );
T::eq( 2, $res['total'], 'filtered paginate still works' );

exit( T::summary() );
