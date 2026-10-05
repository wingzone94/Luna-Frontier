/**
 * 記事チェックUI（ブロックエディタ右ペイン）
 *
 * ファクトチェックと校正・誤字脱字チェックを1つのパネルに統合し、
 * 指摘も1本のリストにまとめて表示する。
 * どの観点の指摘かは Dashicons のアイコンと色で区別する
 * （Dashicons は wp-admin で常に読み込まれるため追加依存はない）。
 *
 * クラシックメタボックスは context='side' を指定してもブロックエディタでは
 * 下部の「メタボックス」領域に落ちるため、右ペインへ出すには
 * PluginDocumentSettingPanel を使う必要がある。
 *
 * @package Node_AI_Tools
 */
( function ( wp ) {
	if ( ! wp || ! wp.plugins || ! wp.editPost || ! wp.element || ! wp.data ) {
		return;
	}

	if ( wp.plugins.getPlugin && wp.plugins.getPlugin( 'node-ai-article-check' ) ) {
		return;
	}

	var factSettings = window.nodeAiFactCheck || {};
	var proofSettings = window.nodeAiProofread || {};

	var registerPlugin = wp.plugins.registerPlugin;
	var PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
	var PluginPrePublishPanel = wp.editPost.PluginPrePublishPanel;
	var Button = wp.components.Button;
	var CheckboxControl = wp.components.CheckboxControl;
	var SelectControl = wp.components.SelectControl;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var createElement = wp.element.createElement;
	var useState = wp.element.useState;

	var APPROVED_META = '_node_ai_fact_check_approved';

	// ファクトチェックの判定
	var FACT_KINDS = {
		correct: { icon: 'dashicons-yes-alt', color: '#007017', label: '事実確認' },
		likely_correct: { icon: 'dashicons-yes', color: '#00a32a', label: '事実確認' },
		uncertain: { icon: 'dashicons-warning', color: '#dba617', label: '事実確認' },
		likely_incorrect: { icon: 'dashicons-dismiss', color: '#d63638', label: '事実確認' },
		unverifiable: { icon: 'dashicons-editor-help', color: '#787c82', label: '事実確認' },
	};

	// 主張の種別（fact 以外はラベルを変えて、事実判定の対象かどうかを分かるようにする）
	var CLAIM_TYPE_LABELS = {
		opinion: '筆者の感想',
		premise: '前提の確認',
	};

	// 校正の指摘種別
	var PROOF_KINDS = {
		typo: { icon: 'dashicons-editor-spellcheck', color: '#d63638', label: '誤字・脱字' },
		grammar: { icon: 'dashicons-editor-paragraph', color: '#8c5e00', label: '文法' },
		usage: { icon: 'dashicons-translation', color: '#007017', label: '言葉遣い' },
		style: { icon: 'dashicons-editor-textcolor', color: '#2271b1', label: '表記ゆれ' },
		readability: { icon: 'dashicons-visibility', color: '#3858e9', label: '読みやすさ' },
		meaning: { icon: 'dashicons-lightbulb', color: '#9b26b6', label: '解釈違い' },
	};

	var SEVERITY_LABELS = { high: '重要', medium: '中', low: '軽微' };

	// 重要度の高い指摘を上に出すための重み
	var FACT_WEIGHT = { likely_incorrect: 0, uncertain: 1, unverifiable: 3, likely_correct: 4, correct: 5 };
	var PROOF_WEIGHT = { high: 0, medium: 2, low: 3 };

	function el( tag, props ) {
		var children = Array.prototype.slice.call( arguments, 2 );
		return createElement.apply( null, [ tag, props ].concat( children ) );
	}

	function icon( dashicon, color, size ) {
		var px = ( size || 16 ) + 'px';
		return el( 'span', {
			className: 'dashicons ' + dashicon,
			'aria-hidden': true,
			style: {
				color: color,
				fontSize: px,
				width: px,
				height: px,
				lineHeight: px,
				flex: '0 0 auto',
			},
		} );
	}

	/**
	 * ファクトチェックと校正の結果を1本の指摘リストへまとめる。
	 *
	 * @param {Object} fact  ファクトチェック結果。
	 * @param {Object} proof 校正結果。
	 * @param {Object} statusLabels 判定ラベル（環境で文言が変わる）。
	 * @return {Array} 統合済みの指摘配列。
	 */
	function mergeIssues( fact, proof, statusLabels ) {
		var merged = [];

		( ( fact && fact.claims ) || [] ).forEach( function ( claim ) {
			var status = claim.status || 'uncertain';
			var kind = FACT_KINDS[ status ] || FACT_KINDS.uncertain;

			merged.push( {
				source: 'fact',
				kind: kind,
				// 筆者の感想は事実判定の対象外。カード上でも事実確認と区別する
				kindLabel: CLAIM_TYPE_LABELS[ claim.claim_type ] || kind.label,
				verdict: statusLabels[ status ] || status,
				body: claim.claim || '',
				suggestion: '',
				note: claim.note || '',
				meta: claim.confidence ? '確信度: ' + claim.confidence : '',
				weight: FACT_WEIGHT[ status ] !== undefined ? FACT_WEIGHT[ status ] : 2,
			} );
		} );

		( ( proof && proof.issues ) || [] ).forEach( function ( issue ) {
			var kind = PROOF_KINDS[ issue.type ] || PROOF_KINDS.typo;
			var severity = issue.severity || 'low';

			merged.push( {
				source: 'proof',
				kind: kind,
				kindLabel: kind.label,
				verdict: SEVERITY_LABELS[ severity ] || severity,
				body: issue.original || '',
				suggestion: issue.suggestion || '',
				note: issue.reason || '',
				meta: '',
				weight: PROOF_WEIGHT[ severity ] !== undefined ? PROOF_WEIGHT[ severity ] : 3,
			} );
		} );

		return merged.sort( function ( a, b ) {
			return a.weight - b.weight;
		} );
	}

	function renderIssue( issue, index ) {
		return el(
			'li',
			{
				key: index,
				style: {
					margin: '0 0 8px',
					padding: '8px 10px',
					border: '1px solid #dcdcde',
					borderLeft: '4px solid ' + issue.kind.color,
					borderRadius: '6px',
					background: '#fff',
				},
			},
			el(
				'div',
				{
					style: {
						display: 'flex',
						alignItems: 'center',
						gap: '6px',
						marginBottom: '4px',
					},
				},
				icon( issue.kind.icon, issue.kind.color, 18 ),
				el(
					'span',
					{ style: { fontSize: '12px', fontWeight: 700, color: issue.kind.color } },
					issue.kindLabel
				),
				el(
					'span',
					{ style: { fontSize: '11px', color: '#646970', marginLeft: 'auto' } },
					issue.verdict
				)
			),
			el(
				'p',
				{
					style: {
						margin: '0 0 4px',
						fontSize: '13px',
						fontWeight: 'proof' === issue.source ? 400 : 600,
						textDecoration: 'proof' === issue.source && issue.suggestion ? 'line-through' : 'none',
						color: 'proof' === issue.source ? '#50575e' : '#1d2327',
					},
				},
				issue.body
			),
			issue.suggestion
				? el(
						'p',
						{ style: { margin: '0 0 4px', fontSize: '13px', fontWeight: 600 } },
						'→ ' + issue.suggestion
				  )
				: null,
			issue.meta
				? el(
						'p',
						{ style: { margin: '0 0 2px', fontSize: '11px', color: '#646970' } },
						issue.meta
				  )
				: null,
			issue.note
				? el(
						'p',
						{ style: { margin: 0, fontSize: '12px', color: '#646970' } },
						issue.note
				  )
				: null,
		);
	}

	/**
	 * 公開までの流れを 3 ステップで示す。
	 * ファクトチェックは必須ではなく任意なので、その位置づけが一目で分かるようにする。
	 *
	 * @param {Object} state 各ステップの状態。
	 */
	function renderFlow( state ) {
		var steps = [
			{
				label: '下書き保存',
				done: state.saved,
				note: state.saved ? '保存済み' : '未保存',
				optional: false,
			},
			{
				label: 'FC',
				title: 'ファクトチェック（任意）',
				done: state.checked,
				warn: state.unresolved > 0,
				note: ! state.checked
					? '未実施'
					: state.unresolved
					? '未対応 ' + state.unresolved + ' 件'
					: '指摘なし',
				optional: true,
			},
			{
				label: state.scheduled ? '予約投稿' : '公開',
				done: state.published,
				note: state.published ? ( state.scheduled ? '予約済み' : '公開済み' ) : '未公開',
				optional: false,
			},
		];

		return el(
			'div',
			{
				style: {
					display: 'flex',
					alignItems: 'stretch',
					gap: '4px',
					margin: '0 0 12px',
				},
			},
			steps.map( function ( step, index ) {
				var color = '#787c82';
				var icon = 'dashicons-marker';

				if ( step.warn ) {
					color = '#d63638';
					icon = 'dashicons-flag';
				} else if ( step.done ) {
					color = '#00a32a';
					icon = 'dashicons-yes-alt';
				} else if ( step.optional ) {
					color = '#dba617';
					icon = 'dashicons-marker';
				}

				return el(
					wp.element.Fragment,
					{ key: step.label },
					el(
						'div',
						{
							title: step.title || step.label,
							style: {
								flex: '1 1 0',
								minWidth: 0,
								textAlign: 'center',
								padding: '6px 2px',
								border: '1px solid ' + ( step.done || step.warn ? color : '#dcdcde' ),
								borderRadius: '6px',
								background: step.done || step.warn ? color + '12' : '#fff',
							},
						},
						el( 'span', {
							className: 'dashicons ' + icon,
							'aria-hidden': true,
							style: {
								color: color,
								fontSize: '16px',
								width: '16px',
								height: '16px',
								lineHeight: '16px',
								display: 'block',
								margin: '0 auto 2px',
							},
						} ),
						el(
							'span',
							{
								style: {
									display: 'block',
									fontSize: '11px',
									fontWeight: 700,
									whiteSpace: 'nowrap',
								},
							},
							step.label
						),
						step.optional
							? el(
									'span',
									{
										style: {
											display: 'block',
											fontSize: '10px',
											color: '#646970',
											whiteSpace: 'nowrap',
										},
									},
									'任意'
							  )
							: null,
						el(
							'span',
							{
								style: {
									display: 'block',
									fontSize: '10px',
									color: color,
									whiteSpace: 'nowrap',
									overflow: 'hidden',
									textOverflow: 'ellipsis',
								},
							},
							step.note
						)
					),
					index < steps.length - 1
						? el(
								'span',
								{
									'aria-hidden': true,
									style: {
										alignSelf: 'center',
										color: '#c3c4c7',
										fontSize: '12px',
									},
								},
								'▶'
						  )
						: null
				);
			} )
		);
	}

	/**
	 * 実行条件（使用モデル・Google 検索の有無・自動参照した公式サイト等）を表示する。
	 *
	 * 検索なしで実行された結果を、検索ありの結果と同じ重みで読まれないようにするための表示。
	 *
	 * @param {Object} fact ファクトチェック結果。
	 * @return {Object|null} 要素。
	 */
	function renderRunContext( fact ) {
		if ( ! fact ) {
			return null;
		}

		var notices = fact.notices || [];
		var parts = [];

		if ( fact.model ) {
			parts.push( 'モデル: ' + fact.model );
		}
		parts.push( fact.grounded ? 'Google 検索: 参照あり' : 'Google 検索: なし' );

		return el(
			'div',
			{ style: { margin: '0 0 6px' } },
			el(
				'p',
				{ style: { margin: 0, fontSize: '11px', color: '#646970' } },
				parts.join( ' / ' )
			),
			notices.length
				? el(
						'ul',
						{ style: { margin: '2px 0 0', paddingLeft: '1.1rem', fontSize: '11px', color: '#646970' } },
						notices.map( function ( notice, index ) {
							return el( 'li', { key: index }, notice );
						} )
				  )
				: null
		);
	}

	function renderSources( sources ) {
		if ( ! sources || ! sources.length ) {
			return null;
		}

		return el(
			'div',
			{ style: { marginTop: '10px' } },
			el(
				'p',
				{ style: { margin: '0 0 4px', fontWeight: 600, fontSize: '12px' } },
				'参照元'
			),
			el(
				'ul',
				{ style: { margin: 0, paddingLeft: '1.1rem', fontSize: '12px' } },
				sources.map( function ( source, index ) {
					return el(
						'li',
						{ key: index, style: { marginBottom: '2px' } },
						el(
							'a',
							{ href: source.url, target: '_blank', rel: 'noopener noreferrer' },
							source.title || source.url
						),
						// 一次情報かどうかは判定の重みが変わるため明示する
						source.official
							? el(
									'span',
									{ style: { marginLeft: '4px', color: '#007017', fontWeight: 700 } },
									'（公式）'
							  )
							: null
					);
				} )
			)
		);
	}

	/**
	 * 統合結果を Markdown 化する（他のAIプラットフォームへ渡せる形）。
	 */
	function buildMarkdown( fact, proof, statusLabels, riskLabels, meta ) {
		var cell = window.nodeAiExport.cell;
		var lines = [];

		lines.push( '# 記事チェック結果（ファクトチェック / 校正）' );
		lines.push( '' );

		if ( meta.title ) {
			lines.push( '- 記事: ' + meta.title );
		}
		if ( meta.url ) {
			lines.push( '- URL: ' + meta.url );
		}
		if ( fact && fact.checked_at ) {
			lines.push( '- ファクトチェック実施: ' + fact.checked_at );
		}
		if ( proof && proof.checked_at ) {
			lines.push( '- 校正チェック実施: ' + proof.checked_at );
		}
		if ( fact ) {
			var risk = fact.overall_risk || 'medium';
			lines.push( '- リスク: ' + ( riskLabels[ risk ] || risk ) );

			if ( fact.model ) {
				lines.push( '- 使用モデル: ' + fact.model );
			}
			lines.push( '- Google 検索: ' + ( fact.grounded ? '参照あり' : 'なし' ) );

			( fact.notices || [] ).forEach( function ( notice ) {
				lines.push( '- 実行条件: ' + notice );
			} );
		}

		lines.push( '' );

		if ( fact && fact.summary ) {
			lines.push( '## 事実確認の所見' );
			lines.push( '' );
			lines.push( fact.summary );
			lines.push( '' );
		}

		if ( proof && proof.summary ) {
			lines.push( '## 文章表現の所見' );
			lines.push( '' );
			lines.push( proof.summary );
			lines.push( '' );
		}

		var issues = mergeIssues( fact, proof, statusLabels );

		if ( issues.length ) {
			lines.push( '## 指摘一覧' );
			lines.push( '' );
			lines.push( '| # | 観点 | 判定 | 対象 | 修正案 | 補足 |' );
			lines.push( '| --- | --- | --- | --- | --- | --- |' );

			issues.forEach( function ( issue, index ) {
				lines.push(
					'| ' +
						( index + 1 ) +
						' | ' +
						cell( issue.kindLabel ) +
						' | ' +
						cell( issue.verdict ) +
						' | ' +
						cell( issue.body ) +
						' | ' +
						cell( issue.suggestion ) +
						' | ' +
						cell( issue.note || issue.meta ) +
						' |'
				);
			} );

			lines.push( '' );
		} else {
			lines.push( '指摘はありませんでした。' );
			lines.push( '' );
		}

		var sources = ( fact && fact.sources ) || [];
		if ( sources.length ) {
			lines.push( '## 参照元' );
			lines.push( '' );
			sources.forEach( function ( source ) {
				lines.push( '- [' + ( source.title || source.url ) + '](' + source.url + ')' );
			} );
			lines.push( '' );
		}

		lines.push( '---' );
		lines.push( '' );
		lines.push(
			'このファイルは Luna Frontier テーマの記事チェックが出力したものです。確認箇所の抽出支援であり、真偽の最終判定と修正の採否は人間の編集者が行います。'
		);

		return lines.join( '\n' );
	}


	function ArticleCheckPanel() {
		var postId = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostId();
		}, [] );

		var content = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'content' ) || '';
		}, [] );

		var postMeta = useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			var meta = editor.getEditedPostAttribute( 'meta' ) || {};

			// 脚注は本文ではなく post meta に入るため、別途取り出さないとAIに渡らない
			var footnotes = [];
			if ( 'string' === typeof meta.footnotes && meta.footnotes ) {
				try {
					footnotes = JSON.parse( meta.footnotes ) || [];
				} catch ( e ) {
					footnotes = [];
				}
			}

			return {
				title: editor.getEditedPostAttribute( 'title' ) || '',
				url: editor.getPermalink ? editor.getPermalink() || '' : '',
				slug: editor.getEditedPostAttribute( 'slug' ) || '',
				excerpt: editor.getEditedPostAttribute( 'excerpt' ) || '',
				footnotes: footnotes,
			};
		}, [] );

		var approved = useSelect( function ( select ) {
			var meta = select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
			return '1' === meta[ APPROVED_META ];
		}, [] );

		// フロー表示用の状態（保存 → ファクトチェック → 公開）
		var flowState = useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			var status = editor.getEditedPostAttribute( 'status' ) || 'auto-draft';

			return {
				saved: 'auto-draft' !== status && !! editor.getCurrentPostId(),
				published: 'publish' === status || 'future' === status || 'private' === status,
				scheduled: 'future' === status,
			};
		}, [] );

		var editPost = useDispatch( 'core/editor' ).editPost;

		var factState = useState( factSettings.data || null );
		var factResults = factState[ 0 ];
		var setFactResults = factState[ 1 ];

		var proofState = useState( proofSettings.data || null );
		var proofResults = proofState[ 0 ];
		var setProofResults = proofState[ 1 ];

		var modelState = useState( factSettings.currentModel || '' );
		var model = modelState[ 0 ];
		var setModel = modelState[ 1 ];

		var thinkingState = useState( factSettings.currentThinking || '' );
		var thinking = thinkingState[ 0 ];
		var setThinking = thinkingState[ 1 ];

		var statusState = useState( null );
		var status = statusState[ 0 ];
		var setStatus = statusState[ 1 ];


		// '' | 'fact' | 'proof'
		var busyState = useState( '' );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];

		var statusLabels = factSettings.statusLabels || {};
		var riskLabels = factSettings.riskLabels || {};

		var modelOptions = Object.keys( factSettings.models || {} ).map( function ( id ) {
			return { value: id, label: factSettings.models[ id ] };
		} );

		var thinkingOptions = Object.keys( factSettings.thinkingLevels || {} ).map( function ( level ) {
			return { value: level, label: factSettings.thinkingLevels[ level ] };
		} );

		var supportsThinking = ( factSettings.thinkingModels || [] ).indexOf( model ) !== -1;


		function setApproved( next ) {
			var meta = {};
			meta[ APPROVED_META ] = next ? '1' : '';
			editPost( { meta: meta } );
		}

		function post( which, action, nonce, extra, onSuccess ) {
			setBusy( which );
			setStatus( {
				type: 'info',
				text: ( 'fact' === which ? '検証中' : '校正中' ) + '... (しばらくお待ちください)',
			} );

			var body = new window.FormData();
			body.append( 'action', action );
			body.append( 'post_id', postId );
			body.append( 'nonce', nonce || '' );

			Object.keys( extra ).forEach( function ( key ) {
				body.append( key, extra[ key ] );
			} );

			window
				.fetch( factSettings.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: body,
				} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					setBusy( '' );

					if ( json && json.success ) {
						onSuccess( json.data );
						setStatus( { type: 'success', text: '完了' } );
						return;
					}

					setStatus( {
						type: 'error',
						text:
							'エラー: ' +
							( json && json.data && json.data.message ? json.data.message : '不明' ),
					} );
				} )
				.catch( function () {
					setBusy( '' );
					setStatus( { type: 'error', text: '通信エラーが発生しました。' } );
				} );
		}

		function runFactCheck() {
			post(
				'fact',
				'node_ai_fact_check',
				factSettings.nonce,
				{
					// 保存形式は `<モデルID>@<思考量>`（1.2 系と互換）
					gemini_model:
						factSettings.providerId === 'gemini'
							? ( model && supportsThinking && thinking ? model + '@' + thinking : model )
							: '',
				},
				function ( data ) {
					setFactResults( data );
					// サーバー側で承認フラグがリセットされるためエディタ状態も揃える
					setApproved( false );
				}
			);
		}

		function runProofread() {
			post(
				'proof',
				'node_ai_proofread',
				proofSettings.nonce,
				{ content: content },
				setProofResults
			);
		}

		var issues = mergeIssues( factResults, proofResults, statusLabels );
		var hasResults = !! ( factResults || proofResults );

		var statusColor = '#646970';
		if ( status && 'success' === status.type ) {
			statusColor = '#00a32a';
		} else if ( status && 'error' === status.type ) {
			statusColor = '#d63638';
		} else if ( status && 'info' === status.type ) {
			statusColor = '#FF9900';
		}

		var risk = factResults ? factResults.overall_risk || 'medium' : '';

		return el(
			PluginDocumentSettingPanel,
			{
				name: 'node-ai-article-check-panel',
				title: '記事チェック',
				icon: 'search',
			},
			renderFlow( {
				saved: flowState.saved,
				published: flowState.published,
				scheduled: flowState.scheduled,
				checked: !! ( factResults && ( factResults.claims || [] ).length ),
				unresolved: factResults
					? ( factResults.claims || [] ).filter( function ( claim ) {
							return 'uncertain' === claim.status || 'likely_incorrect' === claim.status;
					  } ).length
					: 0,
			} ),
			el(
				'p',
				{ style: { fontSize: '12px', color: '#646970', marginTop: 0 } },
				factSettings.providerId === 'gemini'
					? '事実関係（公式サイト・検索結果を参照）と日本語表現をまとめて点検します。確認箇所の抽出支援であり、最終判断は編集者が行います。'
					: '事実関係と日本語表現をまとめて点検します。確認箇所の抽出支援であり、最終判断は編集者が行います。'
			),
			factSettings.hasKey
				? el(
						'p',
						{ style: { fontSize: '12px', color: '#646970' } },
						'下書き保存するとファクトチェックが自動実行されます。',
						el( 'strong', null, '公開前の実行を推奨します（未実施でも公開はできます）。' )
				  )
				: null,
			factSettings.autoError
				? el(
						'p',
						{ style: { fontSize: '12px', color: '#d63638' } },
						'前回の自動実行が失敗しました: ' + factSettings.autoError
				  )
				: null,

			// ---- 実行 ----
			el(
				'div',
				{ style: { display: 'flex', flexWrap: 'wrap', gap: '6px', margin: '10px 0' } },
				el(
					Button,
					{
						variant: 'secondary',
						onClick: runFactCheck,
						disabled: '' !== busy,
						isBusy: 'fact' === busy,
					},
					'ファクトチェック'
				),
				el(
					Button,
					{
						variant: 'secondary',
						onClick: runProofread,
						disabled: '' !== busy,
						isBusy: 'proof' === busy,
					},
					'校正チェック'
				)
			),

			modelOptions.length
				? el( SelectControl, {
						label: '使用モデル',
						value: model,
						options: modelOptions,
						onChange: setModel,
				  } )
				: null,
			supportsThinking && thinkingOptions.length
				? el( SelectControl, {
						label: '思考量',
						value: thinking,
						options: thinkingOptions,
						onChange: setThinking,
						help: '長い記事で応答が途中で切れる場合は Low が有効です。',
				  } )
				: null,

			status
				? el(
						'p',
						{
							style: {
								fontSize: '12px',
								fontWeight: 700,
								color: statusColor,
								margin: '6px 0',
							},
						},
						status.text
				  )
				: null,

			// ---- 統合結果 ----
			hasResults
				? el(
						'div',
						{ style: { marginTop: '10px', borderTop: '1px solid #e0e0e0', paddingTop: '10px' } },
						risk
							? el(
									'p',
									{ style: { margin: '0 0 6px', fontSize: '12px' } },
									el( 'strong', null, 'リスク: ' ),
									riskLabels[ risk ] || risk
							  )
							: null,
						// 実行条件（使用モデル・検索の有無）は判定の読み方に効くので必ず出す
						renderRunContext( factResults ),
						factResults && factResults.summary
							? el(
									'p',
									{ style: { margin: '0 0 6px', fontSize: '13px' } },
									el( 'strong', null, '事実確認: ' ),
									factResults.summary
							  )
							: null,
						proofResults && proofResults.summary
							? el(
									'p',
									{ style: { margin: '0 0 6px', fontSize: '13px' } },
									el( 'strong', null, '文章表現: ' ),
									proofResults.summary
							  )
							: null,
						issues.length
							? el(
									'ul',
									{ style: { margin: '8px 0 0', padding: 0, listStyle: 'none' } },
									issues.map( renderIssue )
							  )
							: el(
									'p',
									{
										style: {
											fontSize: '13px',
											color: '#00a32a',
											display: 'flex',
											alignItems: 'center',
											gap: '6px',
										},
									},
									icon( 'dashicons-yes-alt', '#00a32a', 18 ),
									'指摘はありませんでした。'
							  ),
						renderSources( ( factResults && factResults.sources ) || [] )
				  )
				: el(
						'p',
						{ style: { fontSize: '12px', color: '#646970' } },
						'まだチェックは実行されていません。'
				  ),

			// ---- 公開ゲート ----
			factResults
				? el(
						'div',
						{ style: { marginTop: '10px' } },
						el( CheckboxControl, {
							label: '編集者が結果を確認済み（フロント公開）',
							checked: approved,
							onChange: setApproved,
						} )
				  )
				: null,

			// ---- 持ち出し ----
			hasResults
				? el(
						'div',
						{ style: { display: 'flex', flexWrap: 'wrap', gap: '6px', marginTop: '8px' } },
						el(
							Button,
							{
								variant: 'tertiary',
								onClick: function () {
									window.nodeAiExport
										.copyText(
											buildMarkdown(
												factResults,
												proofResults,
												statusLabels,
												riskLabels,
												postMeta
											)
										)
										.then( function () {
											setStatus( { type: 'success', text: 'Markdown をコピーしました。' } );
										} )
										.catch( function () {
											setStatus( {
												type: 'error',
												text: 'コピーに失敗しました。「Markdownにエクスポート」をお使いください。',
											} );
										} );
								},
							},
							'本文をクリップボードにコピー'
						),
						el(
							Button,
							{
								variant: 'tertiary',
								onClick: function () {
									window.nodeAiExport.downloadMarkdown(
										buildMarkdown(
											factResults,
											proofResults,
											statusLabels,
											riskLabels,
											postMeta
										),
										( postMeta.slug || 'post' ) + '-article-check'
									);
									setStatus( { type: 'success', text: 'Markdown を書き出しました。' } );
								},
							},
							'Markdownにエクスポート'
						)
				  )
				: null
		);
	}

	/**
	 * 公開直前の確認パネル。
	 * ファクトチェックは必須ではなく推奨なので、公開は止めずにその場で気づけるようにする。
	 */
	function PrePublishNotice() {
		var factResults = useSelect( function ( select ) {
			// 保存済みの結果があるかだけ見る（パネル側の state とは独立）
			return select( 'core/editor' ).getCurrentPostId();
		}, [] );

		if ( ! PluginPrePublishPanel ) {
			return null;
		}

		var done = !! ( factSettings.data && ( factSettings.data.claims || [] ).length );

		var unresolved = done
			? ( factSettings.data.claims || [] ).filter( function ( claim ) {
					return (
						'uncertain' === claim.status || 'likely_incorrect' === claim.status
					);
			  } ).length
			: 0;

		var title = done
			? ( unresolved ? 'ファクトチェック: 未対応の指摘あり' : 'ファクトチェック: 実施済み' )
			: 'ファクトチェック: 未実施';

		return el(
			PluginPrePublishPanel,
			{
				name: 'node-ai-fact-check-prepublish',
				title: title,
				initialOpen: ! done || unresolved > 0,
				icon: 'search',
			},
			! done
				? el(
						'p',
						{ style: { fontSize: '13px', margin: 0 } },
						el(
							'span',
							{
								className: 'dashicons dashicons-warning',
								'aria-hidden': true,
								style: { color: '#dba617', marginRight: '4px' },
							}
						),
						'この記事はまだファクトチェックを実施していません。公開前の実行を推奨します（このまま公開することもできます）。'
				  )
				: unresolved
				? el(
						'p',
						{ style: { fontSize: '13px', margin: 0 } },
						el(
							'span',
							{
								className: 'dashicons dashicons-flag',
								'aria-hidden': true,
								style: { color: '#d63638', marginRight: '4px' },
							}
						),
						'未対応の指摘が ' + unresolved + ' 件あります。公開前に「記事チェック」で内容をご確認ください。'
				  )
				: el(
						'p',
						{ style: { fontSize: '13px', margin: 0, color: '#00a32a' } },
						'ファクトチェック済みで、対応が必要な指摘はありません。'
				  ),
			postIdUnused( factResults )
		);
	}

	// useSelect の戻り値を使わないことを明示するだけのヘルパー
	function postIdUnused() {
		return null;
	}

	registerPlugin( 'node-ai-article-check', {
		render: function () {
			return el(
				wp.element.Fragment,
				null,
				el( ArticleCheckPanel, null ),
				el( PrePublishNotice, null )
			);
		},
	} );
} )( window.wp );
