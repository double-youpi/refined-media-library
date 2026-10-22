<?php declare(strict_types=1);
namespace DoubleYoupi\Modules\MediaLibrary\Integrations;

use DoubleYoupi\Modules\MediaLibrary\CollectionTaxonomy;
use DoubleYoupi\Modules\MediaLibrary\FolderTaxonomy;
use DoubleYoupi\Modules\MediaLibrary\ImageVariants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The media library for headless fronts (Astro, Next, Nuxt…): what a front
 * reads through the REST API and WPGraphQL.
 *
 *  - Folders and collections in WPGraphQL: `mediaFolders` (with their manual
 *    `order` and `parentDatabaseId`), `mediaCollections`, and both on each
 *    `MediaItem`. In REST they are already there (`/wp/v2/doubleyoupi-media-
 *    folders`, and the media filters of the same name).
 *  - The WebP and AVIF copies made on upload: `webpUrl`, `avifUrl`,
 *    `webpSrcSet`, `avifSrcSet` on `MediaItem`, and `doubleyoupi_formats` on
 *    REST media. Enough for a `<picture>` with modern sources.
 *  - Folder and collection names are readable by anyone, like categories.
 *    `doubleyoupi_filter_media_folders_public` (false) keeps them to the
 *    accounts that can upload files: REST answers 401 and GraphQL leaves the
 *    two taxonomies out.
 *
 * Only hooks: without WPGraphQL the GraphQL ones never fire. Booted once even
 * when Refined Admin Dashboard and Refined Media Library are both active.
 * Headless test of 21/10/2026: docs/strategy/TEST-HEADLESS-2026-10-21.md.
 */
final class HeadlessApi {

	/** GraphQL names, singular and plural, by taxonomy. */
	private const GRAPHQL_NAMES = [
		FolderTaxonomy::TAXONOMY     => [ 'mediaFolder', 'mediaFolders' ],
		CollectionTaxonomy::TAXONOMY => [ 'mediaCollection', 'mediaCollections' ],
	];

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_filter( 'register_taxonomy_args', [ self::class, 'taxonomy_args' ], 10, 2 );
		add_action( 'graphql_register_types', [ self::class, 'register_graphql_fields' ] );
		add_action( 'rest_api_init', [ self::class, 'register_rest_field' ] );
		add_filter( 'rest_pre_dispatch', [ self::class, 'guard_rest' ], 10, 3 );
		add_filter( 'rest_prepare_attachment', [ self::class, 'strip_private_terms' ], 10, 3 );
	}

	/**
	 * Whether folder and collection names may be read without an account.
	 */
	public static function folders_public(): bool {
		return (bool) apply_filters( 'doubleyoupi_filter_media_folders_public', true );
	}

	/**
	 * Declares both taxonomies to WPGraphQL. The names can be changed through
	 * `doubleyoupi_filter_media_graphql_names`, should another plugin already
	 * use them in the schema.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public static function taxonomy_args( array $args, string $taxonomy ): array {
		if ( ! isset( self::GRAPHQL_NAMES[ $taxonomy ] ) || ! self::folders_public() ) {
			return $args;
		}
		/** @var array<string, array{0:string, 1:string}> $names */
		$names = (array) apply_filters( 'doubleyoupi_filter_media_graphql_names', self::GRAPHQL_NAMES );
		$pair  = $names[ $taxonomy ] ?? self::GRAPHQL_NAMES[ $taxonomy ];

		$args['show_in_graphql']     = true;
		$args['graphql_single_name'] = (string) $pair[0];
		$args['graphql_plural_name'] = (string) $pair[1];
		return $args;
	}

	/**
	 * Fields of our own, registered once WPGraphQL builds its schema.
	 */
	public static function register_graphql_fields(): void {
		if ( ! function_exists( 'register_graphql_field' ) ) {
			return;
		}

		if ( self::folders_public() ) {
			$folder_type = ucfirst( (string) ( get_taxonomy( FolderTaxonomy::TAXONOMY )->graphql_single_name ?? 'mediaFolder' ) );
			register_graphql_field(
				$folder_type,
				'order',
				[
					'type'        => 'Int',
					'description' => 'Position of the folder among its siblings, as arranged in the media library (0 first).',
					'resolve'     => static fn( $term ): int => (int) get_term_meta( (int) $term->databaseId, FolderTaxonomy::META_ORDER, true ),
				]
			);
		}

		$formats = [
			'webp' => 'WebP',
			'avif' => 'AVIF',
		];
		foreach ( $formats as $format => $label ) {
			register_graphql_field(
				'MediaItem',
				$format . 'Url',
				[
					'type'        => 'String',
					'description' => sprintf( 'URL of the %s copy made on upload, at the given size (full size by default). Null when there is none.', $label ),
					'args'        => [
						'size' => [
							'type'        => 'MediaItemSizeEnum',
							'description' => 'Size of the image.',
						],
					],
					'resolve'     => static fn( $item, array $args ): ?string => ImageVariants::url( (int) $item->databaseId, $format, (string) ( $args['size'] ?? 'full' ) ),
				]
			);
			register_graphql_field(
				'MediaItem',
				$format . 'SrcSet',
				[
					'type'        => 'String',
					'description' => sprintf( 'srcset of the %s copies made on upload, for a <source type="image/%s"> element: the sizes in the proportions of the given size (full size by default). Null when there is none.', $label, $format ),
					'args'        => [
						'size' => [
							'type'        => 'MediaItemSizeEnum',
							'description' => 'Size whose proportions the srcset follows.',
						],
					],
					'resolve'     => static fn( $item, array $args ): ?string => ImageVariants::srcset( (int) $item->databaseId, $format, (string) ( $args['size'] ?? 'full' ) ),
				]
			);
		}
	}

	/**
	 * `doubleyoupi_formats` on REST media: the WebP and AVIF copies by size.
	 */
	public static function register_rest_field(): void {
		register_rest_field(
			'attachment',
			'doubleyoupi_formats',
			[
				'get_callback' => static fn( array $item ): object => (object) ImageVariants::all( (int) $item['id'] ),
				'schema'       => [
					'description' => 'WebP and AVIF copies made on upload, by format then by size name ("full" for the original), each with url, width and height.',
					'type'        => 'object',
					'context'     => [ 'view', 'edit', 'embed' ],
					'readonly'    => true,
				],
			]
		);
	}

	/**
	 * With folders kept private, the folder and collection routes answer only
	 * to accounts that can upload files.
	 *
	 * @param mixed $result
	 * @return mixed
	 */
	public static function guard_rest( $result, \WP_REST_Server $server, \WP_REST_Request $request ) {
		if ( null !== $result || self::folders_public() || current_user_can( 'upload_files' ) ) {
			return $result;
		}
		if ( ! preg_match( '#^/wp/v2/(doubleyoupi-media-folders|doubleyoupi-media-collections)(/|$)#', $request->get_route() ) ) {
			return $result;
		}
		return new \WP_Error(
			'rest_forbidden',
			__( 'Media folders and collections are private on this site.', 'doubleyoupi-refined-media-library' ),
			[ 'status' => rest_authorization_required_code() ]
		);
	}

	/**
	 * With folders kept private, REST media stop naming theirs to visitors.
	 *
	 * @param \WP_REST_Response $response
	 */
	public static function strip_private_terms( $response, \WP_Post $post, \WP_REST_Request $request ): \WP_REST_Response {
		if ( self::folders_public() || current_user_can( 'upload_files' ) ) {
			return $response;
		}
		$data = $response->get_data();
		unset( $data['doubleyoupi-media-folders'], $data['doubleyoupi-media-collections'] );
		$response->set_data( $data );
		foreach ( $response->get_links()['https://api.w.org/term'] ?? [] as $link ) {
			if ( in_array( $link['attributes']['taxonomy'] ?? '', [ FolderTaxonomy::TAXONOMY, CollectionTaxonomy::TAXONOMY ], true ) ) {
				$response->remove_link( 'https://api.w.org/term', $link['href'] );
			}
		}
		return $response;
	}
}
