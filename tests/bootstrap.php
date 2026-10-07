<?php
/**
 * Headless test bootstrap: minimal WordPress stubs + plugin autoloader.
 * No real WordPress required — every stub records calls so tests can assert.
 */

define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'OBJECT', 'OBJECT' );
define( 'ARRAY_A', 'ARRAY_A' );

define( 'NEWSDESK_FILE', dirname( __DIR__ ) . '/newsdesk-ai/newsdesk-ai.php' );

/**
 * Read the version constants out of the real plugin header instead of
 * restating them. Hardcoding them here meant the B-11 guard test compared the
 * harness against itself and could not see a drifted DB_VERSION — the exact
 * bug it exists to catch.
 */
( static function () {
	$src     = (string) file_get_contents( NEWSDESK_FILE );
	$version = preg_match( "/define\(\s*'NEWSDESK_VERSION',\s*'([^']+)'/", $src, $m ) ? $m[1] : '0.0.0';
	$db      = preg_match( "/define\(\s*'NEWSDESK_DB_VERSION',\s*'([^']+)'/", $src, $m2 ) ? $m2[1] : '0.0.0';
	define( 'NEWSDESK_VERSION', $version );
	define( 'NEWSDESK_DB_VERSION', $db );
} )();
define( 'NEWSDESK_DIR', dirname( __DIR__ ) . '/newsdesk-ai/' );
define( 'NEWSDESK_URL', 'https://example.test/wp-content/plugins/newsdesk-ai/' );
define( 'NEWSDESK_MIN_PHP', '7.4' );
define( 'NEWSDESK_REST_NAMESPACE', 'nd-newsroom/v1' );
define( 'NEWSDESK_AUTO_PUBLISH', false );

/* ------------------------------------------------------------------ state */

final class WpTestState {
	/** @var array<string, mixed> */
	public static $options = array();
	/** @var array<int, array> */
	public static $posts = array();
	/** @var array<int, array<string,string>> */
	public static $postMeta = array();
	/** @var array<string, array<int, callable>> */
	public static $filters = array();
	/** @var array<string, array<int, callable>> */
	public static $actions = array();
	/** @var array<int, array> */
	public static $scheduled = array();
	/** @var string[] */
	public static $log = array();
	/** @var int */
	public static $nextPostId = 100;
	/** @var array<int, array{fn:string, msg:string}> WP misuse notices. */
	public static $doingItWrong = array();
	/** @var string[] DDL passed to the dbDelta stub. */
	public static $dbDelta = array();
	/** Lets a test exercise the capability guards on admin pages. */
	public static $currentUserCan = true;
	/** @var array<string,array> A-17: options declared via register_setting(). */
	public static $registeredSettings = array();

	/** Record a _doing_it_wrong()-class misuse (e.g. prepare() with no args). */
	public static function doingItWrong( string $fn, string $msg ): void {
		self::$doingItWrong[] = array(
			'fn'  => $fn,
			'msg' => $msg,
		);
	}

	/** Did any WP misuse notice fire since the last reset()? */
	public static function hasDoingItWrong(): bool {
		return ! empty( self::$doingItWrong );
	}

	public static function reset(): void {
		self::$currentUserCan = true;
		self::$registeredSettings = array();
		self::$options    = array();
		self::$posts      = array();
		self::$postMeta   = array();
		self::$filters    = array();
		self::$actions    = array();
		self::$scheduled  = array();
		self::$log          = array();
		self::$nextPostId   = 100;
		self::$doingItWrong = array();
		self::$dbDelta      = array();
	}
}

/* --------------------------------------------------------------- WP stubs */

function wp_timezone() {
	return new DateTimeZone( 'UTC' );
}
/**
 * dbDelta is normally loaded from wp-admin/includes/upgrade.php. Schema::install()
 * throws without it, so the upgrade path could not be exercised headlessly.
 * Record the statements instead of executing DDL the fake DB has no use for.
 */
function dbDelta( $queries = '', $execute = true ) {
	WpTestState::$dbDelta[] = is_array( $queries ) ? implode( ";\n", $queries ) : (string) $queries;
	return array();
}
function get_option( $name, $default = false ) {
	return array_key_exists( $name, WpTestState::$options ) ? WpTestState::$options[ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	WpTestState::$options[ $name ] = $value;
	return true;
}
function add_option( $name, $value, $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $name, WpTestState::$options ) ) {
		return false;
	}
	WpTestState::$options[ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	if ( ! array_key_exists( $name, WpTestState::$options ) ) {
		return false;
	}
	unset( WpTestState::$options[ $name ] );
	return true;
}

function add_filter( $hook, $cb, $priority = 10, $args = 1 ) {
	WpTestState::$filters[ $hook ][] = $cb;
	return true;
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( WpTestState::$filters[ $hook ] ?? array() as $cb ) {
		$value = $cb( $value, ...$args );
	}
	return $value;
}
function add_action( $hook, $cb, $priority = 10, $args = 1 ) {
	WpTestState::$actions[ $hook ][] = $cb;
	return true;
}
function do_action( $hook, ...$args ) {
	foreach ( WpTestState::$actions[ $hook ] ?? array() as $cb ) {
		$cb( ...$args );
	}
}
function has_action( $hook ) {
	return ! empty( WpTestState::$actions[ $hook ] );
}

function wp_next_scheduled( $hook, $args = array() ) {
	foreach ( WpTestState::$scheduled as $e ) {
		if ( $e['hook'] === $hook ) {
			return $e['time'];
		}
	}
	return false;
}
function wp_schedule_event( $ts, $recurrence, $hook, $args = array() ) {
	WpTestState::$scheduled[] = array( 'hook' => $hook, 'time' => $ts, 'recurrence' => $recurrence );
	return true;
}
function wp_schedule_single_event( $ts, $hook, $args = array() ) {
	WpTestState::$scheduled[] = array( 'hook' => $hook, 'time' => $ts, 'recurrence' => 'single' );
	return true;
}
function wp_unschedule_event( $ts, $hook, $args = array() ) {
	foreach ( WpTestState::$scheduled as $i => $e ) {
		if ( $e['hook'] === $hook ) {
			unset( WpTestState::$scheduled[ $i ] );
		}
	}
	WpTestState::$scheduled = array_values( WpTestState::$scheduled );
	return true;
}
function wp_clear_scheduled_hook( $hook, $args = array() ) {
	return wp_unschedule_event( 0, $hook );
}

function __( $text, $domain = null ) {
	return $text;
}
function _e( $text, $domain = null ) {
	echo $text;
}
function esc_html__( $text, $domain = null ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_html_e( $text, $domain = null ) {
	echo esc_html__( $text, $domain );
}
function esc_attr__( $text, $domain = null ) {
	return esc_html__( $text, $domain );
}
function esc_attr_e( $text, $domain = null ) {
	echo esc_html__( $text, $domain );
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $url ) {
	return filter_var( (string) $url, FILTER_SANITIZE_URL );
}
function esc_js( $text ) {
	return addslashes( (string) $text );
}
function esc_textarea( $text ) {
	return esc_html( $text );
}
function wp_kses_post( $content ) {
	return $content;
}
function sanitize_text_field( $str ) {
	return trim( strip_tags( (string) $str ) );
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function sanitize_title( $t ) {
	return strtolower( preg_replace( '/[^a-zA-Z0-9\-]+/', '-', (string) $t ) );
}
function absint( $v ) {
	return abs( (int) $v );
}
function wp_unslash( $v ) {
	return is_array( $v ) ? array_map( 'wp_unslash', $v ) : stripslashes( (string) $v );
}
function wp_json_encode( $data, $flags = 0 ) {
	return json_encode( $data, $flags );
}
function current_user_can( $cap ) {
	return WpTestState::$currentUserCan;
}
function is_admin() {
	return true;
}
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}
function add_query_arg( $args, $url = '' ) {
	$q = http_build_query( is_array( $args ) ? $args : array() );
	return $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' ) . $q;
}
function home_url( $p = '' ) {
	return 'https://example.test' . $p;
}
function get_bloginfo( $k = 'name' ) {
	return 'Example';
}
function plugin_dir_path( $f ) {
	return dirname( (string) $f ) . '/';
}
function plugin_dir_url( $f ) {
	return NEWSDESK_URL;
}
function plugin_basename( $f ) {
	return 'newsdesk-ai/newsdesk-ai.php';
}
function checked( $a, $b = true, $echo = true ) {
	$r = ( (string) $a === (string) $b ) ? ' checked="checked"' : '';
	if ( $echo ) { echo $r; }
	return $r;
}
function selected( $a, $b = true, $echo = true ) {
	$r = ( (string) $a === (string) $b ) ? ' selected="selected"' : '';
	if ( $echo ) { echo $r; }
	return $r;
}
function wp_nonce_field( $a = -1, $n = '_wpnonce', $ref = true, $echo = true ) {
	$r = '<input type="hidden" name="' . $n . '" value="nonce" />';
	if ( $echo ) { echo $r; }
	return $r;
}
function check_admin_referer( $action = -1, $q = '_wpnonce' ) {
	return true;
}
function wp_verify_nonce( $n, $a = -1 ) {
	return 1;
}
function wp_create_nonce( $a = -1 ) {
	return 'nonce';
}
function wp_safe_redirect( $l, $s = 302 ) {
	WpTestState::$log[] = 'redirect:' . $l;
	return true;
}
function wp_die( $m = '', $t = '', $a = array() ) {
	throw new RuntimeException( 'wp_die: ' . ( is_string( $m ) ? $m : '' ) );
}
function is_wp_error( $t ) {
	return $t instanceof WP_Error;
}
function register_setting( $group, $name, $args = array() ) {
	WpTestState::$registeredSettings[ $name ] = array( 'group' => $group, 'args' => $args );
}
function esc_url_raw( $url ) {
	return (string) $url;
}
function paginate_links( $args = array() ) {
	// Enough shape for a template to render; not the real pagination markup.
	$total = isset( $args['total'] ) ? (int) $args['total'] : 1;
	$out   = '';
	for ( $i = 1; $i <= $total; $i++ ) {
		$out .= '<a href="#">' . $i . '</a>';
	}
	return $out;
}
function number_format_i18n( $n, $d = 0 ) {
	return number_format( (float) $n, (int) $d );
}
function get_posts( $args = array() ) {
	$limit = (int) ( $args['posts_per_page'] ?? 10 );
	$out   = array();
	foreach ( array_slice( WpTestState::$posts, 0, $limit ) as $p ) {
		$out[] = (object) $p;
	}
	return $out;
}
function get_permalink( $id ) {
	foreach ( WpTestState::$posts as $p ) {
		if ( (int) $p['ID'] === (int) $id ) {
			return 'https://example.test/' . ( $p['post_name'] ?? ( 'p' . $id ) ) . '/';
		}
	}
	return '';
}
function wp_insert_post( $post, $wpError = false ) {
	$id            = WpTestState::$nextPostId++;
	$post['ID']    = $id;
	WpTestState::$posts[] = $post;
	return $id;
}
function wp_update_post( $post, $wpError = false ) {
	$id = (int) ( $post['ID'] ?? 0 );
	foreach ( WpTestState::$posts as $i => $p ) {
		if ( (int) $p['ID'] === $id ) {
			WpTestState::$posts[ $i ] = array_merge( $p, $post );
			return $id;
		}
	}
	return 0;
}
function get_post( $id ) {
	foreach ( WpTestState::$posts as $p ) {
		if ( (int) $p['ID'] === (int) $id ) {
			return (object) $p;
		}
	}
	return null;
}
function update_post_meta( $id, $k, $v ) {
	WpTestState::$postMeta[ $id ][ $k ] = $v;
	return true;
}
function get_post_meta( $id, $k = '', $single = false ) {
	if ( '' === $k ) {
		return WpTestState::$postMeta[ $id ] ?? array();
	}
	$v = WpTestState::$postMeta[ $id ][ $k ] ?? '';
	return $single ? $v : array( $v );
}
function get_post_field( $field, $id ) {
	$p = get_post( $id );
	return $p ? ( $p->{$field} ?? '' ) : '';
}
function wp_remote_request( $url, $args = array() ) {
	return new WP_Error( 'no_http', 'HTTP disabled in tests' );
}
function wp_remote_get( $url, $args = array() ) {
	return wp_remote_request( $url, $args );
}
function wp_remote_retrieve_response_code( $r ) {
	return 0;
}
function wp_remote_retrieve_headers( $r ) {
	return array();
}
function wp_remote_retrieve_body( $r ) {
	return '';
}
function set_transient( $k, $v, $e = 0 ) {
	WpTestState::$options[ '_t_' . $k ] = $v;
	return true;
}
function get_transient( $k ) {
	return WpTestState::$options[ '_t_' . $k ] ?? false;
}
function delete_transient( $k ) {
	unset( WpTestState::$options[ '_t_' . $k ] );
	return true;
}
function wp_generate_password( $len = 12, $special = true ) {
	return substr( bin2hex( random_bytes( 32 ) ), 0, $len );
}
function get_current_user_id() {
	return 1;
}
function wp_get_current_user() {
	return (object) array( 'ID' => 1, 'display_name' => 'Tester' );
}
function trailingslashit( $s ) {
	return rtrim( (string) $s, '/\\' ) . '/';
}
function wp_parse_args( $a, $d = array() ) {
	return array_merge( $d, (array) $a );
}
function submit_button( $t = null, $ty = 'primary', $n = 'submit', $w = true, $o = null ) {
	echo '<button type="submit">' . esc_html( (string) $t ) . '</button>';
}
function load_plugin_textdomain( $d, $a = false, $p = false ) {
	return true;
}
function wp_enqueue_style( ...$a ) {
	return true;
}
function wp_enqueue_script( ...$a ) {
	return true;
}
function add_menu_page( ...$a ) {
	return 'toplevel_page';
}
function add_submenu_page( ...$a ) {
	return 'sub_page';
}
function register_rest_route( ...$a ) {
	return true;
}
function rest_ensure_response( $d ) {
	return $d;
}
function did_action( $h ) {
	return 0;
}
function wp_mail( ...$a ) {
	return true;
}
function wp_upload_dir() {
	return array( 'path' => sys_get_temp_dir(), 'url' => 'https://example.test/uploads', 'basedir' => sys_get_temp_dir(), 'baseurl' => 'https://example.test/uploads' );
}

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_code() {
		return $this->code;
	}
}

/* -------------------------------------------------------------- autoload */

require_once NEWSDESK_DIR . 'src/Core/Autoloader.php';
NewsDesk\AI\Core\Autoloader::register();

/* ---------------------------------------------------- in-memory DB + fakes */

require_once __DIR__ . '/stubs/FakeWpDb.php';
require_once __DIR__ . '/stubs/fixtures.php';

/* ----------------------------------------------------------- tiny runner */

final class T {
	public static $pass = 0;
	public static $fail = 0;
	/** @var string[] */
	public static $failures = array();
	private static $group = '';

	public static function group( string $name ): void {
		self::$group = $name;
		echo "\n\033[1m── {$name}\033[0m\n";
	}

	public static function ok( bool $cond, string $label ): void {
		if ( $cond ) {
			self::$pass++;
			echo "  \033[32m✓\033[0m {$label}\n";
		} else {
			self::$fail++;
			self::$failures[] = self::$group . ' :: ' . $label;
			echo "  \033[31m✗ {$label}\033[0m\n";
		}
	}

	public static function eq( $expected, $actual, string $label ): void {
		$cond = $expected === $actual;
		if ( ! $cond ) {
			$label .= sprintf( ' (expected %s, got %s)', var_export( $expected, true ), var_export( $actual, true ) );
		}
		self::ok( $cond, $label );
	}

	public static function contains( string $needle, string $haystack, string $label ): void {
		self::ok( false !== strpos( $haystack, $needle ), $label );
	}

	public static function throws( callable $fn, string $label ): void {
		try {
			$fn();
			self::ok( false, $label . ' (no exception thrown)' );
		} catch ( \Throwable $e ) {
			self::ok( true, $label );
		}
	}

	public static function summary(): int {
		$total = self::$pass + self::$fail;
		echo "\n" . str_repeat( '─', 60 ) . "\n";
		if ( self::$fail > 0 ) {
			echo "\033[31mFAILED\033[0m  {$total} assertions, " . self::$fail . " failed\n";
			foreach ( self::$failures as $f ) {
				echo "  • {$f}\n";
			}
			return 1;
		}
		echo "\033[32mPASSED\033[0m  all {$total} assertions\n";
		return 0;
	}
}
