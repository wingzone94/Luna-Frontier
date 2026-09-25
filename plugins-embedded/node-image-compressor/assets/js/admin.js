/**
 * Node Image Compressor — 管理画面の逐次置き換え UI。
 *
 * 一括置き換えは 1 件ずつ AJAX で回す（全件を 1 リクエストで処理するとタイムアウトするため）。
 * 依存ライブラリなし。
 */
(function () {
	'use strict';

	var data = window.nodeIcData || {};

	if (!data.ajaxUrl) {
		return;
	}

	// ブロックエディタはメタボックスをこのスクリプトより後に描画する。
	// 起動時に要素を掴むと取り逃がすので、参照はすべて実行時に引き直す。
	function app() {
		return document.querySelector('.node-ic-app');
	}

	var STATE_ICONS = {
		converted: 'check_circle',
		pending: 'pending',
		skipped: 'block',
		ineligible: 'do_not_disturb_on',
		failed: 'error'
	};

	var snackbarTimer = null;

	function showSnackbar(message) {
		var bar = document.querySelector('[data-node-ic-snackbar]');
		if (!bar || !message) {
			return;
		}

		bar.textContent = message;
		bar.hidden = false;
		// hidden を外した直後だと transition が走らないので次フレームまで待つ
		window.requestAnimationFrame(function () {
			bar.classList.add('is-visible');
		});

		window.clearTimeout(snackbarTimer);
		snackbarTimer = window.setTimeout(function () {
			bar.classList.remove('is-visible');
			window.setTimeout(function () {
				bar.hidden = true;
			}, 250);
		}, 4000);
	}

	function request(action, attachmentId) {
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', data.nonce);
		body.set('attachment_id', String(attachmentId));

		return fetch(data.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (response) {
			return response.json();
		});
	}

	function itemFor(attachmentId) {
		return document.querySelector('[data-node-ic-item="' + attachmentId + '"]');
	}

	/**
	 * サーバーが返した状態でカード 1 枚を描き替える。
	 */
	function applyState(attachmentId, payload, failed) {
		var item = itemFor(attachmentId);
		if (!item || !payload) {
			return;
		}

		var state = failed ? 'failed' : payload.state;
		item.dataset.state = payload.state || '';

		var chip = item.querySelector('[data-node-ic-chip]');
		if (chip) {
			chip.className = 'node-ic-chip node-ic-chip--' + state;
			var icon = chip.querySelector('[data-node-ic-chip-icon]');
			if (icon) {
				icon.textContent = STATE_ICONS[state] || STATE_ICONS.ineligible;
			}
			var label = chip.querySelector('[data-node-ic-chip-label]');
			if (label) {
				label.textContent = failed ? '失敗' : (payload.label || '');
			}
		}

		var size = item.querySelector('[data-node-ic-size]');
		if (size) {
			if (!failed && payload.state === 'converted' && payload.beforeText) {
				size.textContent = payload.beforeText + ' → ' + payload.afterText + '（-' + payload.rate + '%）';
			} else {
				size.textContent = payload.reason || payload.message || '';
			}
		}

		var thumb = item.querySelector('[data-node-ic-thumb]');
		if (thumb && payload.thumbnail) {
			// 置き換えで実ファイルが変わるのでキャッシュを避ける
			thumb.src = payload.thumbnail + (payload.thumbnail.indexOf('?') === -1 ? '?' : '&') + 'v=' + Date.now();
		}

		var convertBtn = item.querySelector('[data-node-ic-convert]');
		var restoreBtn = item.querySelector('[data-node-ic-restore]');
		var skipBtn = item.querySelector('[data-node-ic-skip]');

		if (convertBtn) {
			convertBtn.disabled = payload.state !== 'pending';
		}
		if (restoreBtn) {
			// 元ファイルを削除して置き換えた場合は復元できない
			restoreBtn.disabled = !payload.restorable;
		}
		if (skipBtn) {
			skipBtn.disabled = payload.state === 'converted';
			var skipIcon = skipBtn.querySelector('.material-symbols-outlined');
			var skipText = skipBtn.querySelector('.node-ic-btn__text');
			var isSkipped = payload.state === 'skipped';
			if (skipIcon) {
				skipIcon.textContent = isSkipped ? 'restart_alt' : 'block';
			}
			if (skipText) {
				skipText.textContent = isSkipped ? 'スキップ解除' : 'スキップ';
			}
		}
	}

	/**
	 * サマリーの「置き換え済み / 未置き換え」を数え直す。
	 * 一覧はページネーションされているので、差分で加減算する。
	 */
	function bumpCount(key, delta) {
		var node = document.querySelector('[data-node-ic-count="' + key + '"]');
		if (!node) {
			return;
		}
		var next = Math.max(0, parseInt(node.textContent, 10) + delta);
		node.textContent = String(next);
	}

	function runAction(action, attachmentId) {
		var item = itemFor(attachmentId);
		if (item) {
			item.classList.add('is-busy');
		}

		return request(action, attachmentId)
			.then(function (json) {
				var payload = (json && json.data) || {};
				var ok = !!(json && json.success);
				applyState(attachmentId, payload, !ok);
				return { ok: ok, payload: payload };
			})
			.catch(function () {
				return { ok: false, payload: { message: '通信に失敗しました。' } };
			})
			.finally(function () {
				if (item) {
					item.classList.remove('is-busy');
				}
			});
	}

	// --- 個別操作 -----------------------------------------------------------

	document.addEventListener('click', function (event) {
		if (!event.target || !event.target.closest) {
			return;
		}

		var button = event.target.closest('[data-node-ic-convert], [data-node-ic-restore], [data-node-ic-skip]');
		if (!button || button.disabled || !button.closest('.node-ic-app')) {
			return;
		}

		var item = button.closest('[data-node-ic-item]');
		if (!item) {
			return;
		}

		var attachmentId = item.dataset.nodeIcItem;
		var action = 'node_ic_convert_one';
		if (button.hasAttribute('data-node-ic-restore')) {
			action = 'node_ic_restore_one';
		} else if (button.hasAttribute('data-node-ic-skip')) {
			action = 'node_ic_skip_toggle';
		}

		var wasConverted = item.dataset.state === 'converted';

		runAction(action, attachmentId).then(function (result) {
			showSnackbar(result.payload.message || '');

			if (!result.ok) {
				return;
			}

			var isConverted = result.payload.state === 'converted';
			if (isConverted && !wasConverted) {
				bumpCount('converted', 1);
				bumpCount('pending', -1);
			} else if (!isConverted && wasConverted) {
				bumpCount('converted', -1);
				bumpCount('pending', 1);
			} else if (result.payload.state === 'skipped') {
				bumpCount('pending', -1);
			} else if (result.payload.state === 'pending' && action === 'node_ic_skip_toggle') {
				bumpCount('pending', 1);
			}
		});
	});

	// --- 一括置き換え -------------------------------------------------------

	var root = app();
	var bulkButton = root && root.querySelector('[data-node-ic-bulk]');
	var stopButton = root && root.querySelector('[data-node-ic-bulk-stop]');
	var progress = root && root.querySelector('[data-node-ic-progress]');
	var progressFill = root && root.querySelector('.node-ic-progress__fill');
	var progressText = root && root.querySelector('[data-node-ic-progress-text]');
	var aborted = false;

	function setProgress(done, total, succeeded, failedCount) {
		if (progressFill) {
			progressFill.style.width = total > 0 ? (done / total) * 100 + '%' : '0%';
		}
		if (progressText) {
			progressText.textContent =
				done + ' / ' + total + ' 件 — 成功 ' + succeeded + ' 件、失敗 ' + failedCount + ' 件';
		}
	}

	if (bulkButton) {
		bulkButton.addEventListener('click', function () {
			var queue = (data.pendingIds || []).slice();
			if (!queue.length) {
				showSnackbar('未置き換えのアイキャッチはありません。');
				return;
			}

			aborted = false;
			bulkButton.disabled = true;
			if (stopButton) {
				stopButton.hidden = false;
			}
			if (progress) {
				progress.hidden = false;
			}

			var total = queue.length;
			var done = 0;
			var succeeded = 0;
			var failedCount = 0;

			setProgress(done, total, succeeded, failedCount);

			// 1 件ずつ順番に回す。失敗しても止めずに次へ進める
			function step() {
				if (aborted || !queue.length) {
					bulkButton.disabled = false;
					if (stopButton) {
						stopButton.hidden = true;
					}
					showSnackbar(
						aborted
							? '一括置き換えを中止しました（成功 ' + succeeded + ' 件）。'
							: '一括置き換えが完了しました（成功 ' + succeeded + ' 件、失敗 ' + failedCount + ' 件）。'
					);
					return;
				}

				var attachmentId = queue.shift();

				runAction('node_ic_convert_one', attachmentId).then(function (result) {
					done += 1;
					if (result.ok) {
						succeeded += 1;
						bumpCount('converted', 1);
						bumpCount('pending', -1);
					} else {
						failedCount += 1;
					}
					setProgress(done, total, succeeded, failedCount);
					step();
				});
			}

			step();
		});
	}

	if (stopButton) {
		stopButton.addEventListener('click', function () {
			aborted = true;
			stopButton.hidden = true;
		});
	}

	// --- 品質スライダーと数値入力の同期 -------------------------------------

	var range = root && root.querySelector('[data-node-ic-range]');
	var number = root && root.querySelector('[data-node-ic-number]');

	// 自動のときは手動指定の行を隠す
	var qualityMode = root && root.querySelector('[data-node-ic-quality-mode]');
	var manualField = root && root.querySelector('[data-node-ic-quality-manual]');

	if (qualityMode && manualField) {
		qualityMode.addEventListener('change', function () {
			manualField.hidden = qualityMode.value !== 'manual';
		});
	}

	if (range && number) {
		range.addEventListener('input', function () {
			number.value = range.value;
		});
		number.addEventListener('input', function () {
			var value = parseInt(number.value, 10);
			if (!isNaN(value)) {
				range.value = String(Math.max(1, Math.min(100, value)));
			}
		});
	}
})();
