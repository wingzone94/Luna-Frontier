/* Standalone admin script: no frontend bundle or automatic scan. */
(() => {
  const root = document.getElementById('node-image-repair');
  if (!root) return;
  let stopped = true, busy = false, offset = 0;
  const status = document.getElementById('node-image-progress');
  async function request(task) {
    const body = new URLSearchParams({action: 'node_image_repair', nonce: nodeImageRepair.nonce, task, offset});
    root.querySelectorAll('[name=status]:checked').forEach(el => body.append('statuses[]', el.value));
    const response = await fetch(nodeImageRepair.url, {method: 'POST', credentials: 'same-origin', body});
    const data = await response.json();
    if (!data.success) throw new Error(typeof data.data === 'string' ? data.data : '処理に失敗しました。');
    const {job, rows} = data.data;
    status.textContent = `検査済み ${job.scanned} 記事 / 要確認 ${job.count} 件 / 修復処理 ${job.repair_cursor} 件 / ${job.phase} / 表示 ${offset + 1}〜${offset + rows.length}`;
    const table = document.getElementById('node-image-results');
    table.replaceChildren();
    rows.forEach(row => {
      const tr = document.createElement('tr');
      [`${row.post_id}: ${row.title}`, row.url, row.id || '不明', row.classification, row.status,
        `${row.source || ''} ${row.evidence}`, row.result].forEach(value => {
        const td = document.createElement('td'); td.textContent = value; td.style.overflowWrap = 'anywhere'; tr.append(td);
      });
      table.append(tr);
    });
    return job;
  }
  root.addEventListener('click', async event => {
    const task = event.target.dataset.task;
    if (!task) return;
    if (task === 'stop') { stopped = true; return; }
    if (busy) return;
    if (task === 'repair' && !confirm('検査結果を確認しましたか？修復可能な欠損ファイルだけを追加します。')) return;
    if (task === 'restore' && !confirm('変更後の競合を確認します。本文・メタデータは未変更のため書き戻さず、生成ファイルも保持します。')) return;
    busy = true; stopped = false;
    try {
      if (task === 'previous' || task === 'next') {
        offset = Math.max(0, offset + (task === 'next' ? 50 : -50)); await request('status'); return;
      }
      let work = task;
      if (task === 'start') { offset = 0; await request('start'); work = 'scan'; }
      do {
        const job = await request(work);
        if (job.phase !== work) break;
        await new Promise(resolve => setTimeout(resolve, 150));
      } while (!stopped);
    } catch (error) { status.textContent = error.message + ' 停止しました。状態を確認して再開できます。'; }
    finally { busy = false; }
  });
  request('status').catch(() => { status.textContent = '読み取り専用の検査から開始してください。'; });
})();
