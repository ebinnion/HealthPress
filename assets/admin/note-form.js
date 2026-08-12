/**
 * Imports a local text file into the note body.
 *
 * The file is read with FileReader and never leaves the browser. That is the
 * whole point: no upload handling, no MIME sniffing, no temp files, and nothing
 * added to the media library. It also means the imported text is visible and
 * editable before the note is saved, so the body stays the single source of
 * truth.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var input = document.getElementById( 'hp-note-import-file' );

		/*
		 * Selected by name, not id. add_meta_box() prints a wrapper <div> using
		 * the metabox id, so an id-based lookup here is one rename away from
		 * finding that div instead — and assigning `.value` to a div is legal and
		 * silently does nothing, so the failure would be invisible. The name is
		 * the identifier the save path already depends on.
		 */
		var body = document.querySelector( 'textarea[name="hp_note_body"]' );
		var strings = window.healthPressNotes || {};

		if ( ! input || ! body ) {
			return;
		}

		input.addEventListener( 'change', function () {
			var file = input.files && input.files[ 0 ];

			if ( ! file ) {
				return;
			}

			// Replacing existing text is the only destructive thing here.
			if ( '' !== body.value.trim() && ! window.confirm( strings.confirmReplace ) ) {
				input.value = '';

				return;
			}

			var reader = new FileReader();

			reader.onload = function () {
				body.value = reader.result;

				/*
				 * Let anything watching the field know it changed. Assigning to
				 * `value` fires no event of its own, so without this a listener
				 * added later would miss an import entirely.
				 */
				body.dispatchEvent( new Event( 'change', { bubbles: true } ) );

				/*
				 * Clear the control so choosing the same file twice fires
				 * `change` again — otherwise a re-import after an accidental
				 * edit silently does nothing.
				 */
				input.value = '';
			};

			reader.onerror = function () {
				window.alert( strings.readFailed );
				input.value = '';
			};

			reader.readAsText( file );
		} );
	} );
}() );
