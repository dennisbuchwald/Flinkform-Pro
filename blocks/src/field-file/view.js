/**
 * File Upload field — frontend enhancement (Pro).
 *
 * Upgrades the native file input to a dropzone: shows selected files as
 * cards (name + size + remove), highlights on drag-over, checks size and
 * file count client-side BEFORE the round-trip, and keeps everything
 * functional without JavaScript (the enhancement only kicks in once this
 * script adds `.is-enhanced`).
 *
 * Multi-file fields render each selection into a list with per-item
 * remove buttons; removal rebuilds input.files via DataTransfer so the
 * final POST matches exactly what the visitor sees.
 *
 * All visible strings are rendered (translated) by render.php / data
 * attributes; this script only toggles classes and fills in name/size.
 */

( function () {
	function formatSize( bytes ) {
		if ( ! bytes || bytes <= 0 ) {
			return '';
		}
		if ( bytes < 1024 * 1024 ) {
			return Math.max( 1, Math.round( bytes / 1024 ) ) + ' KB';
		}
		return ( bytes / ( 1024 * 1024 ) ).toFixed( 1 ).replace( '.0', '' ) + ' MB';
	}

	function enhance( field ) {
		const zone  = field.querySelector( '[data-flinkform-dropzone]' );
		const input = zone ? zone.querySelector( 'input[type="file"]' ) : null;
		if ( ! zone || ! input ) {
			return;
		}

		const nameEl   = zone.querySelector( '[data-flinkform-file-name]' );
		const sizeEl   = zone.querySelector( '[data-flinkform-file-size]' );
		const removeEl = zone.querySelector( '[data-flinkform-file-remove]' );
		const listEl   = field.querySelector( '[data-flinkform-file-list]' );
		const errorEl  = field.querySelector( '[data-flinkform-file-error]' );

		const multiple   = input.hasAttribute( 'multiple' );
		const maxSizeMb  = parseInt( zone.dataset.maxSizeMb, 10 ) || 0;
		const maxFiles   = parseInt( zone.dataset.maxFiles, 10 ) || 1;
		const msgLarge   = zone.dataset.msgTooLarge || '';
		const msgMany    = zone.dataset.msgTooMany || '';

		field.classList.add( 'is-enhanced' );
		if ( multiple ) {
			field.classList.add( 'is-multiple' );
		}

		const showError = ( message ) => {
			if ( ! errorEl ) {
				return;
			}
			errorEl.textContent = message;
			errorEl.hidden = '' === message;
		};

		// Rebuild input.files minus one index (multi-file remove).
		const removeAt = ( index ) => {
			const dt = new DataTransfer();
			Array.from( input.files ).forEach( ( file, i ) => {
				if ( i !== index ) {
					dt.items.add( file );
				}
			} );
			input.files = dt.files;
			update();
			input.focus();
		};

		const renderList = () => {
			if ( ! listEl ) {
				return;
			}
			listEl.textContent = '';
			const files = Array.from( input.files || [] );
			listEl.hidden = 0 === files.length;

			files.forEach( ( file, index ) => {
				const item = document.createElement( 'li' );
				item.className = 'flinkform-field__file-item';

				const name = document.createElement( 'span' );
				name.className = 'flinkform-field__file-item-name';
				name.textContent = file.name;

				const size = document.createElement( 'span' );
				size.className = 'flinkform-field__file-item-size';
				size.textContent = formatSize( file.size );

				const remove = document.createElement( 'button' );
				remove.type = 'button';
				remove.className = 'flinkform-field__file-item-remove';
				remove.textContent = '×';
				remove.setAttribute(
					'aria-label',
					( removeEl && removeEl.getAttribute( 'aria-label' ) ? removeEl.getAttribute( 'aria-label' ) : 'Remove' ) + ' – ' + file.name
				);
				remove.addEventListener( 'click', () => removeAt( index ) );

				item.appendChild( name );
				item.appendChild( size );
				item.appendChild( remove );
				listEl.appendChild( item );
			} );
		};

		const update = () => {
			const files = Array.from( input.files || [] );

			if ( multiple ) {
				field.classList.toggle( 'has-file', files.length > 0 );
				renderList();
				return;
			}

			const file = files[ 0 ] || null;
			field.classList.toggle( 'has-file', !! file );
			if ( nameEl ) {
				nameEl.textContent = file ? file.name : '';
			}
			if ( sizeEl ) {
				sizeEl.textContent = file ? formatSize( file.size ) : '';
			}
		};

		// Client-side validation on selection: size per file + total count.
		// Invalid selections are cleared so the visitor learns immediately —
		// the server re-checks everything regardless.
		const validateSelection = () => {
			showError( '' );
			const files = Array.from( input.files || [] );

			if ( multiple && files.length > maxFiles ) {
				input.value = '';
				showError( msgMany );
				return;
			}

			if ( maxSizeMb > 0 ) {
				const tooLarge = files.find( ( f ) => f.size > maxSizeMb * 1024 * 1024 );
				if ( tooLarge ) {
					input.value = '';
					showError( tooLarge.name + ' – ' + msgLarge );
				}
			}
		};

		input.addEventListener( 'change', () => {
			validateSelection();
			update();
		} );

		// Drag-over highlight — the drop itself is handled natively by
		// the input element that covers the whole zone.
		[ 'dragenter', 'dragover' ].forEach( ( type ) =>
			input.addEventListener( type, () => zone.classList.add( 'is-dragover' ) )
		);
		[ 'dragleave', 'drop' ].forEach( ( type ) =>
			input.addEventListener( type, () => zone.classList.remove( 'is-dragover' ) )
		);

		if ( removeEl ) {
			removeEl.addEventListener( 'click', () => {
				input.value = '';
				showError( '' );
				update();
				input.focus();
			} );
		}

		update();
	}

	function init() {
		document.querySelectorAll( '.flinkform-field--file' ).forEach( enhance );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
