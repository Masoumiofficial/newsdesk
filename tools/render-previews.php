<?php
/**
 * Render the real admin screens to standalone HTML for marketplace screenshots.
 *
 * These are not mock-ups: each file is the actual output of the page class,
 * with the plugin's own stylesheet inlined and WordPress chrome approximated,
 * so what a buyer sees on the listing is what the plugin renders.
 *
 * Usage:  php tools/render-previews.php [output-dir]
 *
 * @package NewsDesk\AI\Tools
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
require_once $root . '/tests/bootstrap.php';
require_once $root . '/tests/stubs/FakeWpDb.php';
require_once $root . '/tests/stubs/fixtures.php';

use NewsDesk\AI\Core\Plugin;

// The test bootstrap stubs what the suite needs; the shared footer calls a few
// extras. Define them here rather than widening the harness for a tool.
if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string(): string {
		return 'UTC';
	}
}
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone(): \DateTimeZone {
		return new \DateTimeZone( 'UTC' );
	}
}

$out = $argv[1] ?? ( $root . '/previews' );
if ( ! is_dir( $out ) ) {
	mkdir( $out, 0775, true );
}

/* ── Fixtures: enough real rows that no screen renders an empty state ──── */

global $wpdb;
$wpdb = new FakeGlobalWpdb();

$wpdb->seedResults(
	'newsdesk_sources',
	array(
		array(
			'id' => 1, 'name' => 'WordPress.org News', 'type' => 'rss',
			'feed_url' => 'https://wordpress.org/news/feed/', 'url' => 'https://wordpress.org/news/',
			'status' => 'active', 'source_type' => 'PRIMARY', 'tier' => 1,
			'base_trust_score' => 95.0, 'trust_score' => 95.0, 'priority' => 90,
			'language' => 'en_US', 'category' => 'general', 'fetch_interval_min' => 120,
			'last_fetch_at' => '2026-10-07 08:10:00', 'last_success_at' => '2026-10-07 08:10:00',
			'error_count' => 0, 'created_at' => '2026-09-01 10:00:00',
		),
		array(
			'id' => 2, 'name' => 'WPScan Vulnerability Database', 'type' => 'rss',
			'feed_url' => 'https://wpscan.com/feed/', 'url' => 'https://wpscan.com/',
			'status' => 'active', 'source_type' => 'SECURITY', 'tier' => 1,
			'base_trust_score' => 95.0, 'trust_score' => 95.0, 'priority' => 95,
			'language' => 'en_US', 'category' => 'security', 'fetch_interval_min' => 60,
			'last_fetch_at' => '2026-10-07 08:12:00', 'last_success_at' => '2026-10-07 08:12:00',
			'error_count' => 0, 'created_at' => '2026-09-01 10:05:00',
		),
		array(
			'id' => 3, 'name' => 'Post Status', 'type' => 'rss',
			'feed_url' => 'https://poststatus.com/feed/', 'url' => 'https://poststatus.com/',
			'status' => 'active', 'source_type' => 'MEDIA', 'tier' => 2,
			'base_trust_score' => 80.0, 'trust_score' => 80.0, 'priority' => 60,
			'language' => 'en_US', 'category' => 'general', 'fetch_interval_min' => 240,
			'last_fetch_at' => '2026-10-07 07:40:00', 'last_success_at' => '2026-10-07 07:40:00',
			'error_count' => 0, 'created_at' => '2026-09-02 09:00:00',
		),
	)
);

$wpdb->seedResults(
	'newsdesk_news_items',
	array(
		array(
			'id' => 1, 'source_id' => 1, 'title' => 'WordPress 6.9 released with a rewritten block editor',
			'canonical_url' => 'https://wordpress.org/news/2026/10/wordpress-6-9/',
			'link' => 'https://wordpress.org/news/2026/10/wordpress-6-9/',
			'status' => 'normalized', 'duplicate_of_id' => null, 'duplicate_level' => null,
			'published_at' => '2026-10-07 06:00:00', 'created_at' => '2026-10-07 06:05:00',
		),
		array(
			'id' => 2, 'source_id' => 3, 'title' => 'WordPress 6.9 is out — what changed',
			'canonical_url' => 'https://poststatus.com/wordpress-6-9/',
			'link' => 'https://poststatus.com/wordpress-6-9/',
			'status' => 'duplicate', 'duplicate_of_id' => 1, 'duplicate_level' => 'near',
			'published_at' => '2026-10-07 06:40:00', 'created_at' => '2026-10-07 06:45:00',
		),
		array(
			'id' => 3, 'source_id' => 2, 'title' => 'Critical RCE in a popular forms plugin, patched in 4.2.1',
			'canonical_url' => 'https://wpscan.com/vulnerability/cve-2026-31245/',
			'link' => 'https://wpscan.com/vulnerability/cve-2026-31245/',
			'status' => 'normalized', 'duplicate_of_id' => null, 'duplicate_level' => null,
			'published_at' => '2026-10-07 05:20:00', 'created_at' => '2026-10-07 05:25:00',
		),
	)
);

/* ── The plugin's own stylesheet, inlined so the file stands alone ─────── */

$css = (string) file_get_contents( NEWSDESK_DIR . 'admin/css/admin.css' );

/** Wrap a rendered page in enough WordPress chrome to read like a screenshot. */
function nd_frame( string $title, string $body, string $css ): string {
	return '<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<title>' . htmlspecialchars( $title, ENT_QUOTES ) . ' — NewsDesk AI</title>
<style>
/* Minimal wp-admin approximation: enough for an honest screenshot. */
*{box-sizing:border-box}
body{margin:0;background:#f0f0f1;color:#3c434a;font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
#adminmenuback{position:fixed;inset:0 auto 0 0;width:160px;background:#1d2327}
#adminmenu{list-style:none;margin:0;padding:0}
#adminmenu li a{display:block;padding:9px 12px;color:#f0f0f1;text-decoration:none;font-size:13px}
#adminmenu li.current a{background:#2271b1;color:#fff;font-weight:600}
#adminmenu .sep{height:1px;background:#3c434a;margin:6px 0}
#wpbody{margin-left:160px;padding:0 20px 40px}
.wrap{margin:10px 0 0;max-width:1180px}
h1,h2{color:#1d2327;font-weight:600}
h1{font-size:23px;margin:0 0 6px}
h2{font-size:17px}
table.widefat{border-collapse:collapse;width:100%;background:#fff;border:1px solid #c3c4c7;border-radius:4px;overflow:hidden}
table.widefat th,table.widefat td{padding:9px 12px;text-align:left;border-bottom:1px solid #f0f0f1;font-size:13px;vertical-align:top}
table.widefat thead th{background:#f6f7f7;font-weight:600;color:#1d2327}
table.striped tbody tr:nth-child(odd){background:#f9f9f9}
.button{display:inline-block;padding:5px 12px;border:1px solid #2271b1;border-radius:3px;background:#f6f7f7;color:#2271b1;text-decoration:none;font-size:13px;cursor:pointer}
.button-primary{background:#2271b1;border-color:#2271b1;color:#fff}
.description{color:#646970;font-size:13px}
code{background:#f0f0f1;padding:1px 5px;border-radius:3px;font-size:12px}
.form-table{width:100%;border-collapse:collapse}
.form-table th{width:220px;text-align:left;padding:14px 10px 14px 0;font-weight:600;vertical-align:top}
.form-table td{padding:12px 0}
input[type=text],input[type=number],select,textarea{padding:5px 8px;border:1px solid #8c8f94;border-radius:4px;font-size:14px;min-width:260px;background:#fff}
' . $css . '
</style></head><body>
<div id="adminmenuback"><ul id="adminmenu">
<li><a href="#">Dashboard</a></li>
<li><a href="#">Posts</a></li>
<li><a href="#">Media</a></li>
<li class="sep"></li>
<li class="current"><a href="#">NewsDesk AI</a></li>
<li><a href="#">&nbsp;&nbsp;News inbox</a></li>
<li><a href="#">&nbsp;&nbsp;Stories</a></li>
<li><a href="#">&nbsp;&nbsp;Draft review</a></li>
<li><a href="#">&nbsp;&nbsp;Sources</a></li>
<li><a href="#">&nbsp;&nbsp;AI providers</a></li>
<li><a href="#">&nbsp;&nbsp;Scheduler</a></li>
<li><a href="#">&nbsp;&nbsp;System health</a></li>
<li><a href="#">&nbsp;&nbsp;Logs</a></li>
<li><a href="#">&nbsp;&nbsp;Settings</a></li>
<li class="sep"></li>
<li><a href="#">Appearance</a></li>
<li><a href="#">Plugins</a></li>
</ul></div>
<div id="wpbody">' . $body . '</div>
</body></html>';
}

$pages = array(
	'01-dashboard'    => array( \NewsDesk\AI\Admin\Page\DashboardPage::class,    'Dashboard' ),
	'02-news-inbox'   => array( \NewsDesk\AI\Admin\Page\NewsInboxPage::class,    'News inbox' ),
	'03-sources'      => array( \NewsDesk\AI\Admin\Page\SourcesPage::class,      'Sources' ),
	'04-stories'      => array( \NewsDesk\AI\Admin\Page\StoriesPage::class,      'Stories' ),
	'05-draft-review' => array( \NewsDesk\AI\Admin\Page\DraftReviewPage::class,  'Draft review' ),
	'06-ai-providers' => array( \NewsDesk\AI\Admin\Page\AiProvidersPage::class,  'AI providers' ),
	'07-health'       => array( \NewsDesk\AI\Admin\Page\HealthPage::class,       'System health' ),
	'08-scheduler'    => array( \NewsDesk\AI\Admin\Page\SchedulerPage::class,    'Scheduler' ),
	'09-settings'     => array( \NewsDesk\AI\Admin\Page\SettingsPage::class,     'Settings' ),
	'10-logs'         => array( \NewsDesk\AI\Admin\Page\LogsPage::class,         'Logs' ),
	'11-linking'      => array( \NewsDesk\AI\Admin\Page\LinkingPage::class,      'Linking' ),
	'12-images'       => array( \NewsDesk\AI\Admin\Page\ImagesPage::class,       'Images' ),
);

$ok = 0;
$bad = array();
foreach ( $pages as $slug => $spec ) {
	list( $class, $title ) = $spec;
	ob_start();
	try {
		Plugin::container()->get( $class )->render();
		$html = (string) ob_get_clean();
	} catch ( \Throwable $e ) {
		ob_end_clean();
		$bad[] = $slug . ': ' . get_class( $e ) . ' — ' . $e->getMessage();
		continue;
	}
	file_put_contents( $out . '/' . $slug . '.html', nd_frame( $title, $html, $css ) );
	++$ok;
}

echo "rendered: $ok / " . count( $pages ) . "\n";
foreach ( $bad as $b ) {
	echo "  FAILED  $b\n";
}
echo "output:   $out\n";
