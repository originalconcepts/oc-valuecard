<?php
/**
 * Settings: storage + admin page.
 *
 * All configuration lives in a single option (ocvc_settings) so the plugin is
 * genuinely plug-and-play — drop it on any WordPress + WooCommerce site, fill in
 * the per-client credentials, choose the commit status, and go.
 *
 * @package OC_ValueCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCVC_Settings {

	const OPTION = 'ocvc_settings';
	const GROUP  = 'ocvc_settings_group';
	const PAGE   = 'oc-valuecard';

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_filter(
			'plugin_action_links_' . OCVC_PLUGIN_BASENAME,
			array( __CLASS__, 'action_links' )
		);
	}

	/**
	 * Field schema. Adding a new option = adding one entry here.
	 *
	 * @return array
	 */
	public static function fields() {
		return array(
			// --- Credentials (per client) ---
			'vc_token'          => array(
				'section' => 'credentials',
				'label'   => __( 'VC Token', 'oc-valuecard' ),
				'type'    => 'text',
				'default' => '',
			),
			'pos_id'            => array(
				'section' => 'credentials',
				'label'   => __( 'POS ID', 'oc-valuecard' ),
				'type'    => 'text',
				'default' => '',
			),
			'pos_password'      => array(
				'section' => 'credentials',
				'label'   => __( 'POS Password', 'oc-valuecard' ),
				'type'    => 'password',
				'default' => '',
			),
			'cashiers_password' => array(
				'section' => 'credentials',
				'label'   => __( "Cashier's Password", 'oc-valuecard' ),
				'type'    => 'password',
				'default' => '',
			),

			// --- Behaviour ---
			'reserve_statuses'  => array(
				'section' => 'behaviour',
				'label'   => __( 'Reserve / commit points on status', 'oc-valuecard' ),
				'type'    => 'order_status_multi',
				'default' => array( 'wc-on-hold', 'wc-processing' ),
				'desc'    => __( 'When an order first reaches ANY ticked status, points are committed to ValueCard (locking redemption so they cannot be spent twice). Tick the statuses your orders land in — e.g. credit-card orders start On hold, cash orders start Processing.', 'oc-valuecard' ),
			),
			'settle_status'     => array(
				'section' => 'behaviour',
				'label'   => __( 'Settle & lock points on status', 'oc-valuecard' ),
				'type'    => 'order_status',
				'default' => 'wc-completed',
				'desc'    => __( 'Final status: the redemption/accrual is re-synced to the final order and then permanently locked. Recommended: Completed.', 'oc-valuecard' ),
			),
			'resync_on_edit'    => array(
				'section' => 'behaviour',
				'label'   => __( 'Re-sync ValueCard when an order is edited', 'oc-valuecard' ),
				'type'    => 'checkbox',
				'default' => 1,
				'desc'    => __( 'After points are reserved, if staff edit the order (weight, quantity, items) the commit is updated to match (void + re-quote + re-commit). Turn off to only flag edits for manual review.', 'oc-valuecard' ),
			),
			'account_edit'      => array(
				'section' => 'behaviour',
				'label'   => __( 'Let members edit their club details in My Account', 'oc-valuecard' ),
				'type'    => 'checkbox',
				'default' => 0,
				'desc'    => __( 'Requires the UpdateClubMember operation to be enabled for your POS account by ValueCard. Until then the club tab shows the details read-only.', 'oc-valuecard' ),
			),
			'pull_on_login'     => array(
				'section' => 'behaviour',
				'label'   => __( 'Pull points when a member logs in', 'oc-valuecard' ),
				'type'    => 'checkbox',
				'default' => 1,
			),
			'pull_on_checkout'  => array(
				'section' => 'behaviour',
				'label'   => __( 'Pull points on the checkout page', 'oc-valuecard' ),
				'type'    => 'checkbox',
				'default' => 1,
			),
			'void_on_cancel'    => array(
				'section' => 'behaviour',
				'label'   => __( 'Void transaction on cancel/refund', 'oc-valuecard' ),
				'type'    => 'checkbox',
				'default' => 1,
			),
			'debug_logging'     => array(
				'section' => 'behaviour',
				'label'   => __( 'Enable debug logging (redacted, protected folder)', 'oc-valuecard' ),
				'type'    => 'checkbox',
				'default' => 0,
			),

			// --- Appearance ---
			'primary_color'     => array(
				'section' => 'appearance',
				'label'   => __( 'Primary color', 'oc-valuecard' ),
				'type'    => 'color',
				'default' => '#1aa251',
				'desc'    => __( 'Match the loyalty area to your brand. Affects buttons, highlights and the points box.', 'oc-valuecard' ),
			),

			// --- Member sign-in (OTP) ---
			'redeem_button_label' => array(
				'section' => 'redeem',
				'label'   => __( 'Redeem button label', 'oc-valuecard' ),
				'type'    => 'text',
				'default' => __( 'Redeem points', 'oc-valuecard' ),
			),
			'member_login_label' => array(
				'section' => 'redeem',
				'label'   => __( '"I have a membership" link text', 'oc-valuecard' ),
				'type'    => 'text',
				'default' => __( 'I have a membership', 'oc-valuecard' ),
			),
			'otp_title'         => array(
				'section' => 'redeem',
				'label'   => __( 'Sign-in popup title', 'oc-valuecard' ),
				'type'    => 'text',
				'default' => __( 'Member sign in', 'oc-valuecard' ),
			),
			'otp_intro'         => array(
				'section' => 'redeem',
				'label'   => __( 'Sign-in popup explanation', 'oc-valuecard' ),
				'type'    => 'textarea',
				'default' => __( 'Enter the phone number linked to your membership and we will send you a verification code by SMS.', 'oc-valuecard' ),
			),
			'otp_send_label'    => array(
				'section' => 'redeem',
				'label'   => __( 'Send-code button label', 'oc-valuecard' ),
				'type'    => 'text',
				'default' => __( 'Send code', 'oc-valuecard' ),
			),

			// --- Join club (non-members) ---
			'enable_join_club'  => array(
				'section' => 'join',
				'label'   => __( 'Offer "Join the club" to non-members', 'oc-valuecard' ),
				'type'    => 'checkbox',
				'default' => 1,
			),
			'join_checkbox_label' => array(
				'section' => 'join',
				'label'   => __( 'Join checkbox label', 'oc-valuecard' ),
				'type'    => 'text',
				'default' => __( 'Join our [loyalty club]', 'oc-valuecard' ),
				'desc'    => __( 'Text inside [square brackets] becomes a link that opens the info popup below.', 'oc-valuecard' ),
			),
			'join_popup_content' => array(
				'section' => 'join',
				'label'   => __( 'Join popup content', 'oc-valuecard' ),
				'type'    => 'wysiwyg',
				'default' => '',
			),
		);
	}

	/**
	 * Section metadata.
	 *
	 * @return array
	 */
	private static function sections() {
		return array(
			'credentials' => __( 'ValueCard Credentials', 'oc-valuecard' ),
			'behaviour'   => __( 'Behaviour', 'oc-valuecard' ),
			'appearance'  => __( 'Appearance', 'oc-valuecard' ),
			'redeem'      => __( 'Guest Redemption (OTP)', 'oc-valuecard' ),
			'join'        => __( 'Join the Club', 'oc-valuecard' ),
		);
	}

	/* --------------------------------------------------------------------- *
	 * Storage helpers
	 * --------------------------------------------------------------------- */

	/**
	 * Get one setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback if not set (defaults to the field default).
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		if ( null === self::$cache ) {
			self::$cache = get_option( self::OPTION, array() );
			if ( ! is_array( self::$cache ) ) {
				self::$cache = array();
			}
		}

		if ( array_key_exists( $key, self::$cache ) ) {
			return self::$cache[ $key ];
		}

		if ( null !== $default ) {
			return $default;
		}

		$fields = self::fields();
		return isset( $fields[ $key ]['default'] ) ? $fields[ $key ]['default'] : null;
	}

	/**
	 * Get a boolean setting.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function get_bool( $key ) {
		return (bool) self::get( $key );
	}

	/* --------------------------------------------------------------------- *
	 * Admin page
	 * --------------------------------------------------------------------- */

	/**
	 * Add the settings page under the WooCommerce menu.
	 *
	 * @return void
	 */
	public static function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'OC ValueCard', 'oc-valuecard' ),
			__( 'OC ValueCard', 'oc-valuecard' ),
			'manage_woocommerce',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register the option and its sanitisation.
	 *
	 * @return void
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) )
		);
	}

	/**
	 * Sanitise submitted settings.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$existing = get_option( self::OPTION, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		// Ignore any post that didn't come from our current settings form. This
		// stops a stale or partial form (e.g. a page rendered by an older plugin
		// version) from wiping fields it never rendered.
		if ( empty( $input['__submitted'] ) ) {
			return $existing;
		}

		$clean  = $existing;
		$fields = self::fields();

		foreach ( $fields as $key => $field ) {
			if ( 'checkbox' === $field['type'] ) {
				$clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
				continue;
			}

			// Multi-checkbox statuses: unchecked boxes are not posted, so an absent key
			// means "all unticked" and must clear the value (like a checkbox), not
			// preserve the stale one. Keep only real, current order statuses.
			if ( 'order_status_multi' === $field['type'] ) {
				$vals          = ( isset( $input[ $key ] ) && is_array( $input[ $key ] ) ) ? $input[ $key ] : array();
				$vals          = array_map( 'sanitize_text_field', $vals );
				$valid         = function_exists( 'wc_get_order_statuses' ) ? array_keys( wc_get_order_statuses() ) : array();
				$clean[ $key ] = array_values( $valid ? array_intersect( $vals, $valid ) : array_filter( $vals ) );
				continue;
			}

			// Preserve the stored value for any field this form did not submit.
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			$value = $input[ $key ];
			switch ( $field['type'] ) {
				case 'wysiwyg':
					$clean[ $key ] = wp_kses_post( $value );
					break;
				case 'textarea':
					$clean[ $key ] = sanitize_textarea_field( $value );
					break;
				case 'color':
					$clean[ $key ] = sanitize_hex_color( $value ) ? sanitize_hex_color( $value ) : '#1aa251';
					break;
				default:
					$clean[ $key ] = sanitize_text_field( $value );
			}
		}

		return $clean;
	}

	/**
	 * Add a "Settings" link on the plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'oc-valuecard' ) . '</a>' );
		return $links;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public static function render_page() {
		$fields   = self::fields();
		$sections = self::sections();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'OC ValueCard', 'oc-valuecard' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>[__submitted]" value="1" />

				<?php foreach ( $sections as $section_id => $section_label ) : ?>
					<h2><?php echo esc_html( $section_label ); ?></h2>
					<table class="form-table" role="presentation">
						<tbody>
						<?php
						foreach ( $fields as $key => $field ) {
							if ( $field['section'] !== $section_id ) {
								continue;
							}
							self::render_field( $key, $field );
						}
						?>
						</tbody>
					</table>
				<?php endforeach; ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a single field row.
	 *
	 * @param string $key   Field key.
	 * @param array  $field Field definition.
	 * @return void
	 */
	private static function render_field( $key, $field ) {
		$name  = self::OPTION . '[' . $key . ']';
		$id    = 'ocvc_' . $key;
		$value = self::get( $key );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label></th><td>';

		switch ( $field['type'] ) {
			case 'checkbox':
				echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( 1, (int) $value, false ) . ' />';
				break;

			case 'password':
				echo '<input type="password" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text" autocomplete="new-password" />';
				break;

			case 'order_status':
				$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
				foreach ( $statuses as $status_key => $status_label ) {
					echo '<option value="' . esc_attr( $status_key ) . '" ' . selected( $value, $status_key, false ) . '>' . esc_html( $status_label ) . '</option>';
				}
				echo '</select>';
				break;

			case 'order_status_multi':
				$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
				$selected = is_array( $value ) ? $value : ( $value ? array( $value ) : array() );
				echo '<fieldset>';
				foreach ( $statuses as $status_key => $status_label ) {
					$cid = $id . '_' . sanitize_html_class( $status_key );
					echo '<label for="' . esc_attr( $cid ) . '" style="display:inline-block;min-width:190px;margin:2px 0;">'
						. '<input type="checkbox" id="' . esc_attr( $cid ) . '" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( $status_key ) . '" ' . checked( in_array( $status_key, $selected, true ), true, false ) . ' /> '
						. esc_html( $status_label ) . '</label>';
				}
				echo '</fieldset>';
				break;

			case 'wysiwyg':
				wp_editor(
					$value,
					$id,
					array(
						'textarea_name' => $name,
						'textarea_rows' => 8,
						'media_buttons' => true,
					)
				);
				break;

			case 'textarea':
				echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="3" class="large-text">' . esc_textarea( $value ) . '</textarea>';
				break;

			case 'color':
				$color = $value ? $value : '#1aa251';
				echo '<input type="color" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $color ) . '" style="width:60px;height:34px;vertical-align:middle;" />';
				echo ' <code style="vertical-align:middle;">' . esc_html( $color ) . '</code>';
				break;

			default:
				echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text" />';
		}

		if ( ! empty( $field['desc'] ) ) {
			echo '<p class="description">' . esc_html( $field['desc'] ) . '</p>';
		}

		echo '</td></tr>';
	}
}
