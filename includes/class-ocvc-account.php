<?php
/**
 * My Account — "Club" tab.
 *
 * Adds a club endpoint to the WooCommerce My Account area where a logged-in
 * member sees their points balance, member-since date and profile details
 * (the same fields as the join popup). Edits are pushed to ValueCard via
 * UpdateClubMember; the History tab lists recent accruals/redemptions from
 * RecentCardActivity.
 *
 * Profile data comes from CardInformationEx (ClubMemberDetails returns an
 * empty response for web-POS credentials). The address sent with an update is
 * the account's billing address — the same source enrolment uses — so a stored
 * address is refreshed, never blanked.
 *
 * Identity follows the same rule as the checkout: the account billing phone
 * is the club card number. The phone is therefore shown read-only here —
 * changing it would disconnect the account from the card.
 *
 * @package OC_ValueCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCVC_Account {

	const ENDPOINT = 'valuecard-club';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_items' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( __CLASS__, 'endpoint_title' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_save' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_join' ) );
	}

	/**
	 * Register the endpoint (with a one-time rewrite flush per version).
	 *
	 * @return void
	 */
	public static function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
		if ( '1' !== get_option( 'ocvc_endpoint_version' ) ) {
			flush_rewrite_rules();
			update_option( 'ocvc_endpoint_version', '1' );
		}
	}

	/**
	 * Add the Club item to the My Account menu (before logout).
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public static function menu_items( $items ) {
		$api = new OCVC_API();
		if ( ! $api->is_configured() ) {
			return $items;
		}

		$logout = array();
		if ( isset( $items['customer-logout'] ) ) {
			$logout['customer-logout'] = $items['customer-logout'];
			unset( $items['customer-logout'] );
		}
		$items[ self::ENDPOINT ] = self::tab_label();

		return $items + $logout;
	}

	/**
	 * The tab title (admin-managed, with a sane default).
	 *
	 * @return string
	 */
	public static function tab_label() {
		$label = trim( (string) OCVC_Settings::get( 'account_tab_label' ) );
		return '' !== $label ? $label : __( 'Customers club', 'oc-valuecard' );
	}

	/**
	 * Endpoint page title.
	 *
	 * @return string
	 */
	public static function endpoint_title() {
		return self::tab_label();
	}

	/**
	 * The account's billing address (sent with updates so ValueCard's stored
	 * address is refreshed rather than blanked).
	 *
	 * @return array { address:string, zipcode:string }
	 */
	private static function billing_address() {
		$address = '';
		$zip     = '';
		if ( function_exists( 'WC' ) && WC()->customer ) {
			$address = (string) WC()->customer->get_billing_address_1();
			$zip     = (string) WC()->customer->get_billing_postcode();
		}
		return array(
			'address' => $address,
			'zipcode' => $zip,
		);
	}

	/**
	 * Handle the profile-update POST (before any output, so notices show).
	 *
	 * @return void
	 */
	public static function handle_save() {
		if ( empty( $_POST['ocvc_account_save'] ) || ! is_user_logged_in() ) {
			return;
		}
		if ( ! OCVC_Settings::get_bool( 'account_edit' ) ) {
			return; // Editing is off until ValueCard enables UpdateClubMember.
		}
		if ( empty( $_POST['ocvc_account_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ocvc_account_nonce'] ) ), 'ocvc_account' ) ) {
			return;
		}

		$api = new OCVC_API();
		if ( ! $api->is_configured() ) {
			return;
		}
		$card = OCVC_Member::account_phone();
		if ( ! $card ) {
			return;
		}

		$info = $api->card_information( $card );
		if ( $info->is_error ) {
			wc_add_notice( __( 'Could not update your details: ', 'oc-valuecard' ) . $info->message, 'error' );
			return;
		}

		$gender = isset( $_POST['ocvc_gender'] ) ? sanitize_key( wp_unslash( $_POST['ocvc_gender'] ) ) : '';
		if ( ! in_array( $gender, array( 'male', 'female' ), true ) ) {
			$gender = '';
		}

		// Posted dates; when a date is left empty keep the existing one, so a
		// customer can't accidentally wipe a stored birthday.
		$dates = array(
			'ocvc_birth' => self::iso_to_ymd( $info->birth_date ),
			'ocvc_anniv' => self::iso_to_ymd( $info->anniversary_date ),
		);
		foreach ( $dates as $field => $existing ) {
			$raw = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
			if ( $raw && false !== strtotime( $raw ) ) {
				$dates[ $field ] = gmdate( 'Y-m-d', strtotime( $raw ) );
			}
		}

		$first = isset( $_POST['ocvc_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ocvc_first_name'] ) ) : '';
		$last  = isset( $_POST['ocvc_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ocvc_last_name'] ) ) : '';
		$email = isset( $_POST['ocvc_email'] ) ? sanitize_email( wp_unslash( $_POST['ocvc_email'] ) ) : '';

		$billing = self::billing_address();

		$result = $api->update_club_member(
			array(
				'card_number'      => $card,
				'member_id'        => $info->member_id,
				'first_name'       => '' !== $first ? $first : $info->first_name,
				'last_name'        => '' !== $last ? $last : $info->last_name,
				'email'            => '' !== $email ? $email : $info->email,
				'birth_date'       => $dates['ocvc_birth'],
				'anniversary_date' => $dates['ocvc_anniv'],
				'gender'           => $gender,
				'address'          => $billing['address'],
				'zipcode'          => $billing['zipcode'],
				'marketing'        => empty( $_POST['ocvc_marketing'] ) ? 0 : 1,
			)
		);

		if ( $result->is_error ) {
			wc_add_notice( __( 'Could not update your details: ', 'oc-valuecard' ) . $result->message, 'error' );
		} else {
			wc_add_notice( __( 'Your club details were updated.', 'oc-valuecard' ) );
			// Refresh the cached balance/profile everywhere (e.g. the checkout box).
			OCVC_Member::set( 'member_info', null );
			OCVC_Member::set( 'member_info_ts', null );
		}

		wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
		exit;
	}

	/**
	 * Handle the join-the-club POST from the account page (registers with
	 * ValueCard immediately — unlike the checkout flow, there is no order to
	 * wait for).
	 *
	 * @return void
	 */
	public static function handle_join() {
		if ( empty( $_POST['ocvc_account_join'] ) || ! is_user_logged_in() ) {
			return;
		}
		if ( empty( $_POST['ocvc_account_join_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ocvc_account_join_nonce'] ) ), 'ocvc_account_join' ) ) {
			return;
		}

		$api = new OCVC_API();
		if ( ! $api->is_configured() ) {
			return;
		}
		$card = OCVC_Member::account_phone();
		if ( ! $card ) {
			return;
		}

		$gender = isset( $_POST['ocvc_gender'] ) ? sanitize_key( wp_unslash( $_POST['ocvc_gender'] ) ) : '';
		if ( ! in_array( $gender, array( 'male', 'female' ), true ) ) {
			$gender = '';
		}

		$dates = array(
			'ocvc_birth' => '',
			'ocvc_anniv' => '',
		);
		foreach ( $dates as $field => $unused ) {
			$raw = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
			if ( $raw && false !== strtotime( $raw ) ) {
				$dates[ $field ] = gmdate( 'Y-m-d', strtotime( $raw ) );
			}
		}

		$billing = self::billing_address();

		$result = $api->register_member(
			array(
				'first_name'       => isset( $_POST['ocvc_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ocvc_first_name'] ) ) : '',
				'last_name'        => isset( $_POST['ocvc_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ocvc_last_name'] ) ) : '',
				'phone'            => $card,
				'email'            => isset( $_POST['ocvc_email'] ) ? sanitize_email( wp_unslash( $_POST['ocvc_email'] ) ) : '',
				'address'          => $billing['address'],
				'zip'              => $billing['zipcode'],
				'marketing'        => empty( $_POST['ocvc_marketing'] ) ? 0 : 1,
				'terms'            => 1,
				'birth_date'       => $dates['ocvc_birth'],
				'anniversary_date' => $dates['ocvc_anniv'],
				'gender'           => $gender,
			)
		);

		if ( $result->is_error ) {
			wc_add_notice( __( 'Could not complete the registration: ', 'oc-valuecard' ) . $result->message, 'error' );
		} else {
			wc_add_notice( __( 'Welcome to the club! Your membership is now active.', 'oc-valuecard' ) );
			OCVC_Member::set( 'member_info', null );
			OCVC_Member::set( 'member_info_ts', null );
		}

		wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
		exit;
	}

	/**
	 * Non-member view: the club pitch (same content as the checkout popup) plus
	 * a pre-filled enrolment form.
	 *
	 * @param string $card The account phone (becomes the card number).
	 * @return void
	 */
	private static function render_join( $card ) {
		$content = trim( (string) OCVC_Settings::get( 'join_popup_content' ) );

		$first = '';
		$last  = '';
		$email = '';
		if ( function_exists( 'WC' ) && WC()->customer ) {
			$first = (string) WC()->customer->get_billing_first_name();
			$last  = (string) WC()->customer->get_billing_last_name();
			$email = (string) WC()->customer->get_billing_email();
		}
		if ( '' === $email ) {
			$user  = wp_get_current_user();
			$email = (string) $user->user_email;
		}
		?>
		<div class="ocvc-account">
			<?php if ( '' !== $content ) : ?>
				<div class="ocvc-box">
					<div class="ocvc-join-content"><?php echo wp_kses_post( wpautop( $content ) ); ?></div>
				</div>
			<?php endif; ?>

			<div class="ocvc-box">
				<form method="post" class="ocvc-acc-form">
					<div class="ocvc-join-grid">
						<label class="ocvc-join-field">
							<span><?php esc_html_e( 'First name', 'oc-valuecard' ); ?> *</span>
							<input type="text" name="ocvc_first_name" value="<?php echo esc_attr( $first ); ?>" autocomplete="given-name" />
						</label>
						<label class="ocvc-join-field">
							<span><?php esc_html_e( 'Last name', 'oc-valuecard' ); ?></span>
							<input type="text" name="ocvc_last_name" value="<?php echo esc_attr( $last ); ?>" autocomplete="family-name" />
						</label>
						<label class="ocvc-join-field">
							<span><?php esc_html_e( 'Phone', 'oc-valuecard' ); ?></span>
							<input type="tel" value="<?php echo esc_attr( $card ); ?>" dir="ltr" disabled />
						</label>
						<label class="ocvc-join-field">
							<span><?php esc_html_e( 'Email', 'oc-valuecard' ); ?></span>
							<input type="email" name="ocvc_email" value="<?php echo esc_attr( $email ); ?>" dir="ltr" autocomplete="email" />
						</label>
						<label class="ocvc-join-field">
							<span><?php esc_html_e( 'Birthday', 'oc-valuecard' ); ?></span>
							<input type="date" name="ocvc_birth" lang="en" dir="ltr" max="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" />
						</label>
						<label class="ocvc-join-field">
							<span><?php esc_html_e( 'Anniversary', 'oc-valuecard' ); ?></span>
							<input type="date" name="ocvc_anniv" lang="en" dir="ltr" max="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" />
						</label>
						<label class="ocvc-join-field">
							<span><?php esc_html_e( 'Gender', 'oc-valuecard' ); ?></span>
							<select name="ocvc_gender">
								<option value="">&mdash;</option>
								<option value="male"><?php esc_html_e( 'Male', 'oc-valuecard' ); ?></option>
								<option value="female"><?php esc_html_e( 'Female', 'oc-valuecard' ); ?></option>
							</select>
						</label>
					</div>

					<p class="ocvc-acc-note"><?php esc_html_e( 'Your membership will be linked to this phone number (from your billing details).', 'oc-valuecard' ); ?></p>

					<label class="ocvc-join-consent">
						<input type="checkbox" name="ocvc_marketing" checked="checked" />
						<span><?php esc_html_e( 'I agree to receive updates and offers by email and SMS', 'oc-valuecard' ); ?></span>
					</label>

					<?php wp_nonce_field( 'ocvc_account_join', 'ocvc_account_join_nonce' ); ?>
					<input type="hidden" name="ocvc_account_join" value="1" />
					<button type="submit" class="ocvc-redeem-btn ocvc-acc-save"><?php esc_html_e( 'Join for free', 'oc-valuecard' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the endpoint content.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$api = new OCVC_API();
		if ( ! $api->is_configured() ) {
			echo '<p>' . esc_html__( 'Loyalty program is not configured.', 'oc-valuecard' ) . '</p>';
			return;
		}

		$card = OCVC_Member::account_phone();
		if ( ! $card ) {
			echo '<p>' . esc_html__( 'Add a phone number to your account billing details to see your club info.', 'oc-valuecard' ) . '</p>';
			return;
		}

		$info = $api->card_information( $card );
		if ( $info->is_error ) {
			self::render_join( $card );
			return;
		}

		$activity = $api->recent_card_activity( $card, $info->card_id ? $info->card_id : '0' );
		$since    = self::format_date( $info->join_date );

		echo '<div class="ocvc-account">';
		self::render_head( $info, $since );
		?>
		<nav class="ocvc-acc-tabs">
			<button type="button" class="ocvc-acc-tab is-active" data-ocvc-tab="details"><?php esc_html_e( 'My details', 'oc-valuecard' ); ?></button>
			<button type="button" class="ocvc-acc-tab" data-ocvc-tab="history"><?php esc_html_e( 'History', 'oc-valuecard' ); ?></button>
		</nav>
		<?php
		self::render_details_panel( $info, $card );
		self::render_history_panel( $activity );
		?>
		<script>
		( function () {
			var tabs = document.querySelectorAll( '.ocvc-acc-tab' );
			tabs.forEach( function ( tab ) {
				tab.addEventListener( 'click', function () {
					tabs.forEach( function ( t ) { t.classList.remove( 'is-active' ); } );
					tab.classList.add( 'is-active' );
					document.querySelectorAll( '[data-ocvc-panel]' ).forEach( function ( p ) {
						p.hidden = ( p.getAttribute( 'data-ocvc-panel' ) !== tab.getAttribute( 'data-ocvc-tab' ) );
					} );
				} );
			} );
		} )();
		</script>
		<?php
		echo '</div>';
	}

	/**
	 * Header card: balance + member since.
	 *
	 * @param object $info  CardInformationEx result.
	 * @param string $since Formatted member-since date.
	 * @return void
	 */
	private static function render_head( $info, $since ) {
		$balance = (float) $info->prepaid_balance;
		$n       = ( floor( $balance ) == $balance ) ? number_format_i18n( $balance, 0 ) : number_format_i18n( $balance, 2 );
		?>
		<div class="ocvc-box ocvc-acc-head">
			<div class="ocvc-acc-balance">
				<span class="ocvc-balance-amount"><?php echo esc_html( $n ); ?></span>
				<span class="ocvc-balance-word"><?php esc_html_e( 'points', 'oc-valuecard' ); ?></span>
				<span class="ocvc-balance-sub"><?php esc_html_e( 'for use', 'oc-valuecard' ); ?></span>
			</div>
			<div class="ocvc-acc-meta">
				<?php if ( $since ) : ?>
					<div><?php esc_html_e( 'Member since', 'oc-valuecard' ); ?>: <strong><?php echo esc_html( $since ); ?></strong></div>
				<?php endif; ?>
				<?php if ( ! empty( $info->card_group ) ) : ?>
					<div><?php esc_html_e( 'Card group', 'oc-valuecard' ); ?>: <strong><?php echo esc_html( $info->card_group ); ?></strong></div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Details panel: editable profile form.
	 *
	 * @param object $info CardInformationEx result.
	 * @param string $card Card number (phone).
	 * @return void
	 */
	private static function render_details_panel( $info, $card ) {
		if ( ! OCVC_Settings::get_bool( 'account_edit' ) ) {
			self::render_details_readonly( $info, $card );
			return;
		}
		?>
		<div class="ocvc-box" data-ocvc-panel="details">
			<form method="post" class="ocvc-acc-form">
				<div class="ocvc-join-grid">
					<label class="ocvc-join-field">
						<span><?php esc_html_e( 'First name', 'oc-valuecard' ); ?></span>
						<input type="text" name="ocvc_first_name" value="<?php echo esc_attr( $info->first_name ); ?>" autocomplete="given-name" />
					</label>
					<label class="ocvc-join-field">
						<span><?php esc_html_e( 'Last name', 'oc-valuecard' ); ?></span>
						<input type="text" name="ocvc_last_name" value="<?php echo esc_attr( $info->last_name ); ?>" autocomplete="family-name" />
					</label>
					<label class="ocvc-join-field">
						<span><?php esc_html_e( 'Phone', 'oc-valuecard' ); ?></span>
						<input type="tel" value="<?php echo esc_attr( $card ); ?>" dir="ltr" disabled />
					</label>
					<label class="ocvc-join-field">
						<span><?php esc_html_e( 'Email', 'oc-valuecard' ); ?></span>
						<input type="email" name="ocvc_email" value="<?php echo esc_attr( $info->email ); ?>" dir="ltr" autocomplete="email" />
					</label>
					<label class="ocvc-join-field">
						<span><?php esc_html_e( 'Birthday', 'oc-valuecard' ); ?></span>
						<input type="date" name="ocvc_birth" value="<?php echo esc_attr( self::iso_to_ymd( $info->birth_date ) ); ?>" lang="en" dir="ltr" max="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" />
					</label>
					<label class="ocvc-join-field">
						<span><?php esc_html_e( 'Anniversary', 'oc-valuecard' ); ?></span>
						<input type="date" name="ocvc_anniv" value="<?php echo esc_attr( self::iso_to_ymd( $info->anniversary_date ) ); ?>" lang="en" dir="ltr" max="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" />
					</label>
					<label class="ocvc-join-field">
						<span><?php esc_html_e( 'Gender', 'oc-valuecard' ); ?></span>
						<select name="ocvc_gender">
							<option value="">&mdash;</option>
							<option value="male"><?php esc_html_e( 'Male', 'oc-valuecard' ); ?></option>
							<option value="female"><?php esc_html_e( 'Female', 'oc-valuecard' ); ?></option>
						</select>
					</label>
				</div>

				<p class="ocvc-acc-note"><?php esc_html_e( 'The phone number identifies your club card. To change it, please contact the store.', 'oc-valuecard' ); ?></p>

				<label class="ocvc-join-consent">
					<input type="checkbox" name="ocvc_marketing" <?php checked( 1, (int) $info->marcomm ); ?> />
					<span><?php esc_html_e( 'I agree to receive updates and offers by email and SMS', 'oc-valuecard' ); ?></span>
				</label>

				<?php wp_nonce_field( 'ocvc_account', 'ocvc_account_nonce' ); ?>
				<input type="hidden" name="ocvc_account_save" value="1" />
				<button type="submit" class="ocvc-redeem-btn ocvc-acc-save"><?php esc_html_e( 'Save changes', 'oc-valuecard' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Details panel, read-only variant (member editing not enabled).
	 *
	 * @param object $info CardInformationEx result.
	 * @param string $card Card number (phone).
	 * @return void
	 */
	private static function render_details_readonly( $info, $card ) {
		$rows = array(
			__( 'First name', 'oc-valuecard' )  => $info->first_name,
			__( 'Last name', 'oc-valuecard' )   => $info->last_name,
			__( 'Phone', 'oc-valuecard' )       => $card,
			__( 'Email', 'oc-valuecard' )       => $info->email,
			__( 'Birthday', 'oc-valuecard' )    => self::format_date( $info->birth_date ),
			__( 'Anniversary', 'oc-valuecard' ) => self::format_date( $info->anniversary_date ),
		);
		?>
		<div class="ocvc-box" data-ocvc-panel="details">
			<ul class="ocvc-details-list">
				<?php foreach ( $rows as $label => $value ) : ?>
					<?php if ( '' !== (string) $value ) : ?>
						<li><span><?php echo esc_html( $label ); ?></span><strong><?php echo esc_html( $value ); ?></strong></li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
			<p class="ocvc-acc-note"><?php esc_html_e( 'To update your details, please contact the store.', 'oc-valuecard' ); ?></p>
		</div>
		<?php
	}

	/**
	 * History panel: recent accruals / redemptions.
	 *
	 * @param object $activity recent_card_activity() result.
	 * @return void
	 */
	private static function render_history_panel( $activity ) {
		?>
		<div class="ocvc-box" data-ocvc-panel="history" hidden>
			<?php if ( $activity->is_error || empty( $activity->rows ) ) : ?>
				<p><?php esc_html_e( 'No activity yet.', 'oc-valuecard' ); ?></p>
			<?php else : ?>
				<table class="ocvc-acc-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'oc-valuecard' ); ?></th>
							<th><?php esc_html_e( 'Action', 'oc-valuecard' ); ?></th>
							<th><?php esc_html_e( 'Amount', 'oc-valuecard' ); ?></th>
							<th><?php esc_html_e( 'Points used', 'oc-valuecard' ); ?></th>
							<th><?php esc_html_e( 'Points earned', 'oc-valuecard' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_slice( $activity->rows, 0, 30 ) as $row ) : ?>
							<?php
							$amount = $row->final_amount ? $row->final_amount : $row->amount;
							$used   = abs( $row->points_used );   // ValueCard reports redemptions as negatives.
							$earned = abs( $row->points_earned );
							?>
							<tr>
								<td><?php echo esc_html( self::format_date( $row->date, true ) ); ?></td>
								<td><?php echo esc_html( $row->action ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $amount, 2 ) ); ?></td>
								<td><?php echo esc_html( $used > 0.004 ? number_format_i18n( $used, 2 ) : '—' ); ?></td>
								<td><?php echo esc_html( $earned > 0.004 ? number_format_i18n( $earned, 2 ) : '—' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * ISO date → Y-m-d for date inputs ('' for the 1900 "empty" sentinel).
	 *
	 * @param string $iso ISO date string.
	 * @return string
	 */
	private static function iso_to_ymd( $iso ) {
		if ( ! $iso ) {
			return '';
		}
		$t = strtotime( $iso );
		if ( ! $t || (int) gmdate( 'Y', $t ) <= 1900 ) {
			return '';
		}
		return gmdate( 'Y-m-d', $t );
	}

	/**
	 * ISO date → display date ('' for the 1900 sentinel).
	 *
	 * @param string $iso       ISO date string.
	 * @param bool   $with_time Include the time.
	 * @return string
	 */
	private static function format_date( $iso, $with_time = false ) {
		if ( ! $iso ) {
			return '';
		}
		$t = strtotime( $iso );
		if ( ! $t || (int) gmdate( 'Y', $t ) <= 1900 ) {
			return '';
		}
		return date_i18n( $with_time ? 'd/m/Y H:i' : 'd/m/Y', $t );
	}
}
