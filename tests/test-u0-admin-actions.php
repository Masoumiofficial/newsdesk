<?php
/**
 * U-0 regression tests: B-8 (no "clear logs" action) and B-9 (no "test feed
 * connection"), plus the security contract every admin_post_ handler must
 * satisfy.
 */

require __DIR__ . '/bootstrap.php';

use NewsDesk\AI\Application\FeedTester;
use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Domain\Entity\LogEntry;
use NewsDesk\AI\Domain\Entity\Source;
use NewsDesk\AI\Infrastructure\Database\TableNames;
use NewsDesk\AI\Infrastructure\Repository\LogRepository;
use NewsDesk\AI\News\AdapterFactory;
use NewsDesk\AI\News\Contracts\SourceAdapterInterface;
use NewsDesk\AI\News\Exception\SourceParseException;
use NewsDesk\AI\News\Value\RawNewsItem;
use NewsDesk\AI\Support\Http\HttpFetchException;

/* ══════════════════════════════════════════ B-8 ══════════════════════════ */

T::group( 'B-8 — LogRepository::clear()' );

function log_repo(): array {
	$db   = new FakeWpDb();
	$repo = new LogRepository( $db, new TableNames( $db->prefix() ) );
	$db->seed(
		'wp_newsdesk_logs',
		array(
			array( 'id' => 1, 'level' => 'debug', 'message' => 'd' ),
			array( 'id' => 2, 'level' => 'info', 'message' => 'i' ),
			array( 'id' => 3, 'level' => 'error', 'message' => 'e1' ),
			array( 'id' => 4, 'level' => 'error', 'message' => 'e2' ),
		)
	);
	return array( $db, $repo );
}

list( $db, $repo ) = log_repo();
T::eq( 4, $repo->clear(), 'clear() with no level removes every row' );
T::eq( 0, count( $db->rows( 'wp_newsdesk_logs' ) ), 'table is empty' );

list( $db, $repo ) = log_repo();
T::eq( 2, $repo->clear( 'error' ), 'clear("error") removes only that level' );
$left = array_column( $db->rows( 'wp_newsdesk_logs' ), 'level' );
T::eq( array( 'debug', 'info' ), $left, 'the other levels survive' );

T::group( 'B-8 — an unknown level must NOT fall back to deleting everything' );

list( $db, $repo ) = log_repo();
T::eq( 0, $repo->clear( 'bogus-level' ), 'unknown level deletes nothing' );
T::eq( 4, count( $db->rows( 'wp_newsdesk_logs' ) ), 'all rows still present' );
T::eq( 0, $repo->clear( "error' OR '1'='1" ), 'an injection attempt deletes nothing' );
T::eq( 4, count( $db->rows( 'wp_newsdesk_logs' ) ), 'still all present' );

T::group( 'B-8 — level matching is case-insensitive' );

list( $db, $repo ) = log_repo();
T::eq( 2, $repo->clear( 'ERROR' ), 'uppercase level is normalised' );

T::group( 'B-8 — the admin action is registered and guarded' );

$src = file_get_contents( NEWSDESK_DIR . 'src/Admin/AdminActions.php' );
T::contains( "'logs_clear'", $src, 'logs_clear action is registered' );
T::contains( 'onLogsClear', $src, 'handler exists' );
T::ok(
	(bool) preg_match( '/function onLogsClear.*?\$this->guard\(/s', $src ),
	'onLogsClear() calls guard() (capability + nonce) before doing anything'
);
T::ok(
	(bool) preg_match( "/function onLogsClear.*?'LOGS_CLEARED'/s", $src ),
	'clearing logs is itself audit-logged'
);

$logsView = file_get_contents( NEWSDESK_DIR . 'admin/views/logs.php' );
T::contains( 'newsdesk_newsroom_logs_clear', $logsView, 'the button exists in the view' );
T::contains( 'wp_nonce_field', $logsView, 'the form carries a nonce' );
T::contains( 'confirm(', $logsView, 'and asks for confirmation' );

/* ══════════════════════════════════════════ B-9 ══════════════════════════ */

/** HTTP client that fails loudly if anything tries a real request. */
final class NeverCalledHttpClient implements NewsDesk\AI\Application\Contracts\HttpClientInterface {
	public function get( string $url, array $opts = array() ): NewsDesk\AI\Support\Http\HttpResponse {
		throw new \LogicException( 'unexpected real HTTP GET to ' . $url );
	}
	public function request( string $method, string $url, array $opts = array() ): NewsDesk\AI\Support\Http\HttpResponse {
		throw new \LogicException( 'unexpected real HTTP ' . $method . ' to ' . $url );
	}
}

/** Adapter stub whose behaviour each test chooses. */
final class StubAdapter implements SourceAdapterInterface {
	/** @var callable */
	private $behaviour;
	public function __construct( callable $behaviour ) {
		$this->behaviour = $behaviour;
	}
	public function type(): string {
		return 'rss';
	}
	public function supports( Source $source ): bool {
		return true;
	}
	public function fetch( Source $source, array $opts = array() ): array {
		return call_user_func( $this->behaviour, $source, $opts );
	}
}

function feed_tester( callable $behaviour, ?FakeLogger $logger = null ): FeedTester {
	// The real factory needs an HTTP client, but our stub adapter never uses
	// it — a client that throws proves the test path makes no real request.
	$factory = new AdapterFactory( new NeverCalledHttpClient() );
	$factory->register(
		'rss',
		static function () use ( $behaviour ) {
			return new StubAdapter( $behaviour );
		}
	);
	return new FeedTester(
		$factory,
		new NewsroomSettings( NewsroomSettings::defaults() ),
		$logger ?: new FakeLogger()
	);
}

function test_source(): Source {
	$s       = new Source();
	$s->id   = 3;
	$s->type = 'rss';
	$s->url  = 'https://feed.test/rss';
	return $s;
}

T::group( 'B-9 — a healthy feed reports items' );

$tester = feed_tester(
	static function () {
		$a              = new RawNewsItem();
		$a->title       = 'خبر اول';
		$a->link        = 'https://feed.test/1';
		$a->publishedAt = new DateTimeImmutable( '2026-09-19 08:30', new DateTimeZone( 'UTC' ) );
		$b              = new RawNewsItem();
		$b->title       = 'خبر دوم';
		$b->link        = 'https://feed.test/2';
		return array( $a, $b );
	}
);
$r = $tester->test( test_source() );
T::ok( $r['ok'], 'test succeeds' );
T::eq( 2, $r['count'], 'both items counted' );
T::eq( 'خبر اول', $r['items'][0]['title'], 'the sample carries the title' );
T::eq( 'https://feed.test/1', $r['items'][0]['url'], 'and the link (RawNewsItem::$link, not ->url)' );
T::eq( '2026-09-19 08:30', $r['items'][0]['published'], 'and the publish date' );
T::eq( '', $r['items'][1]['published'], 'a missing date renders empty, not a crash' );
T::eq( '', $r['error'], 'no error reported' );

T::group( 'B-9 — the test never writes to the database' );

$tester = feed_tester(
	static function () {
		$a        = new RawNewsItem();
		$a->title = 'x';
		return array( $a );
	}
);
$before = count( WpTestState::$options );
$tester->test( test_source() );
T::eq( $before, count( WpTestState::$options ), 'no options were touched' );
$src = file_get_contents( NEWSDESK_DIR . 'src/Application/FeedTester.php' );
T::ok( false === strpos( $src, 'insert(' ), 'FeedTester contains no insert() call at all' );
T::ok( false === strpos( $src, 'Repository' ), 'and depends on no repository' );

T::group( 'B-9 — an empty but valid feed is flagged, not called an error' );

$r = feed_tester(
	static function () {
		return array();
	}
)->test( test_source() );
T::ok( $r['ok'], 'still reported as reachable' );
T::eq( 0, $r['count'], 'zero items' );
T::eq( 'EMPTY_FEED', $r['error_code'], 'but the empty feed is surfaced' );

T::group( 'B-9 — transport and parse failures are reported with their codes' );

$r = feed_tester(
	static function () {
		throw new HttpFetchException( 'HTTP_TIMEOUT', 'connection timed out' );
	}
)->test( test_source() );
T::ok( ! $r['ok'], 'http failure is not ok' );
T::contains( 'connection timed out', $r['error'], 'the message is passed through' );
T::eq( 'HTTP_TIMEOUT', $r['error_code'], 'the adapter error code is preserved' );

$r = feed_tester(
	static function () {
		throw new SourceParseException( 'PARSE_INVALID_XML', 'malformed XML' );
	}
)->test( test_source() );
T::ok( ! $r['ok'], 'parse failure is not ok' );
T::contains( 'malformed XML', $r['error'], 'parse message passed through' );
T::eq( 'PARSE_INVALID_XML', $r['error_code'], 'the parse error code is preserved' );

T::group( 'B-9 — an unexpected exception cannot escape' );

$r = feed_tester(
	static function () {
		throw new \RuntimeException( 'something odd' );
	}
)->test( test_source() );
T::ok( ! $r['ok'], 'reported as a failure' );
T::eq( 'UNEXPECTED', $r['error_code'], 'classified as UNEXPECTED' );

T::group( 'B-9 — an empty URL is rejected before any network call' );

$called = false;
$tester = feed_tester(
	static function () use ( &$called ) {
		$called = true;
		return array();
	}
);
$s      = test_source();
$s->url = '   ';
$r      = $tester->test( $s );
T::ok( ! $r['ok'], 'rejected' );
T::eq( 'EMPTY_URL', $r['error_code'], 'with a specific code' );
T::ok( ! $called, 'the adapter was never invoked' );

T::group( 'B-9 — every test is logged and timed' );

$logger = new FakeLogger();
feed_tester(
	static function () {
		return array();
	},
	$logger
)->test( test_source() );
T::ok( $logger->hasEvent( 'SOURCE_TESTED' ), 'a SOURCE_TESTED entry is written' );
$ctx = $logger->contextsFor( 'SOURCE_TESTED' )[0];
T::eq( 3, $ctx['source_id'], 'with the source id' );
T::ok( array_key_exists( 'elapsed_ms', $ctx ), 'and the elapsed time' );

T::group( 'B-9 — the admin action is registered and guarded' );

$adminSrc = file_get_contents( NEWSDESK_DIR . 'src/Admin/AdminActions.php' );
T::contains( "'source_test'", $adminSrc, 'source_test action is registered' );
T::ok(
	(bool) preg_match( '/function onSourceTest.*?\$this->guard\(/s', $adminSrc ),
	'onSourceTest() calls guard() first'
);

$sourcesView = file_get_contents( NEWSDESK_DIR . 'admin/views/sources.php' );
T::contains( 'newsdesk_newsroom_source_test', $sourcesView, 'the button exists in the sources table' );
T::ok(
	substr_count( $sourcesView, "wp_nonce_field( 'newsdesk_newsroom_source_test' )" ) === 1,
	'the test form carries its own nonce'
);

T::group( 'security — every admin_post_ handler is guarded' );

// Extract each on*() handler and require a guard() call inside it.
preg_match_all( '/public function (on[A-Za-z]+)\(\).*?(?=\n\tpublic function |\n\tprivate function )/s', $adminSrc, $m );
$unguarded = array();
foreach ( $m[0] as $i => $body ) {
	if ( false === strpos( $body, '$this->guard(' ) ) {
		$unguarded[] = $m[1][ $i ];
	}
}
T::eq( array(), $unguarded, 'no handler is missing its capability+nonce guard' );
T::ok( count( $m[1] ) >= 14, 'found ' . count( $m[1] ) . ' handlers (12 original + 2 new)' );

exit( T::summary() );
