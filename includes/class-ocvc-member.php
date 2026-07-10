<?php
/**
 * Member identity + balance.
 *
 * Resolves "who is this shopper" and pulls their ValueCard balance:
 *   - Logged-in shopper: we trust the account phone (no re-auth needed).
 *   - Guest: identified only after phone + OTP verification.
 *
 * All per-session state lives in the WooCommerce session so it survives the
 * AJAX round-trips of the checkout without touching the database.
 *
 * @package OC_ValueCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCVC_Member {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_ocvc_send_otp', array( __CLASS__, 'ajax_send_otp' ) );
		add_action( 'wp_ajax_nopriv_ocvc_send_otp', array( __CLASS__, 'ajax_send_otp' ) );
		add_action( 'wp_ajax_ocvc_verify_otp', array( __CLASS__, 'ajax_verify_otp' ) );
		add_action( 'wp_ajax_nopriv_ocvc_verify_otp', array( __CLASS__, 'ajax_verify_otp' ) );
		add_action( 'wp_ajax_ocvc_logout', array( __CLASS__, 'ajax_logout' ) );
		add_action( 'wp_ajax_nopriv_ocvc_logout', array( __CLASS__, 'ajax_logout' ) );
	}

	/* --------------------------------------------------------------------- *
	 * Session helpers
	 * --------------------------------------------------------------------- */

	/**
	 * Get a session value (namespaced under ocvc_).
	 *
	 * @param string $key     Key without prefix.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		if ( ! WC()->session ) {
			return $default;
		}
		$value = WC()->session->get( 'ocvc_' . $key );
		return ( null === $value || '' === $value ) ? $default : $value;
	}

	/**
	 * Set a session value.
	 *
	 * @param string $key   Key without prefix.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public static function set( $key, $value ) {
		if ( ! WC()->session ) {
			return;
		}
		// Ensure a guest gets a real WooCommerce session cookie, so this data
		// survives to the next request (otherwise the verified identity is lost
		// on the checkout refresh right after OTP verification).
		if ( method_exists( WC()->session, 'has_session' ) && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
		WC()->session->set( 'ocvc_' . $key, $value );
	}

	/**
	 * Clear all loyalty session state (e.g. on disconnect).
	 *
	 * @return void
	 */
	public static function clear() {
		foreach ( array( 'phone', 'verified', 'member_info', 'points_to_consume', 'transaction_id', 'discount', 'redeemed_points', 'earn', 'benefit_names', 'qsum', 'qpoints' ) as $key ) {
			self::set( $key, null );
		}
	}

	/* --------------------------------------------------------------------- *
	 * Identity + balance
	 * --------------------------------------------------------------------- */

	/**
	 * Resolve the phone/card to use for this shopper, or '' if none yet.
	 *
	 * @return string
	 */
	public static function card_number() {
		// Guest who verified via OTP.
		if ( self::get( 'verified' ) && self::get( 'phone' ) ) {
			return self::normalise_phone( self::get( 'phone' ) );
		}

		// Logged-in shopper — trust the account phone.
		if ( is_user_logged_in() && OCVC_Settings::get_bool( 'pull_on_login' ) ) {
			$phone = self::account_phone();
			if ( $phone ) {
				return $phone;
			}
		}

		return '';
	}

	/**
	 * Get the logged-in customer's phone number.
	 *
	 * @return string
	 */
	public static function account_phone() {
		$phone = '';
		if ( WC()->customer ) {
			$phone = WC()->customer->get_billing_phone();
		}
		if ( ! $phone ) {
			$phone = get_user_meta( get_current_user_id(), 'billing_phone', true );
		}
		return self::normalise_phone( $phone );
	}

	/**
	 * Pull (and cache) the member's balance for the current identity.
	 *
	 * @param bool $force Re-pull even if already cached this session.
	 * @return object|null Balance object with ->is_member, ->balance, ->name, or null if no identity.
	 */
	public static function ensure_balance( $force = false ) {
		$card = self::card_number();
		if ( ! $card ) {
			return null;
		}

		$cached = self::get( 'member_info' );
		if ( ! $force && is_array( $cached ) && isset( $cached['card'] ) && $cached['card'] === $card ) {
			return (object) $cached;
		}

		$api = new OCVC_API();
		if ( ! $api->is_configured() ) {
			return null;
		}

		$info      = $api->card_information( $card );
		$is_member = ! $info->is_error;

		$data = array(
			'card'       => $card,
			'is_member'  => $is_member,
			'balance'    => $is_member ? (float) $info->prepaid_balance : 0.0,
			'units'      => $is_member ? $info->card_units : '',
			'name'       => $is_member ? $info->full_name : '',
			'first_name' => $is_member ? $info->first_name : '',
			'last_name'  => $is_member ? $info->last_name : '',
			'email'      => $is_member ? $info->email : '',
			'phone'      => $is_member ? ( $info->cell_phone ? $info->cell_phone : $card ) : '',
			'card_group' => $is_member ? $info->card_group : '',
			'status'     => $is_member ? $info->status_text : '',
			'member_id'  => $is_member ? $info->member_id : '',
			'birth_date' => $is_member ? self::format_date( $info->birth_date ) : '',
			'benefits'   => $is_member ? self::parse_benefits( $info->user_benefits_json, $info->available_benefits ) : array(),
		);

		self::set( 'phone', $card );
		self::set( 'member_info', $data );

		return (object) $data;
	}

	/**
	 * Format an ISO date for display, hiding ValueCard's 1900 "empty" sentinel.
	 *
	 * @param string $iso ISO date string.
	 * @return string
	 */
	private static function format_date( $iso ) {
		if ( ! $iso ) {
			return '';
		}
		$t = strtotime( $iso );
		if ( ! $t || (int) gmdate( 'Y', $t ) <= 1900 ) {
			return '';
		}
		return date_i18n( 'd/m/Y', $t );
	}

	/**
	 * Extract benefit titles from the card-information payload.
	 *
	 * @param string $json     UserBenefitsJson (preferred).
	 * @param string $fallback AvailableBenefits pipe-format string.
	 * @return array List of benefit titles.
	 */
	public static function parse_benefits( $json, $fallback ) {
		$out = array();

		if ( $json ) {
			$decoded = json_decode( $json, true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $b ) {
					if ( ! empty( $b['Title'] ) ) {
						$out[] = trim( $b['Title'] );
					}
				}
			}
		}

		if ( empty( $out ) && $fallback ) {
			// Fallback format: "id|title|desc|...;id|title|..."
			foreach ( explode( ';', $fallback ) as $row ) {
				$parts = explode( '|', $row );
				if ( isset( $parts[1] ) && '' !== trim( $parts[1] ) ) {
					$out[] = trim( $parts[1] );
				}
			}
		}

		return $out;
	}

	/**
	 * AJAX: disconnect the current member (clear session).
	 *
	 * @return void
	 */
	public static function ajax_logout() {
		check_ajax_referer( 'ocvc_nonce', 'nonce' );
		self::clear();
		wp_send_json_success();
	}

	/**
	 * Normalise a phone number to digits only.
	 *
	 * @param string $phone Raw phone.
	 * @return string
	 */
	public static function normalise_phone( $phone ) {
		return preg_replace( '/\D/', '', (string) $phone );
	}

	/**
	 * Best-effort client IP for rate limiting.
	 *
	 * @return string
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		return $ip;
	}

	/* --------------------------------------------------------------------- *
	 * Guest OTP (AJAX)
	 * --------------------------------------------------------------------- */

	/**
	 * AJAX: send an OTP to a guest's phone.
	 *
	 * @return void
	 */
	public static function ajax_send_otp() {
		check_ajax_referer( 'ocvc_nonce', 'nonce' );

		$phone = isset( $_POST['phone'] ) ? self::normalise_phone( wp_unslash( $_POST['phone'] ) ) : '';
		if ( strlen( $phone ) < 6 ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid phone number.', 'oc-valuecard' ) ) );
		}

		// Basic abuse guard: a short cooldown between sends per client.
		$rate_key = 'ocvc_otp_cd_' . md5( self::client_ip() );
		if ( get_transient( $rate_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Please wait a few seconds before requesting another code.', 'oc-valuecard' ) ) );
		}
		set_transient( $rate_key, 1, 30 );

		$api = new OCVC_API();
		if ( ! $api->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Loyalty program is not configured.', 'oc-valuecard' ) ) );
		}

		$result = $api->send_otp( $phone );

		if ( empty( $result['success'] ) ) {
			wp_send_json_error( array( 'message' => $result['message'] ? $result['message'] : __( 'Could not send code.', 'oc-valuecard' ) ) );
		}

		// Remember the phone we are verifying (not yet verified).
		self::set( 'phone', $phone );
		self::set( 'verified', null );

		wp_send_json_success( array( 'message' => __( 'A code has been sent to your phone.', 'oc-valuecard' ) ) );
	}

	/**
	 * AJAX: verify a guest's OTP and pull the balance.
	 *
	 * @return void
	 */
	public static function ajax_verify_otp() {
		check_ajax_referer( 'ocvc_nonce', 'nonce' );

		$phone = isset( $_POST['phone'] ) ? self::normalise_phone( wp_unslash( $_POST['phone'] ) ) : (string) self::get( 'phone', '' );
		$code  = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';

		if ( ! $phone || ! $code ) {
			wp_send_json_error( array( 'message' => __( 'Missing phone or code.', 'oc-valuecard' ) ) );
		}

		$api    = new OCVC_API();
		$result = $api->verify_otp( $phone, $code );

		if ( empty( $result['success'] ) ) {
			wp_send_json_error( array( 'message' => $result['message'] ? $result['message'] : __( 'Incorrect code.', 'oc-valuecard' ) ) );
		}

		self::set( 'phone', $phone );
		self::set( 'verified', 1 );
		self::set( 'pulled', null );

		$balance = self::ensure_balance( true );

		wp_send_json_success(
			array(
				'is_member' => $balance ? $balance->is_member : false,
				'balance'   => $balance ? $balance->balance : 0,
				'name'      => $balance ? $balance->name : '',
			)
		);
	}
}
