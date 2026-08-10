/**
 * Swaps the visible value fields when the metric changes.
 *
 * Every metric's group is rendered server-side; this only decides which one is
 * live. The server renders the correct initial state, so with JavaScript off
 * the form still records a reading of the preselected metric.
 */
( function () {
	'use strict';

	var select = document.getElementById( 'hp-metric' );
	var groups = document.querySelectorAll( '.hp-values' );

	if ( ! select || ! groups.length ) {
		return;
	}

	function apply() {
		groups.forEach( function ( group ) {
			var active = group.dataset.metric === select.value;

			group.hidden = ! active;

			/*
			 * Disabled, not merely hidden. A hidden input still submits, and a
			 * hidden `required` input blocks submission outright — the browser
			 * cannot focus it to report the error, so nothing happens at all.
			 */
			group.querySelectorAll( 'input' ).forEach( function ( control ) {
				control.disabled = ! active;
			} );
		} );
	}

	select.addEventListener( 'change', apply );

	// Reconcile once on load, so the rendered state and the DOM cannot disagree.
	apply();
} )();
