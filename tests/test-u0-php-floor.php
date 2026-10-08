<?php
/**
 * U-0 — the declared PHP floor is 7.4, so nothing in the zip may call a
 * function or method that 7.4 does not have.
 *
 * `tests/run.sh` greps for post-7.4 SYNTAX (match, ?->, attributes, enums).
 * It cannot see functions, and that gap shipped a bug: Time::toDb() called
 * DateTimeImmutable::createFromInterface(), which is PHP 8.0+, so on PHP 7.4
 * — the floor printed in the plugin header — every write that stored a date
 * raised a fatal error, and the syntax guard stayed green throughout.
 *
 * This scans the shipped tree for calls to APIs that did not exist in 7.4. A
 * call is allowed only when the same file guards it first, which is how a
 * polyfill is written; JsonSchemaValidator::isList() keeps passing that way.
 * It fails closed: an unguarded call anywhere in the file is a violation, so
 * moving the call out from under its guard re-breaks the build.
 *
 * @package NewsDesk\AI\Tests
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** Functions added after PHP 7.4: name => the version that introduced it. */
$post74Functions = array(
	'str_contains'      => '8.0',
	'str_starts_with'   => '8.0',
	'str_ends_with'     => '8.0',
	'fdiv'              => '8.0',
	'get_debug_type'    => '8.0',
	'preg_last_error_msg' => '8.0',
	'array_is_list'     => '8.1',
	'enum_exists'       => '8.1',
	'fsync'             => '8.1',
	'json_validate'     => '8.3',
	'mb_str_pad'        => '8.3',
	'str_increment'     => '8.3',
	'str_decrement'     => '8.3',
	'array_find'        => '8.4',
	'array_find_key'    => '8.4',
	'array_any'         => '8.4',
	'array_all'         => '8.4',
	'mb_ucfirst'        => '8.4',
	'mb_lcfirst'        => '8.4',
	'mb_trim'           => '8.4',
	'mb_ltrim'          => '8.4',
	'mb_rtrim'          => '8.4',
	'array_first'       => '8.5',
	'array_last'        => '8.5',
);

/** Static/instance methods added after PHP 7.4: name => version. */
$post74Methods = array(
	'createFromInterface' => '8.0', // the one that shipped broken
);

/** Functions that 7.4 has and PHP 8.0 removed — no guard can make these safe. */
$removedIn80 = array( 'create_function', 'each', 'money_format', 'get_magic_quotes_gpc', 'get_magic_quotes_runtime' );

$root  = rtrim( NEWSDESK_DIR, '/' );
$files = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) ) as $file ) {
	if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
		$files[] = $file->getPathname();
	}
}
sort( $files );

/** True when the line carrying $pos is a comment — the guard greps skip those. */
$isComment = static function ( string $src, int $pos ): bool {
	$start = strrpos( substr( $src, 0, $pos ), "\n" );
	$line  = ltrim( substr( $src, false === $start ? 0 : $start + 1, 60 ) );
	return 0 === strpos( $line, '*' ) || 0 === strpos( $line, '//' ) || 0 === strpos( $line, '#' );
};

/**
 * Is this call site guarded? Polyfills declare the guard immediately above the
 * call (`if ( function_exists( 'x' ) ) { return x( ... ); }`), so a short
 * look-back window is enough.
 */
$guarded = static function ( string $src, int $pos, string $guard, string $name ): bool {
	$before = substr( $src, max( 0, $pos - 400 ), 400 );
	return false !== strpos( $before, $guard )
		&& 1 === preg_match( '/["\']' . preg_quote( $name, '/' ) . '["\']/', $before );
};

$unguardedFunctions = array();
$unguardedMethods   = array();
$removed            = array();

foreach ( $files as $path ) {
	$src = (string) file_get_contents( $path );
	$rel = str_replace( $root . '/', '', $path );

	foreach ( $post74Functions as $name => $since ) {
		// The look-behind keeps `$this->each()` and `foreach (` out of the net.
		if ( ! preg_match_all( '/(?<![>\w$])' . preg_quote( $name, '/' ) . '\s*\(/', $src, $hits, PREG_OFFSET_CAPTURE ) ) {
			continue;
		}
		foreach ( $hits[0] as $hit ) {
			if ( $isComment( $src, $hit[1] ) || $guarded( $src, $hit[1], 'function_exists', $name ) ) {
				continue;
			}
			$unguardedFunctions[] = "$rel: $name() needs PHP $since and has no function_exists() fallback";
		}
	}

	foreach ( $post74Methods as $name => $since ) {
		if ( ! preg_match_all( '/(?:::|->)\s*' . preg_quote( $name, '/' ) . '\s*\(/', $src, $hits, PREG_OFFSET_CAPTURE ) ) {
			continue;
		}
		foreach ( $hits[0] as $hit ) {
			if ( $isComment( $src, $hit[1] ) || $guarded( $src, $hit[1], 'method_exists', $name ) ) {
				continue;
			}
			$unguardedMethods[] = "$rel: $name() needs PHP $since and has no method_exists() guard";
		}
	}

	foreach ( $removedIn80 as $name ) {
		if ( ! preg_match_all( '/(?<![>\w$])' . preg_quote( $name, '/' ) . '\s*\(/', $src, $hits, PREG_OFFSET_CAPTURE ) ) {
			continue;
		}
		foreach ( $hits[0] as $hit ) {
			if ( $isComment( $src, $hit[1] ) ) {
				continue;
			}
			$removed[] = "$rel: $name() was removed in PHP 8.0";
		}
	}
}

/* ───────────────────────────────────────────────────────────────────── */

T::group( 'PHP floor — the scan really looks at the shipped tree' );

T::ok( count( $files ) > 100, 'scanned ' . count( $files ) . ' shipped PHP files' );
// If the watch lists are trimmed, the bug they exist for comes back unnoticed.
T::ok( isset( $post74Functions['array_is_list'] ), 'the 8.1 list helper is on the watch list (JsonSchemaValidator polyfills it)' );
T::ok( isset( $post74Methods['createFromInterface'] ), 'the 8.0 method that shipped broken is on the watch list' );

T::group( 'PHP floor — no shipped file calls an API newer than 7.4' );

T::eq( array(), $unguardedFunctions, 'every post-7.4 function call is behind a function_exists() fallback' );
T::eq( array(), $unguardedMethods, 'no post-7.4 class method is called without a method_exists() guard' );
T::eq( array(), $removed, 'no function removed in PHP 8.0 is called' );

exit( T::summary() );
