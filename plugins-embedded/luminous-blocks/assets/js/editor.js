/**
 * Luminous Blocks — エディタスクリプト
 *
 * node/embed ブロック: 投稿画面で X(Twitter) / YouTube / Google マップ / Steam の URL を
 * 貼り付けると自動でこのブロックへ変換し、エディタ内に埋め込みプレビューを表示する。
 *
 * Gutenberg のペーストハンドラは raw transform より先に URL を検出して
 * core/embed ブロックを生成するため、raw transform では横取りできない。
 * 代わりに wp.data.subscribe でエディタストアを監視し、対象 URL の
 * core/embed ブロックが挿入された瞬間に node/embed へ自動置換する。
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.components || ! wp.data ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var Fragment = wp.element.Fragment;
	var registerBlockType = wp.blocks.registerBlockType;
	var createBlock = wp.blocks.createBlock;
	var TextControl = wp.components.TextControl;
	var Button = wp.components.Button;
	var Placeholder = wp.components.Placeholder;
	var Spinner = wp.components.Spinner;
	var subscribe = wp.data.subscribe;
	var select = wp.data.select;
	var dispatch = wp.data.dispatch;
	var apiFetch = wp.apiFetch;
	var __ = ( wp.i18n && wp.i18n.__ ) ? wp.i18n.__ : function ( s ) { return s; };

	// ------------------------------------------------------------------
	// URL 判定（inc/blogcard.php の node_special_embed 系ロジックのミラー）
	// ------------------------------------------------------------------

	function parseUrl( url ) {
		try {
			return new URL( url );
		} catch ( e ) {
			return null;
		}
	}

	function normalizedHost( url ) {
		var u = parseUrl( url );
		return u ? u.hostname.replace( /^www\./, '' ).toLowerCase() : '';
	}

	function getTweetId( url ) {
		var host = normalizedHost( url );
		if ( [ 'twitter.com', 'x.com', 'mobile.twitter.com' ].indexOf( host ) === -1 ) {
			return '';
		}
		var u = parseUrl( url );
		if ( ! u ) {
			return '';
		}
		var m = u.pathname.match( /\/status(?:es)?\/(\d+)/ );
		return m ? m[ 1 ] : '';
	}

	function getYouTubeId( url ) {
		var host = normalizedHost( url );
		var u = parseUrl( url );
		if ( ! u ) {
			return '';
		}
		if ( 'youtu.be' === host ) {
			var m = u.pathname.match( /^\/([\w-]{6,})/ );
			return m ? m[ 1 ] : '';
		}
		if ( 'youtube.com' === host || 'm.youtube.com' === host ) {
			var m2 = u.pathname.match( /^\/(?:shorts|embed|v)\/([\w-]{6,})/ );
			if ( m2 ) {
				return m2[ 1 ];
			}
			var v = u.searchParams.get( 'v' );
			if ( v && /^[\w-]{6,}$/.test( v ) ) {
				return v;
			}
		}
		return '';
	}

	function isMapsUrl( url ) {
		var host = normalizedHost( url );
		var u = parseUrl( url );
		if ( ! u ) {
			return false;
		}
		if ( 'maps.app.goo.gl' === host || 'goo.gl' === host ) {
			return true;
		}
		if ( host.indexOf( 'maps.google.' ) === 0 ) {
			return true;
		}
		return /(^|\.)google\.com$/.test( host ) && u.pathname.indexOf( '/maps' ) !== -1;
	}

	function getSteamAppId( url ) {
		var parsed = parseUrl( url );
		if ( ! parsed || ! /^https?:$/.test( parsed.protocol ) || 'store.steampowered.com' !== parsed.hostname.toLowerCase() ) {
			return '';
		}
		var match = parsed.pathname.match( /^\/(?:app|widget)\/([1-9][0-9]{0,9})(?:\/|$)/ );
		return match ? match[ 1 ] : '';
	}

	function detectProvider( url ) {
		if ( ! /^https?:\/\//i.test( url ) ) {
			return '';
		}
		if ( getSteamAppId( url ) ) {
			return 'steam';
		}
		if ( getTweetId( url ) ) {
			return 'x';
		}
		if ( getYouTubeId( url ) ) {
			return 'youtube';
		}
		if ( isMapsUrl( url ) ) {
			return 'map';
		}
		return '';
	}

	function plainText( content ) {
		var div = document.createElement( 'div' );
		div.innerHTML = String( content || '' );
		return ( div.textContent || '' ).trim();
	}

	// ------------------------------------------------------------------
	// core/embed → node/embed 自動変換
	//
	// Gutenberg のペーストハンドラは URL を検出すると raw transform を
	// 経由せず直接 core/embed ブロックを生成する。そのため subscribe で
	// エディタストアを監視し、対象 URL の core/embed が挿入された瞬間に
	// node/embed へ置換する。
	// ------------------------------------------------------------------

	var replacedIds = {};

	subscribe( function () {
		var blocks = select( 'core/block-editor' ).getBlocks();

		for ( var i = 0; i < blocks.length; i++ ) {
			var block = blocks[ i ];

			if ( 'core/embed' !== block.name || replacedIds[ block.clientId ] ) {
				continue;
			}

			var url = String( block.attributes.url || '' );

			// Store URLs pasted into the post keep their existing blog-card behavior.
			var provider = detectProvider( url );
			if ( '' === url || '' === provider || 'steam' === provider ) {
				continue;
			}

			replacedIds[ block.clientId ] = true;
			dispatch( 'core/block-editor' ).replaceBlock(
				block.clientId,
				createBlock( 'node/embed', { url: url } )
			);
			return;
		}
	} );

	// ------------------------------------------------------------------
	// プレビュー
	// ------------------------------------------------------------------

	var wrapStyle = { position: 'relative' };
	var overlayStyle = { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0 };
	var frameBaseStyle = { display: 'block', width: '100%', border: 0, borderRadius: '8px' };

	var xPlaceholderStyle = {
		display: 'flex',
		flexDirection: 'column',
		alignItems: 'center',
		justifyContent: 'center',
		gap: '10px',
		maxWidth: '550px',
		margin: '0 auto',
		padding: '24px 16px',
		background: '#f7f9f9',
		border: '1px solid #cfd9de',
		borderRadius: '12px',
		color: '#0f1419',
		fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif',
	};

	var xLogoStyle = {
		width: '28px',
		height: '28px',
		borderRadius: '50%',
		background: '#000',
		display: 'flex',
		alignItems: 'center',
		justifyContent: 'center',
		color: '#fff',
		fontSize: '16px',
		fontWeight: 'bold',
	};

	function XPreview( props ) {
		return el(
			'div', { style: xPlaceholderStyle },
			el( 'span', { style: xLogoStyle, 'aria-hidden': 'true' }, 'X' ),
			el( 'strong', { style: { fontSize: '15px' } }, 'X (Twitter)' ),
			el( 'div', {
				style: { fontSize: '13px', color: '#536471', textAlign: 'center', wordBreak: 'break-all' },
			}, props.url ),
			el( 'div', {
				style: { fontSize: '11px', color: '#536471' },
			}, __( '公開ページでポストが表示されます', 'luminous-blocks' ) )
		);
	}

	var mapPlaceholderStyle = {
		position: 'relative',
		width: '100%',
		aspectRatio: '16 / 9',
		overflow: 'hidden',
		borderRadius: '8px',
		border: '1px solid #dcdcde',
		background: '#e8eaed',
		display: 'flex',
		flexDirection: 'column',
		alignItems: 'center',
		justifyContent: 'center',
		gap: '12px',
		color: '#1e1e1e',
	};

	var mapIconStyle = {
		width: '48px',
		height: '48px',
		borderRadius: '50%',
		background: '#4285f4',
		display: 'flex',
		alignItems: 'center',
		justifyContent: 'center',
		color: '#fff',
		fontSize: '24px',
	};

	/**
	 * Google マップ — エディタ内は iframe と同サイズ (16:9) のプレースホルダーを表示する。
	 * Google Maps の embed iframe は wp-admin のコンテキスト（iframe 入れ子・CSP）で
	 * 読み込みが拒否されるため、エディタでは静的表示にしフロントのみ iframe を出力する。
	 */
	function MapPreview( props ) {
		return el(
			'div',
			{ style: mapPlaceholderStyle },
			el( 'span', { style: mapIconStyle, 'aria-hidden': 'true' }, '📍' ),
			el( 'strong', { style: { fontSize: '15px' } }, 'Google Maps' ),
			el( 'div', {
				style: {
					fontSize: '12px',
					color: '#5f6368',
					maxWidth: '80%',
					textAlign: 'center',
					wordBreak: 'break-all',
					lineHeight: '1.4',
				},
			}, props.url ),
			el( 'div', {
				style: { fontSize: '11px', color: '#9aa0a6' },
			}, __( '公開ページでマップが表示されます', 'luminous-blocks' ) )
		);
	}

	function EmbedPreview( props ) {
		var url = props.url;
		var provider = detectProvider( url );

		if ( 'x' === provider ) {
			return el( XPreview, { url: url } );
		}

		if ( 'youtube' === provider ) {
			return el( 'iframe', {
				src: 'https://www.youtube.com/embed/' + encodeURIComponent( getYouTubeId( url ) ),
				title: 'YouTube video player',
				allow: 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share',
				allowFullScreen: true,
				style: Object.assign( { aspectRatio: '16 / 9' }, frameBaseStyle ),
			} );
		}

		if ( 'map' === provider ) {
			return el( MapPreview, { url: url } );
		}

		if ( 'steam' === provider ) {
			return el( 'iframe', {
				src: 'https://store.steampowered.com/widget/' + getSteamAppId( url ) + '/',
				title: 'Steam Store Widget',
				loading: 'lazy',
				width: 646,
				height: 190,
				style: Object.assign( { maxWidth: '646px' }, frameBaseStyle ),
			} );
		}

		return el( 'p', null, url );
	}

	// ------------------------------------------------------------------
	// ブロック登録
	// PHP 側の register_block_type が render_callback 付きで先に登録されるため、
	// JS 側のカスタム edit を確実に使うよう一度 unregister してから再登録する。
	// ------------------------------------------------------------------

	if ( wp.blocks.getBlockType( 'node/embed' ) ) {
		wp.blocks.unregisterBlockType( 'node/embed' );
	}

	registerBlockType( 'node/embed', {
		title: __( 'Luna埋め込み', 'luminous-blocks' ),
		description: __( 'X(Twitter)・YouTube・Google マップ・Steam の URL を埋め込み表示します。', 'luminous-blocks' ),
		icon: 'embed-generic',
		category: 'node',
		keywords: [ 'x', 'twitter', 'youtube', __( 'マップ', 'luminous-blocks' ), 'maps', 'steam', 'embed', __( '埋め込み', 'luminous-blocks' ) ],
		attributes: {
			url: { type: 'string', default: '' },
		},
		supports: {
			html: false,
		},

		transforms: {
			from: [
				{
					type: 'block',
					blocks: [ 'core/embed' ],
					isMatch: function ( attrs ) {
						return '' !== detectProvider( String( attrs.url || '' ) );
					},
					transform: function ( attrs ) {
						return createBlock( 'node/embed', { url: String( attrs.url || '' ) } );
					},
				},
				{
					type: 'block',
					blocks: [ 'core/paragraph' ],
					isMatch: function ( attrs ) {
						return '' !== detectProvider( plainText( attrs.content ) );
					},
					transform: function ( attrs ) {
						return createBlock( 'node/embed', { url: plainText( attrs.content ) } );
					},
				},
			],
			to: [
				{
					type: 'block',
					blocks: [ 'core/paragraph' ],
					transform: function ( attrs ) {
						return createBlock( 'core/paragraph', { content: attrs.url } );
					},
				},
			],
		},

		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var isSelected = props.isSelected;

			var draftState = useState( attributes.url || '' );
			var draft = draftState[ 0 ];
			var setDraft = draftState[ 1 ];
			var errorState = useState( false );
			var hasError = errorState[ 0 ];
			var setError = errorState[ 1 ];

			useEffect( function () {
				if ( attributes.url ) {
					setDraft( attributes.url );
				}
			}, [ attributes.url ] );

			function applyUrl() {
				var trimmed = draft.trim();
				if ( '' === detectProvider( trimmed ) ) {
					setError( true );
					return;
				}
				setError( false );
				setAttributes( { url: trimmed } );
			}

			if ( ! attributes.url ) {
				return el(
					Placeholder,
					{
						icon: 'embed-generic',
						label: __( 'Luna埋め込み', 'luminous-blocks' ),
						instructions: __( 'X(Twitter)・YouTube・Google マップ・Steam の URL を入力してください。', 'luminous-blocks' ),
					},
					el(
						'form',
						{
							style: { display: 'flex', gap: '8px', width: '100%', alignItems: 'flex-start' },
							onSubmit: function ( event ) {
								event.preventDefault();
								applyUrl();
							},
						},
						el( TextControl, {
							value: draft,
							onChange: function ( value ) {
								setDraft( value );
								setError( false );
							},
							placeholder: 'https://…',
							style: { minWidth: '280px' },
							__nextHasNoMarginBottom: true,
						} ),
						el( Button, { variant: 'primary', type: 'submit' }, __( '埋め込む', 'luminous-blocks' ) )
					),
					hasError
						? el(
							'p',
							{ style: { color: '#cc1818', width: '100%', margin: '8px 0 0' } },
							__( 'この URL は X / YouTube / Google マップ / Steam の埋め込みに対応していません。', 'luminous-blocks' )
						)
						: null
				);
			}

			return el(
				Fragment,
				null,
				el(
					'div',
					{ style: wrapStyle },
					el( EmbedPreview, { url: attributes.url } ),
					! isSelected ? el( 'div', { style: overlayStyle } ) : null
				),
				isSelected
					? el(
						'div',
						{ style: { display: 'flex', gap: '8px', marginTop: '8px', alignItems: 'flex-start' } },
						el( TextControl, {
							value: draft,
							onChange: function ( value ) {
								setDraft( value );
								setError( false );
							},
							style: { minWidth: '280px' },
							__nextHasNoMarginBottom: true,
						} ),
						el( Button, { variant: 'secondary', onClick: applyUrl }, __( '更新', 'luminous-blocks' ) ),
						hasError
							? el( 'p', { style: { color: '#cc1818', margin: '6px 0 0' } }, __( '未対応の URL です。', 'luminous-blocks' ) )
							: null
					)
					: null
			);
		},

		save: function () {
			return null;
		},
	} );

	// ------------------------------------------------------------------
	// node/notice — お知らせ / 注意 / 重要 / 補足 のコールアウトブロック。
	// 静的 save（保存時に blockquote ベースの HTML を確定）。フロントの配色は
	// テーマの src/styles/_notice.css（style.css にバンドル）が担当し、エディタ
	// 内はここでのインラインスタイルで近似プレビューする。
	// ------------------------------------------------------------------

	var blockEditor = wp.blockEditor || wp.editor;

	if ( ! ( blockEditor && blockEditor.InnerBlocks ) ) {
		// wp-block-editor が未ロードだと node/notice を登録できない。
		// （enqueue の依存に 'wp-block-editor' が無い古い状態＝opcache 等）
		if ( window.console && console.warn ) {
			console.warn( 'node/notice: wp.blockEditor が利用できないためブロックを登録できませんでした。' );
		}
	} else {
		var InnerBlocks = blockEditor.InnerBlocks;
		var InspectorControls = blockEditor.InspectorControls;
		var useBlockProps = blockEditor.useBlockProps;
		var PlainText = blockEditor.PlainText;
		var PanelBody = wp.components.PanelBody;
		var SelectControl = wp.components.SelectControl;

		// タイトル（最上部）は必ず Inter＋Noto Sans JP の組み合わせ（テーマの
		// --font-heading）＋太字。エディタ canvas に theme 変数が無い場合に
		// 備えてフォールバックを持たせる。
		var NOTICE_TITLE_FONT = 'var(--font-heading, "Inter", "Noto Sans JP", sans-serif)';

		// タイプ別の既定ラベル＋エディタプレビュー配色（テーマの _notice.css の
		// ライトトークンと一致させる）。エディタプレビューのアイコンはインライン
		// SVG（noticeIcon）で描画（canvas に Material Symbols フォントが無いため）。
		// 保存 HTML（save）は Material Symbols の <span> を出力する。
		var NOTICE_TYPES = {
			info:    { label: 'お知らせ', icon: 'campaign', accent: '#0B57D0', title: '#0842A0', bg: '#E8F0FE', border: '#A8C7FA' },
			warning: { label: '注意',     icon: 'warning', accent: '#A15C00', title: '#7A4500', bg: '#FFF3DB', border: '#F0D08A' },
			alert:   { label: '重要',     icon: 'priority_high', accent: '#B3520E', title: '#8C3E08', bg: '#FEF0E6', border: '#F0B88A' },
			memo:    { label: '補足',     icon: 'edit_note', accent: '#5F6368', title: '#3C4043', bg: '#F1F3F4', border: '#DADCE0' },
		};
		var NOTICE_LEGACY_ICONS = {
			info: 'info',
			warning: 'warning',
			alert: 'report',
			memo: 'edit_note',
		};
		var NOTICE_ATTRIBUTES = {
			type:  { type: 'string', default: 'info' },
			title: { type: 'string', default: '' },
			shape: { type: 'string', default: 'rounded' },
		};

		function noticeConfig( type ) {
			return NOTICE_TYPES[ type ] || NOTICE_TYPES.info;
		}

		// エディタプレビュー用：バリアントごとに形状の異なるインライン SVG アイコン。
		// save では使わない（save は Material Symbols の iconMap を出力）。
		function noticeIconChildren( type ) {
			if ( 'warning' === type ) {
				return [
					el( 'path', { key: 's', d: 'M12 3.5 L21.5 20 L2.5 20 Z' } ),
					el( 'line', { key: 'l', x1: 12, y1: 9, x2: 12, y2: 14 } ),
					el( 'line', { key: 'd', x1: 12, y1: 17, x2: 12, y2: 17 } ),
				];
			}
			if ( 'alert' === type ) {
				// 八角形＋!（重要）
				return [
					el( 'path', { key: 'o', d: 'M8 2.75 H16 L21.25 8 V16 L16 21.25 H8 L2.75 16 V8 Z' } ),
					el( 'line', { key: 'l', x1: 12, y1: 8, x2: 12, y2: 13 } ),
					el( 'line', { key: 'd', x1: 12, y1: 16, x2: 12, y2: 16 } ),
				];
			}
			if ( 'memo' === type ) {
				// 鉛筆（補足）
				return [
					el( 'path', { key: 'p', d: 'M12 20h9' } ),
					el( 'path', { key: 'e', d: 'M16.5 3.5a2.121 2.121 0 1 1 3 3L7 19l-4 1 1-4 12.5-12.5z' } ),
				];
			}
			// info
			return [
				el( 'circle', { key: 'c', cx: 12, cy: 12, r: 9 } ),
				el( 'line', { key: 'l', x1: 12, y1: 11, x2: 12, y2: 16 } ),
				el( 'line', { key: 'd', x1: 12, y1: 8, x2: 12, y2: 8 } ),
			];
		}

		function noticeIcon( type, extraProps ) {
			var props = {
				className: 'm3-notice__icon',
				xmlns: 'http://www.w3.org/2000/svg',
				viewBox: '0 0 24 24',
				width: 22,
				height: 22,
				fill: 'none',
				stroke: 'currentColor',
				strokeWidth: 2,
				strokeLinecap: 'round',
				strokeLinejoin: 'round',
				'aria-hidden': 'true',
				focusable: 'false',
			};
			if ( extraProps ) {
				Object.assign( props, extraProps );
			}
			return el( 'svg', props, noticeIconChildren( type ) );
		}

		// role="note" を基本とし、重要度の高い alert は aria-live="polite" を併用
		// （割り込みの強い role="alert" は避ける）。
		function noticeA11y( type ) {
			var a11y = { role: 'note' };
			if ( 'alert' === type ) {
				a11y[ 'aria-live' ] = 'polite';
			}
			return a11y;
		}

		function noticeSaveMarkup( props, iconMap ) {
			var attributes = props.attributes;
			var type = attributes.type || 'info';
			var shape = attributes.shape || 'rounded';
			var cfg = noticeConfig( type );
			var title = ( attributes.title && attributes.title.trim() ) ? attributes.title : cfg.label;
			var icon = iconMap[ type ] || cfg.icon;

			var blockProps = useBlockProps.save( Object.assign( {
				className: 'm3-notice m3-notice--' + type + ( 'square' === shape ? ' m3-notice--square' : '' ),
			}, noticeA11y( type ) ) );

			return el(
				'blockquote',
				blockProps,
				el(
					'div',
					{ className: 'm3-notice__header' },
					el( 'span', { className: 'material-symbols-outlined m3-notice__icon', 'aria-hidden': 'true' }, icon ),
					el( 'span', { className: 'm3-notice__title' }, title )
				),
				el(
					'div',
					{ className: 'm3-notice__body' },
					el( InnerBlocks.Content )
				)
			);
		}

		var NOTICE_ALLOWED = [ 'core/paragraph', 'core/list', 'core/heading', 'core/image', 'core/quote', 'core/table', 'core/buttons' ];
		var NOTICE_TEMPLATE = [ [ 'core/paragraph', {} ] ];

		// PHP 側の register_block_type がサーバー登録するため、
		// クライアント側の registerBlockType が「already registered」で
		// 失敗しないよう一度 unregister してから edit/save 付きで再登録する。
		if ( wp.blocks.getBlockType( 'node/notice' ) ) {
			wp.blocks.unregisterBlockType( 'node/notice' );
		}

		registerBlockType( 'node/notice', {
			title: __( 'Node お知らせ', 'luminous-blocks' ),
			description: __( 'お知らせ・注意・重要・補足のコールアウトを本文中に表示します。', 'luminous-blocks' ),
			icon: 'info-outline',
			category: 'node',
			keywords: [ __( 'お知らせ', 'luminous-blocks' ), __( '注意', 'luminous-blocks' ), __( '重要', 'luminous-blocks' ), __( '補足', 'luminous-blocks' ), 'notice', 'callout', 'admonition' ],
			attributes: NOTICE_ATTRIBUTES,
			supports: {
				html: false,
				anchor: true,
			},

			edit: function ( props ) {
				var attributes = props.attributes;
				var setAttributes = props.setAttributes;
				var type = attributes.type || 'info';
				var shape = attributes.shape || 'rounded';
				var cfg = noticeConfig( type );

				// お知らせ（info）タイプは1記事1個制限。他タイプは制限なし。
				var select = wp.data && wp.data.select;
				var infoLimitHit = false;
				if ( 'info' === type && select ) {
					var allBlocks = select( 'core/block-editor' ).getBlocks();
					var infoCount = 0;
					for ( var bi = 0; bi < allBlocks.length; bi++ ) {
						if ( 'node/notice' === allBlocks[ bi ].name && 'info' === ( allBlocks[ bi ].attributes.type || 'info' ) && allBlocks[ bi ].clientId !== props.clientId ) {
							infoCount++;
						}
					}
					infoLimitHit = infoCount > 0;
				}

				// エディタ canvas はテーマの _notice.css を読み込まないため、
				// フロントのトークンに一致するインラインスタイルで近似プレビューする。
				var blockProps = useBlockProps( Object.assign( {
					className: 'm3-notice m3-notice--' + type + ( 'square' === shape ? ' m3-notice--square' : '' ),
					style: {
						margin: '1.5rem 0',
						padding: '16px 20px',
						background: cfg.bg,
						border: '1px solid ' + cfg.border,
						borderRadius: 'square' === shape ? '0' : '16px',
						color: 'var(--md-sys-color-on-surface, #2b1700)',
						fontStyle: 'normal',
					},
				}, noticeA11y( type ) ) );

				return el(
					Fragment,
					null,
					el(
						InspectorControls,
						null,
						el(
							PanelBody,
							{ title: __( '種別', 'luminous-blocks' ), initialOpen: true },
							el( SelectControl, {
								label: __( 'タイプ', 'luminous-blocks' ),
								value: type,
								options: [
									{ label: __( 'お知らせ（青）', 'luminous-blocks' ), value: 'info' },
									{ label: __( '注意（黄）', 'luminous-blocks' ), value: 'warning' },
									{ label: __( '重要（橙）', 'luminous-blocks' ), value: 'alert' },
									{ label: __( '補足（灰）', 'luminous-blocks' ), value: 'memo' },
								],
								onChange: function ( value ) {
									setAttributes( { type: value } );
								},
								__nextHasNoMarginBottom: true,
							} ),
							el( SelectControl, {
								label: __( '形', 'luminous-blocks' ),
								value: shape,
								options: [
									{ label: __( '角丸', 'luminous-blocks' ), value: 'rounded' },
									{ label: __( '四角', 'luminous-blocks' ), value: 'square' },
								],
								onChange: function ( value ) {
									setAttributes( { shape: value } );
								},
								__nextHasNoMarginBottom: true,
							} )
						)
					),
					infoLimitHit ? el(
						'div',
						{ style: { padding: '8px 12px', marginBottom: '8px', background: '#fff3cd', border: '1px solid #ffc107', borderRadius: '8px', fontSize: '13px', color: '#664d03' } },
						'⚠ お知らせ（info）タイプは1記事につき1つまでです。タイプを変更してください。'
					) : null,
					el(
						'blockquote',
						blockProps,
						el(
							'div',
							{
								className: 'm3-notice__header',
								// アイコンとタイトルの行ボックスを一致させて高さを揃える
								style: { display: 'flex', alignItems: 'flex-start', gap: '8px', marginBottom: '8px', fontSize: '1.05rem', lineHeight: 1.4 },
							},
							noticeIcon( type, { style: { color: cfg.accent, width: '1.4em', height: '1.4em' } } ),
							el( PlainText, {
								className: 'm3-notice__title',
								value: attributes.title || '',
								onChange: function ( value ) {
									setAttributes( { title: value } );
								},
								placeholder: cfg.label,
								style: { color: cfg.title, fontFamily: NOTICE_TITLE_FONT, fontWeight: 700, background: 'transparent', lineHeight: 1.4 },
							} )
						),
						el(
							'div',
							{ className: 'm3-notice__body' },
							el( InnerBlocks, {
								allowedBlocks: NOTICE_ALLOWED,
								template: NOTICE_TEMPLATE,
								templateLock: false,
							} )
						)
					)
				);
			},

			save: function ( props ) {
				return noticeSaveMarkup( props, NOTICE_TYPES );
			},

			// 旧保存HTML（info/reportアイコン）を受理し、次回保存時に新アイコンへ移行する。
			deprecated: [
				{
					attributes: NOTICE_ATTRIBUTES,
					save: function ( props ) {
						return noticeSaveMarkup( props, NOTICE_LEGACY_ICONS );
					},
				},
			],
		} );
	}
} )( window.wp );
