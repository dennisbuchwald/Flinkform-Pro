/**
 * Field — Payment (Pro) — editor component.
 */
import { useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Button,
	PanelBody,
	SelectControl,
	TextControl,
	__experimentalNumberControl as NumberControl,
} from '@wordpress/components';

const CURRENCIES = [
	{ label: 'EUR', value: 'eur' },
	{ label: 'USD', value: 'usd' },
	{ label: 'GBP', value: 'gbp' },
	{ label: 'CHF', value: 'chf' },
];

const CURRENCY_SYMBOLS = { eur: '\u20AC', usd: '$', gbp: '\u00A3', chf: 'CHF' };

function generateFieldName( prefix ) {
	return `${ prefix }_${ Math.random().toString( 36 ).slice( 2, 8 ) }`;
}

function formatAmount( cents, currency ) {
	const symbol = CURRENCY_SYMBOLS[ currency ] || currency.toUpperCase();
	const value = ( cents / 100 ).toFixed( 2 ).replace( '.', ',' );
	return `${ value } ${ symbol }`;
}

export default function Edit( { attributes, setAttributes } ) {
	const { label, fieldName, priceMode, amount, currency, description, products } = attributes;
	const blockProps = useBlockProps( { className: 'flinkform-field flinkform-field--payment' } );
	const effectiveCurrency = currency || 'eur';

	useEffect( () => {
		if ( ! fieldName ) {
			setAttributes( { fieldName: generateFieldName( 'payment' ) } );
		}
	}, [] );

	const productsList = Array.isArray( products ) ? products : [];

	const updateProduct = ( index, key, value ) => {
		const updated = [ ...productsList ];
		updated[ index ] = { ...updated[ index ], [ key ]: value };
		setAttributes( { products: updated } );
	};

	const addProduct = () => {
		setAttributes( {
			products: [ ...productsList, { label: '', amount: 0 } ],
		} );
	};

	const removeProduct = ( index ) => {
		setAttributes( {
			products: productsList.filter( ( _, i ) => i !== index ),
		} );
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
						label={ __( 'Description', 'flinkform-pro' ) }
						help={ __( 'Shown on the Stripe receipt.', 'flinkform-pro' ) }
						value={ description }
						onChange={ ( v ) => setAttributes( { description: v } ) }
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
				<PanelBody title={ __( 'Pricing', 'flinkform-pro' ) }>
					<SelectControl
						label={ __( 'Price mode', 'flinkform-pro' ) }
						value={ priceMode }
						options={ [
							{ label: __( 'Fixed amount', 'flinkform-pro' ), value: 'fixed' },
							{ label: __( 'Product choices', 'flinkform-pro' ), value: 'products' },
						] }
						onChange={ ( v ) => setAttributes( { priceMode: v } ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<SelectControl
						label={ __( 'Currency', 'flinkform-pro' ) }
						help={ __( 'Leave empty to use the default from Stripe settings.', 'flinkform-pro' ) }
						value={ effectiveCurrency }
						options={ CURRENCIES }
						onChange={ ( v ) => setAttributes( { currency: v } ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					{ priceMode === 'fixed' && (
						<NumberControl
							label={ __( 'Amount (cents)', 'flinkform-pro' ) }
							help={ __( 'Amount in the smallest currency unit. 4900 = 49,00 EUR.', 'flinkform-pro' ) }
							value={ amount }
							onChange={ ( v ) => setAttributes( { amount: parseInt( v, 10 ) || 0 } ) }
							min={ 50 }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					) }
					{ priceMode === 'products' && (
						<div style={ { marginTop: '1rem' } }>
							{ productsList.map( ( product, i ) => (
								<div key={ i } style={ { display: 'flex', gap: '0.5rem', marginBottom: '0.5rem', alignItems: 'flex-end' } }>
									<TextControl
										label={ i === 0 ? __( 'Label', 'flinkform-pro' ) : '' }
										value={ product.label || '' }
										onChange={ ( v ) => updateProduct( i, 'label', v ) }
										__nextHasNoMarginBottom
										__next40pxDefaultSize
										style={ { flex: 1 } }
									/>
									<NumberControl
										label={ i === 0 ? __( 'Cents', 'flinkform-pro' ) : '' }
										value={ product.amount || 0 }
										onChange={ ( v ) => updateProduct( i, 'amount', parseInt( v, 10 ) || 0 ) }
										min={ 50 }
										__nextHasNoMarginBottom
										__next40pxDefaultSize
										style={ { width: '100px' } }
									/>
									<Button
										isDestructive
										variant="tertiary"
										onClick={ () => removeProduct( i ) }
										size="compact"
									>
										&times;
									</Button>
								</div>
							) ) }
							<Button variant="secondary" onClick={ addProduct } size="compact">
								{ __( '+ Add product', 'flinkform-pro' ) }
							</Button>
						</div>
					) }
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<label className="flinkform-field__label">
					{ label }
					<span className="flinkform-field__required" aria-hidden="true"> *</span>
				</label>
				{ priceMode === 'products' && productsList.length > 0 ? (
					<div className="flinkform-payment__products">
						{ productsList.map( ( p, i ) => (
							<div key={ i } className="flinkform-payment__product-option">
								<span className="flinkform-payment__product-radio" />
								<span className="flinkform-payment__product-label">{ p.label || __( '(unnamed)', 'flinkform-pro' ) }</span>
								<span className="flinkform-payment__product-price">{ formatAmount( p.amount || 0, effectiveCurrency ) }</span>
							</div>
						) ) }
					</div>
				) : (
					<div className="flinkform-payment__amount">
						{ amount > 0 ? formatAmount( amount, effectiveCurrency ) : __( 'Set an amount in the sidebar', 'flinkform-pro' ) }
					</div>
				) }
				<div className="flinkform-payment__card-preview">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" width="20" height="20">
						<rect x="2" y="5" width="20" height="14" rx="2" />
						<path d="M2 10h20" />
					</svg>
					<span>{ __( 'Stripe card input appears here', 'flinkform-pro' ) }</span>
				</div>
			</div>
		</>
	);
}
