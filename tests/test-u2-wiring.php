<?php
/**
 * A-17 Settings API · and the wiring of A-3 / A-4 / A-6 into the real pipeline.
 *
 * Writing a service is only half the job. A service that is registered in the
 * container but never called from the pipeline is dead code that looks like a
 * finished feature. These assertions pin the CALL SITES.
 */

require __DIR__ . '/bootstrap.php';

use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Core\Plugin;

$root = NEWSDESK_DIR;

T::group( 'A-17 — the settings option is declared to WordPress' );

WpTestState::reset();
Plugin::registerSettings();

T::ok( isset( WpTestState::$registeredSettings[ NewsroomSettings::OPTION ] ), 'register_setting() was called for the settings option' );
$reg = WpTestState::$registeredSettings[ NewsroomSettings::OPTION ];
T::eq( 'array', $reg['args']['type'], 'the option type is declared' );
T::ok( is_callable( $reg['args']['sanitize_callback'] ), 'a sanitize callback is attached' );
T::eq( false, $reg['args']['show_in_rest'], 'settings are NOT exposed over REST' );

T::group( 'A-17 — the declared sanitizer is the real one' );

// If this drifted, core would sanitize with something the plugin never tested.
$clean = call_user_func( $reg['args']['sanitize_callback'], array( 'editorial_window_hours' => 9999 ) );
T::ok( is_array( $clean ), 'the callback returns an array' );
T::ok( (int) $clean['editorial_window_hours'] <= 72, 'and it really clamps out-of-range input' );

T::group( 'A-17 — registration is hooked, not just defined' );

$plugin = (string) file_get_contents( $root . 'src/Core/Plugin.php' );
T::contains( "add_action( 'admin_init', array( self::class, 'registerSettings' ) )", $plugin, 'registerSettings is wired to admin_init' );

T::group( 'A-17 — the hardened save path is still the one in use' );

// register_setting() must not have quietly become the save mechanism: it
// cannot route API keys into encrypted storage.
$actions = (string) file_get_contents( $root . 'src/Admin/AdminActions.php' );
T::contains( 'settings_save', $actions, 'the admin_post save handler still exists' );
T::contains( 'guard(', $actions, 'and it is still guarded' );

T::group( 'A-4 — the risk assessor is actually called during fact-checking' );

$engine = (string) file_get_contents( $root . 'src/Application/Research/FactCheckEngine.php' );
T::contains( 'riskAssessor->assess(', $engine, 'assess() is called in the fact-check loop' );
T::contains( 'updateClaimRisk(', $engine, 'and the verdict is persisted' );
T::contains( 'must_remove', $engine, 'claims requiring removal are reported upward' );

T::group( 'A-4 — the risk columns exist in the baseline schema' );

$schema = (string) file_get_contents( $root . 'src/Infrastructure/Database/Schema.php' );
$mig    = (string) file_get_contents( $root . 'src/Infrastructure/Database/Migrations.php' );
foreach ( array( 'risk', 'action' ) as $col ) {
	T::contains( $col, $schema, "research_claims declares '$col' for fresh installs" );
}

T::group( 'A-3 — security intel is extracted in the research flow' );

$research = (string) file_get_contents( $root . 'src/Application/Research/StoryResearchService.php' );
T::contains( 'securityIntel->extract(', $research, 'the extractor is called' );
T::contains( 'updateSecurityIntel(', $research, 'and the result is persisted on the story' );

T::group( 'A-3 — the security columns are declared in the baseline schema' );

foreach ( array( 'cve_ids', 'cvss_score', 'severity', 'affected_versions', 'fixed_versions', 'exploited' ) as $col ) {
	T::contains( $col, $schema, "'$col' is declared in Schema.php" );
}

T::group( 'A-6 — the audit runs before the draft is created, and can block it' );

$content = (string) file_get_contents( $root . 'src/Application/Content/StoryContentService.php' );
T::contains( 'auditor->audit(', $content, 'the auditor is called' );
T::contains( 'AUDIT_CRITICAL_FAIL', $content, 'a critical failure is logged' );

// Order matters: auditing after the draft exists would be pointless.
$auditPos = strpos( $content, 'auditor->audit(' );
$draftPos = strpos( $content, 'drafts->create(' );
T::ok( false !== $auditPos && false !== $draftPos && $auditPos < $draftPos, 'the audit happens BEFORE drafts->create()' );

T::group( 'A-12 — the source taxonomy columns are declared in the baseline schema' );

T::contains( 'source_type', $schema, 'source_type is declared in Schema.php' );
T::contains( 'tier', $schema, 'tier is declared in Schema.php' );

T::group( 'the DB version constant still matches the newest migration' );

// 1.0.0 ships with an empty migration set, so this reads as "the constant
// equals the baseline". The guard matters the moment a migration is added:
// a new entry without a matching constant bump never runs on existing sites.
$latest = \NewsDesk\AI\Infrastructure\Database\Migrations::latestVersion();
T::eq( NEWSDESK_DB_VERSION, $latest, 'constant and newest migration agree' );

T::group( 'the baseline schema creates every column the code depends on' );

// Historically this guarded fresh-install/upgrade divergence: a column added
// to Migrations but not Schema left NEW sites broken (or vice versa), and
// only one of the two paths runs on any given install, so neither is
// exercised by ordinary testing. Collapsing to a 1.0.0 baseline removes the
// divergence by construction -- but the column list still has to be right.
$fresh = implode( "\n", \NewsDesk\AI\Infrastructure\Database\Schema::definitions( 'wp_', '' ) );
foreach ( array( 'risk', 'action', 'is_security', 'cve_ids', 'cvss_score', 'severity', 'affected_versions', 'fixed_versions', 'exploited', 'source_type', 'tier', 'duplicate_of_id', 'duplicate_level' ) as $col ) {
	T::ok( (bool) preg_match( '/\b' . preg_quote( $col, '/' ) . '\b/', $fresh ), "fresh install creates '$col'" );
}

// And once a migration DOES exist, it must never contradict the baseline:
// every ADD COLUMN must name a column the schema also declares.
$all = '';
foreach ( \NewsDesk\AI\Infrastructure\Database\Migrations::all() as $stmts ) {
	$all .= implode( "\n", $stmts ) . "\n";
}
preg_match_all( '/ADD COLUMN\s+`?([a-z0-9_]+)`?/i', $all, $m );
foreach ( array_unique( $m[1] ) as $col ) {
	T::ok( false !== strpos( $fresh, $col ), "migrated column '$col' is also in the baseline schema" );
}

T::group( 'A-16 — both spec hooks fire from real code paths' );

$scorer = (string) file_get_contents( $root . 'src/Application/Scoring/StoryScorer.php' );
T::contains( "apply_filters(\n\t\t\t\t'newsdesk_news_score'", $scorer, 'the score filter is applied' );
$editorial = (string) file_get_contents( $root . 'src/Application/StoryEditorialService.php' );
T::contains( "do_action( 'newsdesk_news_selected'", $editorial, 'the selection action fires' );

exit( T::summary() );
