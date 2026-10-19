<?php declare(strict_types=1);
namespace DoubleYoupi\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feedback — "send feedback about this screen" from anywhere in wp-admin.
 *
 * A small Double Youpi purple tab, stuck to the bottom-right edge of every
 * admin screen, opens a dialog pre-filled with the current screen and the
 * product it belongs to (WordPress, a plugin, the theme or Double Youpi,
 * see ScreenOwner; detected and reported, never asked). The
 * user writes a message, may attach a screenshot, and sends. The report
 * goes to Double Youpi's feedback queue through THIS site's REST API, which
 * validates it, adds the environment facts and relays it to the endpoint.
 *
 * Nothing leaves the site without an explicit action AND an explicit
 * consent checkbox on every report (WordPress.org guideline: no data sent
 * to an external service without the user's knowledge). What is sent is
 * listed in the dialog and can be previewed as JSON before sending. The
 * site address is never included unless the user asks for a reply.
 *
 * No setting: the button shows for users who can manage the site (or hold
 * the `doubleyoupi_send_feedback` capability). A host can turn it off with
 * `define( 'DOUBLEYOUPI_FEEDBACK_ENDPOINT', '' )` or the
 * `doubleyoupi_filter_feedback_enabled` filter.
 *
 * Endpoint contract: docs/design/admin-ui/12-bouton-retour.md.
 */
final class Feedback {

	public const REST_NAMESPACE   = 'doubleyoupi/v1';
	public const REST_ROUTE       = '/feedback';
	public const CAPABILITY       = 'doubleyoupi_send_feedback';
	public const NODE_ID          = 'doubleyoupi-feedback';
	public const DEFAULT_ENDPOINT = 'https://doubleyoupi.com/api/v1/feedback';
	public const PAYLOAD_VERSION  = 1;

	private const MAX_MESSAGE     = 5000;
	private const MAX_SCREENSHOT  = 1500000; // bytes of data URL (~1.1 MB of image)
	private const RATE_LIMIT      = 10;      // reports per user per hour
	private const TYPES           = [ 'bug', 'idea', 'other' ];

	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'register_rest' ] );
		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_action( 'admin_footer', [ self::class, 'render_dialog' ] );
	}

	// =====================================================================
	//  Availability
	// =====================================================================

	/** Endpoint URL; empty string disables the whole feature. */
	public static function endpoint(): string {
		$endpoint = defined( 'DOUBLEYOUPI_FEEDBACK_ENDPOINT' ) ? (string) DOUBLEYOUPI_FEEDBACK_ENDPOINT : self::DEFAULT_ENDPOINT;
		/**
		 * Filter: doubleyoupi_filter_feedback_endpoint
		 * Where reports are relayed. Return '' to disable the feature.
		 *
		 * @param string $endpoint Absolute URL.
		 */
		$endpoint = (string) apply_filters( 'doubleyoupi_filter_feedback_endpoint', $endpoint );
		return '' === $endpoint ? '' : esc_url_raw( $endpoint );
	}

	public static function user_can(): bool {
		return current_user_can( self::CAPABILITY ) || current_user_can( 'manage_options' );
	}

	public static function is_available(): bool {
		if ( '' === self::endpoint() || ! self::user_can() ) {
			return false;
		}
		global $pagenow;
		// The Customizer and the builders hide the admin bar: nothing to attach to.
		if ( 'customize.php' === $pagenow ) {
			return false;
		}
		/**
		 * Filter: doubleyoupi_filter_feedback_enabled
		 *
		 * @param bool $enabled Whether the feedback button is shown on this request.
		 */
		return (bool) apply_filters( 'doubleyoupi_filter_feedback_enabled', true );
	}

	/**
	 * The product the report is sent from: the solo plugin or the suite.
	 *
	 * @return array{slug:string,name:string,version:string,build:string}
	 */
	public static function product(): array {
		if ( class_exists( '\\DoubleYoupi\\Core\\SoloConfig' ) ) {
			return [
				'slug'    => (string) \DoubleYoupi\Core\SoloConfig::text_domain(),
				'name'    => (string) \DoubleYoupi\Core\SoloConfig::plugin_name(),
				'version' => (string) \DoubleYoupi\Core\SoloConfig::version(),
				'build'   => 'solo',
			];
		}
		return [
			'slug'    => 'doubleyoupi',
			'name'    => 'Double Youpi',
			'version' => defined( 'DOUBLEYOUPI_VERSION' ) ? (string) DOUBLEYOUPI_VERSION : '',
			'build'   => 'suite',
		];
	}

	/**
	 * WordPress.org review address of this build (`support.review_url` in the
	 * build config, read through the generated SoloConfig). Empty in the suite,
	 * which has no WordPress.org page: no review link there.
	 */
	public static function review_url(): string {
		$url = '';
		if ( class_exists( '\\DoubleYoupi\\Core\\SoloConfig' ) && method_exists( '\\DoubleYoupi\\Core\\SoloConfig', 'support' ) ) {
			$support = (array) \DoubleYoupi\Core\SoloConfig::support();
			$url     = (string) ( $support['review_url'] ?? '' );
		}
		return '' === $url ? '' : esc_url_raw( $url );
	}

	/**
	 * The "Leave a review" link beside the Feedback tab (19/10/2026): for
	 * administrators only, wherever the tab shows, never in the suite. A
	 * demo (WordPress Playground's Live Preview) turns it off with
	 * `add_filter( 'doubleyoupi_filter_review_link_enabled', '__return_false' )`.
	 */
	public static function review_link_available(): bool {
		if ( ! self::is_available() || ! current_user_can( 'manage_options' ) || '' === self::review_url() ) {
			return false;
		}
		/**
		 * Filter: doubleyoupi_filter_review_link_enabled
		 *
		 * @param bool $enabled Whether the review link is shown on this request.
		 */
		return (bool) apply_filters( 'doubleyoupi_filter_review_link_enabled', true );
	}

	// =====================================================================
	//  Admin bar + assets + dialog
	// =====================================================================

	/**
	 * The tab: bottom-right, Double Youpi purple, rounded top corners. Printed
	 * with the dialog in the footer; under 782px only the mark remains.
	 */
	private static function render_tab(): void {
		?>
<div class="dy-feedback-dock">
		<?php if ( self::review_link_available() ) : ?>
<button type="button" class="dy-review-tab" id="dy-review-tab" aria-haspopup="dialog" aria-controls="dy-review">
	<svg class="dy-review-tab__icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
	<span class="dy-review-tab__label"><?php esc_html_e( 'Leave a review', 'doubleyoupi-refined-media-library' ); ?></span>
</button>
		<?php endif; ?>
<button type="button" class="dy-feedback-tab" id="dy-feedback-tab" aria-haspopup="dialog" aria-controls="dy-feedback" title="<?php echo esc_attr( self::title() ); ?>">
	<span class="dy-feedback-tab__icon" aria-hidden="true"><?php echo wp_kses( self::brand_icon(), Kses::svg_tags() ); ?></span>
	<span class="dy-feedback-tab__label"><?php echo esc_html( _x( 'Feedback', 'admin bar button', 'doubleyoupi-refined-media-library' ) ); ?></span>
</button>
</div>
		<?php
	}

	/**
	 * The review dialog: one sentence and a link to the WordPress.org review
	 * form, in a new tab. Nothing is counted or sent, nothing comes back.
	 */
	private static function render_review_dialog(): void {
		if ( ! self::review_link_available() ) {
			return;
		}
		$text = sprintf(
			/* translators: %s = label of the Feedback button (e.g. "Feedback") */
			__( 'If you like the plugin, two lines on WordPress.org help it a lot. If something is wrong, tell us with “%s” instead: it is quicker to fix.', 'doubleyoupi-refined-media-library' ),
			_x( 'Feedback', 'admin bar button', 'doubleyoupi-refined-media-library' )
		);
		?>
<dialog class="dy-feedback dy-feedback--review" id="dy-review" aria-labelledby="dy-review-title">
	<div class="dy-feedback__form">
		<header class="dy-feedback__head">
			<h2 class="dy-feedback__title" id="dy-review-title"><?php esc_html_e( 'Leave a review', 'doubleyoupi-refined-media-library' ); ?></h2>
			<button type="button" class="dy-feedback__close" data-dy-review-close aria-label="<?php esc_attr_e( 'Close', 'doubleyoupi-refined-media-library' ); ?>">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
			</button>
		</header>
		<div class="dy-feedback__body">
			<p class="dy-review__text"><?php echo esc_html( $text ); ?></p>
		</div>
		<footer class="dy-feedback__foot">
			<button type="button" class="dy-feedback__cancel" data-dy-review-close><?php esc_html_e( 'Close', 'doubleyoupi-refined-media-library' ); ?></button>
			<a class="dy-feedback__send" href="<?php echo esc_url( self::review_url() ); ?>" target="_blank" rel="noopener noreferrer" data-dy-review-go>
				<?php esc_html_e( 'Write a review on WordPress.org', 'doubleyoupi-refined-media-library' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'doubleyoupi-refined-media-library' ); ?></span>
			</a>
		</footer>
	</div>
</dialog>
		<?php
	}

	/** "Your feedback on Refined Admin Dashboard": the button and the dialog say what the feedback is about. */
	private static function title(): string {
		/* translators: %s = product name (e.g. "Refined Admin Dashboard") */
		return sprintf( __( 'Your feedback on %s', 'doubleyoupi-refined-media-library' ), self::product()['name'] );
	}

	/**
	 * The Double Youpi mark (assets/img/icon-doubleyoupi.svg) inlined in the
	 * tab so the button reads as ours; fill follows the text colour. Falls
	 * back to a speech bubble.
	 */
	private static function brand_icon(): string {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$fallback = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 21l1.9-5.4A8 8 0 1 1 21 12Z"/></svg>';
		$path     = DOUBLEYOUPI_DIR . 'assets/img/icon-doubleyoupi.svg';
		$svg      = file_exists( $path ) ? (string) file_get_contents( $path ) : '';
		if ( '' === $svg || ! str_starts_with( ltrim( $svg ), '<svg' ) ) {
			$cache = $fallback;
			return $cache;
		}
		$svg = preg_replace( '/\sfill="#[0-9a-fA-F]+"/', ' fill="currentColor"', $svg ) ?: $svg;
		$svg = preg_replace( '/\s(width|height)="[^"]*"/', '', $svg, 2 ) ?: $svg;
		$svg = preg_replace( '/^<svg/', '<svg width="18" height="15" aria-hidden="true" focusable="false"', ltrim( $svg ), 1 ) ?: $svg;
		$cache = $svg;
		return $cache;
	}

	public static function enqueue(): void {
		if ( ! self::is_available() ) {
			return;
		}
		$css = DOUBLEYOUPI_DIR . 'assets/css/admin-feedback.css';
		$js  = DOUBLEYOUPI_DIR . 'assets/js/feedback.js';
		if ( ! file_exists( $css ) || ! file_exists( $js ) ) {
			return;
		}
		wp_enqueue_style(
			'doubleyoupi-feedback',
			DOUBLEYOUPI_URL . 'assets/css/admin-feedback.css',
			[],
			(string) ( filemtime( $css ) ?: ( defined( 'DOUBLEYOUPI_VERSION' ) ? DOUBLEYOUPI_VERSION : '1' ) )
		);
		wp_enqueue_script(
			'doubleyoupi-feedback',
			DOUBLEYOUPI_URL . 'assets/js/feedback.js',
			[],
			(string) ( filemtime( $js ) ?: ( defined( 'DOUBLEYOUPI_VERSION' ) ? DOUBLEYOUPI_VERSION : '1' ) ),
			true
		);

		$resolved = ScreenOwner::resolve();
		$user     = wp_get_current_user();
		$theme    = wp_get_theme();
		$product  = self::product();

		wp_localize_script(
			'doubleyoupi-feedback',
			'doubleyoupiFeedback',
			[
				'rest'       => esc_url_raw( rest_url( self::REST_NAMESPACE . self::REST_ROUTE ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'screen'     => $resolved['screen'],
				'owner'      => $resolved['owner'],
				'product'    => $product,
				'env'        => [
					'wp'        => (string) get_bloginfo( 'version' ),
					'php'       => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
					'locale'    => get_user_locale(),
					'multisite' => is_multisite(),
					'theme'     => [
						'slug'    => (string) $theme->get_stylesheet(),
						'name'    => (string) $theme->get( 'Name' ),
						'version' => (string) $theme->get( 'Version' ),
					],
				],
				'email'      => (string) $user->user_email,
				'maxMessage' => self::MAX_MESSAGE,
				'maxShot'    => self::MAX_SCREENSHOT,
				'i18n'       => [
					'send'          => __( 'Send', 'doubleyoupi-refined-media-library' ),
					'sending'       => __( 'Sending…', 'doubleyoupi-refined-media-library' ),
					'retry'         => __( 'Try again', 'doubleyoupi-refined-media-library' ),
					/* translators: %s = name of the theme */
					'themeSuffix'   => __( '%s (theme)', 'doubleyoupi-refined-media-library' ),
					'shotTooLarge'  => __( 'This image is still too large after compression (1.5 MB maximum).', 'doubleyoupi-refined-media-library' ),
					'shotBadType'   => __( 'Please use a PNG, JPEG or WebP image.', 'doubleyoupi-refined-media-library' ),
					'networkError'  => __( 'Your report could not be sent. Check your connection and try again.', 'doubleyoupi-refined-media-library' ),
					/* translators: %s = reference returned by the feedback service */
					'reference'     => __( 'Reference %s.', 'doubleyoupi-refined-media-library' ),
					'anonymousSite' => __( '(anonymous site identifier)', 'doubleyoupi-refined-media-library' ),
				],
			]
		);
	}

	/**
	 * The tab and the dialog shell, printed once in the footer (outside the
	 * scrollable content, so the top layer works everywhere).
	 */
	public static function render_dialog(): void {
		if ( ! self::is_available() ) {
			return;
		}
		self::render_tab();
		self::render_review_dialog();
		$product = self::product();
		$version = '' !== $product['version'] ? $product['name'] . ' ' . $product['version'] : $product['name'];
		?>
<dialog class="dy-feedback" id="dy-feedback" aria-labelledby="dy-feedback-title">
	<form class="dy-feedback__form" id="dy-feedback-form" novalidate>
		<header class="dy-feedback__head">
			<h2 class="dy-feedback__title" id="dy-feedback-title"><?php echo esc_html( self::title() ); ?></h2>
			<button type="button" class="dy-feedback__close" id="dy-feedback-close" aria-label="<?php esc_attr_e( 'Close', 'doubleyoupi-refined-media-library' ); ?>">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
			</button>
		</header>
		<div class="dy-feedback__body" id="dy-feedback-body">
			<div class="dy-feedback__context">
				<span class="dy-feedback__eyebrow"><?php esc_html_e( 'This screen', 'doubleyoupi-refined-media-library' ); ?></span>
				<div class="dy-feedback__screen"><strong id="dy-feedback-screen"></strong> <code id="dy-feedback-path"></code></div>
			</div>
			<fieldset class="dy-feedback__type" id="dy-feedback-type">
				<legend class="screen-reader-text"><?php esc_html_e( 'What kind of feedback', 'doubleyoupi-refined-media-library' ); ?></legend>
				<label><input type="radio" name="dy-feedback-type" value="bug" checked><span><?php esc_html_e( 'Something is wrong', 'doubleyoupi-refined-media-library' ); ?></span></label>
				<label><input type="radio" name="dy-feedback-type" value="idea"><span><?php esc_html_e( 'An idea', 'doubleyoupi-refined-media-library' ); ?></span></label>
				<label><input type="radio" name="dy-feedback-type" value="other"><span><?php esc_html_e( 'Something else', 'doubleyoupi-refined-media-library' ); ?></span></label>
			</fieldset>
			<label class="dy-feedback__field">
				<span class="dy-feedback__label"><?php esc_html_e( 'Your message', 'doubleyoupi-refined-media-library' ); ?></span>
				<textarea id="dy-feedback-message" rows="5" maxlength="<?php echo (int) self::MAX_MESSAGE; ?>" required placeholder="<?php esc_attr_e( 'What happened, and what you expected.', 'doubleyoupi-refined-media-library' ); ?>"></textarea>
				<span class="dy-feedback__count"><span id="dy-feedback-count">0</span>/<?php echo (int) self::MAX_MESSAGE; ?></span>
			</label>
			<div class="dy-feedback__shot">
				<label class="dy-feedback__shot-add">
					<input type="file" id="dy-feedback-file" accept="image/png,image/jpeg,image/webp">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 15-5-5-9 9"/></svg>
					<span><?php esc_html_e( 'Attach a screenshot', 'doubleyoupi-refined-media-library' ); ?></span>
				</label>
				<span class="dy-feedback__shot-hint"><?php esc_html_e( 'or paste an image here (Ctrl/Cmd + V)', 'doubleyoupi-refined-media-library' ); ?></span>
				<div class="dy-feedback__shot-preview" id="dy-feedback-shot" hidden>
					<img alt="" id="dy-feedback-shot-img">
					<button type="button" class="dy-feedback__shot-remove" id="dy-feedback-shot-remove"><?php esc_html_e( 'Remove', 'doubleyoupi-refined-media-library' ); ?></button>
				</div>
			</div>
			<label class="dy-feedback__check">
				<input type="checkbox" id="dy-feedback-contact">
				<span><?php esc_html_e( 'You may write back to me at', 'doubleyoupi-refined-media-library' ); ?></span>
				<input type="email" id="dy-feedback-email" class="dy-feedback__email" autocomplete="email">
			</label>
			<label class="dy-feedback__check dy-feedback__consent">
				<input type="checkbox" id="dy-feedback-consent">
				<span><?php esc_html_e( 'I agree to send this report to Double Youpï: my message, the screenshot if any, this screen\'s address inside wp-admin, the plugin or theme it belongs to, and version numbers (WordPress, PHP, theme, this plugin). No site content is sent, and no site address unless I ask for a reply.', 'doubleyoupi-refined-media-library' ); ?></span>
			</label>
			<details class="dy-feedback__preview">
				<summary><?php esc_html_e( 'See exactly what will be sent', 'doubleyoupi-refined-media-library' ); ?></summary>
				<pre id="dy-feedback-json"></pre>
			</details>
			<p class="dy-feedback__error" id="dy-feedback-error" role="alert" hidden></p>
		</div>
		<footer class="dy-feedback__foot" id="dy-feedback-foot">
			<span class="dy-feedback__meta">
				<?php
				/* translators: %s = product name and version (e.g. "Refined Admin Dashboard 0.1.0") */
				echo esc_html( sprintf( __( 'Seen with %s', 'doubleyoupi-refined-media-library' ), $version ) );
				?>
			</span>
			<button type="button" class="dy-feedback__cancel" id="dy-feedback-cancel"><?php esc_html_e( 'Cancel', 'doubleyoupi-refined-media-library' ); ?></button>
			<button type="submit" class="dy-feedback__send" id="dy-feedback-send" disabled><?php esc_html_e( 'Send', 'doubleyoupi-refined-media-library' ); ?></button>
		</footer>
		<div class="dy-feedback__done" id="dy-feedback-done" hidden>
			<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
			<p class="dy-feedback__done-title"><?php esc_html_e( 'Thank you, it is on its way.', 'doubleyoupi-refined-media-library' ); ?></p>
			<p class="dy-feedback__done-sub" id="dy-feedback-ref"></p>
			<button type="button" class="dy-feedback__done-close" id="dy-feedback-done-close"><?php esc_html_e( 'Close', 'doubleyoupi-refined-media-library' ); ?></button>
		</div>
	</form>
</dialog>
		<?php
	}

	// =====================================================================
	//  REST relay
	// =====================================================================

	public static function register_rest(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'handle' ],
				'permission_callback' => [ self::class, 'rest_permission' ],
				'args'                => [
					'type'       => [ 'required' => true, 'type' => 'string' ],
					'message'    => [ 'required' => true, 'type' => 'string' ],
					'consent'    => [ 'required' => true, 'type' => 'boolean' ],
					'owner'      => [ 'required' => false, 'type' => 'string' ],
					'owner_detected' => [ 'required' => false, 'type' => 'boolean' ],
					'owner_via'  => [ 'required' => false, 'type' => 'string' ],
					'contact'    => [ 'required' => false, 'type' => 'boolean' ],
					'email'      => [ 'required' => false, 'type' => 'string' ],
					'screen'     => [ 'required' => false, 'type' => 'object' ],
					'client'     => [ 'required' => false, 'type' => 'object' ],
					'screenshot' => [ 'required' => false, 'type' => 'string' ],
				],
			]
		);
	}

	public static function rest_permission(): bool {
		return is_user_logged_in() && self::user_can() && '' !== self::endpoint();
	}

	public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! (bool) $request->get_param( 'consent' ) ) {
			return self::fail( 'consent_required', __( 'Please confirm you agree to send this report.', 'doubleyoupi-refined-media-library' ), 400 );
		}
		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		if ( ! in_array( $type, self::TYPES, true ) ) {
			$type = 'other';
		}
		$message = trim( sanitize_textarea_field( (string) $request->get_param( 'message' ) ) );
		if ( mb_strlen( $message ) < 5 ) {
			return self::fail( 'message_required', __( 'Please write a few words first.', 'doubleyoupi-refined-media-library' ), 400 );
		}
		$message = mb_substr( $message, 0, self::MAX_MESSAGE );

		$contact = (bool) $request->get_param( 'contact' );
		$email   = $contact ? sanitize_email( (string) $request->get_param( 'email' ) ) : '';
		if ( $contact && ! is_email( $email ) ) {
			return self::fail( 'email_invalid', __( 'This email address does not look right.', 'doubleyoupi-refined-media-library' ), 400 );
		}

		$screenshot = (string) $request->get_param( 'screenshot' );
		if ( '' !== $screenshot ) {
			if ( strlen( $screenshot ) > self::MAX_SCREENSHOT ) {
				return self::fail( 'screenshot_too_large', __( 'The screenshot is too large.', 'doubleyoupi-refined-media-library' ), 400 );
			}
			if ( ! preg_match( '#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$#', $screenshot ) ) {
				return self::fail( 'screenshot_invalid', __( 'Please use a PNG, JPEG or WebP image.', 'doubleyoupi-refined-media-library' ), 400 );
			}
		}

		// One user, ten reports an hour: enough for a real session, not for a loop.
		$user_id = get_current_user_id();
		$rl_key  = 'doubleyoupi_feedback_rl_' . $user_id;
		$count   = (int) get_transient( $rl_key );
		if ( $count >= self::RATE_LIMIT ) {
			return self::fail( 'rate_limited', __( 'Too many reports in a short time. Please try again in an hour.', 'doubleyoupi-refined-media-library' ), 429 );
		}

		$owner             = self::owner_from_key( (string) $request->get_param( 'owner' ) );
		$owner['detected'] = (bool) $request->get_param( 'owner_detected' );
		$owner['via']      = sanitize_key( (string) $request->get_param( 'owner_via' ) );
		$screen  = self::clean_screen( (array) $request->get_param( 'screen' ) );
		$client  = self::clean_client( (array) $request->get_param( 'client' ) );
		$theme   = wp_get_theme();
		$product = self::product();

		$payload = [
			'version'    => self::PAYLOAD_VERSION,
			'sent_at'    => gmdate( 'c' ),
			'type'       => $type,
			'message'    => $message,
			'owner'      => $owner,
			'screen'     => $screen,
			'product'    => $product,
			'env'        => [
				'wp'        => (string) get_bloginfo( 'version' ),
				'php'       => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
				'locale'    => get_user_locale(),
				'multisite' => is_multisite(),
				'theme'     => [
					'slug'    => (string) $theme->get_stylesheet(),
					'name'    => (string) $theme->get( 'Name' ),
					'version' => (string) $theme->get( 'Version' ),
				],
			],
			'client'     => $client,
			// Stable, non-reversible per site: lets the queue group reports
			// from the same install without knowing its address.
			'site_id'    => substr( hash( 'sha256', (string) home_url() ), 0, 16 ),
			'contact'    => $contact ? [ 'email' => $email, 'site' => (string) home_url() ] : null,
			'screenshot' => '' !== $screenshot ? $screenshot : null,
		];

		/**
		 * Filter: doubleyoupi_filter_feedback_payload
		 * Last look at the report before it leaves the site.
		 *
		 * @param array            $payload The report.
		 * @param \WP_REST_Request $request The originating request.
		 */
		$payload = (array) apply_filters( 'doubleyoupi_filter_feedback_payload', $payload, $request );

		$response = wp_remote_post(
			self::endpoint(),
			[
				'timeout'    => 15,
				'headers'    => [
					'Content-Type'          => 'application/json; charset=utf-8',
					'Accept'                => 'application/json',
					'X-DoubleYoupi-Product' => $product['slug'] . '/' . $product['version'],
				],
				'user-agent' => 'DoubleYoupi-Feedback/' . self::PAYLOAD_VERSION . ' (' . $product['slug'] . ' ' . $product['version'] . '; WordPress ' . get_bloginfo( 'version' ) . ')',
				'body'       => wp_json_encode( $payload ),
			]
		);
		if ( is_wp_error( $response ) ) {
			return self::fail( 'endpoint_unreachable', __( 'The feedback service did not answer. Please try again later.', 'doubleyoupi-refined-media-library' ), 502 );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return self::fail( 'endpoint_error', __( 'The feedback service did not answer. Please try again later.', 'doubleyoupi-refined-media-library' ), 502 );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$id   = is_array( $body ) && isset( $body['id'] ) ? sanitize_text_field( (string) $body['id'] ) : '';

		set_transient( $rl_key, $count + 1, HOUR_IN_SECONDS );

		/**
		 * Action: doubleyoupi_feedback_sent
		 *
		 * @param array  $payload The report as sent.
		 * @param string $id      Reference returned by the endpoint (may be empty).
		 */
		do_action( 'doubleyoupi_feedback_sent', $payload, $id );

		return new \WP_REST_Response( [ 'ok' => true, 'id' => $id ], 200 );
	}

	private static function fail( string $code, string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response( [ 'ok' => false, 'code' => $code, 'message' => $message ], $status );
	}

	/**
	 * `kind:slug` chosen in the dialog → validated against the real
	 * candidates (an unknown key falls back to WordPress).
	 *
	 * @return array{kind:string,slug:string,name:string,version:string}
	 */
	private static function owner_from_key( string $key ): array {
		$key    = sanitize_text_field( $key );
		$owners = ScreenOwner::owners();
		foreach ( $owners as $owner ) {
			if ( $owner['kind'] . ':' . $owner['slug'] === $key ) {
				return [ 'kind' => $owner['kind'], 'slug' => $owner['slug'], 'name' => $owner['name'], 'version' => $owner['version'] ];
			}
		}
		return [ 'kind' => 'core', 'slug' => 'wordpress', 'name' => 'WordPress', 'version' => (string) get_bloginfo( 'version' ) ];
	}

	/** @return array<string,string> */
	private static function clean_screen( array $screen ): array {
		$out = [];
		foreach ( [ 'pagenow', 'page', 'post_type', 'taxonomy', 'parent', 'screenId', 'title' ] as $k ) {
			$out[ $k ] = mb_substr( sanitize_text_field( (string) ( $screen[ $k ] ?? '' ) ), 0, 200 );
		}
		$url        = sanitize_text_field( (string) ( $screen['url'] ?? '' ) );
		$out['url'] = ScreenOwner::clean_url( (string) wp_parse_url( $url, PHP_URL_PATH ), (string) wp_parse_url( $url, PHP_URL_QUERY ) );
		return $out;
	}

	/** @return array<string,mixed> */
	private static function clean_client( array $client ): array {
		$theme = sanitize_key( (string) ( $client['theme'] ?? '' ) );
		return [
			'viewport' => [
				'w' => (int) ( $client['viewport']['w'] ?? 0 ),
				'h' => (int) ( $client['viewport']['h'] ?? 0 ),
			],
			'theme'    => in_array( $theme, [ 'dark', 'light' ], true ) ? $theme : '',
			'ua'       => mb_substr( sanitize_text_field( (string) ( $client['ua'] ?? '' ) ), 0, 200 ),
		];
	}
}
