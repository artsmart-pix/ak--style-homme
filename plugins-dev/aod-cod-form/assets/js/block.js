/* AOD COD Form — bloc Gutenberg (dynamique, rendu serveur via ServerSideRender). */
( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	'use strict';

	var el                = element.createElement;
	var __                = i18n.__;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps     = blockEditor.useBlockProps;
	var PanelBody         = components.PanelBody;
	var TextControl       = components.TextControl;
	var ServerSideRender  = serverSideRender; // export par défaut = composant.

	blocks.registerBlockType( 'aod/cod-form', {
		edit: function ( props ) {
			var productId  = props.attributes.productId || 0;
			var blockProps = useBlockProps();

			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Réglages du formulaire COD', 'aod-cod-form' ), initialOpen: true },
						el( TextControl, {
							label: __( 'ID du produit', 'aod-cod-form' ),
							help: __( 'Laissez 0 pour utiliser le produit de la page courante.', 'aod-cod-form' ),
							type: 'number',
							value: productId,
							onChange: function ( v ) {
								props.setAttributes( { productId: parseInt( v, 10 ) || 0 } );
							}
						} )
					)
				),
				el( ServerSideRender, {
					block: 'aod/cod-form',
					attributes: props.attributes
				} )
			);
		},
		// Rendu dynamique : rien n'est sauvegardé dans le contenu.
		save: function () {
			return null;
		}
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.serverSideRender,
	window.wp.i18n
);
