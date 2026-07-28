/**
 * Tutor Course Bundles — bundle builder.
 *
 * Handles the AJAX course search, duplicate prevention, drag-to-reorder and
 * index renumbering so the POST payload always arrives in display order.
 */
( function ( $ ) {
	'use strict';

	var config = window.tcbAdmin || {};
	var searchTimer = null;

	var $builder;
	var $input;
	var $results;
	var $list;
	var $empty;

	/**
	 * Course IDs already in the list.
	 *
	 * @return {number[]} IDs.
	 */
	function selectedIds() {
		return $list
			.find( '.tcb-course-row' )
			.map( function () {
				return parseInt( $( this ).attr( 'data-course-id' ), 10 );
			} )
			.get();
	}

	/**
	 * Rewrite the `tcb_courses[n]` indexes after any change in order.
	 */
	function reindex() {
		$list.find( '.tcb-course-row' ).each( function ( index ) {
			$( this )
				.find( 'input' )
				.each( function () {
					var name = $( this ).attr( 'name' );

					if ( ! name ) {
						return;
					}

					$( this ).attr( 'name', name.replace( /tcb_courses\[\d+\]/, 'tcb_courses[' + index + ']' ) );
				} );
		} );

		$empty.prop( 'hidden', $list.find( '.tcb-course-row' ).length > 0 );
	}

	/**
	 * Append a course to the selected list.
	 *
	 * @param {Object} course Course payload from the server.
	 */
	function addCourse( course ) {
		if ( selectedIds().indexOf( course.id ) !== -1 ) {
			return;
		}

		var template = document.getElementById( 'tcb-course-row-template' );

		if ( ! template ) {
			return;
		}

		var title = course.title || '';

		if ( course.status && 'publish' !== course.status ) {
			title += ' (' + ( config.i18n.draft || course.status ) + ')';
		}

		var markup = template.innerHTML
			.replace( /__ID__/g, String( course.id ) )
			.replace( /__INDEX__/g, String( $list.find( '.tcb-course-row' ).length ) );

		var $row = $( markup );
		$row.find( '.tcb-course-row__title' ).text( title );

		$list.append( $row );
		reindex();
	}

	/**
	 * Render search results.
	 *
	 * @param {Array} courses Course list.
	 */
	function renderResults( courses ) {
		$results.empty();

		if ( ! courses.length ) {
			$results.append( $( '<p class="tcb-course-search__empty"></p>' ).text( config.i18n.noResults ) );
			$results.show();
			return;
		}

		courses.forEach( function ( course ) {
			var label = course.title;

			if ( course.status && 'publish' !== course.status ) {
				label += ' — ' + ( config.i18n.draft || course.status );
			}

			var $item = $( '<button type="button" class="tcb-course-search__item"></button>' )
				.attr( 'role', 'option' )
				.text( label );

			$item.on( 'click', function () {
				addCourse( course );
				$results.hide().empty();
				$input.val( '' ).trigger( 'focus' );
			} );

			$results.append( $item );
		} );

		$results.show();
	}

	/**
	 * Query the server for matching courses.
	 *
	 * @param {string} term Search term.
	 */
	function search( term ) {
		$results.html( '<p class="tcb-course-search__empty">' + config.i18n.searching + '</p>' ).show();

		$.post( config.ajaxUrl, {
			action: 'tcb_search_courses',
			nonce: config.nonce,
			search: term,
			exclude: selectedIds()
		} )
			.done( function ( response ) {
				if ( response && response.success ) {
					renderResults( response.data.courses || [] );
				} else {
					$results.html( '<p class="tcb-course-search__empty">' + config.i18n.error + '</p>' );
				}
			} )
			.fail( function () {
				$results.html( '<p class="tcb-course-search__empty">' + config.i18n.error + '</p>' );
			} );
	}

	function bind() {
		$input.on( 'input', function () {
			var term = $.trim( $( this ).val() );

			window.clearTimeout( searchTimer );

			if ( term.length < 2 ) {
				$results.hide().empty();
				return;
			}

			searchTimer = window.setTimeout( function () {
				search( term );
			}, 300 );
		} );

		$input.on( 'keydown', function ( event ) {
			if ( 13 === event.which ) {
				event.preventDefault();
			}
		} );

		$( document ).on( 'click', function ( event ) {
			if ( ! $( event.target ).closest( '.tcb-course-search' ).length ) {
				$results.hide();
			}
		} );

		$list.on( 'click', '.tcb-course-row__remove', function () {
			$( this ).closest( '.tcb-course-row' ).remove();
			reindex();
		} );

		if ( $.fn.sortable ) {
			$list.sortable( {
				handle: '.tcb-course-row__handle',
				axis: 'y',
				placeholder: 'tcb-course-row-placeholder',
				update: reindex
			} );
		}

		// Show or hide the price fields with the bundle type.
		$( '#tcb_access_type' ).on( 'change', function () {
			$( '.tcb-paid-fields' ).toggle( 'paid' === $( this ).val() );
		} );
	}

	$( function () {
		$builder = $( '.tcb-course-builder' );

		if ( ! $builder.length ) {
			return;
		}

		$input = $builder.find( '.tcb-course-search__input' );
		$results = $builder.find( '.tcb-course-search__results' );
		$list = $( '#tcb-selected-courses' );
		$empty = $builder.find( '.tcb-empty-state' );

		$results.hide();

		bind();
		reindex();
	} );
}( jQuery ) );
