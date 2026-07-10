/**
 * OC ValueCard — checkout front-end.
 *
 * The loyalty box lives inside the WooCommerce order-review fragment, which is
 * replaced on every `update_checkout`, so all box handlers are delegated from
 * the document. The modals live in the footer and persist.
 */
( function ( $ ) {
	'use strict';

	if ( typeof ocvc === 'undefined' ) {
		return;
	}

	var currentPhone = '';

	function post( action, data ) {
		return $.post(
			ocvc.ajax_url,
			$.extend( { action: action, nonce: ocvc.nonce }, data || {} )
		);
	}

	function refreshCheckout() {
		$( document.body ).trigger( 'update_checkout' );
	}

	function setMsg( el, text, isError ) {
		$( el ).text( text || '' ).toggleClass( 'ocvc-error', !! isError );
	}

	/* ----- Redeem (member) ------------------------------------------------ */

	function applyPoints( amount, $btn ) {
		if ( $btn ) { $btn.prop( 'disabled', true ); }
		setMsg( '#ocvc-msg', ocvc.i18n.applying, false );
		post( 'ocvc_apply_points', { amount: amount } )
			.done( function ( res ) {
				if ( res && res.success ) {
					refreshCheckout();
				} else {
					setMsg( '#ocvc-msg', res && res.data ? res.data.message : ocvc.i18n.error, true );
					if ( $btn ) { $btn.prop( 'disabled', false ); }
				}
			} )
			.fail( function () {
				setMsg( '#ocvc-msg', ocvc.i18n.error, true );
				if ( $btn ) { $btn.prop( 'disabled', false ); }
			} );
	}

	$( document ).on( 'click', '#ocvc-apply-all', function ( e ) {
		e.preventDefault();
		applyPoints( '', $( this ) ); // Empty amount = use the maximum available.
	} );

	$( document ).on( 'click', '#ocvc-redeem-open', function ( e ) {
		e.preventDefault();
		$( this ).attr( 'hidden', 'hidden' );
		$( '#ocvc-redeem-field' ).removeAttr( 'hidden' );
		$( '#ocvc-amount' ).trigger( 'focus' ).select();
	} );

	$( document ).on( 'click', '#ocvc-apply-amount', function ( e ) {
		e.preventDefault();
		applyPoints( $( '#ocvc-amount' ).val(), $( this ) );
	} );

	$( document ).on( 'click', '#ocvc-clear', function ( e ) {
		e.preventDefault();
		post( 'ocvc_clear_points' ).always( refreshCheckout );
	} );

	$( document ).on( 'click', '#ocvc-logout', function ( e ) {
		e.preventDefault();
		post( 'ocvc_logout' ).always( refreshCheckout );
	} );

	$( document ).on( 'click', '#ocvc-member-details', function ( e ) {
		e.preventDefault();
		openModal( '#ocvc-details-modal' );
	} );

	/* ----- Member sign-in (OTP) ------------------------------------------ */

	$( document ).on( 'click', '#ocvc-open-otp', function ( e ) {
		e.preventDefault();
		showPhoneStep();
		openModal( '#ocvc-otp-modal' );
		setTimeout( function () { $( '#ocvc-otp-phone' ).trigger( 'focus' ); }, 50 );
	} );

	function showPhoneStep() {
		$( '#ocvc-otp-modal .ocvc-otp-step[data-step="phone"]' ).prop( 'hidden', false );
		$( '#ocvc-otp-modal .ocvc-otp-step[data-step="code"]' ).prop( 'hidden', true );
		setMsg( '#ocvc-otp-msg', '', false );
		setMsg( '#ocvc-otp-msg2', '', false );
		clearDigits();
	}

	function showCodeStep( phone ) {
		var digits = phone.replace( /\D/g, '' );
		var last4 = digits.slice( -4 );
		$( '#ocvc-otp-masked' ).text( '****' + last4 );
		$( '#ocvc-otp-modal .ocvc-otp-step[data-step="phone"]' ).prop( 'hidden', true );
		$( '#ocvc-otp-modal .ocvc-otp-step[data-step="code"]' ).prop( 'hidden', false );
		clearDigits();
		setTimeout( function () { $( '.ocvc-otp-digit' ).first().trigger( 'focus' ); }, 50 );
	}

	function clearDigits() {
		$( '.ocvc-otp-digit' ).val( '' );
	}

	function collectCode() {
		var code = '';
		$( '.ocvc-otp-digit' ).each( function () { code += ( this.value || '' ); } );
		return code;
	}

	// Send code.
	$( document ).on( 'click', '#ocvc-otp-send', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		var phone = $( '#ocvc-otp-phone' ).val();
		if ( ! phone || phone.replace( /\D/g, '' ).length < 6 ) {
			setMsg( '#ocvc-otp-msg', ocvc.i18n.invalid_phone, true );
			return;
		}
		$btn.prop( 'disabled', true );
		setMsg( '#ocvc-otp-msg', ocvc.i18n.sending, false );

		post( 'ocvc_send_otp', { phone: phone } )
			.done( function ( res ) {
				$btn.prop( 'disabled', false );
				if ( res && res.success ) {
					currentPhone = phone;
					showCodeStep( phone );
				} else {
					setMsg( '#ocvc-otp-msg', res && res.data ? res.data.message : ocvc.i18n.error, true );
				}
			} )
			.fail( function () {
				$btn.prop( 'disabled', false );
				setMsg( '#ocvc-otp-msg', ocvc.i18n.error, true );
			} );
	} );

	// Change number → back to phone step.
	$( document ).on( 'click', '#ocvc-otp-change', function ( e ) {
		e.preventDefault();
		showPhoneStep();
		$( '#ocvc-otp-phone' ).val( currentPhone ).trigger( 'focus' );
	} );

	// Digit boxes: keep numeric, auto-advance, auto-verify on completion.
	$( document ).on( 'input', '.ocvc-otp-digit', function () {
		this.value = ( this.value || '' ).replace( /\D/g, '' ).slice( 0, 1 );
		if ( this.value ) {
			$( this ).next( '.ocvc-otp-digit' ).trigger( 'focus' );
		}
		var code = collectCode();
		if ( code.length === 6 ) {
			verifyCode( code );
		}
	} );

	$( document ).on( 'keydown', '.ocvc-otp-digit', function ( e ) {
		if ( e.key === 'Backspace' && ! this.value ) {
			$( this ).prev( '.ocvc-otp-digit' ).trigger( 'focus' );
		}
	} );

	// Paste a full code into any box.
	$( document ).on( 'paste', '.ocvc-otp-digit', function ( e ) {
		var data = ( e.originalEvent.clipboardData || window.clipboardData ).getData( 'text' ) || '';
		var digits = data.replace( /\D/g, '' ).slice( 0, 6 );
		if ( ! digits ) {
			return;
		}
		e.preventDefault();
		var $boxes = $( '.ocvc-otp-digit' );
		$boxes.each( function ( i ) { this.value = digits[ i ] || ''; } );
		if ( digits.length === 6 ) {
			verifyCode( digits );
		} else {
			$boxes.eq( digits.length ).trigger( 'focus' );
		}
	} );

	function verifyCode( code ) {
		setMsg( '#ocvc-otp-msg2', ocvc.i18n.verifying, false );
		$( '.ocvc-otp-digit' ).prop( 'disabled', true );

		post( 'ocvc_verify_otp', { phone: currentPhone, code: code } )
			.done( function ( res ) {
				if ( res && res.success ) {
					closeModal();
					refreshCheckout();
				} else {
					$( '.ocvc-otp-digit' ).prop( 'disabled', false );
					setMsg( '#ocvc-otp-msg2', res && res.data ? res.data.message : ocvc.i18n.error, true );
					clearDigits();
					$( '.ocvc-otp-digit' ).first().trigger( 'focus' );
				}
			} )
			.fail( function () {
				$( '.ocvc-otp-digit' ).prop( 'disabled', false );
				setMsg( '#ocvc-otp-msg2', ocvc.i18n.error, true );
			} );
	}

	/* ----- Join popup ----------------------------------------------------- */

	$( document ).on( 'click', '#ocvc-join-details', function ( e ) {
		e.preventDefault();
		e.stopPropagation();
		openModal( '#ocvc-join-modal' );
	} );

	// Join popup: primary button turns the toggle on and closes; secondary just closes.
	$( document ).on( 'click', '#ocvc-join-accept', function ( e ) {
		e.preventDefault();
		$( '#ocvc-join-club' ).prop( 'checked', true ).trigger( 'change' );
		closeModal();
	} );

	$( document ).on( 'click', '#ocvc-join-decline', function ( e ) {
		e.preventDefault();
		$( '#ocvc-join-club' ).prop( 'checked', false ).trigger( 'change' );
		closeModal();
	} );

	/* ----- Modal plumbing ------------------------------------------------- */

	function openModal( selector ) {
		$( selector ).prop( 'hidden', false ).addClass( 'ocvc-open' );
	}

	function closeModal() {
		$( '.ocvc-modal' ).prop( 'hidden', true ).removeClass( 'ocvc-open' );
	}

	$( document ).on( 'click', '[data-ocvc-close]', function ( e ) {
		e.preventDefault();
		closeModal();
	} );

	$( document ).on( 'click', '.ocvc-modal', function ( e ) {
		if ( e.target === this ) {
			closeModal();
		}
	} );

	$( document ).on( 'keyup', function ( e ) {
		if ( e.key === 'Escape' ) {
			closeModal();
		}
	} );
} )( jQuery );
