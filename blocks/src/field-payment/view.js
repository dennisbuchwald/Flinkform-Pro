/**
 * Payment field — frontend Stripe integration.
 *
 * Loads Stripe.js (enqueued by the PHP module), mounts a Card Element,
 * and intercepts the form submit to process the payment before the
 * form data is POSTed to the server.
 *
 * Flow:
 *   1. User fills form + card details
 *   2. User clicks submit
 *   3. This script intercepts the submit event
 *   4. Creates a PaymentIntent via the REST endpoint
 *   5. Confirms the payment with Stripe.js
 *   6. On success, sets the hidden input to the PaymentIntent ID
 *   7. Re-submits the form (server verifies the intent)
 */
( function () {
	'use strict';

	function init() {
		document.querySelectorAll( '[data-flinkform-payment]' ).forEach( setup );
	}

	function setup( field ) {
		const stripeKey = field.dataset.stripeKey;
		if ( ! stripeKey || typeof window.Stripe === 'undefined' ) {
			return;
		}

		const stripe      = window.Stripe( stripeKey );
		const elements    = stripe.elements();
		const cardMount   = field.querySelector( '[data-flinkform-card-element]' );
		const errorsEl    = field.querySelector( '[data-flinkform-card-errors]' );
		const intentInput = field.querySelector( '[data-flinkform-payment-intent]' );
		const form        = field.closest( 'form' );

		if ( ! cardMount || ! intentInput || ! form ) {
			return;
		}

		// Mount the Stripe Card Element.
		const card = elements.create( 'card', {
			style: {
				base: {
					fontSize: '16px',
					color: '#333',
					'::placeholder': { color: '#999' },
				},
				invalid: { color: '#b32d2e' },
			},
			hidePostalCode: true,
		} );
		card.mount( cardMount );

		// Show inline card validation errors.
		card.on( 'change', function ( event ) {
			if ( errorsEl ) {
				errorsEl.textContent = event.error ? event.error.message : '';
			}
		} );

		// Track whether we already processed payment for this submit cycle.
		let paymentProcessed = false;

		form.addEventListener( 'submit', function ( e ) {
			// If payment was already confirmed, let the form submit through.
			if ( paymentProcessed ) {
				return;
			}

			e.preventDefault();

			// Determine amount (from product radio or fixed).
			let amount = 0;
			const checkedProduct = field.querySelector( '.flinkform-payment__product-radio:checked' );
			if ( checkedProduct ) {
				amount = parseInt( checkedProduct.dataset.amount, 10 ) || 0;
			} else {
				const amountEl = field.querySelector( '[data-amount]' );
				amount = amountEl ? parseInt( amountEl.dataset.amount, 10 ) || 0 : 0;
			}

			if ( amount < 50 ) {
				showError( errorsEl, 'Invalid amount.' );
				return;
			}

			// Disable submit button.
			const submitBtn = form.querySelector( '[type="submit"]' );
			if ( submitBtn ) {
				submitBtn.disabled = true;
				submitBtn.classList.add( 'is-loading' );
			}

			// Step 1: Create PaymentIntent via REST.
			const restUrl  = field.dataset.restUrl;
			const nonce    = field.dataset.nonce;
			const formId   = field.dataset.formId;
			const currency = field.dataset.currency || 'eur';

			fetch( restUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( {
					form_id: formId,
					amount: amount,
					currency: currency,
					nonce: nonce,
				} ),
			} )
				.then( function ( res ) { return res.json(); } )
				.then( function ( data ) {
					if ( data.error ) {
						throw new Error( data.error );
					}

					// Step 2: Confirm the payment with Stripe.js.
					return stripe.confirmCardPayment( data.client_secret, {
						payment_method: { card: card },
					} );
				} )
				.then( function ( result ) {
					if ( result.error ) {
						throw new Error( result.error.message );
					}

					// Step 3: Payment succeeded. Set the intent ID and re-submit.
					intentInput.value = result.paymentIntent.id;
					paymentProcessed = true;

					// Use requestSubmit() to re-trigger validation + submit handlers.
					if ( typeof form.requestSubmit === 'function' ) {
						form.requestSubmit();
					} else {
						form.submit();
					}
				} )
				.catch( function ( err ) {
					showError( errorsEl, err.message || 'Payment failed.' );
					if ( submitBtn ) {
						submitBtn.disabled = false;
						submitBtn.classList.remove( 'is-loading' );
					}
				} );
		} );
	}

	function showError( el, message ) {
		if ( el ) {
			el.textContent = message;
			el.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
