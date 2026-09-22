<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FeedbackOwner — tells the feedback tab that the media screens are ours.
 *
 * The feedback dialog (`src/Admin/Feedback.php`) names the product a report
 * is about, resolved by `ScreenOwner`. The media library is not one of our
 * own pages: it is `upload.php`, a WordPress screen we enhance. ScreenOwner
 * therefore files those reports under "WordPress", and a bug about folders
 * or collections arrives with core's name on it.
 *
 * This claims them back through the public filter, the way the Admin module
 * claims a page builder's satellite pages. No module calls into another:
 * whoever ships the feedback dialog (the suite, or Refined Admin Dashboard
 * installed alongside) raises the filter, and we answer for our screens.
 * If the media library module is not running, nothing is registered and the
 * screens stay WordPress's, which is then the truth.
 */
final class FeedbackOwner {

	/** Screens the media library owns, by `pagenow`. */
	private const SCREENS = [ 'upload.php', 'media-new.php' ];

	public function boot(): void {
		add_filter( 'doubleyoupi_filter_feedback_owner', [ self::class, 'claim' ], 10, 3 );
	}

	/**
	 * @param array<string,mixed>              $owner  Owner detected so far.
	 * @param array<string,string>             $screen Screen context.
	 * @param array<int,array<string,string>>  $owners Candidates (plugins, theme, core, self).
	 * @return array<string,mixed>
	 */
	public static function claim( array $owner, array $screen, array $owners ): array {
		if ( ! self::is_ours( $screen ) ) {
			return $owner;
		}
		$mine = self::product( $owners );
		if ( null === $mine ) {
			return $owner;
		}
		return [
			'kind'     => (string) $mine['kind'],
			'slug'     => (string) $mine['slug'],
			'name'     => (string) $mine['name'],
			'version'  => (string) ( $mine['version'] ?? '' ),
			'detected' => true,
			'via'      => 'media-library',
		];
	}

	/** @param array<string,string> $screen */
	private static function is_ours( array $screen ): bool {
		$pagenow = strtolower( (string) ( $screen['pagenow'] ?? '' ) );
		if ( in_array( $pagenow, self::SCREENS, true ) ) {
			return true;
		}
		// A single attachment, edited on its own screen.
		if ( 'post.php' === $pagenow && 'attachment' === strtolower( (string) ( $screen['post_type'] ?? '' ) ) ) {
			return true;
		}
		// Our two taxonomies, listed on edit-tags.php.
		$taxonomy = (string) ( $screen['taxonomy'] ?? '' );
		return in_array( $taxonomy, [ FolderTaxonomy::TAXONOMY, CollectionTaxonomy::TAXONOMY ], true );
	}

	/**
	 * The product to file the report under: the standalone Refined Media
	 * Library when it is the one installed, otherwise the product that runs
	 * this module (the suite, or the standalone itself, which ScreenOwner
	 * already lists as `doubleyoupi`).
	 *
	 * @param array<int,array<string,string>> $owners
	 * @return array<string,string>|null
	 */
	private static function product( array $owners ): ?array {
		foreach ( $owners as $candidate ) {
			if ( 'plugin' !== ( $candidate['kind'] ?? '' ) ) {
				continue;
			}
			if ( str_contains( strtolower( (string) ( $candidate['slug'] ?? '' ) ), 'refined-media-library' ) ) {
				return $candidate;
			}
		}
		foreach ( $owners as $candidate ) {
			if ( 'doubleyoupi' === ( $candidate['kind'] ?? '' ) ) {
				return $candidate;
			}
		}
		return null;
	}
}
