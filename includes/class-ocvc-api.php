<?php
/**
 * ValueCard API client.
 *
 * Wraps both ValueCard API generations behind one clean class:
 *   - Legacy SOAP V5 (ws.valuecard.co.il/pos/V5) for points/benefits/payment.
 *   - Newer REST (valuecard.co.il/api/Woocommerce) for token + OTP auth.
 *
 * Every method returns a normalised array/object; callers never touch XML.
 *
 * @package OC_ValueCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCVC_API {

	/** SOAP V5 base (all POS operations). */
	const SOAP_BASE = 'https://ws.valuecard.co.il/pos/V5/Default.asmx';

	/** SOAP XML namespace used inside the envelope. */
	const SOAP_NS = 'https://ws.valuecard.co.il/pos/V5/';

	/** REST base (token + OTP). */
	const REST_BASE = 'https://valuecard.co.il/api/Woocommerce/';

	/**
	 * Credentials, resolved from settings.
	 *
	 * @var array
	 */
	private $creds;

	/**
	 * Constructor.
	 *
	 * @param array|null $creds Optional credential override; defaults to saved settings.
	 */
	public function __construct( $creds = null ) {
		if ( is_array( $creds ) ) {
			$this->creds = $creds;
		} else {
			$this->creds = array(
				'vc_token'         => OCVC_Settings::get( 'vc_token' ),
				'pos_id'           => OCVC_Settings::get( 'pos_id' ),
				'pos_password'     => OCVC_Settings::get( 'pos_password' ),
				'cashiers_password' => OCVC_Settings::get( 'cashiers_password' ),
			);
		}
	}

	/**
	 * Whether the API has enough credentials to operate.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return ! empty( $this->creds['vc_token'] ) && ! empty( $this->creds['pos_id'] );
	}

	/* --------------------------------------------------------------------- *
	 * SOAP V5 operations
	 * --------------------------------------------------------------------- */

	/**
	 * Pull a member's card information and balance.
	 *
	 * @param string $card_number Card number or member phone.
	 * @return object Normalised result with ->is_error and balance/member fields.
	 */
	public function card_information( $card_number ) {
		$card_number = $this->esc_xml( $card_number );

		$body = '<CardInformationEx xmlns="' . self::SOAP_NS . '">'
			. '<RequestParameters>'
			. $this->common_block( $card_number )
			. '<CardNumber>' . $card_number . '</CardNumber>'
			. '<PIN></PIN>'
			. '<Coupon>0</Coupon>'
			. '</RequestParameters>'
			. '</CardInformationEx>';

		$xml = $this->soap_request( 'CardInformationEx', $body );

		$result       = new stdClass();
		$result->is_error = true;

		if ( ! $xml ) {
			$result->message = __( 'Could not connect to ValueCard.', 'oc-valuecard' );
			return $result;
		}

		$node = isset( $xml->soapBody->CardInformationExResponse->CardInformationExResult )
			? $xml->soapBody->CardInformationExResponse->CardInformationExResult
			: null;

		if ( ! $node ) {
			$result->message = __( 'Unexpected ValueCard response.', 'oc-valuecard' );
			return $result;
		}

		$result->is_error        = $this->is_error( $node );
		$result->message         = isset( $node->Common->Message ) ? (string) $node->Common->Message : '';
		$result->print_message   = isset( $node->Common->PrintMessage ) ? (string) $node->Common->PrintMessage : '';
		$result->prepaid_balance = isset( $node->PrepaidBalance ) ? (float) $node->PrepaidBalance : 0.0;
		$result->total_points    = isset( $node->TotalPoints ) ? (float) $node->TotalPoints : 0.0;
		$result->member_id       = isset( $node->MemberId ) ? (string) $node->MemberId : '';
		$result->first_name      = isset( $node->FirstName ) ? (string) $node->FirstName : '';
		$result->last_name       = isset( $node->LastName ) ? (string) $node->LastName : '';
		$result->full_name       = isset( $node->MemberFullName ) ? (string) $node->MemberFullName : trim( $result->first_name . ' ' . $result->last_name );
		$result->cell_phone      = isset( $node->MemberCellPhone ) ? (string) $node->MemberCellPhone : '';
		$result->email           = isset( $node->Email ) ? (string) $node->Email : '';
		$result->available_benefits = isset( $node->AvailableBenefits ) ? (string) $node->AvailableBenefits : '';
		$result->user_benefits_json = isset( $node->UserBenefitsJson ) ? (string) $node->UserBenefitsJson : '';
		$result->card_group    = isset( $node->CardGroup ) ? (string) $node->CardGroup : '';
		$result->status_text   = isset( $node->StatusText ) ? (string) $node->StatusText : '';
		$result->card_units    = isset( $node->CardUnitsDescription ) ? (string) $node->CardUnitsDescription : '';
		$result->birth_date    = isset( $node->BirthDate ) ? (string) $node->BirthDate : '';

		return $result;
	}

	/**
	 * Quote the benefits/points that can be applied to a transaction.
	 * Returns a TransactionId that must later be passed to commit_benefits().
	 *
	 * @param array $args {
	 *     @type string $card_number      Member phone/card.
	 *     @type float  $transaction_sum  Cart total the discount is calculated on.
	 *     @type float  $points_to_consume Points/budget the member wants to use (-1 = none).
	 *     @type string $json_items       JSON items payload (optional).
	 *     @type string $requested_promos Comma-separated promo IDs (optional).
	 *     @type int    $void_transaction_id Void this committed transaction atomically before quoting (0 = none).
	 * }
	 * @return object Normalised quote (->is_error, ->transaction_id, ->discount, ->given_points_redemption, ->print_message).
	 */
	public function get_benefits_query( $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'card_number'         => '',
				'transaction_sum'     => 0,
				'points_to_consume'   => -1,
				'json_items'          => '',
				'requested_promos'    => '',
				'void_transaction_id' => 0,
			)
		);

		$card = $this->esc_xml( $args['card_number'] );

		$body = '<GetBenefitsQuery xmlns="' . self::SOAP_NS . '">'
			. '<RequestParameters>'
			. $this->common_block( $card )
			. '<CardNumber>' . $card . '</CardNumber>'
			. '<TransactionSum>' . (float) $args['transaction_sum'] . '</TransactionSum>'
			. '<CouponNum>' . (float) $args['points_to_consume'] . '</CouponNum>'
			. '<PromoNum>-1</PromoNum>'
			. '<PIN>0</PIN>'
			. '<VoidTransactionId>' . (int) $args['void_transaction_id'] . '</VoidTransactionId>'
			. '<RequestedPromoIDs>' . $this->esc_xml( $args['requested_promos'] ) . '</RequestedPromoIDs>'
			. '<RequestedPointsPromoIDs>0</RequestedPointsPromoIDs>'
			. '<DisableRedemptions>-1</DisableRedemptions>'
			. '<JsonItems>' . str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), (string) $args['json_items'] ) . '</JsonItems>'
			. '</RequestParameters>'
			. '</GetBenefitsQuery>';

		$xml = $this->soap_request( 'GetBenefitsQuery', $body );

		$result           = new stdClass();
		$result->is_error = true;
		$result->transaction_id = -1;
		$result->discount = 0.0;
		$result->given_points_redemption = 0.0;
		$result->print_message = '';
		$result->message  = '';

		if ( ! $xml ) {
			return $result;
		}

		$node = isset( $xml->soapBody->GetBenefitsQueryResponse->GetBenefitsQueryResult )
			? $xml->soapBody->GetBenefitsQueryResponse->GetBenefitsQueryResult
			: null;

		if ( ! $node ) {
			return $result;
		}

		$result->is_error       = $this->is_error( $node );
		$result->transaction_id = isset( $node->TransactionId ) ? (string) $node->TransactionId : -1;
		$result->discount       = isset( $node->Discount ) ? (float) $node->Discount : 0.0;
		$result->given_points_redemption = isset( $node->GivenPointsRedemption ) ? (float) $node->GivenPointsRedemption : 0.0;
		$result->money_back      = isset( $node->MoneyBack ) ? (float) $node->MoneyBack : 0.0;
		$result->max_redemption  = isset( $node->MaxRedemption ) ? (float) $node->MaxRedemption : 0.0;
		$result->given_promo_ids = isset( $node->GivenPromoIDs ) ? (string) $node->GivenPromoIDs : '';
		$result->print_message  = isset( $node->PrintMessage ) ? (string) $node->PrintMessage : '';
		$result->message        = isset( $node->Common->Message ) ? (string) $node->Common->Message : ( isset( $node->Message ) ? (string) $node->Message : '' );

		return $result;
	}

	/**
	 * Commit a previously quoted transaction — this is what tells ValueCard the
	 * points were actually consumed. THIS is the step the legacy plugin failed to fire.
	 *
	 * @param string $transaction_id Transaction id from get_benefits_query().
	 * @param string $card_number    Member phone/card.
	 * @return object Normalised result (->is_error, ->committed_transaction_id, ->print_message).
	 */
	public function commit_benefits( $transaction_id, $card_number ) {
		$card = $this->esc_xml( $card_number );

		$body = '<GetBenefitsCommitQuery xmlns="' . self::SOAP_NS . '">'
			. '<RequestParameters>'
			. $this->common_block( $card )
			. '<CardNumber>' . $card . '</CardNumber>'
			. '<TransactionSum>-1</TransactionSum>'
			. '<CouponNum>-1</CouponNum>'
			. '<PromoNum>-1</PromoNum>'
			. '<QueryTransactionId>' . $this->esc_xml( $transaction_id ) . '</QueryTransactionId>'
			. '<PIN/>'
			. '<JsonItems></JsonItems>'
			. '<RequestedPromoIDs></RequestedPromoIDs>'
			. '<RequestedPointsPromoIDs></RequestedPointsPromoIDs>'
			. '</RequestParameters>'
			. '</GetBenefitsCommitQuery>';

		$xml = $this->soap_request( 'GetBenefitsCommitQuery', $body );

		$result           = new stdClass();
		$result->is_error = true;
		$result->committed_transaction_id = -1;
		$result->print_message = '';
		$result->message  = '';

		if ( ! $xml ) {
			return $result;
		}

		$node = isset( $xml->soapBody->GetBenefitsCommitQueryResponse->GetBenefitsCommitQueryResult )
			? $xml->soapBody->GetBenefitsCommitQueryResponse->GetBenefitsCommitQueryResult
			: null;

		if ( ! $node ) {
			return $result;
		}

		$result->is_error = $this->is_error( $node );
		$result->committed_transaction_id = isset( $node->TransactionId ) ? (string) $node->TransactionId : -1;
		$result->print_message = isset( $node->PrintMessage ) ? (string) $node->PrintMessage : '';
		$result->message  = isset( $node->Message ) ? (string) $node->Message : '';

		return $result;
	}

	/**
	 * Void a transaction (on cancel/refund).
	 *
	 * @param string $transaction_id Transaction id to void.
	 * @return object Normalised result (->is_error).
	 */
	public function void_transaction( $transaction_id ) {
		$body = '<VoidTransaction xmlns="' . self::SOAP_NS . '">'
			. '<RequestParameters>'
			. $this->common_block( '-1' )
			. '<PIN>-1</PIN>'
			. '<VoidTransactionId>' . $this->esc_xml( $transaction_id ) . '</VoidTransactionId>'
			. '</RequestParameters>'
			. '</VoidTransaction>';

		$xml = $this->soap_request( 'VoidTransaction', $body );

		$result           = new stdClass();
		$result->is_error = true;

		if ( ! $xml ) {
			return $result;
		}

		$node = isset( $xml->soapBody->VoidTransactionResponse->VoidTransactionResult )
			? $xml->soapBody->VoidTransactionResponse->VoidTransactionResult
			: null;

		$result->is_error = $node ? $this->is_error( $node ) : true;

		return $result;
	}

	/**
	 * Register a new club member (used when a non-member ticks "Join the club").
	 *
	 * @param array $args {
	 *     @type string $first_name First name.
	 *     @type string $last_name  Last name.
	 *     @type string $phone      Cell phone (identifier).
	 *     @type string $email      Email.
	 *     @type string $address    Street address.
	 *     @type string $zip        Postcode.
	 *     @type int    $marketing  Terms/marketing consent (0/1).
	 *     @type string $birth_date Optional Y-m-d birth date.
	 * }
	 * @return object Normalised result (->is_error, ->message, ->phone).
	 */
	public function register_member( $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'first_name' => '',
				'last_name'  => '',
				'phone'      => '',
				'email'      => '',
				'address'    => '',
				'zip'        => '',
				'marketing'  => 0,
				'birth_date' => '',
			)
		);

		$birth = '';
		if ( ! empty( $args['birth_date'] ) && false !== strtotime( $args['birth_date'] ) ) {
			$birth = '<BirthDate>' . $this->esc_xml( $args['birth_date'] ) . '</BirthDate>';
		}

		$phone = preg_replace( '/\D/', '', (string) $args['phone'] );

		$body = '<RegisterClubMemberEx xmlns="' . self::SOAP_NS . '">'
			. '<RequestParameters>'
			. $this->common_block( '-1' )
			. '<CardNumber>-1</CardNumber>'
			. '<FirstName>' . $this->esc_xml( $args['first_name'] ) . '</FirstName>'
			. '<LastName>' . $this->esc_xml( $args['last_name'] ) . '</LastName>'
			. $birth
			. '<Phone>' . $this->esc_xml( $phone ) . '</Phone>'
			. '<CellPhone>' . $this->esc_xml( $phone ) . '</CellPhone>'
			. '<Email>' . $this->esc_xml( $args['email'] ) . '</Email>'
			. '<Address>' . $this->esc_xml( $args['address'] ) . '</Address>'
			. '<ZipCode>' . $this->esc_xml( $args['zip'] ) . '</ZipCode>'
			. '<TermsConsent>' . (int) $args['marketing'] . '</TermsConsent>'
			. '<MessageAccept>' . (int) $args['marketing'] . '</MessageAccept>'
			. '<GenderId>0</GenderId>'
			. '</RequestParameters>'
			. '</RegisterClubMemberEx>';

		$xml = $this->soap_request( 'RegisterClubMemberEx', $body );

		$result           = new stdClass();
		$result->is_error = true;
		$result->message  = '';
		$result->phone    = $phone;

		if ( ! $xml ) {
			return $result;
		}

		$node = isset( $xml->soapBody->RegisterClubMemberExResponse->RegisterClubMemberExResult )
			? $xml->soapBody->RegisterClubMemberExResponse->RegisterClubMemberExResult
			: null;

		if ( ! $node ) {
			return $result;
		}

		$result->is_error = $this->is_error( $node );
		$raw_message      = isset( $node->Common->PrintMessage ) ? (string) $node->Common->PrintMessage : '';
		$result->message  = trim( explode( '=', $raw_message )[0] );

		return $result;
	}

	/* --------------------------------------------------------------------- *
	 * REST operations (token + OTP)
	 * --------------------------------------------------------------------- */

	/**
	 * Get (and cache) a REST bearer token derived from the POS credentials.
	 *
	 * @return string|null
	 */
	public function get_auth_token() {
		$cache_key = 'ocvc_api_token_' . md5( (string) $this->creds['pos_id'] . '|' . (string) $this->creds['vc_token'] );
		$cached    = get_transient( $cache_key );
		if ( $cached ) {
			return $cached;
		}

		$response = wp_remote_post(
			self::REST_BASE . 'GetAuthToken',
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'PosId'            => $this->creds['pos_id'],
						'PosPassword'      => $this->creds['pos_password'],
						'CashiersPassword' => $this->creds['cashiers_password'],
						'VcToken'          => $this->creds['vc_token'],
					),
					JSON_UNESCAPED_SLASHES
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			OCVC_Logger::log( 'GetAuthToken error', $response->get_error_message() );
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		OCVC_Logger::log( 'GetAuthToken response (' . wp_remote_retrieve_response_code( $response ) . ')', $body );

		$token = $this->extract_token( $body );

		if ( $token ) {
			// Cache a little under a typical token lifetime; refreshed on demand.
			set_transient( $cache_key, $token, 20 * MINUTE_IN_SECONDS );
		}

		return $token;
	}

	/**
	 * Send an OTP to a guest's phone/identifier.
	 *
	 * @param string $identifier Phone number or member identifier.
	 * @return array { success:bool, message:string }
	 */
	public function send_otp( $identifier ) {
		return $this->rest_auth_post( 'SendAuthOtp', array( 'Identifier' => $identifier ) );
	}

	/**
	 * Verify an OTP code.
	 *
	 * @param string $identifier Phone number or member identifier.
	 * @param string $code       OTP code.
	 * @return array { success:bool, message:string }
	 */
	public function verify_otp( $identifier, $code ) {
		return $this->rest_auth_post(
			'VerifyAuthOtp',
			array(
				'Identifier' => $identifier,
				'OtpCode'    => $code,
			)
		);
	}

	/* --------------------------------------------------------------------- *
	 * Internals
	 * --------------------------------------------------------------------- */

	/**
	 * Build the shared <Common> credential block.
	 *
	 * @param string $card_number Card number to embed (already xml-escaped).
	 * @return string
	 */
	private function common_block( $card_number ) {
		return '<Common>'
			. '<RequestId>' . time() . '</RequestId>'
			. '<VCToken>' . $this->esc_xml( $this->creds['vc_token'] ) . '</VCToken>'
			. '<POSId>' . $this->esc_xml( $this->creds['pos_id'] ) . '</POSId>'
			. '<POSPassword>' . $this->esc_xml( $this->creds['pos_password'] ) . '</POSPassword>'
			. '<CashiersPassword>' . $this->esc_xml( $this->creds['cashiers_password'] ) . '</CashiersPassword>'
			. '<CardNumber>' . $card_number . '</CardNumber>'
			. '</Common>';
	}

	/**
	 * Perform a SOAP request and return parsed XML (namespace prefixes stripped).
	 *
	 * @param string $operation Operation name (for endpoint + logging).
	 * @param string $body      Inner SOAP body XML.
	 * @return SimpleXMLElement|null
	 */
	private function soap_request( $operation, $body ) {
		$envelope = '<?xml version="1.0" encoding="utf-8"?>'
			. '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
			. '<soap:Body>' . $body . '</soap:Body>'
			. '</soap:Envelope>';

		OCVC_Logger::log( $operation . ' request', $envelope );

		$response = wp_remote_post(
			self::SOAP_BASE . '?op=' . rawurlencode( $operation ),
			array(
				'timeout' => 45,
				'headers' => array( 'Content-Type' => 'text/xml; charset=utf-8' ),
				'body'    => $envelope,
			)
		);

		if ( is_wp_error( $response ) ) {
			OCVC_Logger::log( $operation . ' wp_error', $response->get_error_message() );
			return null;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );

		OCVC_Logger::log( $operation . ' response (' . $status . ')', $raw );

		if ( 200 !== (int) $status || '' === $raw ) {
			return null;
		}

		return $this->parse_soap( $raw );
	}

	/**
	 * Strip namespace prefixes so nodes are addressable as ->soapBody->...
	 *
	 * @param string $raw Raw SOAP XML.
	 * @return SimpleXMLElement|null
	 */
	private function parse_soap( $raw ) {
		$clean = preg_replace( '/(<\/?)(\w+):([^>]*>)/', '$1$2$3', $raw );

		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $clean );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return ( false === $xml ) ? null : $xml;
	}

	/**
	 * Read the IsError flag from a response node (Common->IsError or IsError).
	 *
	 * @param SimpleXMLElement $node Result node.
	 * @return bool
	 */
	private function is_error( $node ) {
		$flag = null;
		if ( isset( $node->Common->IsError ) ) {
			$flag = (string) $node->Common->IsError;
		} elseif ( isset( $node->IsError ) ) {
			$flag = (string) $node->IsError;
		}
		// Treat anything that isn't an explicit "false" as an error.
		return ( 'false' !== strtolower( (string) $flag ) );
	}

	/**
	 * POST to a REST auth endpoint with a bearer token.
	 *
	 * @param string $endpoint Endpoint name.
	 * @param array  $payload  Body payload.
	 * @return array { success:bool, message:string, data:mixed }
	 */
	private function rest_auth_post( $endpoint, $payload ) {
		$token = $this->get_auth_token();
		if ( ! $token ) {
			return array(
				'success' => false,
				'message' => __( 'Could not authenticate with ValueCard.', 'oc-valuecard' ),
			);
		}

		OCVC_Logger::log( $endpoint . ' request', $payload );

		$response = wp_remote_post(
			self::REST_BASE . $endpoint,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$body = wp_remote_retrieve_body( $response );
		OCVC_Logger::log( $endpoint . ' response', $body );
		$data = json_decode( $body );

		$is_ok = ! ( isset( $data->IsError ) && ( 'true' === strtolower( (string) $data->IsError ) || true === $data->IsError ) );

		return array(
			'success' => $is_ok,
			'message' => isset( $data->Message ) ? (string) $data->Message : '',
			'data'    => $data,
		);
	}

	/**
	 * Extract the auth token from the GetAuthToken response body.
	 *
	 * ValueCard returns the token as a bare JWT string (not JSON), so json_decode
	 * on its own yields null. We handle every shape: a JSON object with a token
	 * field, a JSON-quoted string, or a bare token string.
	 *
	 * @param string $body Raw response body.
	 * @return string|null
	 */
	private function extract_token( $body ) {
		$body = trim( (string) $body );
		if ( '' === $body ) {
			return null;
		}

		$data = json_decode( $body );
		if ( JSON_ERROR_NONE === json_last_error() ) {
			if ( is_string( $data ) ) {
				return '' !== $data ? $data : null;
			}
			if ( is_object( $data ) ) {
				foreach ( array( 'Token', 'token', 'AuthToken', 'authToken', 'access_token', 'AccessToken' ) as $key ) {
					if ( ! empty( $data->$key ) && is_string( $data->$key ) ) {
						return $data->$key;
					}
				}
				return null; // A JSON object with no recognisable token (likely an error payload).
			}
		}

		// Not JSON — the endpoint returned a bare token string (e.g. a JWT).
		if ( false === strpos( $body, ' ' ) && false === strpos( $body, '<' ) ) {
			return trim( $body, '"' );
		}

		return null;
	}

	/**
	 * XML-escape a scalar value for safe embedding in the SOAP envelope.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function esc_xml( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}
}
