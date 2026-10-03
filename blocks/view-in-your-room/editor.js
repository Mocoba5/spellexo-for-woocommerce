( function( blocks, element, i18n ) {
	'use strict';

	blocks.registerBlockType( 'spellexo/view-in-your-room', {
		edit: function() {
			return element.createElement(
				'div',
				{ className: 'spellexo-woocommerce-editor-placeholder' },
				i18n.__( 'Spellexo viewer button placement', 'spellexo-for-woocommerce' )
			);
		},
		save: function() {
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.i18n );
