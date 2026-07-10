<?php
/**
 * Order lifecycle: persist → enrol → commit → void.
 *
 * This is where the legacy plugin's core defect is fixed. The commit
 * (the call that tells ValueCard the points were actually used) fires on the
 * order status chosen in settings — not hard-wired to "completed", which is why
 * the old plugin's log showed 92 quotes and zero commits.
 *
 * All order data is read/written through the CRUD API ($order->get_meta /
 * update_meta_data), so it works with both legacy post storage and HPOS.
 *
 * @package OC_ValueCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OCVC_Order {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'persist_to_order' ), 20, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'after_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'after_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 20, 4 );
	}

	/**
	 * Copy the loyalty session state onto the order as it is created.
	 *
	 * @param WC_Order $order Order being created.
	 * @param array    $data  Posted checkout data.
	 * @return void
	 */
	public static function persist_to_order( $order, $data ) {
		$card = OCVC_Member::card_number();
		$txn  = OCVC_Member::get( 'transaction_id' );

		if ( $card ) {
			$order->update_meta_data( '_ocvc_card', $card );
		}
		if ( $txn ) {
			$order->update_meta_data( '_ocvc_transaction_id', (string) $txn );
			$order->update_meta_data( '_ocvc_points', (float) OCVC_Member::get( 'points_to_consume', -1 ) );
			$order->update_meta_data( '_ocvc_discount', (float) OCVC_Member::get( 'discount', 0 ) );
		}

		// "Join the club" checkbox (posted with the checkout form).
		$join = isset( $_POST['ocvc_join_club'] ) ? (int) wp_unslash( $_POST['ocvc_join_club'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce.
		if ( $join ) {
			$order->update_meta_data( '_ocvc_join_club', 1 );
		}
	}

	/**
	 * Clear the loyalty session once the order is placed.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public static function after_order( $order_id ) {
		OCVC_Member::set( 'discount', null );
		OCVC_Member::set( 'transaction_id', null );
		OCVC_Member::set( 'points_to_consume', null );
		OCVC_Member::set( 'redeemed_points', null );
		OCVC_Member::set( 'earn', null );
		OCVC_Member::set( 'benefit_names', null );
		OCVC_Member::set( 'qsum', null );
		OCVC_Member::set( 'qpoints', null );
	}

	/**
	 * React to order status changes: enrol + commit on the configured status,
	 * void on cancel/refund.
	 *
	 * @param int      $order_id Order id.
	 * @param string   $old      Old status (no wc- prefix).
	 * @param string   $new      New status (no wc- prefix).
	 * @param WC_Order $order    Order object.
	 * @return void
	 */
	public static function on_status_changed( $order_id, $old, $new, $order ) {
		$commit_status = str_replace( 'wc-', '', (string) OCVC_Settings::get( 'commit_status', 'wc-processing' ) );

		if ( $new === $commit_status ) {
			self::process_enrol( $order );
			self::process_commit( $order );
		}

		if ( OCVC_Settings::get_bool( 'void_on_cancel' ) && in_array( $new, array( 'cancelled', 'refunded' ), true ) ) {
			self::process_void( $order );
		}
	}

	/* --------------------------------------------------------------------- *
	 * Steps
	 * --------------------------------------------------------------------- */

	/**
	 * Enrol the customer to the club if they opted in and are not enrolled yet.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	private static function process_enrol( $order ) {
		if ( ! $order->get_meta( '_ocvc_join_club' ) || $order->get_meta( '_ocvc_enrolled' ) ) {
			return;
		}

		$api = new OCVC_API();
		if ( ! $api->is_configured() ) {
			return;
		}

		$result = $api->register_member(
			array(
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
				'phone'      => $order->get_billing_phone(),
				'email'      => $order->get_billing_email(),
				'address'    => $order->get_billing_address_1(),
				'zip'        => $order->get_billing_postcode(),
				'marketing'  => 1,
			)
		);

		if ( ! $result->is_error ) {
			$order->update_meta_data( '_ocvc_enrolled', 1 );
			$order->add_order_note( __( 'ValueCard: member enrolled. ', 'oc-valuecard' ) . $result->message );
		} else {
			$order->add_order_note( __( 'ValueCard enrolment failed: ', 'oc-valuecard' ) . $result->message );
		}
		$order->save();
	}

	/**
	 * Commit the redemption to ValueCard.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	private static function process_commit( $order ) {
		if ( $order->get_meta( '_ocvc_committed' ) ) {
			return; // Idempotent — never commit twice.
		}

		$txn  = $order->get_meta( '_ocvc_transaction_id' );
		$card = $order->get_meta( '_ocvc_card' );

		if ( ! $txn || (int) $txn <= 0 || ! $card ) {
			return; // Nothing was redeemed on this order.
		}

		$api = new OCVC_API();
		if ( ! $api->is_configured() ) {
			return;
		}

		$result = $api->commit_benefits( $txn, $card );

		if ( ! $result->is_error ) {
			$order->update_meta_data( '_ocvc_committed', 1 );
			$order->update_meta_data( '_ocvc_committed_txn', (string) $result->committed_transaction_id );
			$order->add_order_note(
				sprintf(
					/* translators: %s transaction id. */
					__( 'ValueCard: points committed (transaction %s). ', 'oc-valuecard' ),
					$result->committed_transaction_id
				) . $result->print_message
			);
		} else {
			$order->add_order_note( __( 'ValueCard commit failed: ', 'oc-valuecard' ) . ( $result->message ? $result->message : $result->print_message ) );
		}
		$order->save();
	}

	/**
	 * Void a committed transaction on cancel/refund.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	private static function process_void( $order ) {
		if ( $order->get_meta( '_ocvc_voided' ) ) {
			return;
		}

		$txn = $order->get_meta( '_ocvc_committed_txn' );
		if ( ! $txn ) {
			$txn = $order->get_meta( '_ocvc_transaction_id' );
		}
		if ( ! $txn || (int) $txn <= 0 ) {
			return;
		}

		$api = new OCVC_API();
		if ( ! $api->is_configured() ) {
			return;
		}

		$result = $api->void_transaction( $txn );

		if ( ! $result->is_error ) {
			$order->update_meta_data( '_ocvc_voided', 1 );
			$order->add_order_note( __( 'ValueCard: transaction voided.', 'oc-valuecard' ) );
		} else {
			$order->add_order_note( __( 'ValueCard void failed.', 'oc-valuecard' ) );
		}
		$order->save();
	}
}
