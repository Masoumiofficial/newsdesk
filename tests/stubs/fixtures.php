<?php
/**
 * Shared test doubles + factory helpers.
 *
 * Loaded by bootstrap.php after the autoloader, so every test file gets the
 * same fakes and they can safely reference each other.
 */

use NewsDesk\AI\Application\Contracts\LinkRepositoryInterface;
use NewsDesk\AI\Application\Contracts\WpPostIndexInterface;
use NewsDesk\AI\Application\Contracts\WpPostWriterInterface;
use NewsDesk\AI\Application\Content\DraftService;
use NewsDesk\AI\Application\NewsroomSettings;
use NewsDesk\AI\Domain\Entity\ExternalLink;
use NewsDesk\AI\Domain\Entity\InternalLinkSuggestion;
use NewsDesk\AI\Domain\Entity\Story;
use NewsDesk\AI\Logging\Contracts\LoggerInterface;

/** Post index that records the limit it was asked for. */
final class FakePostIndex implements WpPostIndexInterface {
	/** @var array<int, array{post_id:int,title:string,url:string}> */
	public $posts = array();
	/** @var int last limit requested */
	public $askedFor = 0;

	public function existingPosts( int $limit = 100 ): array {
		$this->askedFor = $limit;
		return array_slice( $this->posts, 0, $limit );
	}

	public function urlFor( int $postId ): string {
		foreach ( $this->posts as $p ) {
			if ( (int) $p['post_id'] === $postId ) {
				return (string) $p['url'];
			}
		}
		return '';
	}
}

/** In-memory link repository. */
final class FakeLinkRepo implements LinkRepositoryInterface {
	/** @var InternalLinkSuggestion[] */
	public $internal = array();
	/** @var ExternalLink[] */
	public $external = array();
	private $id = 0;

	public function insertInternal( InternalLinkSuggestion $s ): int {
		$this->internal[] = $s;
		return ++$this->id;
	}
	public function internalForTarget( int $targetPostId ): array {
		$out = array();
		foreach ( $this->internal as $s ) {
			if ( (int) $s->targetPostId === $targetPostId ) {
				$out[] = $s;
			}
		}
		return $out;
	}
	public function insertExternal( ExternalLink $link ): int {
		$this->external[] = $link;
		return ++$this->id;
	}
	public function externalForClaimIds( array $claimIds ): array {
		return array();
	}
	public function externalForStory( int $storyId ): array {
		return array();
	}
}

/** Collects every draft handed to it. */
final class FakePostWriter implements WpPostWriterInterface {
	/** @var array<int, array{post:array, meta:array}> */
	public $drafts = array();
	private $nextId = 500;

	/** @var array<int, array{target:int,kind:string,post:array,meta:array}> */
	public $revisions = array();
	/** @var array<int, array{post_id:int,notice:string}> */
	public $corrections = array();
	/** @var int[] post IDs this double reports as published */
	public $published = array();

	public function createDraft( array $post, array $meta ): int {
		$this->drafts[] = array(
			'post' => $post,
			'meta' => $meta,
		);
		return $this->nextId++;
	}

	public function createRevisionDraft( int $targetPostId, string $kind, array $post, array $meta ): int {
		if ( $targetPostId <= 0 ) {
			return 0;
		}
		$id                = $this->nextId++;
		$this->revisions[] = array(
			'target' => $targetPostId,
			'kind'   => $kind,
			'post'   => $post,
			'meta'   => $meta,
		);
		// A revision IS a draft; record it in both so drafts-only stays checkable.
		$this->drafts[] = array(
			'post' => $post,
			'meta' => $meta,
		);
		return $id;
	}

	public function appendCorrectionNotice( int $postId, string $notice ): bool {
		if ( $postId <= 0 || '' === trim( $notice ) ) {
			return false;
		}
		$this->corrections[] = array(
			'post_id' => $postId,
			'notice'  => $notice,
		);
		return true;
	}

	public function isPublished( int $postId ): bool {
		return in_array( $postId, $this->published, true );
	}

	/** @return array{post:array, meta:array}|null */
	public function last() {
		return $this->drafts ? $this->drafts[ count( $this->drafts ) - 1 ] : null;
	}

	/** @return array{target:int,kind:string,post:array,meta:array}|null */
	public function lastRevision() {
		return $this->revisions ? $this->revisions[ count( $this->revisions ) - 1 ] : null;
	}
}

/** Records log lines so tests can assert on events. */
final class FakeLogger implements LoggerInterface {
	/** @var array<int, array> */
	public $lines = array();

	public function debug( string $m, array $c = array(), string $comp = '', string $e = '', ?int $j = null, ?string $cid = null ): void {
		$this->log( 'debug', $m, $c, $comp, $e, $j, $cid );
	}
	public function info( string $m, array $c = array(), string $comp = '', string $e = '', ?int $j = null, ?string $cid = null ): void {
		$this->log( 'info', $m, $c, $comp, $e, $j, $cid );
	}
	public function warning( string $m, array $c = array(), string $comp = '', string $e = '', ?int $j = null, ?string $cid = null ): void {
		$this->log( 'warning', $m, $c, $comp, $e, $j, $cid );
	}
	public function error( string $m, array $c = array(), string $comp = '', string $e = '', ?int $j = null, ?string $cid = null ): void {
		$this->log( 'error', $m, $c, $comp, $e, $j, $cid );
	}
	public function critical( string $m, array $c = array(), string $comp = '', string $e = '', ?int $j = null, ?string $cid = null ): void {
		$this->log( 'critical', $m, $c, $comp, $e, $j, $cid );
	}
	public function log( string $level, string $m, array $c = array(), string $comp = '', string $e = '', ?int $j = null, ?string $cid = null ): void {
		$this->lines[] = array(
			'level'     => $level,
			'message'   => $m,
			'context'   => $c,
			'component' => $comp,
			'event'     => $e,
			'job_id'    => $j,
		);
	}

	/** Did an event code fire? */
	public function hasEvent( string $event ): bool {
		foreach ( $this->lines as $l ) {
			if ( $l['event'] === $event ) {
				return true;
			}
		}
		return false;
	}

	/** All context arrays logged under an event code. */
	public function contextsFor( string $event ): array {
		$out = array();
		foreach ( $this->lines as $l ) {
			if ( $l['event'] === $event ) {
				$out[] = $l['context'];
			}
		}
		return $out;
	}
}

/** Build a DraftService wired to fakes. */
function make_draft_service( WpPostIndexInterface $index, ?FakePostWriter $writer = null, ?array $settings = null ): DraftService {
	return new DraftService(
		$writer ?: new FakePostWriter(),
		$index,
		new FakeLinkRepo(),
		new NewsroomSettings( $settings ?: NewsroomSettings::defaults() ),
		new FakeLogger()
	);
}

/** Minimal Story good enough for rendering. */
function make_story( array $over = array() ): Story {
	$s                 = new Story();
	$s->storyId        = $over['storyId'] ?? 1;
	$s->canonicalTitle = $over['title'] ?? 'Test story';
	$s->language       = $over['language'] ?? 'fa_IR';
	return $s;
}

/**
 * A global $wpdb double good enough for the real WpDb adapter.
 *
 * Tests that build the production container (or run Activation) need more
 * than `prefix`: WpDb forwards get_charset_collate(), prepare(), query() and
 * the CRUD helpers straight through to $wpdb.
 */
final class FakeGlobalWpdb {
	/** @var string */
	public $prefix = 'wp_';
	/** @var int */
	public $insert_id = 0; // phpcs:ignore
	/** @var string[] */
	public $queries = array();

	public function get_charset_collate(): string { // phpcs:ignore
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}
	public function prepare( $query, ...$args ) { // phpcs:ignore
		if ( isset( $args[0] ) && is_array( $args[0] ) && 1 === count( $args ) ) {
			$args = $args[0];
		}
		foreach ( $args as $a ) {
			$rep   = is_int( $a ) || is_float( $a ) ? (string) $a : "'" . (string) $a . "'";
			$query = preg_replace( '/%[sdf]/', $rep, (string) $query, 1 );
		}
		return (string) $query;
	}
	public function query( $sql ) { // phpcs:ignore
		$this->queries[] = (string) $sql;
		return 1;
	}
	/**
	 * Seeded responses: substring of the SQL => rows (or scalar for get_var).
	 * Without this the double returns nothing, so any template branch that
	 * only appears when rows EXIST would silently never be tested.
	 *
	 * @var array<string,mixed>
	 */
	public $seeded = array();

	public function seedResults( $match, $rows ) {
		$this->seeded[ $match ] = $rows;
		return $this;
	}

	private function seededFor( $sql ) {
		foreach ( $this->seeded as $match => $rows ) {
			if ( false !== strpos( (string) $sql, (string) $match ) ) {
				return $rows;
			}
		}
		return null;
	}

	public function get_results( $sql, $output = OBJECT ) { // phpcs:ignore
		$this->queries[] = (string) $sql;
		$hit = $this->seededFor( $sql );
		return is_array( $hit ) ? $hit : array();
	}
	public function get_row( $sql, $output = OBJECT, $y = 0 ) { // phpcs:ignore
		$this->queries[] = (string) $sql;
		return null;
	}
	public function get_var( $sql, $x = 0, $y = 0 ) { // phpcs:ignore
		$this->queries[] = (string) $sql;
		$hit = $this->seededFor( $sql );
		if ( is_array( $hit ) ) {
			return count( $hit );
		}
		return null === $hit ? null : $hit;
	}
	public function insert( $table, $data, $format = null ) { // phpcs:ignore
		$this->insert_id++; // phpcs:ignore
		return 1;
	}
	public function update( $table, $data, $where, $format = null, $whereFormat = null ) { // phpcs:ignore
		return 1;
	}
	public function delete( $table, $where, $whereFormat = null ) { // phpcs:ignore
		return 1;
	}
}
