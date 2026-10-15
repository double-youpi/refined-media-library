<?php declare(strict_types=1);
namespace DoubleYoupi\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ScreenOwner — which product does the current admin screen belong to?
 *
 * Used by the feedback button (see Feedback) so a report is filed against
 * the right thing: WordPress itself, one of the active plugins, the active
 * theme, or Double Youpi. Nothing here is a setting: the answer is a
 * heuristic on the menu tree and the active plugins, shown to the user, who
 * can correct it before sending.
 *
 * Strategy, in order:
 *   1. Our own screens (`?page=doubleyoupi…`) → Double Youpi.
 *   2. Core screens (no `page`, built-in post type / taxonomy) → WordPress.
 *   3. Known aliases: menu prefixes and post types that do not carry the
 *      product's name (`wc-` → WooCommerce, `wpseo` → Yoast, `ct-` → Blocksy…),
 *      applied only when that plugin or theme is active.
 *   4. Token match between the screen (page slug, its top-level parent, post
 *      type, taxonomy) and the active plugins / theme (directory slug, text
 *      domain, name).
 *   5. Otherwise WordPress, flagged as a guess so the UI asks the user.
 *
 * The matching itself (`match()`) is pure: no WordPress call, so it is
 * unit-testable. `resolve()` gathers the WordPress context around it.
 */
final class ScreenOwner {

	/**
	 * Menu-slug or post-type prefixes → product directory slug (plugin or
	 * theme). The hinted product must be ACTIVE for the alias to apply, so a
	 * short prefix like `e-` or `ct-` cannot capture an unrelated plugin.
	 *
	 * @var array<string,string>
	 */
	private const ALIASES = [
		// Plugins.
		'wc-'                 => 'woocommerce',
		'woocommerce'         => 'woocommerce',
		'product'             => 'woocommerce',
		'shop_'               => 'woocommerce',
		'wpseo'               => 'wordpress-seo',
		'rank-math'           => 'seo-by-rank-math',
		'elementor'           => 'elementor',
		'e-'                  => 'elementor',
		'wpforms'             => 'wpforms',
		'monsterinsights'     => 'google-analytics-for-wordpress',
		'litespeed'           => 'litespeed-cache',
		'wordfence'           => 'wordfence',
		'wfls'                => 'wordfence',
		'jetpack'             => 'jetpack',
		'my-jetpack'          => 'jetpack',
		'updraft'             => 'updraftplus',
		'wpcf7'               => 'contact-form-7',
		'mlang'               => 'polylang',
		'loco'                => 'loco-translate',
		'acf'                 => 'advanced-custom-fields',
		'cmb2'                => 'cmb2',
		// Themes (page slugs registered under Appearance or at top level).
		'astra'               => 'astra',
		'theme-builder-free'  => 'astra',
		'kadence'             => 'kadence',
		'ct-'                 => 'blocksy',
		'blocksy'             => 'blocksy',
		'generate'            => 'generatepress',
		'neve'                => 'neve',
		'ti-about-neve'       => 'neve',
		'ocean'               => 'oceanwp',
		'hello-elementor'     => 'hello-elementor',
		'bricks'              => 'bricks',
		'et_'                 => 'divi',
		'et-'                 => 'divi',
		'avada'               => 'avada',
		'fusion'              => 'avada',
	];

	/** Tokens too generic to identify a product. */
	private const STOP_WORDS = [
		'admin', 'page', 'pages', 'settings', 'setting', 'options', 'option', 'dashboard', 'edit', 'php',
		'post', 'posts', 'type', 'general', 'tools', 'tool', 'list', 'new', 'add', 'home', 'welcome',
		'about', 'pro', 'free', 'lite', 'plugin', 'plugins', 'theme', 'themes', 'panel', 'menu', 'the',
		'for', 'and', 'with', 'wordpress', 'site', 'builder', 'main', 'index', 'reports', 'report',
		'status', 'overview', 'help', 'support', 'system', 'info', 'log', 'logs', 'form', 'forms',
	];

	/** Built-in post types and taxonomies (their screens belong to WordPress). */
	private const CORE_POST_TYPES = [ 'post', 'page', 'attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'wp_global_styles' ];
	private const CORE_TAXONOMIES = [ 'category', 'post_tag', 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category' ];

	/**
	 * Resolves the owner of the current admin screen.
	 *
	 * @return array{owner:array{kind:string,slug:string,name:string,version:string,detected:bool,via:string},screen:array<string,string>,owners:array<int,array{kind:string,slug:string,name:string,version:string}>}
	 */
	public static function resolve(): array {
		$screen = self::screen_context();
		$owners = self::owners();
		$owner  = self::match( $screen, $owners );

		/**
		 * Filter: doubleyoupi_filter_feedback_owner
		 * Lets a module refine the detected owner of the current screen
		 * (the Admin module knows builder satellite pages, for instance).
		 *
		 * @param array $owner  Detected owner {kind, slug, name, version, detected, via}.
		 * @param array $screen Screen context {pagenow, page, post_type, taxonomy, parent, screenId, title, url}.
		 * @param array $owners Candidates (active plugins, theme, core, self).
		 */
		$owner = apply_filters( 'doubleyoupi_filter_feedback_owner', $owner, $screen, $owners );

		return [
			'owner'  => $owner,
			'screen' => $screen,
			'owners' => $owners,
		];
	}

	/**
	 * Pure matching between a screen context and a list of candidate owners.
	 *
	 * @param array<string,string> $screen {pagenow, page, post_type, taxonomy, parent}
	 * @param array<int,array{kind:string,slug:string,name:string,version:string}> $owners
	 * @return array{kind:string,slug:string,name:string,version:string,detected:bool,via:string}
	 */
	public static function match( array $screen, array $owners ): array {
		$page      = strtolower( (string) ( $screen['page'] ?? '' ) );
		$post_type = strtolower( (string) ( $screen['post_type'] ?? '' ) );
		$taxonomy  = strtolower( (string) ( $screen['taxonomy'] ?? '' ) );
		$parent    = strtolower( (string) ( $screen['parent'] ?? '' ) );
		$pagenow   = strtolower( (string) ( $screen['pagenow'] ?? '' ) );

		$self = self::find( $owners, 'doubleyoupi' );
		$core = self::find( $owners, 'core' );

		// 1. Our own screens.
		if ( '' !== $page && str_starts_with( $page, 'doubleyoupi' ) && null !== $self ) {
			return self::result( $self, true, 'self' );
		}

		// 2. Core screens: no page slug, built-in (or no) post type / taxonomy.
		$core_pt  = '' === $post_type || in_array( $post_type, self::CORE_POST_TYPES, true );
		$core_tax = '' === $taxonomy || in_array( $taxonomy, self::CORE_TAXONOMIES, true );
		if ( '' === $page && $core_pt && $core_tax && null !== $core ) {
			return self::result( $core, true, 'core' );
		}

		// 3 + 4. Aliases, then token match, on the most specific candidates first.
		$candidates = array_values( array_filter( [ $page, $post_type, $taxonomy, $parent ], static fn( $c ) => '' !== $c ) );
		foreach ( $candidates as $candidate ) {
			$hit = self::alias( $candidate, $owners );
			if ( null !== $hit ) {
				return self::result( $hit, true, 'alias' );
			}
		}
		$best = null;
		$best_score = 0;
		foreach ( $candidates as $candidate ) {
			foreach ( $owners as $owner ) {
				if ( 'plugin' !== $owner['kind'] && 'theme' !== $owner['kind'] ) {
					continue;
				}
				$score = self::score( $candidate, $owner );
				if ( $score > $best_score ) {
					$best_score = $score;
					$best       = $owner;
				}
			}
			if ( $best_score >= 100 ) {
				break;
			}
		}
		if ( null !== $best && $best_score >= 40 ) {
			return self::result( $best, true, 'match' );
		}

		// 5. Unknown page slug: WordPress, but flagged as a guess.
		$fallback = $core ?? $self ?? [ 'kind' => 'core', 'slug' => 'wordpress', 'name' => 'WordPress', 'version' => '' ];
		return self::result( $fallback, '' === $page && 'admin.php' !== $pagenow, 'fallback' );
	}

	/** @param array<int,array{kind:string,slug:string,name:string,version:string}> $owners */
	private static function find( array $owners, string $kind ): ?array {
		foreach ( $owners as $owner ) {
			if ( $owner['kind'] === $kind ) {
				return $owner;
			}
		}
		return null;
	}

	private static function result( array $owner, bool $detected, string $via ): array {
		return [
			'kind'     => (string) $owner['kind'],
			'slug'     => (string) $owner['slug'],
			'name'     => (string) $owner['name'],
			'version'  => (string) ( $owner['version'] ?? '' ),
			'detected' => $detected,
			'via'      => $via,
		];
	}

	/** Alias lookup: the hinted product must be among the active owners. */
	private static function alias( string $candidate, array $owners ): ?array {
		foreach ( self::ALIASES as $prefix => $hint ) {
			if ( $candidate !== $prefix && ! str_starts_with( $candidate, $prefix ) ) {
				continue;
			}
			foreach ( $owners as $owner ) {
				if ( 'plugin' !== $owner['kind'] && 'theme' !== $owner['kind'] ) {
					continue;
				}
				$slug = strtolower( (string) $owner['slug'] );
				if ( $slug === $hint || str_starts_with( $slug, $hint ) ) {
					return $owner;
				}
			}
		}
		return null;
	}

	/**
	 * Similarity between a screen candidate (page slug, post type…) and an
	 * owner: 100 = the candidate is the product's slug (or starts with it),
	 * 60 = a shared distinctive token, 40 = a shared prefix of 5+ characters.
	 */
	private static function score( string $candidate, array $owner ): int {
		$slug = strtolower( (string) $owner['slug'] );
		if ( '' === $slug ) {
			return 0;
		}
		if ( $candidate === $slug || ( strlen( $slug ) >= 4 && str_starts_with( $candidate, $slug ) ) ) {
			return 100;
		}
		$cand_tokens  = self::tokens( $candidate );
		$owner_tokens = array_unique( array_merge(
			self::tokens( $slug ),
			self::tokens( (string) ( $owner['text_domain'] ?? '' ) ),
			self::tokens( (string) $owner['name'] )
		) );
		$best = 0;
		foreach ( $cand_tokens as $ct ) {
			foreach ( $owner_tokens as $ot ) {
				if ( $ct === $ot ) {
					$best = max( $best, 60 );
				} elseif ( strlen( $ct ) >= 5 && strlen( $ot ) >= 5 && ( str_starts_with( $ct, $ot ) || str_starts_with( $ot, $ct ) ) ) {
					$best = max( $best, 40 );
				}
			}
		}
		return $best;
	}

	/** @return string[] */
	private static function tokens( string $s ): array {
		$parts = preg_split( '/[^a-z0-9]+/', strtolower( $s ) ) ?: [];
		$out   = [];
		foreach ( $parts as $p ) {
			if ( strlen( $p ) < 3 || in_array( $p, self::STOP_WORDS, true ) || ctype_digit( $p ) ) {
				continue;
			}
			$out[] = $p;
		}
		return $out;
	}

	// =====================================================================
	//  WordPress context
	// =====================================================================

	/**
	 * Candidate owners: Double Youpi (this product), WordPress, the active
	 * theme (parent + child) and the active plugins, sorted by name.
	 *
	 * @return array<int,array{kind:string,slug:string,name:string,version:string,text_domain?:string}>
	 */
	public static function owners(): array {
		$product = Feedback::product();
		$owners  = [
			[ 'kind' => 'doubleyoupi', 'slug' => $product['slug'], 'name' => $product['name'], 'version' => $product['version'] ],
			[ 'kind' => 'core', 'slug' => 'wordpress', 'name' => 'WordPress', 'version' => (string) get_bloginfo( 'version' ) ],
		];

		$theme = wp_get_theme();
		if ( $theme->exists() ) {
			$owners[] = [
				'kind'        => 'theme',
				'slug'        => (string) $theme->get_template(),
				'name'        => (string) $theme->get( 'Name' ),
				'version'     => (string) $theme->get( 'Version' ),
				'text_domain' => (string) $theme->get( 'TextDomain' ),
			];
			if ( $theme->get_stylesheet() !== $theme->get_template() ) {
				// Child theme: listed as its own entry, the parent stays first.
				$owners[] = [
					'kind'        => 'theme',
					'slug'        => (string) $theme->get_stylesheet(),
					'name'        => (string) $theme->get( 'Name' ),
					'version'     => (string) $theme->get( 'Version' ),
					'text_domain' => (string) $theme->get( 'TextDomain' ),
				];
				$parent = $theme->parent();
				if ( $parent instanceof \WP_Theme ) {
					$owners[2]['name']    = (string) $parent->get( 'Name' );
					$owners[2]['version'] = (string) $parent->get( 'Version' );
				}
			}
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = [];
		foreach ( get_plugins() as $basename => $data ) {
			if ( ! is_plugin_active( $basename ) ) {
				continue;
			}
			if ( defined( 'DOUBLEYOUPI_BASENAME' ) && DOUBLEYOUPI_BASENAME === $basename ) {
				continue; // this product is already listed as "doubleyoupi"
			}
			$dir  = str_contains( $basename, '/' ) ? dirname( $basename ) : basename( $basename, '.php' );
			$plugins[] = [
				'kind'        => 'plugin',
				'slug'        => $dir,
				'name'        => wp_strip_all_tags( (string) ( $data['Name'] ?? $dir ) ),
				'version'     => (string) ( $data['Version'] ?? '' ),
				'text_domain' => (string) ( $data['TextDomain'] ?? '' ),
			];
		}
		usort( $plugins, static fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );

		return array_merge( $owners, $plugins );
	}

	/**
	 * The current screen, reduced to what identifies it. Read-only navigation
	 * state (no nonce: nothing is processed here).
	 *
	 * @return array<string,string>
	 */
	public static function screen_context(): array {
		global $pagenow, $submenu, $title;
		$get = static function ( string $key ): string {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation/display state; not form processing.
			return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
		};
		$page      = $get( 'page' );
		$post_type = $get( 'post_type' );
		$taxonomy  = $get( 'taxonomy' );
		$base      = is_string( $pagenow ) ? $pagenow : '';

		// The menu entry this screen corresponds to, then its top-level parent.
		if ( '' !== $page ) {
			$key = $page;
		} elseif ( 'edit.php' === $base && '' !== $post_type ) {
			$key = 'edit.php?post_type=' . $post_type;
		} elseif ( 'edit-tags.php' === $base && '' !== $taxonomy ) {
			$key = 'edit-tags.php?taxonomy=' . $taxonomy;
		} else {
			$key = $base;
		}
		$parent = '';
		if ( is_array( $submenu ) ) {
			foreach ( $submenu as $parent_slug => $items ) {
				foreach ( (array) $items as $item ) {
					$slug = isset( $item[2] ) ? (string) $item[2] : '';
					if ( $slug === $key || ( '' !== $page && str_starts_with( $slug, $key . '&' ) ) ) {
						$parent = (string) $parent_slug;
						break 2;
					}
				}
			}
		}

		$request = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		$path    = (string) wp_parse_url( $request, PHP_URL_PATH );
		$query   = (string) wp_parse_url( $request, PHP_URL_QUERY );
		$url     = self::clean_url( $path, $query );

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$label  = is_string( $title ) && '' !== $title ? $title : (string) get_admin_page_title();

		return [
			'pagenow'   => $base,
			'page'      => $page,
			'post_type' => $post_type,
			'taxonomy'  => $taxonomy,
			'parent'    => $parent,
			'screenId'  => $screen instanceof \WP_Screen ? (string) $screen->id : '',
			'title'     => wp_strip_all_tags( $label ),
			'url'       => $url,
		];
	}

	/**
	 * Admin-relative address of the screen, without nonces and with the
	 * search term dropped (it may contain personal data). The host is never
	 * part of it.
	 *
	 * @param string      $path       Path of the screen's URL.
	 * @param string      $query      Its query string.
	 * @param string|null $admin_path Path of admin_url(); read from it when null
	 *                                (WordPress may live in a subdirectory).
	 */
	public static function clean_url( string $path, string $query, ?string $admin_path = null ): string {
		$path  = '/' . ltrim( $path, '/' );
		$admin = trim( null === $admin_path ? (string) wp_parse_url( admin_url(), PHP_URL_PATH ) : $admin_path, '/' );
		if ( '' !== $admin && str_starts_with( $path, '/' . $admin . '/' ) ) {
			$path = substr( $path, strlen( '/' . $admin ) );
		}
		$params = [];
		if ( '' !== $query ) {
			parse_str( $query, $params );
		}
		foreach ( array_keys( $params ) as $k ) {
			$lk = strtolower( (string) $k );
			if ( str_contains( $lk, 'nonce' ) || 's' === $lk || 'search' === $lk || str_starts_with( $lk, '_wp' ) ) {
				unset( $params[ $k ] );
			}
		}
		$out = $path;
		if ( ! empty( $params ) ) {
			$out .= '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
		}
		return substr( $out, 0, 300 );
	}
}
