/**
 * Payment field — frontend Stripe integration.
 *
 * Loads Stripe.js (enqueued by the PHP module), mounts a Payment Element,
 * and intercepts the form submit to process the payment before the form
 * data is POSTed to the server.
 *
 * Uses Stripe's deferred-intent flow so the Element can render before the
 * PaymentIntent exists and its amount can follow the visitor's product
 * choice:
 *
 *   1. Mount the Payment Element with the current amount + currency.
 *   2. On product change, elements.update() keeps the amount in sync.
 *   3. On submit: elements.submit() validates → create the PaymentIntent
 *      server-side (amount + currency are bound to the form definition
 *      there, not trusted from here) → stripe.confirmPayment().
 *   4. redirect: 'if_required' keeps the confirmation in-page; the server
 *      restricts the intent to non-redirect methods (cards, Apple Pay,
 *      Google Pay, Link) for this synchronous flow.
 *   5. On success, set the hidden input to the PaymentIntent ID and
 *      re-submit the form (the server verifies the intent).
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

		const mount       = field.querySelector( '[data-flinkform-card-element]' );
		const errorsEl    = field.querySelector( '[data-flinkform-card-errors]' );
		const intentInput = field.querySelector( '[data-flinkform-payment-intent]' );
		const form        = field.closest( 'form' );

		if ( ! mount || ! intentInput || ! form ) {
			return;
		}

		const stripe   = window.Stripe( stripeKey );
		const currency = ( field.dataset.currency || 'eur' ).toLowerCase();

		const messages = {
			invalidAmount: field.dataset.msgInvalidAmount || 'Please choose a payment option.',
			failed:        field.dataset.msgFailed || 'Payment could not be completed. Please try again.',
		};

		const currentAmount = function () {
			const checked = field.querySelector( '.flinkform-payment__product-radio:checked' );
			if ( checked ) {
				return parseInt( checked.dataset.amount, 10 ) || 0;
			}
			const amountEl = field.querySelector( '[data-amount]' );
			return amountEl ? parseInt( amountEl.dataset.amount, 10 ) || 0 : 0;
		};

		// The Element needs a positive amount to mount; clamp for display and
		// gate the real charge on submit.
		const elements = stripe.elements( {
			mode: 'payment',
			amount: Math.max( currentAmount(), 50 ),
			currency: currency,
		} );
		const paymentElement = elements.create( 'payment', { layout: 'tabs' } );
		paymentElement.mount( mount );

		// Keep the Element's amount in step with the selected product.
		field.querySelectorAll( '.flinkform-payment__product-radio' ).forEach( function ( radio ) {
			radio.addEventListener( 'change', function () {
				const next = currentAmount();
				if ( next >= 50 ) {
					elements.update( { amount: next } );
				}
			} );
		} );

		// Guard so the second (post-payment) submit passes straight through.
		let paymentProcessed = false;

		form.addEventListener( 'submit', function ( e ) {
			if ( paymentProcessed ) {
				return;
			}

			e.preventDefault();

			const amount = currentAmount();
			if ( amount < 50 ) {
				showError( errorsEl, messages.invalidAmount );
				return;
			}

			const submitBtn = form.querySelector( '[type="submit"]' );
			setLoading( submitBtn, true );
			clearError( errorsEl );

			// Deferred flow: validate → create intent → confirm.
			elements.submit()
				.then( function ( res ) {
					if ( res.error ) {
						throw new Error( res.error.message || messages.failed );
					}
					return createIntent( field, amount );
				} )
				.then( function ( clientSecret ) {
					return stripe.confirmPayment( {
						elements: elements,
						clientSecret: clientSecret,
						confirmParams: {
							// Required by the API; unused because the server
							// disallows redirect methods for this flow.
							return_url: window.location.href,
						},
						redirect: 'if_required',
					} );
				} )
				.then( function ( result ) {
					if ( result.error ) {
						throw new Error( result.error.message || messages.failed );
					}
					intentInput.value = result.paymentIntent.id;
					paymentProcessed = true;

					// Re-trigger native validation + submit handlers.
					if ( typeof form.requestSubmit === 'function' ) {
						form.requestSubmit();
					} else {
						form.submit();
					}
				} )
				.catch( function ( err ) {
					showError( errorsEl, err.message || messages.failed );
					setLoading( submitBtn, false );
				} );
		} );
	}

	/**
	 * Create the PaymentIntent server-side and return its client_secret.
	 * The server derives the real amount + currency from the form
	 * definition; the amount we send is only accepted when it matches a
	 * configured price.
	 */
	function createIntent( field, amount ) {
		const body = {
			form_id: field.dataset.formId,
			post_id: parseInt( field.dataset.postId, 10 ) || 0,
			amount: amount,
			nonce: field.dataset.nonce,
		};

		const email = findEmail( field );
		if ( email ) {
			body.email = email;
		}

		return fetch( field.dataset.restUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( body ),
		} )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( data ) {
				if ( ! data || data.error || ! data.client_secret ) {
					throw new Error( ( data && data.error ) || 'Payment failed.' );
				}
				return data.client_secret;
			} );
	}

	/**
	 * Best-effort lookup of an email value in the same form, for the Stripe
	 * receipt. Never a security input — the server re-sanitises it.
	 */
	function findEmail( field ) {
		const form = field.closest( 'form' );
		if ( ! form ) {
			return '';
		}
		const el = form.querySelector( 'input[type="email"]' );
		return el && el.value ? el.value.trim() : '';
	}

	function setLoading( btn, on ) {
		if ( ! btn ) {
			return;
		}
		btn.disabled = on;
		btn.classList.toggle( 'is-loading', on );
	}

	function clearError( el ) {
		if ( el ) {
			el.textContent = '';
		}
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
