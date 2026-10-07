<?php
/**
 * A-8 — acting on the U-1 editorial decisions.
 *
 * U-1 computed UPDATE/REWRITE/MERGE/CORRECT/REPLACE and could do nothing with
 * them: WpPostWriterInterface only had createDraft(). These tests pin the two
 * new paths and, more importantly, the limits on them — drafts-only is the
 * rule this feature is most likely to break.
 */

require __DIR__ . '/bootstrap.php';

use NewsDesk\AI\Application\Content\CorrectionService;
use NewsDesk\AI\Application\Contracts\WpPostWriterInterface;
use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Application\Scoring\CannibalizationEngine;
use NewsDesk\AI\Domain\Entity\Story;
use NewsDesk\AI\Infrastructure\WordPress\WordPressPostWriter;

/** A story carrying an editorial decision. */
function decided_story( string $decision, int $target, array $over = array() ): Story {
	$s                    = new Story();
	$s->storyId           = $over['id'] ?? 7;
	$s->canonicalTitle    = $over['title'] ?? 'WordPress 6.9 ships new block editor';
	$s->language          = 'en_US';
	$s->editorialDecision = $decision;
	$s->existingArticleId = $target;
	return $s;
}

function correction_service( FakePostWriter $w ): CorrectionService {
	return new CorrectionService( $w, new NewsroomSettings( NewsroomSettings::defaults() ), new FakeLogger() );
}

/* ───────────────────── the contract itself ───────────────────────────── */

T::group( 'A-8 — the writer contract can now express an update' );

foreach ( array( 'createDraft', 'createRevisionDraft', 'appendCorrectionNotice', 'isPublished' ) as $m ) {
	T::ok( method_exists( WpPostWriterInterface::class, $m ), "WpPostWriterInterface::$m() exists" );
}

T::group( 'A-8 — there is still no way to publish' );

$methods = get_class_methods( WpPostWriterInterface::class );
$banned  = array();
foreach ( $methods as $m ) {
	// isPublished() is a read-only query; the ban is on mutations.
	if ( preg_match( '/^(publish|unpublish|delete|update)/i', $m ) ) {
		$banned[] = $m;
	}
}
T::eq( array(), $banned, 'the contract exposes no publish/delete method' );

// The real implementation must force draft status and refuse a caller ID.
$src = file_get_contents( NEWSDESK_DIR . 'src/Infrastructure/WordPress/WordPressPostWriter.php' );
T::contains( "\$post['post_status']  = 'draft'", $src, 'post_status is still forced to draft' );
T::contains( "unset( \$post['ID'] )", $src, 'a caller-supplied ID cannot turn an insert into an overwrite' );

T::group( 'A-8 — a revision draft is a draft, and it names its target' );

$w  = new FakePostWriter();
$id = $w->createRevisionDraft( 42, CannibalizationEngine::DECISION_UPDATE, array( 'post_title' => 'Updated' ), array() );
T::ok( $id > 0, 'a revision draft is created' );
T::eq( 1, count( $w->drafts ), 'it is recorded as a draft, not a live edit' );
$rev = $w->lastRevision();
T::eq( 42, $rev['target'], 'it records which live post it revises' );
T::eq( 'UPDATE', $rev['kind'], 'and which decision produced it' );

T::group( 'A-8 — a revision with no target is refused' );

$w = new FakePostWriter();
T::eq( 0, $w->createRevisionDraft( 0, 'UPDATE', array(), array() ), 'target 0 is rejected' );
T::eq( array(), $w->drafts, 'and nothing is written' );

/* ────────────────── DraftService routes the decision ─────────────────── */

T::group( 'A-8 — DraftService stages a revision when the story says UPDATE' );

$w   = new FakePostWriter();
$svc = make_draft_service( new FakePostIndex(), $w );
$out = $svc->create(
	decided_story( CannibalizationEngine::DECISION_UPDATE, 88 ),
	array( 'title' => 'T', 'sections' => array() ),
	array( 'title' => 'T' ),
	array(),
	array(),
	array(),
	1
);
T::eq( 88, $out['revises'], 'the result reports the revised post' );
T::eq( 'UPDATE', $out['decision'], 'and the decision' );
T::eq( 1, count( $w->revisions ), 'the revision path was used' );
T::eq( 88, $w->lastRevision()['target'], 'pointing at the right article' );

T::group( 'A-8 — a NEW story still takes the plain draft path' );

$w   = new FakePostWriter();
$svc = make_draft_service( new FakePostIndex(), $w );
$out = $svc->create(
	decided_story( CannibalizationEngine::DECISION_NEW, 0 ),
	array( 'title' => 'T', 'sections' => array() ),
	array( 'title' => 'T' ),
	array(),
	array(),
	array(),
	1
);
T::eq( 0, $out['revises'], 'nothing is revised' );
T::eq( array(), $w->revisions, 'the revision path was not used' );
T::eq( 1, count( $w->drafts ), 'a normal draft was created' );

T::group( 'A-8 — a decision without a target degrades to a normal draft' );

// Belt and braces: UPDATE with existingArticleId = 0 must not create a
// revision pointing at post 0.
$w   = new FakePostWriter();
$svc = make_draft_service( new FakePostIndex(), $w );
$out = $svc->create(
	decided_story( CannibalizationEngine::DECISION_UPDATE, 0 ),
	array( 'title' => 'T', 'sections' => array() ),
	array( 'title' => 'T' ),
	array(),
	array(),
	array(),
	1
);
T::eq( 0, $out['revises'], 'no revision is claimed' );
T::eq( array(), $w->revisions, 'and none is written' );
T::eq( 1, count( $w->drafts ), 'a plain draft is produced instead' );

/* ───────────────────────── the correction path ───────────────────────── */

T::group( 'A-8 — a correction is appended to a published article' );

WpTestState::reset();
$w            = new FakePostWriter();
$w->published = array( 55 );
$svc          = correction_service( $w );
$res          = $svc->apply( decided_story( CannibalizationEngine::DECISION_CORRECT, 55 ), array( 'The version number was 6.9, not 6.8.' ), 3 );

T::ok( $res['applied'], 'the correction is applied' );
T::eq( 55, $res['post_id'], 'to the right post' );
T::eq( 1, count( $w->corrections ), 'exactly one notice is appended' );
T::contains( '6.9, not 6.8', $w->corrections[0]['notice'], 'the notice carries the substance' );

T::group( 'A-8 — the correction is logged for the audit trail' );

$log = $svc->log();
T::eq( 1, count( $log ), 'one log entry' );
T::eq( 55, $log[0]['post_id'], 'naming the post' );
T::ok( ! empty( $log[0]['at'] ), 'and when it happened' );

T::group( 'A-8 — corrections are refused unless everything lines up' );

// Not a CORRECT decision.
WpTestState::reset();
$w            = new FakePostWriter();
$w->published = array( 55 );
$r            = correction_service( $w )->apply( decided_story( CannibalizationEngine::DECISION_UPDATE, 55 ), array( 'x' ) );
T::ok( ! $r['applied'], 'an UPDATE never appends a correction' );
T::eq( 'NOT_A_CORRECTION', $r['reason'], 'and says why' );

// Target is a draft, not a published post: nobody has read it.
WpTestState::reset();
$w = new FakePostWriter(); // nothing published
$r = correction_service( $w )->apply( decided_story( CannibalizationEngine::DECISION_CORRECT, 55 ), array( 'x' ) );
T::ok( ! $r['applied'], 'an unpublished target is not corrected' );
T::eq( 'TARGET_NOT_PUBLISHED', $r['reason'], 'and says why' );
T::eq( array(), $w->corrections, 'nothing was written' );

// No contradiction detail: never append an empty "we were wrong" box.
WpTestState::reset();
$w            = new FakePostWriter();
$w->published = array( 55 );
$r            = correction_service( $w )->apply( decided_story( CannibalizationEngine::DECISION_CORRECT, 55 ), array( '', '   ' ) );
T::ok( ! $r['applied'], 'an empty correction is refused' );
T::eq( 'NO_CONTRADICTION_DETAIL', $r['reason'], 'and says why' );

// No target at all.
WpTestState::reset();
$w = new FakePostWriter();
$r = correction_service( $w )->apply( decided_story( CannibalizationEngine::DECISION_CORRECT, 0 ), array( 'x' ) );
T::ok( ! $r['applied'], 'a correction with no article is refused' );
T::eq( 'NO_TARGET_ARTICLE', $r['reason'], 'and says why' );

T::group( 'A-8 — the correction log is bounded' );

T::ok( CorrectionService::LOG_LIMIT > 0, 'a retention limit exists' );
WpTestState::reset();
$w            = new FakePostWriter();
$w->published = array( 55 );
$svc          = correction_service( $w );
for ( $i = 0; $i < CorrectionService::LOG_LIMIT + 5; $i++ ) {
	$svc->apply( decided_story( CannibalizationEngine::DECISION_CORRECT, 55 ), array( 'contradiction number ' . $i ) );
}
T::eq( CorrectionService::LOG_LIMIT, count( $svc->log() ), 'the log cannot grow without bound' );
T::contains(
	(string) ( CorrectionService::LOG_LIMIT + 4 ),
	(string) $svc->log()[0]['notes'][0],
	'and the newest entry is kept, not the oldest'
);

T::group( 'A-8 — duplicate contradiction lines are collapsed' );

WpTestState::reset();
$w            = new FakePostWriter();
$w->published = array( 55 );
$svc          = correction_service( $w );
$svc->apply( decided_story( CannibalizationEngine::DECISION_CORRECT, 55 ), array( 'same', 'same', 'other' ) );
T::eq( 2, count( $svc->log()[0]['notes'] ), 'repeated text is stored once' );

exit( T::summary() );
