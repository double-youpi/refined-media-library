<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary\Migration\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapter for Folders (Premio / WebCreators) — native WordPress taxonomy.
 *
 * Hierarchical `media_folder` taxonomy on `attachment` (Premio creates its
 * folders via wp_insert_term on this taxonomy). Folder sort order is stored in
 * the term meta `wcp_custom_order` (Premio sorts by meta_value_num).
 */
final class FoldersPremioAdapter extends AbstractTaxonomyAdapter {

	public function key(): string {
		return 'folders-premio';
	}

	public function label(): string {
		return 'Folders (Premio)';
	}

	protected function taxonomy(): string {
		return 'media_folder';
	}

	protected function order_meta_key(): ?string {
		return 'wcp_custom_order';
	}
}
