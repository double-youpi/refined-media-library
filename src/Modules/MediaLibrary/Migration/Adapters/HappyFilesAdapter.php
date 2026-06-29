<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary\Migration\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapter for HappyFiles (Codeer, Bricks ecosystem) — native WordPress taxonomy.
 *
 * Hierarchical `happyfiles_category` taxonomy on `attachment`. HappyFiles has
 * no concept of collections — everything is a folder. Folder sort order is stored
 * in the term meta `happyfiles_order`.
 */
final class HappyFilesAdapter extends AbstractTaxonomyAdapter {

	public function key(): string {
		return 'happyfiles';
	}

	public function label(): string {
		return 'HappyFiles';
	}

	protected function taxonomy(): string {
		return 'happyfiles_category';
	}

	protected function order_meta_key(): ?string {
		return 'happyfiles_order';
	}
}
