<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary\Migration;

use DoubleYoupi\Modules\MediaLibrary\Migration\Adapters\EnhancedMediaLibraryAdapter;
use DoubleYoupi\Modules\MediaLibrary\Migration\Adapters\FileBirdAdapter;
use DoubleYoupi\Modules\MediaLibrary\Migration\Adapters\FoldersPremioAdapter;
use DoubleYoupi\Modules\MediaLibrary\Migration\Adapters\HappyFilesAdapter;
use DoubleYoupi\Modules\MediaLibrary\Migration\Adapters\RealMediaLibraryAdapter;
use DoubleYoupi\Modules\MediaLibrary\Migration\Contracts\SourceAdapter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discovers the migration sources available on the current site.
 *
 * A source is "present" if its plugin is active OR if its residual data still
 * exists in the database (custom table or taxonomy terms).
 */
final class SourceDetector {

	/**
	 * All known adapters, in display order.
	 *
	 * @return SourceAdapter[]
	 */
	public static function all(): array {
		return [
			new FileBirdAdapter(),
			new HappyFilesAdapter(),
			new RealMediaLibraryAdapter(),
			new FoldersPremioAdapter(),
			new EnhancedMediaLibraryAdapter(),
		];
	}

	/**
	 * Only the sources that are present (active plugin or residual data).
	 *
	 * @return SourceAdapter[]
	 */
	public static function present(): array {
		return array_values( array_filter(
			self::all(),
			static fn( SourceAdapter $a ): bool => $a->is_present()
		) );
	}

	public static function has_any(): bool {
		foreach ( self::all() as $adapter ) {
			if ( $adapter->is_present() ) {
				return true;
			}
		}
		return false;
	}

	public static function get( string $key ): ?SourceAdapter {
		foreach ( self::all() as $adapter ) {
			if ( $adapter->key() === $key ) {
				return $adapter;
			}
		}
		return null;
	}
}
