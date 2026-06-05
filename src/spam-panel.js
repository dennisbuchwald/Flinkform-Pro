/**
 * Spam Protection inspector panel (Pro).
 *
 * Re-adds the spam-strategy selector that the free core removed for .org
 * compliance. The `spamProtection` attribute still lives on the free core's
 * form-container block.json — only the UI was stripped.
 *
 * @package PerFormPro
 * @since 0.2.10
 */
import { __ } from '@wordpress/i18n';
import { PanelBody, SelectControl, Notice } from '@wordpress/components';

export default function SpamPanel( { attributes, setAttributes } ) {
	const spamProtection = attributes.spamProtection ?? 'auto';

	return (
		<PanelBody
			title={ __( 'Spam Protection', 'perform-forms-pro' ) }
			initialOpen={ false }
		>
			<SelectControl
				label={ __( 'Strategy', 'perform-forms-pro' ) }
				value={ spamProtection }
				options={ [
					{
						label: __( 'Auto — built-in challenge (recommended)', 'perform-forms-pro' ),
						value: 'auto',
					},
					{
						label: __( 'Built-in challenge (force on)', 'perform-forms-pro' ),
						value: 'builtin',
					},
					{
						label: __( 'None — honeypot + time-check only', 'perform-forms-pro' ),
						value: 'none',
					},
				] }
				help={ __(
					'The built-in challenge runs a tiny proof-of-work in the visitor\'s browser (transparent, ~50–500 ms) plus a math fallback for visitors without JavaScript. No external services, no cookies.',
					'perform-forms-pro'
				) }
				onChange={ ( value ) => setAttributes( { spamProtection: value } ) }
				__nextHasNoMarginBottom
			/>
			{ spamProtection === 'none' && (
				<Notice status="warning" isDismissible={ false } className="perform-spam-notice">
					{ __(
						'Honeypot + time-check still apply, but the active challenge is off. Use only for trusted-context forms (logged-in users, internal tools).',
						'perform-forms-pro'
					) }
				</Notice>
			) }
		</PanelBody>
	);
}
