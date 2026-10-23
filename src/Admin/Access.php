<?php declare(strict_types=1);
namespace DoubleYoupi\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Access — who may change Double Youpï (23/10/2026, asked by agencies).
 *
 * One capability, `doubleyoupi_manage`, guards every Double Youpï surface:
 * its menu and pages, the settings of Refined Admin Dashboard and Refined
 * Media Library, the profile import, the site-wide dashboard layout, the
 * Feedback button and the review requests. Nobody stores it: it is granted
 * on the fly to administrators (`manage_options`).
 *
 * One site setting narrows it: "Only me" keeps it for one account. The other
 * administrators keep the redesigned admin, without its settings. Nothing is
 * hidden from the Plugins screen: the plugin stays listed and can be
 * deactivated like any other.
 *
 * Never a lock-out: an owner who no longer exists, or is no longer an
 * administrator, hands the settings back to every administrator, and
 * `define( 'DOUBLEYOUPI_UNLOCK_SETTINGS', true )` in wp-config.php does the
 * same. An agency that prefers its own rule (a role, a capability) answers
 * the `doubleyoupi_filter_can_manage` filter.
 *
 * What stays on `manage_options` on purpose: what serves the site rather than
 * Double Youpï (backup and disk widgets, quick links to WordPress settings,
 * the automatic learning of light plugin screens).
 */
final class Access {

	public const CAP          = 'doubleyoupi_manage';
	public const OPTION_OWNER = 'doubleyoupi_settings_owner';
	public const UNLOCK       = 'DOUBLEYOUPI_UNLOCK_SETTINGS';

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_filter( 'user_has_cap', [ self::class, 'grant' ], 10, 4 );
	}

	/**
	 * The account the settings are kept for, or 0 for every administrator.
	 * A stale owner (deleted, or no longer an administrator) counts as 0.
	 */
	public static function owner_id(): int {
		if ( defined( self::UNLOCK ) && constant( self::UNLOCK ) ) {
			return 0;
		}
		$id = absint( get_option( self::OPTION_OWNER, 0 ) );
		if ( ! $id ) {
			return 0;
		}
		$user = get_userdata( $id );
		if ( ! $user || empty( $user->allcaps['manage_options'] ) ) {
			return 0;
		}
		return $id;
	}

	/** Whether the current user may change Double Youpï. */
	public static function can_manage(): bool {
		return current_user_can( self::CAP );
	}

	/**
	 * `user_has_cap`: computes `doubleyoupi_manage` when it is asked for.
	 *
	 * @param array<string,bool> $allcaps
	 * @param string[]           $caps
	 * @param array<int,mixed>   $args
	 * @param \WP_User|null      $user
	 * @return array<string,bool>
	 */
	public static function grant( $allcaps, $caps, $args, $user = null ): array {
		$allcaps = (array) $allcaps;
		if ( ! in_array( self::CAP, (array) $caps, true ) || ! $user instanceof \WP_User ) {
			return $allcaps;
		}
		$owner   = self::owner_id();
		$allowed = ! empty( $allcaps['manage_options'] ) && ( 0 === $owner || $owner === (int) $user->ID );
		/**
		 * Filter: doubleyoupi_filter_can_manage
		 *
		 * @param bool     $allowed Whether this user may change Double Youpï.
		 * @param \WP_User $user    The user being checked.
		 */
		$allcaps[ self::CAP ] = (bool) apply_filters( 'doubleyoupi_filter_can_manage', $allowed, $user );
		return $allcaps;
	}
}
