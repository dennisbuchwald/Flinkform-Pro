/**
 * PerForm Pro — block-editor extensions entry.
 *
 * Docks Pro inspector panels onto the free core's form-container via the
 * `perform.formContainer.inspectorPanels` filter (the M-c-c seam). Built with
 * @wordpress/scripts; the compiled bundle + its asset manifest are enqueued by
 * PerFormPro\Editor\Extensions on enqueue_block_editor_assets.
 *
 * @package PerFormPro
 * @since 0.2.4
 */
import { addFilter } from '@wordpress/hooks';

import IntegrationsPanel from './integrations-panel';

/**
 * Append the Pro Integrations (webhooks) panel to the form-container inspector.
 *
 * @param {Array}  panels Panels collected so far (React elements).
 * @param {Object} props  Editing context: { attributes, setAttributes, clientId, formId, formFields }.
 * @return {Array} Panels including the Pro additions.
 */
addFilter(
	'perform.formContainer.inspectorPanels',
	'perform-forms-pro/integrations',
	( panels, props ) => [
		...panels,
		<IntegrationsPanel
			key="perform-pro-integrations"
			formId={ props.formId }
			formFields={ props.formFields }
		/>,
	]
);
