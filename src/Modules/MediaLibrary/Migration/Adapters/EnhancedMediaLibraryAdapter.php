<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary\Migration\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapter for Enhanced Media Library (wpUXSS) — native WordPress taxonomy.
 *
 * Hierarchical `media_category` taxonomy on `attachment` (default slug;
 * EML allows configuring additional taxonomies — not handled in v1). EML supports
 * MULTIPLE categories per attachment: the migration engine places the attachment in
 * the first folder encountered (extras are not promoted to collections — v1 limitation).
 *
 * EML does not store a folder sort order — falls back to alphabetical sorting.
 */
final class EnhancedMediaLibraryAdapter extends AbstractTaxonomyAdapter {

	public function key(): string {
		return 'enhanced-media-library';
	}

	public function label(): string {
		return 'Enhanced Media Library';
	}

	protected function taxonomy(): string {
		return 'media_category';
	}
}
