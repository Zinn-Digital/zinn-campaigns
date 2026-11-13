/**
 * Zinn® Campaigns: the `zinn-campaigns/form` block in the editor (docs/1052 §11.9b).
 *
 * Plain script, no build step. Every string and the form list arrive translated from PHP
 * (`window.zinnCampaignsForm`, Zinn_Campaigns_Forms::editor_data()), so the plugin's catalogues
 * carry them all. The block is dynamic: the editor previews the server's own markup.
 *
 * @package ZinnCampaigns
 */
( function ( wp, data ) {
	'use strict';
	if ( ! wp || ! wp.blocks || ! data ) {
		return;
	}
	var el = wp.element.createElement;
	var be = wp.blockEditor;
	var c = wp.components;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'zinn-campaigns/form', {
		apiVersion: 3,
		title: data.title,
		description: data.description,
		category: 'zinn',
		icon: 'email-alt',
		attributes: {
			formId: { type: 'string', default: '' },
		},
		supports: { html: false },
		edit: function ( props ) {
			return el(
				'div',
				be.useBlockProps(),
				el(
					be.InspectorControls,
					null,
					el(
						c.PanelBody,
						{ title: data.settings },
						el( c.SelectControl, {
							label: data.form,
							value: props.attributes.formId,
							options: data.forms,
							onChange: function ( v ) {
								props.setAttributes( { formId: v } );
							},
						} )
					)
				),
				el( ServerSideRender, { block: 'zinn-campaigns/form', attributes: props.attributes } )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp, window.zinnCampaignsForm );
