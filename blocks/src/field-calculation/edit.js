/**
 * Field — Calculation (Pro) — editor component.
 *
 * The formula references sibling fields as {field:name}. To spare authors
 * from typing cryptic field names by hand, the inspector offers an
 * "Insert field" dropdown listing every sibling field of the surrounding
 * form; choosing one appends its reference to the formula.
 */
import { useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';

import { evaluate } from './evaluator';

function generateFieldName( prefix ) {
	return `${ prefix }_${ Math.random().toString( 36 ).slice( 2, 8 ) }`;
}

export default function Edit( { attributes, setAttributes, clientId } ) {
	const { label, fieldName, formula, decimals, prefix, suffix, helpText } = attributes;
	const blockProps = useBlockProps( { className: 'flinkform-field flinkform-field--calculation' } );

	useEffect( () => {
		if ( ! fieldName ) {
			setAttributes( { fieldName: generateFieldName( 'calc' ) } );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	// Sibling fields of the surrounding form (for the insert dropdown).
	const siblingFields = useSelect(
		( select ) => {
			const { getBlockParentsByBlockName, getBlock } = select( 'core/block-editor' );
			const formIds = getBlockParentsByBlockName( clientId, 'flinkform/form' );
			if ( ! formIds.length ) {
				return [];
			}
			const form = getBlock( formIds[ formIds.length - 1 ] );
			if ( ! form || ! form.innerBlocks ) {
				return [];
			}
			return form.innerBlocks
				.filter(
					( block ) =>
						block.clientId !== clientId &&
						block.attributes &&
						block.attributes.fieldName &&
						! [ 'flinkform/page-break', 'flinkform/section-heading' ].includes( block.name )
				)
				.map( ( block ) => ( {
					name: block.attributes.fieldName,
					label: block.attributes.label || block.attributes.fieldName,
				} ) );
		},
		[ clientId ]
	);

	// Formula sanity feedback: evaluate with all refs = 1.
	const formulaValid = ! formula || null !== evaluate( formula, () => 1 );

	const previewValue = () => {
		if ( ! formula ) {
			return '—';
		}
		const result = evaluate( formula, () => 1 );
		if ( null === result || ! isFinite( result ) ) {
			return '—';
		}
		return result.toFixed( typeof decimals === 'number' ? decimals : 2 );
	};

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Field Settings', 'flinkform-pro' ) }>
					<TextControl
						label={ __( 'Label', 'flinkform-pro' ) }
						value={ label }
						onChange={ ( v ) => setAttributes( { label: v } ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						label={ __( 'Help Text', 'flinkform-pro' ) }
						value={ helpText }
						onChange={ ( v ) => setAttributes( { helpText: v } ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						label={ __( 'Field Name', 'flinkform-pro' ) }
						help={ __( 'Key used in submission data. Auto-generated; change with care.', 'flinkform-pro' ) }
						value={ fieldName }
						onChange={ ( v ) => setAttributes( { fieldName: v } ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</PanelBody>
				<PanelBody title={ __( 'Formula', 'flinkform-pro' ) }>
					<TextareaControl
						label={ __( 'Formula', 'flinkform-pro' ) }
						help={ __( 'Numbers, + - * /, parentheses and field references. Example: ({field:qty} * 49.90) + {field:setup}', 'flinkform-pro' ) }
						value={ formula }
						onChange={ ( v ) => setAttributes( { formula: v } ) }
						rows={ 3 }
						__nextHasNoMarginBottom
					/>
					{ ! formulaValid && (
						<p style={ { color: '#b32d2e', marginTop: '4px' } }>
							{ __( 'This formula cannot be evaluated — check operators and parentheses.', 'flinkform-pro' ) }
						</p>
					) }
					<SelectControl
						label={ __( 'Insert field reference', 'flinkform-pro' ) }
						help={
							siblingFields.length
								? __( 'Appends the chosen field to the formula.', 'flinkform-pro' )
								: __( 'Add other fields to the form first.', 'flinkform-pro' )
						}
						value=""
						options={ [
							{ value: '', label: __( '— Select a field —', 'flinkform-pro' ) },
							...siblingFields.map( ( f ) => ( {
								value: f.name,
								label: `${ f.label } ({field:${ f.name }})`,
							} ) ),
						] }
						onChange={ ( v ) => {
							if ( v ) {
								setAttributes( { formula: `${ formula || '' }{field:${ v }}` } );
							}
						} }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<RangeControl
						label={ __( 'Decimal places', 'flinkform-pro' ) }
						value={ typeof decimals === 'number' ? decimals : 2 }
						onChange={ ( v ) => setAttributes( { decimals: v } ) }
						min={ 0 }
						max={ 4 }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						label={ __( 'Prefix', 'flinkform-pro' ) }
						help={ __( 'Shown before the value, e.g. "€".', 'flinkform-pro' ) }
						value={ prefix }
						onChange={ ( v ) => setAttributes( { prefix: v } ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						label={ __( 'Suffix', 'flinkform-pro' ) }
						help={ __( 'Shown after the value, e.g. "EUR" or "kg".', 'flinkform-pro' ) }
						value={ suffix }
						onChange={ ( v ) => setAttributes( { suffix: v } ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<span className="flinkform-field__label">{ label }</span>
				<div className="flinkform-field__calculation-value">
					{ prefix && <span className="flinkform-field__calculation-prefix">{ prefix }</span> }
					<output className="flinkform-field__calculation-output">{ previewValue() }</output>
					{ suffix && <span className="flinkform-field__calculation-suffix">{ suffix }</span> }
				</div>
				<p className="flinkform-field__help">
					{ formula
						? sprintf(
							/* translators: %s: the formula */
							__( 'Formula: %s', 'flinkform-pro' ),
							formula
						)
						: __( 'No formula yet — set one in the block settings.', 'flinkform-pro' ) }
				</p>
				{ helpText && <p className="flinkform-field__help">{ helpText }</p> }
			</div>
		</>
	);
}
