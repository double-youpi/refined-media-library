<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resumable state for an in-progress migration, persisted in a transient.
 *
 * A migration runs in two passes driven by the JS (successive REST calls to
 * /run until done=true):
 *   1. phase 'terms'     : creates folders/collections (BFS, parents first)
 *   2. phase 'relations' : assigns attachments to terms, in batches
 *
 * The `map` source_id → [term_id, taxonomy] survives across ticks so that
 * children can be attached to the correct parents and each relation can be
 * routed to the right taxonomy.
 *
 * Idempotency: resuming an interrupted migration picks up at the cursor;
 * re-running a completed migration is a no-op (already-mapped terms are reused).
 */
final class MigrationState {

	private const TRANSIENT = 'doubleyoupi_ml_migration';
	private const TTL       = HOUR_IN_SECONDS;

	public string $source;
	public string $phase;       // 'terms' | 'relations' | 'done'
	public int    $offset;      // pagination cursor for relations
	public int    $terms_total  = 0;
	public int    $terms_done   = 0;
	public int    $rel_total    = 0;
	public int    $rel_done     = 0;
	/** @var array<int,array{tid:int,tax:string}> map src_id → created term */
	public array  $map = [];

	public function __construct( string $source ) {
		$this->source = $source;
		$this->phase  = 'terms';
		$this->offset = 0;
	}

	/** Loads the current state, or returns null if no migration is in progress. */
	public static function load(): ?self {
		$raw = get_transient( self::TRANSIENT );
		if ( ! is_array( $raw ) || empty( $raw['source'] ) ) {
			return null;
		}
		$state              = new self( (string) $raw['source'] );
		$state->phase       = (string) ( $raw['phase'] ?? 'terms' );
		$state->offset      = (int) ( $raw['offset'] ?? 0 );
		$state->terms_total = (int) ( $raw['terms_total'] ?? 0 );
		$state->terms_done  = (int) ( $raw['terms_done'] ?? 0 );
		$state->rel_total   = (int) ( $raw['rel_total'] ?? 0 );
		$state->rel_done    = (int) ( $raw['rel_done'] ?? 0 );
		$state->map         = is_array( $raw['map'] ?? null ) ? $raw['map'] : [];
		return $state;
	}

	public function save(): void {
		set_transient( self::TRANSIENT, [
			'source'      => $this->source,
			'phase'       => $this->phase,
			'offset'      => $this->offset,
			'terms_total' => $this->terms_total,
			'terms_done'  => $this->terms_done,
			'rel_total'   => $this->rel_total,
			'rel_done'    => $this->rel_done,
			'map'         => $this->map,
		], self::TTL );
	}

	public static function clear(): void {
		delete_transient( self::TRANSIENT );
	}

	/** Overall progress 0–100 (terms = first half, relations = second half). */
	public function percent(): int {
		$t = $this->terms_total > 0 ? ( $this->terms_done / $this->terms_total ) : 1.0;
		$r = $this->rel_total > 0 ? ( $this->rel_done / $this->rel_total ) : 1.0;
		return (int) round( ( $t * 50 ) + ( $r * 50 ) );
	}
}
