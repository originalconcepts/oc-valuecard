<?php
/**
 * Checkout integration.
 *
 * Renders the loyalty box on the checkout, quotes the redemption via
 * GetBenefitsQuery, and applies the resulting discount as a negative cart fee.
 * The quote's TransactionId is kept in the session and later copied onto the
 * order so it can be committed to ValueCard.
 *
 * @package OC_ValueCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCVC_Checkout {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		// Place the box between the order summary and the payment method:
		//  - custom themes (e.g. deliz-short) fire woocommerce_checkout_payment at
		//    the top of the payment area — priority 5 puts us just above payment;
		//  - the standard checkout doesn't fire that, so fall back to the review's
		//    before-payment hook.
		// The render-once guard makes sure it appears exactly once either way.
		add_action( 'woocommerce_checkout_payment', array( __CLASS__, 'render_box' ), 5 );
		add_action( 'woocommerce_review_order_before_payment', array( __CLASS__, 'render_box' ) );
		add_filter( 'woocommerce_update_order_review_fragments', array( __CLASS__, 'order_review_fragments' ) );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'apply_fee' ) );
		add_filter( 'woocommerce_cart_totals_fee_html', array( __CLASS__, 'fee_html' ), 10, 2 );
		add_action( 'wp_footer', array( __CLASS__, 'render_modals' ) );

		add_action( 'wp_ajax_ocvc_apply_points', array( __CLASS__, 'ajax_apply_points' ) );
		add_action( 'wp_ajax_nopriv_ocvc_apply_points', array( __CLASS__, 'ajax_apply_points' ) );
		add_action( 'wp_ajax_ocvc_clear_points', array( __CLASS__, 'ajax_clear_points' ) );
		add_action( 'wp_ajax_nopriv_ocvc_clear_points', array( __CLASS__, 'ajax_clear_points' ) );

		// Shortcode so custom checkouts can place the loyalty area anywhere.
		add_shortcode( 'oc_valuecard', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Shortcode: renders the loyalty area. Use [oc_valuecard] anywhere on the
	 * checkout (handy for custom/page-builder checkouts that don't fire the
	 * default WooCommerce hooks).
	 *
	 * @return string
	 */
	public static function shortcode() {
		if ( ! function_exists( 'is_checkout' ) ) {
			return '';
		}
		self::enqueue(); // Make sure assets are present even outside the default hook.
		return self::box_html_once();
	}

	/**
	 * Build the CSS custom properties for the admin-chosen primary color.
	 *
	 * @return string
	 */
	private static function primary_css() {
		$hex = sanitize_hex_color( (string) OCVC_Settings::get( 'primary_color' ) );
		if ( ! $hex ) {
			$hex = '#1aa251';
		}
		list( $r, $g, $b ) = self::hex_to_rgb( $hex );
		$dr = (int) round( $r * 0.85 );
		$dg = (int) round( $g * 0.85 );
		$db = (int) round( $b * 0.85 );

		return sprintf(
			':root{--ocvc-primary:%1$s;--ocvc-primary-dark:rgb(%2$d,%3$d,%4$d);--ocvc-primary-bg:rgba(%5$d,%6$d,%7$d,.10);--ocvc-primary-bg2:rgba(%5$d,%6$d,%7$d,.02);--ocvc-primary-border:rgba(%5$d,%6$d,%7$d,.35);--ocvc-primary-soft:rgba(%5$d,%6$d,%7$d,.08);}',
			$hex,
			$dr,
			$dg,
			$db,
			$r,
			$g,
			$b
		);
	}

	/**
	 * Convert a hex color to an [r, g, b] array.
	 *
	 * @param string $hex Hex color (#rgb or #rrggbb).
	 * @return array
	 */
	private static function hex_to_rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return array(
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * Enqueue assets on the checkout page.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		$css_path = OCVC_PLUGIN_DIR . 'assets/css/frontend.css';
		$js_path  = OCVC_PLUGIN_DIR . 'assets/js/frontend.js';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : OCVC_VERSION;
		$js_ver   = file_exists( $js_path ) ? (string) filemtime( $js_path ) : OCVC_VERSION;

		wp_enqueue_style( 'ocvc-frontend', OCVC_PLUGIN_URL . 'assets/css/frontend.css', array(), $css_ver );
		wp_add_inline_style( 'ocvc-frontend', self::primary_css() );
		wp_enqueue_script( 'ocvc-frontend', OCVC_PLUGIN_URL . 'assets/js/frontend.js', array( 'jquery' ), $js_ver, true );

		wp_localize_script(
			'ocvc-frontend',
			'ocvc',
			array(
				'ajax_url'    => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'ocvc_nonce' ),
				'i18n'        => array(
					'sending'       => __( 'Sending…', 'oc-valuecard' ),
					'verifying'     => __( 'Verifying…', 'oc-valuecard' ),
					'applying'      => __( 'Applying…', 'oc-valuecard' ),
					'invalid_phone' => __( 'Please enter a valid phone number.', 'oc-valuecard' ),
					'error'         => __( 'Something went wrong. Please try again.', 'oc-valuecard' ),
				),
			)
		);
	}

	/**
	 * Render the loyalty box inside the order review.
	 *
	 * @return void
	 */
	/**
	 * Whether the box has already been output on this request (prevents a double
	 * render when a theme places it manually as well as via the auto hook).
	 *
	 * @var bool
	 */
	private static $rendered = false;

	public static function render_box() {
		echo self::box_html_once(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled from escaped parts.
	}

	/**
	 * Return the box HTML at most once per request.
	 *
	 * @return string
	 */
	private static function box_html_once() {
		if ( self::$rendered ) {
			return '';
		}
		self::$rendered = true;
		return self::box_html();
	}

	/**
	 * Expose the loyalty box as an order-review fragment, so it refreshes live on
	 * every update_checkout (apply / remove / sign-in) with no page reload.
	 *
	 * @param array $fragments Existing fragments.
	 * @return array
	 */
	public static function order_review_fragments( $fragments ) {
		$fragments['#ocvc-box-wrap'] = self::box_html();

		// Some custom checkouts (e.g. deliz-short) show the order total in their own
		// element outside the standard review fragment, so it wouldn't reflect our
		// discount on AJAX. Refresh those "total mirror" elements too. The selector
		// list is filterable so any theme can register its own total element.
		if ( function_exists( 'wc_cart_totals_order_total_html' ) ) {
			ob_start();
			wc_cart_totals_order_total_html();
			$total_html = trim( (string) ob_get_clean() );

			$mirrors = apply_filters(
				'ocvc_order_total_mirror_selectors',
				array( '.checkout-order-compact__total' => 'span' )
			);

			foreach ( (array) $mirrors as $selector => $tag ) {
				$tag = preg_replace( '/[^a-z0-9]/i', '', (string) $tag );
				if ( '' === $tag ) {
					$tag = 'span';
				}
				$class = ltrim( (string) $selector, '.' );
				$fragments[ $selector ] = '<' . $tag . ' class="' . esc_attr( $class ) . '">' . $total_html . '</' . $tag . '>';
			}
		}

		return $fragments;
	}

	/**
	 * Build the loyalty box HTML (wrapped in a stable element for fragments).
	 *
	 * @return string
	 */
	private static function box_html() {
		ob_start();
		echo '<div class="ocvc-box-wrap" id="ocvc-box-wrap">';

		$api = new OCVC_API();
		if ( $api->is_configured() ) {
			$balance   = OCVC_Member::ensure_balance();
			$is_member = $balance && $balance->is_member;

			echo '<div class="ocvc-box" id="ocvc-box">';
			if ( $is_member ) {
				self::render_member_box( $balance );
			} else {
				self::render_prospect_box();
			}
			echo '</div>';
		}

		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Member view: balance + redeem control.
	 *
	 * @param object $balance Balance object.
	 * @return void
	 */
	private static function render_member_box( $m ) {
		$discount     = (float) OCVC_Member::get( 'discount', 0 );
		$redeemed     = (float) OCVC_Member::get( 'redeemed_points', 0 );
		$earn         = (float) OCVC_Member::get( 'earn', 0 );
		$avail        = (float) $m->balance;
		$units        = isset( $m->units ) && '' !== $m->units ? (string) $m->units : get_woocommerce_currency_symbol();
		$balance_html = self::format_balance( $avail, $units );
		$benefits     = isset( $m->benefits ) && is_array( $m->benefits ) ? $m->benefits : array();
		$show_points  = ( $redeemed > 0 ) ? max( 0, $avail - $redeemed ) : $avail;
		?>
		<div class="ocvc-member">
			<div class="ocvc-member-head">
				<span class="ocvc-badge" aria-hidden="true">&#10003;</span>
				<span class="ocvc-connected"><?php esc_html_e( 'Connected to the club', 'oc-valuecard' ); ?></span>
				<button type="button" class="ocvc-member-name" id="ocvc-member-details" title="<?php esc_attr_e( 'View my details', 'oc-valuecard' ); ?>"><?php echo esc_html( $m->name ); ?></button>
				<button type="button" class="ocvc-link ocvc-logout" id="ocvc-logout"><?php esc_html_e( 'Disconnect', 'oc-valuecard' ); ?></button>
			</div>

			<div class="ocvc-points-area">
				<div class="ocvc-points-row">
					<div class="ocvc-points-count">
						<span class="ocvc-balance-amount"><?php echo esc_html( self::num( $show_points ) ); ?></span>
						<span class="ocvc-balance-word"><?php echo esc_html( self::points_word( $show_points ) ); ?></span>
						<span class="ocvc-balance-sub"><?php esc_html_e( 'for use', 'oc-valuecard' ); ?></span>
					</div>

					<?php if ( $redeemed > 0 ) : ?>
						<div class="ocvc-applied-inline">
							<span class="ocvc-applied-text">&#10003;
								<?php
								/* translators: %s number of points. */
								printf( esc_html__( 'Redeemed %s points', 'oc-valuecard' ), esc_html( self::num( $redeemed ) ) );
								?>
								<span class="ocvc-applied-value">(<?php echo esc_html( self::format_balance( $redeemed, $units ) ); ?>)</span>
							</span>
							<button type="button" class="ocvc-link" id="ocvc-clear"><?php esc_html_e( 'Remove', 'oc-valuecard' ); ?></button>
						</div>
					<?php elseif ( $avail > 0 ) : ?>
						<div class="ocvc-points-action">
							<button type="button" class="ocvc-redeem-btn ocvc-redeem-open" id="ocvc-redeem-open"><?php echo esc_html( OCVC_Settings::get( 'redeem_button_label' ) ); ?></button>
							<div class="ocvc-redeem-field" id="ocvc-redeem-field" hidden>
								<input type="number" min="0" step="1" max="<?php echo esc_attr( (int) floor( $avail ) ); ?>" value="<?php echo esc_attr( (int) floor( $avail ) ); ?>" id="ocvc-amount" class="ocvc-amount" />
								<button type="button" class="ocvc-redeem-btn ocvc-redeem-confirm" id="ocvc-apply-amount">&#10003; <?php esc_html_e( 'Redeem now', 'oc-valuecard' ); ?></button>
							</div>
						</div>
					<?php endif; ?>
				</div>

				<div class="ocvc-msg" id="ocvc-msg" aria-live="polite"></div>
			</div>

			<p class="ocvc-point-note">
				<?php
				/* translators: %s currency/unit symbol. */
				printf( esc_html__( '1 point = 1 %s', 'oc-valuecard' ), esc_html( $units ) );
				?>
			</p>

			<?php if ( $earn > 0 ) : ?>
				<p class="ocvc-earn">&#127881;
					<?php
					/* translators: %s number of points earned. */
					printf( esc_html__( 'Completing this order will earn you %s more points', 'oc-valuecard' ), esc_html( self::num( $earn ) ) );
					?>
				</p>
			<?php endif; ?>

			<?php self::render_details_modal( $m, $balance_html ); ?>
		</div>
		<?php
	}

	/**
	 * Format a number for display (no decimals when whole).
	 *
	 * @param float $n Number.
	 * @return string
	 */
	private static function num( $n ) {
		$n = (float) $n;
		return number_format_i18n( $n, ( $n == floor( $n ) ) ? 0 : 2 );
	}

	/**
	 * "point" / "points" depending on the count.
	 *
	 * @param float $n Count.
	 * @return string
	 */
	private static function points_word( $n ) {
		return ( 1 === (int) round( (float) $n ) ) ? __( 'point', 'oc-valuecard' ) : __( 'points', 'oc-valuecard' );
	}

	/**
	 * Format a balance amount with its unit (ValueCard unit, or the store currency).
	 *
	 * @param float  $amount Amount.
	 * @param string $units  Unit label from ValueCard (e.g. ₪), or ''.
	 * @return string
	 */
	private static function format_balance( $amount, $units ) {
		$n     = ( floor( $amount ) == $amount ) ? number_format_i18n( $amount, 0 ) : number_format_i18n( $amount, 2 );
		$units = trim( (string) $units );
		if ( '' === $units ) {
			$units = get_woocommerce_currency_symbol();
		}
		return $n . ' ' . $units;
	}

	/**
	 * Member details popup (rendered hidden inside the member box).
	 *
	 * @param object $m            Member info object.
	 * @param string $balance_html Pre-formatted balance.
	 * @return void
	 */
	private static function render_details_modal( $m, $balance_html ) {
		?>
		<div class="ocvc-modal" id="ocvc-details-modal" hidden>
			<div class="ocvc-modal-inner ocvc-details">
				<button type="button" class="ocvc-modal-close" data-ocvc-close aria-label="<?php esc_attr_e( 'Close', 'oc-valuecard' ); ?>">&times;</button>
				<h3><?php echo esc_html( $m->name ); ?></h3>
				<ul class="ocvc-details-list">
					<?php if ( ! empty( $m->status ) ) : ?>
						<li><span><?php esc_html_e( 'Membership status', 'oc-valuecard' ); ?></span><strong><?php echo esc_html( $m->status ); ?></strong></li>
					<?php endif; ?>
					<?php if ( ! empty( $m->card_group ) ) : ?>
						<li><span><?php esc_html_e( 'Card group', 'oc-valuecard' ); ?></span><strong><?php echo esc_html( $m->card_group ); ?></strong></li>
					<?php endif; ?>
					<li><span><?php esc_html_e( 'Balance', 'oc-valuecard' ); ?></span><strong><?php echo esc_html( $balance_html ); ?></strong></li>
					<?php if ( ! empty( $m->phone ) ) : ?>
						<li><span><?php esc_html_e( 'Phone', 'oc-valuecard' ); ?></span><strong dir="ltr"><?php echo esc_html( $m->phone ); ?></strong></li>
					<?php endif; ?>
					<?php if ( ! empty( $m->email ) ) : ?>
						<li><span><?php esc_html_e( 'Email', 'oc-valuecard' ); ?></span><strong dir="ltr"><?php echo esc_html( $m->email ); ?></strong></li>
					<?php endif; ?>
					<?php if ( ! empty( $m->birth_date ) ) : ?>
						<li><span><?php esc_html_e( 'Birthday', 'oc-valuecard' ); ?></span><strong><?php echo esc_html( $m->birth_date ); ?></strong></li>
					<?php endif; ?>
				</ul>
				<?php if ( ! empty( $m->benefits ) ) : ?>
					<h4><?php esc_html_e( 'Your benefits', 'oc-valuecard' ); ?></h4>
					<ul class="ocvc-benefits-list">
						<?php foreach ( $m->benefits as $b ) : ?>
							<li><?php echo esc_html( trim( $b ) ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Non-member / not-yet-identified view.
	 *
	 * One row with (optionally) the "join the club" checkbox on the start side and
	 * an "I have a membership" link on the end side that opens the sign-in popup.
	 *
	 * @return void
	 */
	private static function render_prospect_box() {
		$has_join  = OCVC_Settings::get_bool( 'enable_join_club' );
		$has_popup = trim( (string) OCVC_Settings::get( 'join_popup_content' ) ) !== '';
		?>
		<div class="ocvc-prospect">
			<?php if ( $has_join ) : ?>
				<div class="ocvc-join-line">
					<span class="ocvc-toggle">
						<input type="checkbox" name="ocvc_join_club" id="ocvc-join-club" value="1" class="ocvc-toggle-input" />
						<label for="ocvc-join-club" class="ocvc-toggle-track" aria-label="<?php esc_attr_e( 'Join the club', 'oc-valuecard' ); ?>"><span class="ocvc-toggle-thumb"></span></label>
					</span>
					<span class="ocvc-join-text">
						<?php echo self::linkify_label( OCVC_Settings::get( 'join_checkbox_label' ), $has_popup ? 'ocvc-join-details' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside linkify_label. ?>
					</span>
				</div>
			<?php endif; ?>

			<button type="button" class="ocvc-link ocvc-have-club" id="ocvc-open-otp">
				<?php echo esc_html( OCVC_Settings::get( 'member_login_label' ) ); ?>
			</button>
		</div>
		<?php
	}

	/**
	 * Render a label where text wrapped in [square brackets] becomes a popup link.
	 * All segments are escaped; the bracketed segment becomes a <button> link.
	 *
	 * @param string $text    Raw label text (may contain one [linked] segment).
	 * @param string $link_id Element id for the link, or '' to render plain text.
	 * @return string Safe HTML.
	 */
	private static function linkify_label( $text, $link_id ) {
		if ( '' !== $link_id && preg_match( '/^(.*?)\[(.*?)\](.*)$/s', (string) $text, $m ) ) {
			return esc_html( $m[1] )
				. '<button type="button" class="ocvc-link" id="' . esc_attr( $link_id ) . '">' . esc_html( $m[2] ) . '</button>'
				. esc_html( $m[3] );
		}
		// No brackets (or no popup): strip the brackets and show plain text.
		return esc_html( str_replace( array( '[', ']' ), '', (string) $text ) );
	}

	/**
	 * Output the OTP + join modals in the footer.
	 *
	 * @return void
	 */
	public static function render_modals() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		?>
		<div class="ocvc-modal" id="ocvc-otp-modal" hidden>
			<div class="ocvc-modal-inner ocvc-otp">
				<button type="button" class="ocvc-modal-close" data-ocvc-close aria-label="<?php esc_attr_e( 'Close', 'oc-valuecard' ); ?>">&times;</button>

				<div class="ocvc-otp-step" data-step="phone">
					<h3 class="ocvc-otp-title"><?php echo esc_html( OCVC_Settings::get( 'otp_title' ) ); ?></h3>
					<?php $ocvc_intro = trim( (string) OCVC_Settings::get( 'otp_intro' ) ); ?>
					<?php if ( '' !== $ocvc_intro ) : ?>
						<p class="ocvc-otp-intro"><?php echo esc_html( $ocvc_intro ); ?></p>
					<?php endif; ?>
					<input type="tel" id="ocvc-otp-phone" class="ocvc-input" inputmode="tel" dir="ltr" placeholder="<?php esc_attr_e( 'Phone number', 'oc-valuecard' ); ?>" />
					<button type="button" class="button ocvc-btn-primary" id="ocvc-otp-send"><?php echo esc_html( OCVC_Settings::get( 'otp_send_label' ) ); ?></button>
					<div class="ocvc-msg" id="ocvc-otp-msg" aria-live="polite"></div>
				</div>

				<div class="ocvc-otp-step" data-step="code" hidden>
					<h3 class="ocvc-otp-title"><?php esc_html_e( 'Enter verification code', 'oc-valuecard' ); ?></h3>
					<p class="ocvc-otp-sent">
						<?php esc_html_e( 'A code was sent by SMS to the number', 'oc-valuecard' ); ?>
						<strong class="ocvc-masked" id="ocvc-otp-masked" dir="ltr"></strong>
					</p>
					<button type="button" class="ocvc-link ocvc-otp-change" id="ocvc-otp-change"><?php esc_html_e( 'Change number', 'oc-valuecard' ); ?></button>
					<p class="ocvc-otp-hint"><?php esc_html_e( 'Enter the 6-digit code you received', 'oc-valuecard' ); ?></p>
					<div class="ocvc-otp-boxes" id="ocvc-otp-boxes" dir="ltr">
						<?php for ( $ocvc_i = 0; $ocvc_i < 6; $ocvc_i++ ) : ?>
							<input type="text" class="ocvc-otp-digit" maxlength="1" inputmode="numeric" autocomplete="one-time-code" aria-label="<?php esc_attr_e( 'Verification code digit', 'oc-valuecard' ); ?>" />
						<?php endfor; ?>
					</div>
					<div class="ocvc-msg" id="ocvc-otp-msg2" aria-live="polite"></div>
				</div>
			</div>
		</div>

		<?php if ( trim( (string) OCVC_Settings::get( 'join_popup_content' ) ) !== '' ) : ?>
		<div class="ocvc-modal" id="ocvc-join-modal" hidden>
			<div class="ocvc-modal-inner">
				<button type="button" class="ocvc-modal-close" data-ocvc-close aria-label="<?php esc_attr_e( 'Close', 'oc-valuecard' ); ?>">&times;</button>
				<div class="ocvc-join-content"><?php echo wp_kses_post( wpautop( OCVC_Settings::get( 'join_popup_content' ) ) ); ?></div>
				<div class="ocvc-join-actions">
					<button type="button" class="ocvc-redeem-btn ocvc-join-accept" id="ocvc-join-accept"><?php esc_html_e( 'Join for free', 'oc-valuecard' ); ?></button>
					<button type="button" class="ocvc-btn-muted" id="ocvc-join-decline"><?php esc_html_e( 'Maybe later', 'oc-valuecard' ); ?></button>
				</div>
			</div>
		</div>
		<?php endif; ?>
		<?php
	}

	/* --------------------------------------------------------------------- *
	 * Fee + AJAX
	 * --------------------------------------------------------------------- */

	/**
	 * Apply the stored loyalty discount as a negative cart fee.
	 *
	 * @param WC_Cart $cart Cart instance.
	 * @return void
	 */
	public static function apply_fee( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		// Refresh the ValueCard quote so the automatic club benefit (and any redeemed
		// points) always matches the CURRENT cart — on the cart, the checkout, and any
		// custom/mini-cart context. The quote self-guards on the cart total, so
		// ValueCard is only contacted when the total actually changes.
		self::ensure_benefit_query( false );

		$discount = (float) OCVC_Member::get( 'discount', 0 );
		if ( $discount <= 0 ) {
			return;
		}

		// Safety net against a stale discount: only apply it when it was quoted for
		// THIS exact cart total. If the quote could not be refreshed (e.g. a context
		// where the re-quote didn't run, or a transient API error), a leftover
		// discount from a previous, larger cart is dropped instead of shown.
		$qsum = OCVC_Member::get( 'qsum' );
		if ( null === $qsum || abs( (float) $qsum - self::transaction_sum() ) > 0.01 ) {
			return;
		}

		// Never discount more than the cart is worth.
		$cap = (float) $cart->get_subtotal() + (float) $cart->get_shipping_total();
		if ( $discount > $cap ) {
			$discount = $cap;
		}

		// Split into two lines: automatic club benefit vs. redeemed points.
		$redeemed = (float) OCVC_Member::get( 'redeemed_points', 0 );
		$points   = min( max( 0, $redeemed ), $discount );
		$benefit  = max( 0, $discount - $points );

		if ( $benefit > 0 ) {
			$cart->add_fee( __( 'Club benefit', 'oc-valuecard' ), -1 * $benefit, false );
		}
		if ( $points > 0 ) {
			$cart->add_fee( __( 'Points redemption', 'oc-valuecard' ), -1 * $points, false );
		}
	}

	/**
	 * Ensure the current ValueCard quote reflects the cart + selected points.
	 * Points value -1 means "no points" — only the automatic club benefit.
	 * Returns an error message on failure, or null on success / no-op.
	 *
	 * @param bool $force Re-query even if the cart/selection is unchanged.
	 * @return string|null
	 */
	private static function ensure_benefit_query( $force = false ) {
		$card = OCVC_Member::card_number();
		if ( ! $card ) {
			return null;
		}
		$m = OCVC_Member::ensure_balance();
		if ( ! $m || empty( $m->is_member ) ) {
			return null;
		}

		$sum    = self::transaction_sum();
		$points = (float) OCVC_Member::get( 'points_to_consume', -1 );
		if ( 0.0 === $points ) {
			$points = -1;
		}

		$json = self::build_json_items();
		if ( '' === $json ) {
			// Empty cart — drop any stale discount instead of leaving it applied.
			foreach ( array( 'discount', 'redeemed_points', 'earn', 'benefit_names', 'transaction_id' ) as $key ) {
				OCVC_Member::set( $key, null );
			}
			OCVC_Member::set( 'qsum', 0 );
			OCVC_Member::set( 'qpoints', $points );
			return null;
		}

		$qtxn    = OCVC_Member::get( 'transaction_id' );
		$qsum    = OCVC_Member::get( 'qsum' );
		$qpoints = OCVC_Member::get( 'qpoints' );

		if ( ! $force && $qtxn && null !== $qsum && (float) $qsum === (float) $sum && (float) $qpoints === (float) $points ) {
			return null; // Already up to date.
		}

		$api    = new OCVC_API();
		$result = $api->get_benefits_query(
			array(
				'card_number'       => $card,
				'transaction_sum'   => $sum,
				'points_to_consume' => $points,
				'json_items'        => $json,
			)
		);

		if ( $result->is_error || ! is_numeric( $result->transaction_id ) || (int) $result->transaction_id <= 0 ) {
			return $result->message ? $result->message : __( 'No redemption is available.', 'oc-valuecard' );
		}

		OCVC_Member::set( 'transaction_id', (string) $result->transaction_id );
		OCVC_Member::set( 'discount', (float) $result->discount );
		OCVC_Member::set( 'redeemed_points', (float) $result->given_points_redemption );
		OCVC_Member::set( 'earn', (float) $result->money_back );
		OCVC_Member::set( 'benefit_names', self::parse_promo_names( $result->given_promo_ids ) );
		OCVC_Member::set( 'qsum', $sum );
		OCVC_Member::set( 'qpoints', $points );

		return null;
	}

	/**
	 * Extract benefit titles from a GivenPromoIDs string.
	 * Format: "id|Title|amount;id|Title|amount;"
	 *
	 * @param string $given GivenPromoIDs value.
	 * @return string Comma-separated titles.
	 */
	private static function parse_promo_names( $given ) {
		$names = array();
		foreach ( explode( ';', (string) $given ) as $row ) {
			$row = trim( $row );
			if ( '' === $row ) {
				continue;
			}
			$parts = explode( '|', $row );
			if ( isset( $parts[1] ) && '' !== trim( $parts[1] ) ) {
				$names[] = trim( $parts[1] );
			}
		}
		return implode( ', ', $names );
	}

	/**
	 * Append the applied benefit names (non-bold) under the "Club benefit" fee.
	 *
	 * @param string $html Fee amount HTML.
	 * @param object $fee  Fee object.
	 * @return string
	 */
	public static function fee_html( $html, $fee ) {
		if ( isset( $fee->name ) && __( 'Club benefit', 'oc-valuecard' ) === $fee->name ) {
			$names = (string) OCVC_Member::get( 'benefit_names', '' );
			if ( '' !== $names ) {
				$html .= '<span class="ocvc-benefit-names">' . esc_html( $names ) . '</span>';
			}
		}
		return $html;
	}

	/**
	 * Base transaction sum the discount is calculated on (excludes our own fee).
	 *
	 * @return float
	 */
	private static function transaction_sum() {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return 0.0;
		}
		return (float) $cart->get_subtotal()
			+ (float) $cart->get_subtotal_tax()
			+ (float) $cart->get_shipping_total()
			+ (float) $cart->get_shipping_tax();
	}

	/**
	 * Build the ValueCard "JsonItems" payload from the current cart.
	 * ValueCard needs the transaction lines to compute benefits — sending an
	 * empty payload triggers "parameter missing or invalid" (ReturnCode -10101).
	 *
	 * @return string JSON string (empty when the cart is empty).
	 */
	private static function build_json_items() {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return '';
		}
		$cart_items = $cart->get_cart();
		if ( empty( $cart_items ) ) {
			return '';
		}

		$trans_items = array();
		$i           = 0;
		foreach ( $cart_items as $ci ) {
			$product = isset( $ci['data'] ) ? $ci['data'] : null;
			if ( ! $product ) {
				continue;
			}
			$qty   = (float) $ci['quantity'];
			$total = (float) $ci['line_subtotal'] + (float) $ci['line_subtotal_tax'];
			$price = $qty > 0 ? $total / $qty : (float) $product->get_price();

			$sku = $product->get_sku();

			$trans_items[] = array(
				'ExtItemNum'    => (string) ( $sku ? $sku : $product->get_id() ),
				'SequentialNum' => $i,
				'ItemName'      => wp_strip_all_tags( $product->get_name() ),
				'ItemPrice'     => round( $price, 2 ),
				'Amount'        => $qty,
				'Total'         => round( $total, 2 ),
				'BusinessCode'  => '',
				'GroupName'     => '',
				'Familly'       => '',
			);
			$i++;
		}

		if ( empty( $trans_items ) ) {
			return '';
		}

		$payload = array(
			'Trans' => array(
				array(
					'TransId'         => 0,
					'OrderNum'        => 0,
					'NumberOfClients' => 1,
					'OrderType'       => 1,
					'ClientNum'       => 1,
					'TransItems'      => $trans_items,
				),
			),
		);

		return wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
	}

	/* --------------------------------------------------------------------- *
	 * Order-based quoting (server-side: reserve / edit re-sync / settle)
	 *
	 * After checkout there is no cart or WC session, so these mirror the
	 * cart-based helpers above but read a saved WC_Order instead. Identity
	 * comes from the order's _ocvc_card meta.
	 * --------------------------------------------------------------------- */

	/**
	 * Build the ValueCard "JsonItems" payload from a SAVED ORDER.
	 *
	 * @param WC_Order $order Order.
	 * @return string JSON string (empty when the order has no items).
	 */
	public static function build_json_items_from_order( $order ) {
		if ( ! $order || ! method_exists( $order, 'get_items' ) ) {
			return '';
		}
		$items = $order->get_items();
		if ( empty( $items ) ) {
			return '';
		}

		$trans_items = array();
		$i           = 0;
		foreach ( $items as $item ) {
			$product = $item->get_product();
			$qty     = (float) $item->get_quantity();
			$total   = (float) $item->get_subtotal() + (float) $item->get_subtotal_tax();
			$price   = $qty > 0 ? $total / $qty : ( $product ? (float) $product->get_price() : 0.0 );

			$sku    = $product ? $product->get_sku() : '';
			$extnum = $sku ? $sku : ( $product ? $product->get_id() : $item->get_product_id() );

			$trans_items[] = array(
				'ExtItemNum'    => (string) $extnum,
				'SequentialNum' => $i,
				'ItemName'      => wp_strip_all_tags( $item->get_name() ),
				'ItemPrice'     => round( $price, 2 ),
				'Amount'        => $qty,
				'Total'         => round( $total, 2 ),
				'BusinessCode'  => '',
				'GroupName'     => '',
				'Familly'       => '',
			);
			$i++;
		}

		if ( empty( $trans_items ) ) {
			return '';
		}

		$payload = array(
			'Trans' => array(
				array(
					'TransId'         => 0,
					'OrderNum'        => 0,
					'NumberOfClients' => 1,
					'OrderType'       => 1,
					'ClientNum'       => 1,
					'TransItems'      => $trans_items,
				),
			),
		);

		return wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Base transaction sum for a SAVED ORDER (mirrors transaction_sum() for the cart).
	 *
	 * @param WC_Order $order Order.
	 * @return float
	 */
	public static function transaction_sum_from_order( $order ) {
		if ( ! $order || ! method_exists( $order, 'get_items' ) ) {
			return 0.0;
		}
		$sum = 0.0;
		foreach ( $order->get_items() as $item ) {
			$sum += (float) $item->get_subtotal() + (float) $item->get_subtotal_tax();
		}
		$sum += (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
		return $sum;
	}

	/**
	 * A stable fingerprint of an order's ValueCard-relevant state (lines, totals,
	 * weight). Lets the re-sync fire only when the order actually changed — not on
	 * every trivial admin save.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function order_signature( $order ) {
		if ( ! $order || ! method_exists( $order, 'get_items' ) ) {
			return '';
		}
		$parts  = array();
		$weight = 0.0;
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$pid     = $product ? $product->get_id() : $item->get_product_id();
			$qty     = (float) $item->get_quantity();
			$line    = (float) $item->get_subtotal() + (float) $item->get_subtotal_tax();
			$parts[] = $pid . ':' . rtrim( rtrim( number_format( $qty, 3, '.', '' ), '0' ), '.' ) . ':' . number_format( $line, 2, '.', '' );
			if ( $product && '' !== (string) $product->get_weight() ) {
				$weight += (float) $product->get_weight() * $qty;
			}
		}
		sort( $parts );
		$sig = implode( '|', $parts )
			. '#sum:' . number_format( self::transaction_sum_from_order( $order ), 2, '.', '' )
			. '#w:' . number_format( $weight, 3, '.', '' );
		return md5( $sig );
	}

	/**
	 * AJAX: quote a redemption and store it in the session.
	 *
	 * @return void
	 */
	public static function ajax_apply_points() {
		check_ajax_referer( 'ocvc_nonce', 'nonce' );

		$card = OCVC_Member::card_number();
		if ( ! $card ) {
			wp_send_json_error( array( 'message' => __( 'No member is identified.', 'oc-valuecard' ) ) );
		}

		$amount = isset( $_POST['amount'] ) ? (float) wp_unslash( $_POST['amount'] ) : 0;
		if ( $amount <= 0 ) {
			// "Redeem points" with no amount = redeem the full available balance.
			$m      = OCVC_Member::ensure_balance();
			$amount = ( $m && isset( $m->balance ) ) ? (float) $m->balance : 0;
		}
		// Never consume more points than the order is actually worth. The automatic
		// club benefit already covers part of the cart, so the useful redemption is
		// the payable amount (subtotal minus the benefit). Redeeming beyond that makes
		// ValueCard shrink the benefit and burn extra points for no added discount.
		$benefit_only = max( 0.0, (float) OCVC_Member::get( 'discount', 0 ) - (float) OCVC_Member::get( 'redeemed_points', 0 ) );
		$payable      = self::transaction_sum() - $benefit_only;
		if ( $payable > 0 && $amount > $payable ) {
			$amount = $payable;
		}
		// ValueCard only accepts whole-number point redemptions — a fractional
		// CouponNum (e.g. a 12.12 balance) is rejected. Floor to whole points.
		$amount = floor( $amount );
		$points = ( $amount >= 1 ) ? $amount : -1;

		OCVC_Member::set( 'points_to_consume', $points );
		$err = self::ensure_benefit_query( true );

		if ( $err ) {
			// Revert to benefit-only so the automatic club benefit stays applied.
			OCVC_Member::set( 'points_to_consume', -1 );
			self::ensure_benefit_query( true );
			wp_send_json_error( array( 'message' => $err ) );
		}

		wp_send_json_success();
	}

	/**
	 * AJAX: clear an applied redemption.
	 *
	 * @return void
	 */
	public static function ajax_clear_points() {
		check_ajax_referer( 'ocvc_nonce', 'nonce' );

		// Remove only the points — keep the automatic club benefit applied.
		OCVC_Member::set( 'points_to_consume', -1 );
		self::ensure_benefit_query( true );

		wp_send_json_success();
	}
}
