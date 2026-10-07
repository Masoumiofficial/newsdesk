<?php
/**
 * In-memory implementation of WpDbInterface for headless tests.
 * Supports the narrow SQL surface the plugin actually uses:
 * INSERT / UPDATE / DELETE via the API, plus SELECT COUNT(*) and
 * simple WHERE filtering that the repositories rely on.
 */

use NewsDesk\AI\Application\Contracts\WpDbInterface;

class FakeWpDb implements WpDbInterface {

	/** @var array<string, array<int, array<string, mixed>>> */
	public $tables = array();
	/** @var int */
	public $lastInsertId = 0;
	/** @var string[] */
	public $queries = array();
	/** @var array<int, array{sql:string,rows:array}> */
	public $stubbedResults = array();

	public function prepare( string $query, ...$args ): string {
		if ( isset( $args[0] ) && is_array( $args[0] ) && 1 === count( $args ) ) {
			$args = $args[0];
		}
		// WP 6.2+ fires _doing_it_wrong() when prepare() is called with no
		// placeholders/arguments. Mirror that so tests can catch the misuse.
		if ( empty( $args ) ) {
			WpTestState::doingItWrong( 'wpdb::prepare', 'called without arguments: ' . $query );
		}
		$out = '';
		$i   = 0;
		$len = strlen( $query );
		for ( $p = 0; $p < $len; $p++ ) {
			if ( '%' === $query[ $p ] && $p + 1 < $len ) {
				$spec = $query[ $p + 1 ];
				if ( 's' === $spec || 'd' === $spec || 'f' === $spec ) {
					$v = $args[ $i ] ?? '';
					$i++;
					if ( 'd' === $spec ) {
						$out .= (string) (int) $v;
					} elseif ( 'f' === $spec ) {
						$out .= (string) (float) $v;
					} else {
						$out .= "'" . str_replace( "'", "''", (string) $v ) . "'";
					}
					$p++;
					continue;
				}
				if ( '%' === $spec ) {
					$out .= '%';
					$p++;
					continue;
				}
			}
			$out .= $query[ $p ];
		}
		return $out;
	}

	public function query( string $sql ) {
		$this->queries[] = $sql;
		if ( preg_match( '/^\s*UPDATE\s+(\S+)\s+SET\s+(.+?)\s+WHERE\s+(.+)$/is', $sql, $m ) ) {
			return $this->applyRawUpdate( $m[1], $m[2], $m[3] );
		}
		if ( preg_match( '/^\s*DELETE\s+FROM\s+(\S+)\s+WHERE\s+(.+)$/is', $sql, $m ) ) {
			return $this->applyRawDelete( $m[1], $m[2] );
		}
		// DELETE FROM table  (truncate, no WHERE)
		if ( preg_match( '/^\s*DELETE\s+FROM\s+(\S+)\s*$/is', $sql, $m ) ) {
			$n                      = count( $this->tables[ $m[1] ] ?? array() );
			$this->tables[ $m[1] ] = array();
			return $n;
		}
		return 0;
	}

	public function getResults( string $sql, string $output = 'OBJECT' ): array {
		$this->queries[] = $sql;
		foreach ( $this->stubbedResults as $stub ) {
			if ( false !== strpos( $sql, $stub['sql'] ) ) {
				return $stub['rows'];
			}
		}
		if ( preg_match( '/FROM\s+(\S+)/i', $sql, $m ) ) {
			$rows = $this->filterRows( $m[1], $sql );
			return array_map( static function ( $r ) {
				return (object) $r;
			}, $rows );
		}
		return array();
	}

	public function getRow( string $sql, string $output = 'OBJECT', $y = 0 ) {
		$rows = $this->getResults( $sql, $output );
		if ( ! isset( $rows[0] ) ) {
			return null;
		}
		// Mirror production WpDb::getRow(), which always casts to an assoc
		// array regardless of $output so repositories get a stable shape.
		return (array) $rows[0];
	}

	public function getVar( string $sql, $x = 0, $y = 0 ) {
		$this->queries[] = $sql;
		if ( preg_match( '/SELECT\s+COUNT\(\*\)\s+FROM\s+(\S+)/i', $sql, $m ) ) {
			return (string) count( $this->filterRows( $m[1], $sql ) );
		}
		if ( preg_match( '/FROM\s+(\S+)/i', $sql, $m ) ) {
			$rows = $this->filterRows( $m[1], $sql );
			if ( ! $rows ) {
				return null;
			}
			$first = reset( $rows );
			return (string) reset( $first );
		}
		return null;
	}

	/**
	 * Primary-key column per table, parsed once from the real schema.
	 *
	 * Four tables do not use `id` (stories.story_id, content_versions.version_id,
	 * generated_images.image_id, research_packages.research_id). Hardcoding `id`
	 * here made update()/find() silently no-op on those tables while still
	 * reporting success, so the fake is taught the truth from Schema.php rather
	 * than from a list that can drift.
	 *
	 * @var array<string,string>|null
	 */
	private static $pkMap = null;

	/** @return array<string,string> table suffix (e.g. 'nd_stories') => pk column */
	private static function pkMap(): array {
		if ( null !== self::$pkMap ) {
			return self::$pkMap;
		}
		self::$pkMap = array();
		$schema      = NEWSDESK_DIR . 'src/Infrastructure/Database/Schema.php';
		$sql         = is_readable( $schema ) ? (string) file_get_contents( $schema ) : '';
		if ( preg_match_all( '/CREATE TABLE \{\$prefix\}(\w+) \((.*?)\) \$charsetCollate/s', $sql, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				if ( preg_match( '/(\w+)\s+bigint\(20\) unsigned NOT NULL AUTO_INCREMENT/', $match[2], $col ) ) {
					self::$pkMap[ $match[1] ] = $col[1];
				}
			}
		}
		return self::$pkMap;
	}

	/** Primary-key column for a prefixed table name. */
	public function pkFor( string $table ): string {
		$prefix = $this->prefix();
		$suffix = ( 0 === strpos( $table, $prefix ) )
			? substr( $table, strlen( $prefix ) )
			: $table;
		return self::pkMap()[ $suffix ] ?? 'id';
	}

	public function insert( string $table, array $data, array $format = array() ) {
		$this->tables[ $table ]   = $this->tables[ $table ] ?? array();
		$id                       = ++$this->lastInsertId;
		$pk                       = $this->pkFor( $table );
		$data[ $pk ]              = $data[ $pk ] ?? $id;
		$this->tables[ $table ][] = $data;
		return 1;
	}

	public function update( string $table, array $data, array $where, array $format = array(), array $whereFormat = array() ) {
		$n = 0;
		foreach ( $this->tables[ $table ] ?? array() as $i => $row ) {
			$match = true;
			foreach ( $where as $k => $v ) {
				if ( ( $row[ $k ] ?? null ) != $v ) { // phpcs:ignore
					$match = false;
					break;
				}
			}
			if ( $match ) {
				$this->tables[ $table ][ $i ] = array_merge( $row, $data );
				$n++;
			}
		}
		return $n;
	}

	public function delete( string $table, array $where, array $whereFormat = array() ) {
		$n = 0;
		foreach ( $this->tables[ $table ] ?? array() as $i => $row ) {
			$match = true;
			foreach ( $where as $k => $v ) {
				if ( ( $row[ $k ] ?? null ) != $v ) { // phpcs:ignore
					$match = false;
					break;
				}
			}
			if ( $match ) {
				unset( $this->tables[ $table ][ $i ] );
				$n++;
			}
		}
		$this->tables[ $table ] = array_values( $this->tables[ $table ] ?? array() );
		return $n;
	}

	public function tableExists( string $table ): bool {
		return isset( $this->tables[ $table ] );
	}

	public function insertId(): int {
		return $this->lastInsertId;
	}

	public function prefix(): string {
		return 'wp_';
	}

	public function charsetCollate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	/* ------------------------------------------------------------ helpers */

	public function seed( string $table, array $rows ): void {
		$this->tables[ $table ] = array();
		foreach ( $rows as $r ) {
			$r['id']                  = $r['id'] ?? ++$this->lastInsertId;
			$this->tables[ $table ][] = $r;
		}
	}

	public function rows( string $table ): array {
		return $this->tables[ $table ] ?? array();
	}

	/** Very small WHERE interpreter: `col = 'v'` / `col = 1` joined by AND. */
	private function filterRows( string $table, string $sql ): array {
		$rows = $this->tables[ $table ] ?? array();
		if ( ! preg_match( '/WHERE\s+(.+?)(?:\s+ORDER\s+BY|\s+LIMIT|$)/is', $sql, $m ) ) {
			return $rows;
		}
		// Use the same clause engine as UPDATE/DELETE so a SELECT cannot
		// silently ignore an operator it does not understand: a window test
		// that quietly returns every row would pass while proving nothing.
		$where = $m[1];
		$self  = $this;
		$rows  = array_filter(
			$rows,
			static function ( $r ) use ( $self, $where ) {
				return $self->matchesWhere( $r, $where );
			}
		);
		return array_values( $rows );
	}

	private function applyRawUpdate( string $table, string $set, string $where ): int {
		$assign = array();
		foreach ( explode( ',', $set ) as $pair ) {
			if ( preg_match( "/([a-z_]+)\s*=\s*'?([^']*)'?/i", trim( $pair ), $m ) ) {
				$assign[ $m[1] ] = $m[2];
			}
		}
		$n = 0;
		foreach ( $this->tables[ $table ] ?? array() as $i => $row ) {
			if ( $this->matchesWhere( $row, $where ) ) {
				$this->tables[ $table ][ $i ] = array_merge( $row, $assign );
				$n++;
			}
		}
		return $n;
	}

	private function applyRawDelete( string $table, string $where ): int {
		// LIMIT n — honour the batch cap so batching logic is really tested.
		$limit = PHP_INT_MAX;
		if ( preg_match( '/\s+LIMIT\s+(\d+)\s*$/i', $where, $lm ) ) {
			$limit = (int) $lm[1];
			$where = (string) preg_replace( '/\s+LIMIT\s+\d+\s*$/i', '', $where );
		}

		$n = 0;
		foreach ( $this->tables[ $table ] ?? array() as $i => $row ) {
			if ( $n >= $limit ) {
				break;
			}
			if ( $this->matchesWhere( $row, $where ) ) {
				unset( $this->tables[ $table ][ $i ] );
				$n++;
			}
		}
		$this->tables[ $table ] = array_values( $this->tables[ $table ] ?? array() );
		return $n;
	}

	/**
	 * Tiny WHERE interpreter. Supports AND-joined clauses of the forms:
	 *   col < 'v'   col = 'v'   col = 123
	 *   col NOT IN ( SELECT other FROM table )
	 */
	public function matchesWhere( array $row, string $where ): bool {
		// Split on AND, but not inside parentheses (subqueries).
		$clauses = $this->splitAnd( $where );
		foreach ( $clauses as $clause ) {
			if ( ! $this->matchesClause( $row, trim( $clause ) ) ) {
				return false;
			}
		}
		return ! empty( $clauses );
	}

	/** @return string[] */
	protected function splitAnd( string $where ): array {
		$out   = array();
		$depth = 0;
		$buf   = '';
		$len   = strlen( $where );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $where[ $i ];
			if ( '(' === $ch ) {
				$depth++;
			} elseif ( ')' === $ch ) {
				$depth--;
			}
			if ( 0 === $depth && 0 === stripos( substr( $where, $i, 5 ), ' and ' ) ) {
				$out[] = $buf;
				$buf   = '';
				$i    += 4;
				continue;
			}
			$buf .= $ch;
		}
		if ( '' !== trim( $buf ) ) {
			$out[] = $buf;
		}
		return $out;
	}

	protected function matchesClause( array $row, string $clause ): bool {
		// col NOT IN ( SELECT other_col FROM other_table )
		if ( preg_match( '/([a-z_]+)\s+NOT\s+IN\s*\(\s*SELECT\s+([a-z_]+)\s+FROM\s+(\S+?)\s*\)/i', $clause, $m ) ) {
			$needle = (string) ( $row[ $m[1] ] ?? '' );
			foreach ( $this->tables[ $m[3] ] ?? array() as $other ) {
				if ( (string) ( $other[ $m[2] ] ?? '' ) === $needle ) {
					return false;
				}
			}
			return true;
		}
		if ( preg_match( "/([a-z_]+)\s*<>\s*'([^']*)'/i", $clause, $m ) ) {
			return (string) ( $row[ $m[1] ] ?? '' ) !== $m[2];
		}
		// col IN ( 'a', 'b', ... ) — used by status filters.
		if ( preg_match( "/([a-z_]+)\s+IN\s*\(([^)]*)\)/i", $clause, $m )
			&& false === stripos( $m[2], 'SELECT' ) ) {
			$hay = (string) ( $row[ $m[1] ] ?? '' );
			foreach ( explode( ',', $m[2] ) as $lit ) {
				if ( trim( trim( $lit ), "'" ) === $hay ) {
					return true;
				}
			}
			return false;
		}
		// Ordered so the two-character operators win over their prefixes.
		if ( preg_match( "/([a-z_]+)\s*>=\s*'([^']*)'/i", $clause, $m ) ) {
			return (string) ( $row[ $m[1] ] ?? '' ) >= $m[2];
		}
		if ( preg_match( "/([a-z_]+)\s*<=\s*'([^']*)'/i", $clause, $m ) ) {
			return (string) ( $row[ $m[1] ] ?? '' ) <= $m[2];
		}
		if ( preg_match( "/([a-z_]+)\s*>\s*'([^']*)'/i", $clause, $m ) ) {
			return (string) ( $row[ $m[1] ] ?? '' ) > $m[2];
		}
		if ( preg_match( "/([a-z_]+)\s*<\s*'([^']*)'/i", $clause, $m ) ) {
			return (string) ( $row[ $m[1] ] ?? '' ) < $m[2];
		}
		if ( preg_match( '/([a-z_]+)\s*>=\s*(\d+(?:\.\d+)?)/i', $clause, $m ) ) {
			return (float) ( $row[ $m[1] ] ?? 0 ) >= (float) $m[2];
		}
		if ( preg_match( '/([a-z_]+)\s*<=\s*(\d+(?:\.\d+)?)/i', $clause, $m ) ) {
			return (float) ( $row[ $m[1] ] ?? 0 ) <= (float) $m[2];
		}
		if ( preg_match( "/([a-z_]+)\s*=\s*'([^']*)'/i", $clause, $m ) ) {
			return (string) ( $row[ $m[1] ] ?? '' ) === $m[2];
		}
		if ( preg_match( '/([a-z_]+)\s*=\s*(\d+)/i', $clause, $m ) ) {
			return (int) ( $row[ $m[1] ] ?? 0 ) === (int) $m[2];
		}
		return false;
	}
}
