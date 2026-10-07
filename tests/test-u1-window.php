<?php
/**
 * U-1 tests: A-1 — the 24h → 7d → NO NEWS ladder.
 *
 * v1.6.0 had no ladder: it always loaded a flat 7-day window and
 * `editorial_window_hours` was a dead setting with no reader anywhere.
 */

require __DIR__ . '/bootstrap.php';

use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Application\Scoring\NewsWindow;

$now = new DateTimeImmutable( '2026-09-20 12:00:00', new DateTimeZone( 'UTC' ) );

/* ─────────────────────────── rung sequence ───────────────────────────── */

T::group( 'A-1 — the ladder runs PRIMARY → FALLBACK → NO_NEWS' );

$w = new NewsWindow( 24, 7 );
T::eq( NewsWindow::RUNG_PRIMARY, $w->firstRung(), 'a run starts on the primary rung' );
T::eq( NewsWindow::RUNG_FALLBACK, $w->nextRung( NewsWindow::RUNG_PRIMARY ), '24h widens to 7d' );
T::eq( NewsWindow::RUNG_NONE, $w->nextRung( NewsWindow::RUNG_FALLBACK ), '7d gives up with NO NEWS' );

T::group( 'A-1 — NO_NEWS is terminal so a caller loop cannot spin' );

T::eq( NewsWindow::RUNG_NONE, $w->nextRung( NewsWindow::RUNG_NONE ), 'NO_NEWS stays NO_NEWS' );
T::ok( ! $w->isSearchable( NewsWindow::RUNG_NONE ), 'NO_NEWS is not searchable' );
T::ok( $w->isSearchable( NewsWindow::RUNG_PRIMARY ), 'primary is searchable' );
T::ok( $w->isSearchable( NewsWindow::RUNG_FALLBACK ), 'fallback is searchable' );
// Unknown input must not produce an infinite ladder either.
T::eq( NewsWindow::RUNG_NONE, $w->nextRung( 'GARBAGE' ), 'an unknown rung terminates' );

T::group( 'A-1 — each rung yields the right cutoff' );

T::eq(
	'2026-09-19 12:00:00',
	$w->since( NewsWindow::RUNG_PRIMARY, $now )->format( 'Y-m-d H:i:s' ),
	'primary cutoff is 24 hours back'
);
T::eq(
	'2026-09-13 12:00:00',
	$w->since( NewsWindow::RUNG_FALLBACK, $now )->format( 'Y-m-d H:i:s' ),
	'fallback cutoff is 7 days back'
);
T::throws(
	static function () use ( $w, $now ) {
		$w->since( NewsWindow::RUNG_NONE, $now );
	},
	'asking NO_NEWS for a window throws rather than returning a bogus date'
);

T::group( 'A-1 — the fallback window is always wider than the primary' );

$ladder = $w->ladder( $now );
T::eq( 2, count( $ladder ), 'two searchable rungs' );
T::ok(
	$ladder[1]['since'] < $ladder[0]['since'],
	'descending the ladder always reaches further back in time'
);
T::eq( NewsWindow::RUNG_PRIMARY, $ladder[0]['rung'], 'primary comes first' );
T::eq( NewsWindow::RUNG_FALLBACK, $ladder[1]['rung'], 'fallback second' );

T::group( 'A-1 — degenerate configuration cannot disable the ladder' );

// 0 hours would make the primary window empty by definition and silently skip
// rung 1 on every run.
$z = new NewsWindow( 0, 0 );
T::ok( $z->primaryHours() >= 1, 'a zero primary window is clamped to >= 1h' );
T::ok( $z->fallbackDays() >= 1, 'a zero fallback window is clamped to >= 1d' );
$neg = new NewsWindow( -5, -3 );
T::ok( $neg->primaryHours() >= 1, 'negative hours clamped' );
T::ok( $neg->fallbackDays() >= 1, 'negative days clamped' );
T::ok(
	$neg->since( NewsWindow::RUNG_PRIMARY, $now ) < $now,
	'the cutoff is still in the past, never in the future'
);

T::group( 'A-1 — the ladder is driven by settings, not hardcoded' );

$custom = NewsWindow::fromSettings(
	new NewsroomSettings(
		array_merge(
			NewsroomSettings::defaults(),
			array(
				'editorial_window_hours' => 6,
				'fallback_window_days'   => 30,
			)
		)
	)
);
T::eq( 6, $custom->primaryHours(), 'primary hours come from settings' );
T::eq( 30, $custom->fallbackDays(), 'fallback days come from settings' );
T::eq(
	'2026-09-20 06:00:00',
	$custom->since( NewsWindow::RUNG_PRIMARY, $now )->format( 'Y-m-d H:i:s' ),
	'a 6h window is honoured'
);

T::group( 'A-1 — editorial_window_hours is no longer a dead setting' );

// It had no reader at all in v1.6.0 (B-7's leftover). Prove it is read now.
$svc = file_get_contents( NEWSDESK_DIR . 'src/Application/StoryEditorialService.php' );
T::contains( 'NewsWindow', $svc, 'the editorial service uses the ladder' );
T::ok(
	false === strpos( $svc, "\$now->modify( '-' . (int) \$this->settings->fallbackWindowDays() . ' days' );\n\n\t\t\$sourceList" ),
	'the flat 7-day load is gone'
);
$windowSrc = file_get_contents( NEWSDESK_DIR . 'src/Application/Scoring/NewsWindow.php' );
T::contains( 'editorialWindowHours', $windowSrc, 'the ladder reads editorial_window_hours' );

T::group( 'A-1 — the default primary window matches the spec (24h)' );

$defaults = NewsroomSettings::defaults();
T::eq( 24, $defaults['editorial_window_hours'], 'default is 24h, not the old unused 6' );
T::eq( 7, $defaults['fallback_window_days'], 'fallback default is 7d' );

T::group( 'A-1 — labels are usable in logs and the UI' );

T::eq( '24h', $w->label( NewsWindow::RUNG_PRIMARY ), 'primary label' );
T::eq( '7d', $w->label( NewsWindow::RUNG_FALLBACK ), 'fallback label' );
T::eq( 'none', $w->label( NewsWindow::RUNG_NONE ), 'terminal label' );

exit( T::summary() );
