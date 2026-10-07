<?php
/**
 * Release blocker: a site UPDATING to 2.0 must get the new schema.
 *
 * register_activation_hook() has not fired on plugin updates since WP 3.1 —
 * it only runs when an admin activates by hand. Every migration in this
 * plugin lived behind that hook, so a 1.6.0 → 2.0 update would leave the
 * schema at 1.3.0 while the 2.0 code queries 1.4.0 columns
 * (news_items.duplicate_of_id / duplicate_level).
 *
 * These tests pin the catch-up path that closes that gap.
 */

require __DIR__ . '/bootstrap.php';

use NewsDesk\AI\Core\Plugin;
use NewsDesk\AI\Infrastructure\Database\Migrations;
use NewsDesk\AI\Infrastructure\Database\Schema;

// The real WpDb adapter forwards to $wpdb, so a full double is required.
global $wpdb;
$wpdb = new FakeGlobalWpdb();

const DB_OPTION = 'newsdesk_db_version';

T::group( 'upgrade — the constant matches the newest migration' );

// If these drift, every install silently believes it is up to date.
T::eq(
	Migrations::latestVersion(),
	NEWSDESK_DB_VERSION,
	'NEWSDESK_DB_VERSION equals the highest registered migration'
);

T::group( 'upgrade — maybeUpgrade() exists and is public' );

T::ok( method_exists( Plugin::class, 'maybeUpgrade' ), 'Plugin::maybeUpgrade() is defined' );
$ref = new ReflectionMethod( Plugin::class, 'maybeUpgrade' );
T::ok( $ref->isPublic() && $ref->isStatic(), 'it is a public static callback' );

T::group( 'upgrade — an up-to-date site does no work' );

WpTestState::reset();
WpTestState::$options[ DB_OPTION ] = NEWSDESK_DB_VERSION;
Plugin::maybeUpgrade();
T::eq(
	NEWSDESK_DB_VERSION,
	WpTestState::$options[ DB_OPTION ],
	'the stored version is untouched'
);
T::eq( array(), WpTestState::$actions, 'no error notice is queued' );

T::group( 'upgrade — a site on a stale schema version is stamped forward' );

WpTestState::reset();
// 1.0.0 is the baseline, so there is nothing to apply — but the version MUST
// still be stamped, or every admin_init would retry the upgrade forever.
WpTestState::$options[ DB_OPTION ] = '0.9.0';
Plugin::maybeUpgrade();
T::eq(
	NEWSDESK_DB_VERSION,
	WpTestState::$options[ DB_OPTION ] ?? '',
	'the schema version is brought up to date (0.9.0 -> ' . NEWSDESK_DB_VERSION . ')'
);

T::group( 'schema — the 1.0.0 baseline holds every column the code depends on' );

// Bug #2 was fresh-install/upgrade divergence: columns that existed only in
// Migrations. With the chain collapsed, Schema.php is the single source of
// truth, so it must carry the columns the 2.x code reads and writes.
$schema = implode( ' | ', Schema::definitions( 'wp_', '' ) );
foreach ( array(
	'duplicate_of_id', 'duplicate_level',          // dedup write path (B-2)
	'is_security', 'cve_ids', 'cvss_score',        // security intelligence (A-3)
	'severity', 'affected_versions', 'fixed_versions', 'exploited',
	'source_type', 'tier',                         // source trust model
) as $col ) {
	T::contains( $col, $schema, "the baseline schema defines '{$col}'" );
}

T::group( 'upgrade — a fresh install with no option at all is migrated' );

WpTestState::reset();
Plugin::maybeUpgrade(); // no DB_OPTION set: get_option() returns the '0' default
T::eq(
	NEWSDESK_DB_VERSION,
	WpTestState::$options[ DB_OPTION ] ?? '',
	'a missing version option still triggers the upgrade'
);

T::group( 'upgrade — it is idempotent' );

WpTestState::reset();
WpTestState::$options[ DB_OPTION ] = '0.9.0';
Plugin::maybeUpgrade();
$after = WpTestState::$options;
Plugin::maybeUpgrade();
Plugin::maybeUpgrade();
T::eq(
	$after[ DB_OPTION ],
	WpTestState::$options[ DB_OPTION ],
	'running it repeatedly changes nothing further'
);
T::eq(
	(array) ( $after['newsdesk_migrations_applied'] ?? array() ),
	(array) ( WpTestState::$options['newsdesk_migrations_applied'] ?? array() ),
	'the applied-migration history is not duplicated'
);

T::group( 'upgrade — it is wired to admin_init, not left unreachable' );

// A correct method nobody calls is exactly the bug class this fixes.
$src = file_get_contents( NEWSDESK_DIR . 'src/Core/Plugin.php' );
T::contains( "add_action( 'admin_init'", $src, 'boot() registers the check on admin_init' );
T::contains( "'maybeUpgrade'", $src, 'and it points at maybeUpgrade' );

T::group( 'upgrade — the activation hook still works for fresh activations' );

T::ok( method_exists( Plugin::class, 'activate' ), 'Plugin::activate() is still there' );
$main = file_get_contents( NEWSDESK_DIR . 'newsdesk-ai.php' );
T::contains( 'register_activation_hook', $main, 'the activation hook is still registered' );

exit( T::summary() );
