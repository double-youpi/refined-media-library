<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary\Migration\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for a migration source adapter.
 *
 * Each supported third-party plugin (FileBird, HappyFiles, Real Media Library…)
 * provides an adapter that reads ITS own data — either a WP taxonomy or custom
 * tables — and returns it in a normalized format that the migration engine maps
 * to our `doubleyoupi_media_folder` / `doubleyoupi_media_collection` taxonomies.
 *
 * HARD RULE: an adapter must NEVER load a class from the source plugin.
 * It reads the database via prepared SQL (or the native WP taxonomy API for
 * taxonomy-based sources). The source plugin may be uninstalled: only its
 * residual data matters.
 */
interface SourceAdapter {

	/** Stable machine key in kebab-case: 'filebird', 'happyfiles', 'rml'. */
	public function key(): string;

	/** Human-readable name shown in the UI (not translated — it is a proper product name). */
	public function label(): string;

	/**
	 * Whether the source is present: active plugin OR residual data in the database.
	 * We detect uninstalled plugins that left their folder tree behind — that is
	 * the most common use case.
	 */
	public function is_present(): bool;

	/** Number of folders + collections found in the source. */
	public function count_terms(): int;

	/** Number of attachment→folder relations (classified attachments). */
	public function count_relations(): int;

	/**
	 * Reads ALL terms (folders + collections) from the source.
	 *
	 * Folder counts are small (tens to a few hundred), so we load them all at once.
	 * The migration engine is responsible for sorting parents before children.
	 *
	 * @return array<int,array{src_id:int,name:string,parent:int,order:int,kind:string}>
	 *         `kind` ∈ {'folder','collection'}; `parent` = src_id of the parent (0 = root).
	 */
	public function read_terms(): array;

	/**
	 * Reads one page of attachment→folder relations. Relations can number in the
	 * hundreds of thousands, so pagination is mandatory.
	 *
	 * @return array<int,array{att_id:int,src_id:int}>
	 */
	public function read_relations( int $offset, int $limit ): array;
}
