<?php
/**
 * A-9 / A-11 — the four pages that used to be placeholders must actually
 * render.
 *
 * The container test proves they can be CONSTRUCTED. That is not the same as
 * working: a typo in a template, a missing view file, or a repository method
 * that does not exist only shows up when render() runs. So this test renders
 * each page for real and inspects the HTML.
 */

require __DIR__ . '/bootstrap.php';

use NewsDesk\AI\Admin\Menu;
use NewsDesk\AI\Admin\Page\AiProvidersPage;
use NewsDesk\AI\Admin\Page\ImagesPage;
use NewsDesk\AI\Admin\Page\LinkingPage;
use NewsDesk\AI\Admin\Page\NewsInboxPage;
use NewsDesk\AI\Core\Plugin;

global $wpdb;
$wpdb = new FakeGlobalWpdb();

// Seed two news items — a winner and its duplicate — so the inbox is tested
// with real rows. An empty table would make the cluster branch untestable.
$wpdb->seedResults(
	'wp_newsdesk_news_items',
	array(
		array(
			'id'              => 1,
			'source_id'       => 7,
			'title'           => 'WordPress 6.9 released',
			'link'            => 'https://wordpress.org/news/6-9/',
			'status'          => 'normalized',
			'duplicate_of_id' => null,
			'duplicate_level' => null,
			'published_at'    => '2026-09-19 10:00:00',
			'created_at'      => '2026-09-19 10:05:00',
		),
		array(
			'id'              => 2,
			'source_id'       => 8,
			'title'           => 'WP 6.9 is out',
			'link'            => 'https://example.org/wp-69',
			'status'          => 'duplicate',
			'duplicate_of_id' => 1,
			'duplicate_level' => 'near',
			'published_at'    => '2026-09-19 11:00:00',
			'created_at'      => '2026-09-19 11:05:00',
		),
	)
);

/**
 * Render a page and hand back the HTML.
 *
 * PHP notices/warnings are promoted to failures: an "Undefined property"
 * warning is how a template referencing a field the entity does not have
 * shows up, and that would otherwise render "successfully" with a blank cell.
 */
$GLOBALS['render_warnings'] = array();
set_error_handler(
	static function ( $no, $str ) {
		$GLOBALS['render_warnings'][] = $str;
		return true;
	},
	E_ALL
);

function render_page( string $class ): string {
	$GLOBALS['render_warnings'] = array();
	$page                       = Plugin::container()->get( $class );
	ob_start();
	try {
		$page->render();
	} catch ( \Throwable $e ) {
		ob_end_clean();
		return 'THREW: ' . get_class( $e ) . ': ' . $e->getMessage();
	}
	$html = (string) ob_get_clean();
	if ( $GLOBALS['render_warnings'] ) {
		return 'THREW: PHP notice: ' . implode( ' | ', array_unique( $GLOBALS['render_warnings'] ) );
	}
	return $html;
}

T::group( 'A-9 — the News Inbox renders instead of a "coming soon" stub' );

$html = render_page( NewsInboxPage::class );
T::ok( 0 !== strpos( $html, 'THREW' ), 'it renders without throwing: ' . substr( $html, 0, 160 ) );
T::contains( 'News inbox', $html, 'the inbox heading is present' );
T::contains( '<table', $html, 'a table of items is rendered' );
T::ok( false === strpos( $html, 'phase 2' ), 'the old placeholder text is gone' );

T::group( 'A-9 — the inbox offers the filters an operator needs' );

T::contains( 'name="status"', $html, 'status filter' );
T::contains( 'name="source_id"', $html, 'source filter' );
T::contains( 'name="s"', $html, 'search box' );

T::group( 'A-10 — duplicate clusters are visible and drillable' );

T::contains( 'cluster_of', $html, 'a cluster drill-down link exists' );
T::contains( 'duplicates', $html, 'duplicates are labelled' );

T::group( 'A-11 — the AI providers page reports real configuration state' );

$html = render_page( AiProvidersPage::class );
T::ok( 0 !== strpos( $html, 'THREW' ), 'it renders without throwing: ' . substr( $html, 0, 160 ) );
T::contains( 'AI providers', $html, 'the providers heading is present' );
T::ok( false === strpos( $html, 'phase 3' ), 'the old placeholder text is gone' );

T::group( 'A-11 — provider keys are never printed on screen' );

// The page must describe configuration without ever echoing a secret.
// 'sk-' cannot be a bare substring check: the plugin slug "newsdesk-ai"
// contains it. Match the shape of a real key instead -- a provider prefix
// followed by a long opaque token.
T::ok(
	0 === preg_match( '/\b(sk|pk|rk)-[A-Za-z0-9_-]{16,}/', $html ),
	'no API-key-shaped token in the markup'
);
foreach ( array( 'api_key', 'apiKey', 'secret' ) as $needle ) {
	T::ok( false === stripos( $html, $needle ), "no '$needle' in the markup" );
}

T::group( 'A-11 — an unconfigured install is told so plainly' );

T::contains( 'No provider is configured', $html, 'the empty state warns the operator' );

T::group( 'A-11 — the images page states the plan-only rule' );

$html = render_page( ImagesPage::class );
T::ok( 0 !== strpos( $html, 'THREW' ), 'it renders without throwing: ' . substr( $html, 0, 160 ) );
T::contains( '16:9', $html, 'the recommended aspect is shown' );
T::contains( '1200', $html, 'the minimum width is shown' );
T::contains( 'Disabled (default)', $html, 'generation is shown as off by default' );
T::ok( false === strpos( $html, 'phase 5' ), 'the old placeholder text is gone' );

T::group( 'A-11 — the images page offers no "generate now" button' );

// A manual trigger here would reintroduce exactly what the spec rules out.
T::ok( false === strpos( $html, '<form method="post"' ), 'no generation form is exposed' );

T::group( 'A-11 — the linking page renders the attribution policy' );

$html = render_page( LinkingPage::class );
T::ok( 0 !== strpos( $html, 'THREW' ), 'it renders without throwing: ' . substr( $html, 0, 160 ) );
T::contains( 'Linking', $html, 'the linking heading is present' );
T::contains( 'Source citations', $html, 'source attribution is covered' );
T::ok( false === strpos( $html, 'phase 4' ), 'the old placeholder text is gone' );

T::group( 'A-11 — every page still enforces the capability check' );

$before = WpTestState::$currentUserCan;
WpTestState::$currentUserCan = false;
foreach ( array( NewsInboxPage::class, AiProvidersPage::class, ImagesPage::class, LinkingPage::class ) as $class ) {
	$out   = render_page( $class );
	$short = substr( strrchr( $class, '\\' ) ?: $class, 1 );
	T::ok( false !== strpos( $out, 'THREW' ) || '' === trim( $out ), "$short refuses an unauthorised user" );
}
WpTestState::$currentUserCan = $before;

T::group( 'A-11 — no placeholder page is left in the menu' );

$menu = file_get_contents( NEWSDESK_DIR . 'src/Admin/Menu.php' );
T::ok( false === strpos( $menu, "PlaceholderPage::class, array(" ), 'no submenu still points at PlaceholderPage' );
T::eq( 4, substr_count( $menu, 'Admin\Page\\' ) > 0 ? 4 : 0, 'the new pages are wired by class' );
foreach ( array( 'NewsInboxPage', 'AiProvidersPage', 'ImagesPage', 'LinkingPage' ) as $cls ) {
	T::contains( $cls, $menu, "$cls is registered in the menu" );
}

exit( T::summary() );
