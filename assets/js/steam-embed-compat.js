/** Extend an older standalone Node Blocks editor without changing saved node/embed blocks. */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.components || ! wp.domReady ) { return; }

	wp.domReady( function () {
		var existing = wp.blocks.getBlockType( 'node/embed' );
		if ( ! existing || String( existing.title ).indexOf( 'Steam' ) !== -1 ) { return; }

		var el = wp.element.createElement;
		var Fragment = wp.element.Fragment;
		var useState = wp.element.useState;
		var useEffect = wp.element.useEffect;
		var TextControl = wp.components.TextControl;
		var Button = wp.components.Button;
		var Placeholder = wp.components.Placeholder;
		var __ = wp.i18n.__;
		var oldEdit = existing.edit;

		function steamId( url ) {
			try {
				var parsed = new URL( url );
				if ( ! /^https?:$/.test( parsed.protocol ) || parsed.hostname.toLowerCase() !== 'store.steampowered.com' ) { return ''; }
				var match = parsed.pathname.match( /^\/(?:app|widget)\/([1-9][0-9]{0,9})(?:\/|$)/ );
				return match ? match[ 1 ] : '';
			} catch ( e ) { return ''; }
		}

		function supported( url ) {
			if ( steamId( url ) ) { return true; }
			try {
				var parsed = new URL( url );
				if ( ! /^https?:$/.test( parsed.protocol ) ) { return false; }
				var host = parsed.hostname.toLowerCase().replace( /^www\./, '' );
				if ( [ 'x.com', 'twitter.com', 'mobile.twitter.com' ].indexOf( host ) !== -1 ) { return /\/status(?:es)?\/\d+/.test( parsed.pathname ); }
				if ( [ 'youtube.com', 'm.youtube.com', 'youtu.be' ].indexOf( host ) !== -1 ) { return true; }
				return host === 'maps.app.goo.gl' || host === 'goo.gl' || host.indexOf( 'maps.google.' ) === 0 || ( host === 'google.com' && parsed.pathname.indexOf( '/maps' ) !== -1 );
			} catch ( e ) { return false; }
		}

		function Edit( props ) {
			var url = String( props.attributes.url || '' );
			var draftState = useState( url );
			var errorState = useState( false );
			var draft = draftState[ 0 ];
			var setDraft = draftState[ 1 ];
			var hasError = errorState[ 0 ];
			var setError = errorState[ 1 ];
			useEffect( function () { setDraft( url ); }, [ url ] );
			if ( url && ! steamId( url ) ) { return el( oldEdit, props ); }
			function apply( event ) {
				if ( event ) { event.preventDefault(); }
				var next = draft.trim();
				if ( ! supported( next ) ) { setError( true ); return; }
				setError( false );
				props.setAttributes( { url: next } );
			}
			var form = el( 'form', { onSubmit: apply, style: { display: 'flex', gap: '8px', alignItems: 'flex-start', marginTop: url ? '8px' : 0 } },
				el( TextControl, { value: draft, onChange: function ( value ) { setDraft( value ); setError( false ); }, placeholder: 'https://store.steampowered.com/app/570/', style: { minWidth: '280px' }, __nextHasNoMarginBottom: true } ),
				el( Button, { variant: url ? 'secondary' : 'primary', type: 'submit' }, url ? __( '更新', 'luminous-blocks' ) : __( '埋め込む', 'luminous-blocks' ) )
			);
			var message = hasError ? el( 'p', { style: { color: '#cc1818' } }, __( '対応する埋め込み URL を入力してください。', 'luminous-blocks' ) ) : null;
			if ( ! url ) {
				return el( Placeholder, { icon: 'embed-generic', label: __( 'Node 埋め込み（X / YouTube / Google マップ / Steam）', 'luminous-blocks' ) }, form, message );
			}
			return el( Fragment, null,
				el( 'iframe', { src: 'https://store.steampowered.com/widget/' + steamId( url ) + '/', title: 'Steam Store Widget', loading: 'lazy', width: 646, height: 190, style: { display: 'block', width: '100%', maxWidth: '646px', border: 0 } } ),
				props.isSelected ? el( Fragment, null, form, message ) : null
			);
		}

		wp.blocks.unregisterBlockType( 'node/embed' );
		wp.blocks.registerBlockType( 'node/embed', Object.assign( {}, existing, {
			title: __( 'Node 埋め込み（X / YouTube / Google マップ / Steam）', 'luminous-blocks' ),
			description: __( 'X(Twitter)・YouTube・Google マップ・Steam の URL を埋め込み表示します。', 'luminous-blocks' ),
			keywords: ( existing.keywords || [] ).concat( [ 'steam' ] ),
			edit: Edit,
		} ) );
	} );
} )( window.wp );
