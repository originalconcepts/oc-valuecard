<?php
/**
 * Order lifecycle: persist → reserve → re-sync on edit → settle & lock → void.
 *
 * ValueCard has no separate "reserve vs. capture" and no "amend" — the only
 * finalise step is the commit, and one commit atomically settles BOTH the
 * redemption (spend) and the accrual (earn). The only way to change a committed
 * transaction is VoidTransaction (full reversal, verified) then re-quote + commit.
 * So the flow is:
 *
 *   RESERVE  – on the first configured status the order reaches, commit a fresh
 *              quote built FROM THE ORDER. This locks the redemption early so the
 *              same points cannot be spent on a second order (no double-spend),
 *              and reports the accrual.
 *   RE-SYNC  – if staff edit the order (weight/qty/items) the committed amount is
 *              corrected: void the old transaction → re-quote from the edited
 *              order → commit the new one. Guarded so it only runs on a real change.
 *   SETTLE   – on the final status, one last re-sync-if-changed, then LOCK so the
 *              member's points are frozen at the shipped-order amount.
 *   VOID     – on cancel/refund, reverse the whole transaction (returns the spent
 *              points and cancels the accrual). Works even after the lock.
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

		// Staff edited an existing order (item add/remove, qty/weight, recalculate).
		// Fires after wc_save_order_items() has already recalculated totals; HPOS-safe.
		add_action( 'woocommerce_saved_order_items', array( __CLASS__, 'on_order_edited' ), 20, 2 );

		// Manual recovery action in the order screen's "Order actions" box.
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'admin_order_actions' ) );
		add_action( 'woocommerce_order_action_ocvc_resync', array( __CLASS__, 'admin_do_resync' ) );
	}

	/**
	 * Add a manual "re-sync / re-commit" action to the order screen — the escape
	 * hatch for an order paused after an interrupted sync (staff verify ValueCard,
	 * then trigger this).
	 *
	 * @param array $actions Existing order actions.
	 * @return array
	 */
	public static function admin_order_actions( $actions ) {
		$actions['ocvc_resync'] = __( 'ValueCard: re-sync / re-commit points', 'oc-valuecard' );
		return $actions;
	}

	/**
	 * Run the manual re-sync/re-commit (operator-initiated recovery).
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public static function admin_do_resync( $order ) {
		if ( ! $order || ! $order->get_meta( '_ocvc_card' ) ) {
			return;
		}

		// Clear any paused/interrupted state — the operator has verified ValueCard.
		$order->update_meta_data( '_ocvc_resync_state', '' );
		$order->add_order_note( __( 'ValueCard: manual re-sync requested by staff.', 'oc-valuecard' ) );
		$order->save();

		if ( $order->get_meta( '_ocvc_committed' ) ) {
			$order->update_meta_data( '_ocvc_signature', '' ); // Force a full re-sync to the current order.
			$order->save();
			self::resync_if_dirty( $order, true );
		} else {
			self::commit_from_order( $order, 'manual' );
		}
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
			$order->update_meta_data( '_ocvc_redeemed', (float) OCVC_Member::get( 'redeemed_points', 0 ) );
			$order->update_meta_data( '_ocvc_earn', (float) OCVC_Member::get( 'earn', 0 ) );
		}

		// "Join the club" toggle (posted with the checkout form) + the enrolment
		// details the customer confirmed in the join popup (kept in the session).
		$join = isset( $_POST['ocvc_join_club'] ) ? (int) wp_unslash( $_POST['ocvc_join_club'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce.
		if ( $join ) {
			$order->update_meta_data( '_ocvc_join_club', 1 );
			$join_data = OCVC_Member::get( 'join_data' );
			if ( is_array( $join_data ) && ! empty( $join_data ) ) {
				$order->update_meta_data( '_ocvc_join_data', wp_json_encode( $join_data, JSON_UNESCAPED_UNICODE ) );
			}
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
		// Drop the cached balance so the next checkout reflects the points just spent.
		OCVC_Member::set( 'member_info', null );
		OCVC_Member::set( 'member_info_ts', null );
		// The enrolment details were copied onto the order — clear the session copy.
		OCVC_Member::set( 'join_data', null );
	}

	/**
	 * React to order status changes.
	 *
	 * @param int      $order_id Order id.
	 * @param string   $old      Old status (no wc- prefix).
	 * @param string   $new      New status (no wc- prefix).
	 * @param WC_Order $order    Order object.
	 * @return void
	 */
	public static function on_status_changed( $order_id, $old, $new, $order ) {
		// Cancel/refund reverses everything and must run even after the lock.
		if ( OCVC_Settings::get_bool( 'void_on_cancel' ) && in_array( $new, array( 'cancelled', 'refunded' ), true ) ) {
			self::process_void( $order );
			return;
		}

		// A previous re-sync was interrupted mid-flight — surface it and stop until a
		// human confirms the ValueCard state; never act on an ambiguous transaction.
		if ( self::reconcile( $order ) ) {
			return;
		}

		// Final status: sync-if-changed, then lock.
		$settle = str_replace( 'wc-', '', (string) OCVC_Settings::get( 'settle_status', 'wc-completed' ) );
		if ( $new === $settle ) {
			self::process_settle( $order );
			return;
		}

		// Early status(es): reserve/commit the points.
		if ( in_array( $new, self::reserve_statuses(), true ) ) {
			self::process_reserve( $order );
		}
	}

	/**
	 * Staff edited an existing order — re-sync the committed amount to match.
	 *
	 * @param int   $order_id Order id.
	 * @param array $items    Saved items payload (unused).
	 * @return void
	 */
	public static function on_order_edited( $order_id, $items = null ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_meta( '_ocvc_card' ) ) {
			return;
		}
		if ( self::reconcile( $order ) ) {
			return;
		}
		if ( ! $order->get_meta( '_ocvc_committed' ) || $order->get_meta( '_ocvc_locked' ) ) {
			return;
		}

		$sig = OCVC_Checkout::order_signature( $order );
		if ( $sig === (string) $order->get_meta( '_ocvc_signature' ) ) {
			return; // Nothing ValueCard-relevant actually changed.
		}

		if ( OCVC_Settings::get_bool( 'resync_on_edit' ) ) {
			self::resync_if_dirty( $order );
		} elseif ( $sig !== (string) $order->get_meta( '_ocvc_flagged_sig' ) ) {
			// Auto re-sync is off: flag the change once for manual review.
			$order->update_meta_data( '_ocvc_flagged_sig', $sig );
			$order->add_order_note( __( 'ValueCard: this order changed after points were committed, but auto re-sync is off. Update ValueCard manually if needed.', 'oc-valuecard' ) );
			$order->save();
		}
	}

	/* --------------------------------------------------------------------- *
	 * Phases
	 * --------------------------------------------------------------------- */

	/**
	 * Reserve: enrol (if opted in) and commit the points from the current order.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	private static function process_reserve( $order ) {
		self::process_enrol( $order );

		if ( $order->get_meta( '_ocvc_locked' ) ) {
			return;
		}
		if ( $order->get_meta( '_ocvc_committed' ) ) {
			// Already reserved at an earlier status — just keep it in sync.
			self::resync_if_dirty( $order );
			return;
		}
		self::commit_from_order( $order, 'reserve' );
	}

	/**
	 * Settle: a final re-sync-if-changed, then lock the transaction.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	private static function process_settle( $order ) {
		if ( $order->get_meta( '_ocvc_settled' ) ) {
			return;
		}

		self::process_enrol( $order );

		if ( $order->get_meta( '_ocvc_committed' ) ) {
			self::resync_if_dirty( $order, true ); // Force: sync even though we are about to lock.
		} elseif ( $order->get_meta( '_ocvc_card' ) ) {
			// Order reached the final status without ever passing a reserve status.
			self::commit_from_order( $order, 'settle' );
		}

		// Only lock once ValueCard is actually committed. If the forced re-sync had to
		// void the old transaction but could not re-commit (API error), or a prior sync
		// was interrupted, leave the order UNLOCKED so a later edit or status change can
		// retry — never freeze it with the member's points reversed and nothing committed.
		$state = (string) $order->get_meta( '_ocvc_resync_state' );
		if ( ! $order->get_meta( '_ocvc_committed' ) || 'needs_review' === $state || 'interrupted' === $state ) {
			if ( $order->get_meta( '_ocvc_card' ) ) {
				$order->add_order_note( __( 'ValueCard: NOT locked — points are not committed (re-sync/commit did not complete). Left open to retry on the next edit or status change; resolve manually if it persists.', 'oc-valuecard' ) );
				$order->save();
			}
			return;
		}

		$order->update_meta_data( '_ocvc_settled', 1 );
		$order->update_meta_data( '_ocvc_locked', 1 );
		$order->add_order_note( __( 'ValueCard: points settled and locked.', 'oc-valuecard' ) );
		$order->save();
	}

	/**
	 * Commit a fresh quote built from the current order (no cart/session).
	 * Used for the first reserve and for the settle self-heal.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $context Label for the order note ('reserve' | 'settle').
	 * @return void
	 */
	private static function commit_from_order( $order, $context ) {
		$card = $order->get_meta( '_ocvc_card' );
		if ( ! $card ) {
			return; // Not a loyalty order.
		}

		// Never blind-commit while a prior sync is unresolved — a leftover transaction
		// could still be live on ValueCard and we would double-commit.
		if ( in_array( (string) $order->get_meta( '_ocvc_resync_state' ), array( 'voiding', 'requoting', 'committing', 'interrupted' ), true ) ) {
			return;
		}

		$api = new OCVC_API();
		if ( ! $api->is_configured() ) {
			return;
		}

		$json = OCVC_Checkout::build_json_items_from_order( $order );
		if ( '' === $json ) {
			return; // No items to quote.
		}
		$sum    = OCVC_Checkout::transaction_sum_from_order( $order );
		$points = self::order_points( $order );

		$quote = $api->get_benefits_query(
			array(
				'card_number'       => $card,
				'transaction_sum'   => $sum,
				'points_to_consume' => $points,
				'json_items'        => $json,
			)
		);

		if ( $quote->is_error || (int) $quote->transaction_id <= 0 ) {
			$order->add_order_note( __( 'ValueCard reserve: quote failed — ', 'oc-valuecard' ) . self::err( $quote ) );
			$order->save();
			return;
		}

		$commit = $api->commit_benefits( $quote->transaction_id, $card );
		if ( $commit->is_error ) {
			$order->add_order_note( __( 'ValueCard reserve: commit failed — ', 'oc-valuecard' ) . self::err( $commit ) );
			$order->save();
			return;
		}

		self::store_commit( $order, $quote, $commit );
		$order->add_order_note(
			sprintf(
				/* translators: 1: committed transaction id, 2: redeemed points, 3: points earned, 4: context (reserve/settle). */
				__( 'ValueCard: points committed (txn %1$s). Redeemed %2$s, will earn %3$s. [%4$s]', 'oc-valuecard' ),
				$commit->committed_transaction_id,
				self::fmt( $quote->given_points_redemption ),
				self::fmt( $quote->money_back ),
				$context
			)
		);
		$order->save();
	}

	/**
	 * Re-sync a committed order to its current contents: void → re-quote → commit.
	 * Only acts when the order's signature actually changed.
	 *
	 * @param WC_Order $order Order.
	 * @param bool     $force Run even if locked / auto-resync is off (settle).
	 * @return void
	 */
	private static function resync_if_dirty( $order, $force = false ) {
		if ( $order->get_meta( '_ocvc_locked' ) && ! $force ) {
			return;
		}
		if ( ! $order->get_meta( '_ocvc_committed' ) ) {
			return;
		}
		if ( ! $force && ! OCVC_Settings::get_bool( 'resync_on_edit' ) ) {
			return;
		}

		$card = $order->get_meta( '_ocvc_card' );
		if ( ! $card ) {
			return;
		}

		$new_sig = OCVC_Checkout::order_signature( $order );
		if ( $new_sig === (string) $order->get_meta( '_ocvc_signature' ) ) {
			return; // Unchanged.
		}

		// In-request re-entrancy guard (our own $order->save() must not re-enter).
		static $busy = array();
		$oid = $order->get_id();
		if ( ! empty( $busy[ $oid ] ) ) {
			return;
		}
		$busy[ $oid ] = true;

		$api = new OCVC_API();
		if ( ! $api->is_configured() ) {
			$busy[ $oid ] = false;
			return;
		}

		$old_txn        = $order->get_meta( '_ocvc_committed_txn' );
		$old_txn        = ( $old_txn && (int) $old_txn > 0 ) ? $old_txn : $order->get_meta( '_ocvc_transaction_id' );
		$prior_redeemed = (float) $order->get_meta( '_ocvc_redeemed' );

		// 1) Void the old committed transaction (verified to fully reverse both sides).
		$order->update_meta_data( '_ocvc_resync_state', 'voiding' );
		$order->save();

		$void = $api->void_transaction( $old_txn );
		if ( $void->is_error ) {
			// Old transaction is untouched and still valid — keep it, flag the gap.
			$order->update_meta_data( '_ocvc_resync_state', '' );
			$order->add_order_note( __( 'ValueCard sync: could not void the previous transaction; keeping it. The order changed but ValueCard was NOT updated — please review.', 'oc-valuecard' ) );
			$order->save();
			$busy[ $oid ] = false;
			return;
		}

		// Old reversed — from here the member's points have been returned.
		$order->update_meta_data( '_ocvc_committed', 0 );
		$order->update_meta_data( '_ocvc_resync_state', 'requoting' );
		$order->save();

		$json = OCVC_Checkout::build_json_items_from_order( $order );
		if ( '' === $json ) {
			// Order emptied — a full void is the correct end state.
			$order->update_meta_data( '_ocvc_committed_txn', '' );
			$order->update_meta_data( '_ocvc_redeemed', 0 );
			$order->update_meta_data( '_ocvc_discount', 0 );
			$order->update_meta_data( '_ocvc_earn', 0 );
			$order->update_meta_data( '_ocvc_signature', $new_sig );
			$order->update_meta_data( '_ocvc_resync_state', '' );
			$order->add_order_note( __( 'ValueCard sync: order has no items; transaction voided.', 'oc-valuecard' ) );
			$order->save();
			$busy[ $oid ] = false;
			return;
		}

		$sum    = OCVC_Checkout::transaction_sum_from_order( $order );
		$points = self::order_points( $order );

		$quote = $api->get_benefits_query(
			array(
				'card_number'       => $card,
				'transaction_sum'   => $sum,
				'points_to_consume' => $points,
				'json_items'        => $json,
			)
		);

		if ( $quote->is_error || (int) $quote->transaction_id <= 0 ) {
			$order->update_meta_data( '_ocvc_resync_state', 'needs_review' );
			$order->add_order_note( __( 'ValueCard sync: re-quote FAILED after voiding. The points were returned to the member but the new amount was not committed — MANUAL REVIEW NEEDED.', 'oc-valuecard' ) . ' ' . self::err( $quote ) );
			$order->save();
			$busy[ $oid ] = false;
			return;
		}

		$order->update_meta_data( '_ocvc_transaction_id', (string) $quote->transaction_id );
		$order->update_meta_data( '_ocvc_resync_state', 'committing' );
		$order->save();

		$commit = $api->commit_benefits( $quote->transaction_id, $card );
		if ( $commit->is_error ) {
			$order->update_meta_data( '_ocvc_resync_state', 'needs_review' );
			$order->add_order_note( __( 'ValueCard sync: commit FAILED after void + re-quote. The points were returned but the new amount was not committed — MANUAL REVIEW NEEDED.', 'oc-valuecard' ) . ' ' . self::err( $commit ) );
			$order->save();
			$busy[ $oid ] = false;
			return;
		}

		self::store_commit( $order, $quote, $commit );
		$order->update_meta_data( '_ocvc_signature', $new_sig );
		$order->update_meta_data( '_ocvc_resync_state', '' );

		$note = sprintf(
			/* translators: 1: redeemed points, 2: points earned, 3: transaction id. */
			__( 'ValueCard sync: updated to the edited order — redeemed %1$s, will earn %2$s (txn %3$s).', 'oc-valuecard' ),
			self::fmt( $quote->given_points_redemption ),
			self::fmt( $quote->money_back ),
			$commit->committed_transaction_id
		);
		if ( (float) $quote->given_points_redemption + 0.005 < $prior_redeemed ) {
			$note .= ' ' . sprintf(
				/* translators: 1: previous redeemed points, 2: new redeemed points. */
				__( 'NOTE: redemption dropped from %1$s to %2$s — verify the customer was charged correctly.', 'oc-valuecard' ),
				self::fmt( $prior_redeemed ),
				self::fmt( $quote->given_points_redemption )
			);
		}
		$order->add_order_note( $note );
		$order->save();

		$busy[ $oid ] = false;
	}

	/* --------------------------------------------------------------------- *
	 * Enrol / void
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

		// Prefer the details the customer confirmed in the join popup; fall back to
		// the billing fields for anything missing (or when no popup data exists).
		$data = json_decode( (string) $order->get_meta( '_ocvc_join_data' ), true );
		$data = is_array( $data ) ? $data : array();

		$result = $api->register_member(
			array(
				'first_name'       => ! empty( $data['first_name'] ) ? $data['first_name'] : $order->get_billing_first_name(),
				'last_name'        => ! empty( $data['last_name'] ) ? $data['last_name'] : $order->get_billing_last_name(),
				'phone'            => ! empty( $data['phone'] ) ? $data['phone'] : $order->get_billing_phone(),
				'email'            => ! empty( $data['email'] ) ? $data['email'] : $order->get_billing_email(),
				'address'          => $order->get_billing_address_1(),
				'zip'              => $order->get_billing_postcode(),
				'marketing'        => array_key_exists( 'marketing', $data ) ? (int) $data['marketing'] : 1,
				'terms'            => 1,
				'birth_date'       => isset( $data['birth_date'] ) ? $data['birth_date'] : '',
				'anniversary_date' => isset( $data['anniversary_date'] ) ? $data['anniversary_date'] : '',
				'gender'           => isset( $data['gender'] ) ? $data['gender'] : '',
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
	 * Void a committed transaction on cancel/refund (reverses spend + accrual).
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	private static function process_void( $order ) {
		if ( $order->get_meta( '_ocvc_voided' ) || ! $order->get_meta( '_ocvc_committed' ) ) {
			return; // Nothing committed → nothing to reverse.
		}

		$txn = $order->get_meta( '_ocvc_committed_txn' );
		if ( ! $txn || (int) $txn <= 0 ) {
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
			$order->update_meta_data( '_ocvc_committed', 0 );
			$order->add_order_note( __( 'ValueCard: transaction voided — points returned and accrual cancelled.', 'oc-valuecard' ) );
		} else {
			$order->add_order_note( __( 'ValueCard void failed — please reverse the transaction manually.', 'oc-valuecard' ) );
		}
		$order->save();
	}

	/* --------------------------------------------------------------------- *
	 * Helpers
	 * --------------------------------------------------------------------- */

	/**
	 * Detect a re-sync that was interrupted mid-flight (a prior request died between
	 * the void and the re-commit). Rather than guess whether the void/commit landed —
	 * which risks double-charging or losing points — flag it for manual review and
	 * pause automatic syncing for this order.
	 *
	 * @param WC_Order $order Order.
	 * @return bool True if the order is interrupted (the caller should stop).
	 */
	private static function reconcile( $order ) {
		$state = (string) $order->get_meta( '_ocvc_resync_state' );
		if ( 'interrupted' === $state ) {
			return true;
		}
		if ( in_array( $state, array( 'voiding', 'requoting', 'committing' ), true ) ) {
			$order->update_meta_data( '_ocvc_resync_state', 'interrupted' );
			$order->add_order_note( __( 'ValueCard: a points re-sync was interrupted before it finished — the member balance may be mid-change. Please verify it in ValueCard and re-commit manually if needed. Automatic sync is paused for this order.', 'oc-valuecard' ) );
			$order->save();
			return true;
		}
		return false;
	}

	/**
	 * The reserve/commit statuses (without the wc- prefix). The settle status is
	 * excluded so a status ticked as both simply settles (a single, terminal action)
	 * instead of trying to reserve and then immediately lock.
	 *
	 * @return array
	 */
	private static function reserve_statuses() {
		$raw = OCVC_Settings::get( 'reserve_statuses', array( 'wc-on-hold', 'wc-processing' ) );
		if ( ! is_array( $raw ) ) {
			$raw = '' === (string) $raw ? array() : array( $raw );
		}
		$settle = str_replace( 'wc-', '', (string) OCVC_Settings::get( 'settle_status', 'wc-completed' ) );
		$out    = array();
		foreach ( $raw as $s ) {
			$s = str_replace( 'wc-', '', (string) $s );
			if ( '' !== $s && $s !== $settle && ! in_array( $s, $out, true ) ) {
				$out[] = $s;
			}
		}
		return $out;
	}

	/**
	 * Points the customer chose to redeem on this order (-1 = auto benefit only).
	 *
	 * @param WC_Order $order Order.
	 * @return float
	 */
	private static function order_points( $order ) {
		$points = $order->get_meta( '_ocvc_points' );
		if ( '' === $points || null === $points ) {
			return -1;
		}
		$points = (float) $points;
		return ( 0.0 === $points ) ? -1 : $points;
	}

	/**
	 * Persist the committed quote's outcome onto the order.
	 *
	 * @param WC_Order $order  Order.
	 * @param object   $quote  get_benefits_query() result.
	 * @param object   $commit commit_benefits() result.
	 * @return void
	 */
	private static function store_commit( $order, $quote, $commit ) {
		$order->update_meta_data( '_ocvc_transaction_id', (string) $quote->transaction_id );
		$order->update_meta_data( '_ocvc_committed', 1 );
		$order->update_meta_data( '_ocvc_committed_txn', (string) $commit->committed_transaction_id );
		$order->update_meta_data( '_ocvc_redeemed', (float) $quote->given_points_redemption );
		$order->update_meta_data( '_ocvc_discount', (float) $quote->discount );
		$order->update_meta_data( '_ocvc_earn', (float) $quote->money_back );
		$order->update_meta_data( '_ocvc_signature', OCVC_Checkout::order_signature( $order ) );
		$order->update_meta_data( '_ocvc_resync_state', '' );
	}

	/**
	 * Best-effort error text from a normalised API result.
	 *
	 * @param object $result API result.
	 * @return string
	 */
	private static function err( $result ) {
		if ( ! empty( $result->message ) ) {
			return (string) $result->message;
		}
		return isset( $result->print_message ) ? (string) $result->print_message : '';
	}

	/**
	 * Format a points value for an order note.
	 *
	 * @param float $n Number.
	 * @return string
	 */
	private static function fmt( $n ) {
		$n = (float) $n;
		return ( floor( $n ) === $n ) ? number_format( $n, 0 ) : number_format( $n, 2 );
	}
}
