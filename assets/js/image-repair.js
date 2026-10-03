/* Standalone admin script: no frontend bundle or automatic scan. */
(() => {
  const root = document.getElementById('node-image-repair');
  if (!root) return;
  let stopped = true, busy = false, offset = 0, jobId = '';
  const status = document.getElementById('node-image-progress');
  async function request(task, index = -1) {
    const body = new URLSearchParams({action: nodeImageRepair.action, nonce: nodeImageRepair.nonce, task, offset, index, job_id: jobId, run: document.getElementById('node-image-run').value});
    root.querySelectorAll('[name=status]:checked').forEach(el => body.append('statuses[]', el.value));
    const response = await fetch(nodeImageRepair.url, {method: 'POST', credentials: 'same-origin', body});
    const data = await response.json();
    if (!data.success) throw new Error(typeof data.data === 'string' ? data.data : '処理に失敗しました。');
    const {job, rows} = data.data;
    if (jobId && jobId !== job.id) document.getElementById('node-image-reviewed').checked = false;
    jobId = job.id;
    status.textContent = `検査ID ${job.id} / 検査済み ${job.scanned} 記事 / 要確認 ${job.count} 件 / 修復処理 ${job.repair_cursor} 件 / ${job.phase} / 表示 ${offset + 1}〜${offset + rows.length}`;
    const table = document.getElementById('node-image-results');
    table.replaceChildren();
    rows.forEach(row => {
      const tr = document.createElement('tr');
      [`${row.post_id}: ${row.title}`, row.url, row.id || '不明', row.classification, row.status,
        `${row.source || ''} ${row.evidence}`, row.result].forEach(value => {
        const td = document.createElement('td'); td.textContent = value; td.style.overflowWrap = 'anywhere'; tr.append(td);
      });
      if (row.status === '修復可能') {
        const button = document.createElement('button'); button.className = 'button'; button.dataset.task = 'repair_one'; button.dataset.index = row.index; button.textContent = 'この項目を修復／再試行'; tr.lastElementChild.append(button);
      }
      table.append(tr);
    });
    return job;
  }
  root.addEventListener('click', async event => {
    const task = event.target.dataset.task;
    if (!task) return;
    if (task === 'stop') { stopped = true; return; }
    if (busy) return;
    if (['repair', 'retry', 'repair_one', 'restore'].includes(task) && !document.getElementById('node-image-reviewed').checked) {
      status.textContent = '検査結果と候補を確認し、確認済みのチェックを入れてください。'; return;
    }
    busy = true; stopped = false;
    try {
      if (task === 'previous' || task === 'next') {
        offset = Math.max(0, offset + (task === 'next' ? 50 : -50)); await request('status'); return;
      }
      if (task === 'repair_one') { await request(task, event.target.dataset.index); return; }
      let work = task;
      if (task === 'retry') { await request('retry'); work = 'repair'; }
      if (task === 'start') { document.getElementById('node-image-reviewed').checked = false; offset = 0; await request('start'); work = 'scan'; }
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
