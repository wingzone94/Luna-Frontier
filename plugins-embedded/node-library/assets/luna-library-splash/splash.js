/* Luna Library スプラッシュ画面の終了制御
   - 最低表示 700ms を確保し、window load でフェードアウト
   - 何があっても 3000ms で必ず終了（CSS 側にも 4.2s のフェイルセーフあり）
   - クリック / キー入力でも即終了
   - 終了時に sessionStorage へ記録し、同一セッションでは再表示しない */
(function () {
	var el = document.getElementById('ll-splash');
	if (!el) return;
	if (document.documentElement.classList.contains('ll-splash-seen')) return;

	var MIN_MS = 700;
	var started = Date.now();
	var done = false;

	function finish() {
		if (done) return;
		done = true;
		try { sessionStorage.setItem('ll_splash_seen', '1'); } catch (e) {}
		el.classList.add('is-done');
		setTimeout(function () {
			if (el.parentNode) el.parentNode.removeChild(el);
		}, 600);
	}

	function finishAfterMin() {
		var wait = Math.max(0, MIN_MS - (Date.now() - started));
		setTimeout(finish, wait);
	}

	if (document.readyState === 'complete') {
		finishAfterMin();
	} else {
		window.addEventListener('load', finishAfterMin);
	}
	setTimeout(finish, 3000);
	el.addEventListener('click', finish);
	document.addEventListener('keydown', finish);
})();
