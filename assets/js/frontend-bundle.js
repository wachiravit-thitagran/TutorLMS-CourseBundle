/**
 * Tutor Course Bundles — frontend behaviour.
 *
 * Progressive enhancement only: every action here also works as a plain link
 * with a nonce, so the page is functional with JavaScript disabled.
 */
( function () {
	'use strict';

	var config = window.tcbFrontend || {};

	/**
	 * Post JSON to a REST route.
	 *
	 * @param {string} route Route relative to the tcb/v1 namespace.
	 * @param {Object} body  Payload.
	 * @return {Promise<Object>} Parsed response.
	 */
	function post( route, body ) {
		return fetch( config.restUrl + route, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce || ''
			},
			body: JSON.stringify( body || {} )
		} ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( ! response.ok ) {
					throw new Error( ( data && data.message ) || ( config.i18n && config.i18n.error ) );
				}

				return data;
			} );
		} );
	}

	/**
	 * Handle the AJAX enrollment button, when a theme opts into one.
	 *
	 * @param {Event} event Click event.
	 */
	function onEnrollClick( event ) {
		var button = event.currentTarget;
		var bundleId = parseInt( button.getAttribute( 'data-bundle-id' ), 10 );

		if ( ! bundleId ) {
			return;
		}

		event.preventDefault();

		if ( button.classList.contains( 'is-busy' ) ) {
			return;
		}

		var originalText = button.textContent;
		button.classList.add( 'is-busy' );
		button.textContent = ( config.i18n && config.i18n.working ) || '…';

		post( '/bundles/' + bundleId + '/enroll', {} )
			.then( function () {
				window.location.reload();
			} )
			.catch( function ( error ) {
				button.classList.remove( 'is-busy' );
				button.textContent = originalText;
				showMessage( button, error.message );
			} );
	}

	/**
	 * Render an inline error next to a control.
	 *
	 * @param {HTMLElement} anchor  Element to append after.
	 * @param {string}      message Text to show.
	 */
	function showMessage( anchor, message ) {
		var existing = anchor.parentNode.querySelector( '.tcb-inline-message' );

		if ( existing ) {
			existing.remove();
		}

		var node = document.createElement( 'p' );
		node.className = 'tcb-inline-message tcb-notice';
		node.setAttribute( 'role', 'alert' );
		node.textContent = message;
		anchor.parentNode.appendChild( node );
	}

	/**
	 * Animate progress bars into place on first paint.
	 */
	function initProgressBars() {
		var bars = document.querySelectorAll( '.tcb-progress-bar__fill' );

		Array.prototype.forEach.call( bars, function ( bar ) {
			var target = bar.style.width;
			bar.style.width = '0%';

			window.requestAnimationFrame( function () {
				window.requestAnimationFrame( function () {
					bar.style.width = target;
				} );
			} );
		} );
	}

	function init() {
		var buttons = document.querySelectorAll( '[data-tcb-enroll]' );

		Array.prototype.forEach.call( buttons, function ( button ) {
			button.addEventListener( 'click', onEnrollClick );
		} );

		if ( ! window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
			initProgressBars();
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
