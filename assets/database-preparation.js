(() => {
  'use strict';
  const config = window.pictsDatabasePreparation;
  const section = document.getElementById('picts-db-preparation');
  const start = document.getElementById('picts-db-start');
  const backup = document.getElementById('picts-db-backup');
  const recover = document.getElementById('picts-db-recover');
  const message = document.getElementById('picts-db-message');
  const detail = document.getElementById('picts-db-detail');
  const progress = document.getElementById('picts-db-progress');
  let busy = false;
  let expected = config.requestId;
  let timer;
  let frozen = [];
  let previousBackupDisabled = false;
  let observer;
  let finishedRequest = null;
  let generation = 0;
  let fallbackAttempt = 0;

  function freeze(element) {
    if (!element.inert) { element.inert = true; frozen.push(element); }
  }

  function setBusy(value) {
    if (value === busy) { return; }
    busy = value;
    if (value) {
      previousBackupDisabled = backup.disabled;
      backup.disabled = true;
      for (let node = section; node && node !== document.body; node = node.parentElement) {
        if (!node.parentElement) { break; }
        for (const sibling of node.parentElement.children) {
          if (sibling !== node) { freeze(sibling); }
        }
      }
      observer = new MutationObserver(records => {
        for (const record of records) {
          if (section.contains(record.target)) { continue; }
          for (const element of record.addedNodes) {
            if (element.nodeType === 1 && element !== section && !element.contains(section)) { freeze(element); }
          }
        }
      });
      observer.observe(document.body, { childList: true, subtree: true });
    } else {
      observer?.disconnect();
      observer = undefined;
      for (const element of frozen) { element.inert = false; }
      frozen = [];
      backup.disabled = previousBackupDisabled;
    }
  }

  window.addEventListener('beforeunload', event => {
    if (busy) { event.preventDefault(); event.returnValue = ''; }
  });
  async function request(action, extra = {}) {
    const body = new URLSearchParams({ action, nonce: config.nonce, ...extra });
    const response = await fetch(config.url, { method: 'POST', credentials: 'same-origin', body });
    const result = await response.json();
    if (!response.ok || !result.success) {
      const error = new Error(result.data?.message || 'The request could not be completed.');
      error.definite = result.success === false && [400, 403, 409].includes(response.status);
      throw error;
    }
    return result.data;
  }
  function show(state) {
    const completed = Number(state.completed) || 0;
    const total = Number(state.total) || 0;
    progress.max = Math.max(total, 1);
    progress.value = Math.min(completed, total);
    for (const table of state.tables || []) {
      const entry = document.querySelector(`[data-picts-db-table="${table.name}"]`);
      if (entry) { entry.textContent = table.engine; }
    }
    detail.textContent = `${completed} of ${total} tables completed${state.current ? `; working on ${state.current}` : ''}. Individual tables may take some time.`;
    if (state.status === 'running' || state.status === 'finishing') {
      setBusy(true); start.disabled = true;
      message.textContent = state.status === 'finishing' ? 'Finishing and restoring normal access…' : 'Preparing the database. Keep this page open.';
      return false;
    }
    finishedRequest = state.request_id || expected;
    setBusy(false); clearTimeout(timer);
    if (state.status === 'complete') {
      message.textContent = 'Preparation complete. The required tables were checked and maintenance mode was removed.';
      start.disabled = true;
    } else if (state.status === 'failed') {
      message.textContent = state.message || 'Preparation stopped. Some tables may already have changed. Review their status and your backup before trying again.';
      start.disabled = false;
    } else {
      message.textContent = 'The result needs review. Use “Check an interrupted preparation” before trying again.';
      start.disabled = true;
    }
    return true;
  }
  async function poll() {
    const pollingId = expected;
    const pollingGeneration = generation;
    if (finishedRequest === pollingId) { return; }
    try {
      const state = await request('picts_db_progress');
      if (generation !== pollingGeneration || expected !== pollingId || finishedRequest === pollingId) { return; }
      if (state.request_id === pollingId && state.status && show(state)) { return; }
      timer = setTimeout(poll, 2500);
    } catch {
      if (generation !== pollingGeneration || expected !== pollingId || finishedRequest === pollingId) { return; }
      message.textContent = 'Progress cannot be reached. The conversion may still be running; keep this page open and check again.';
      timer = setTimeout(poll, 5000);
    }
  }
  start.addEventListener('click', () => {
    if (!backup.checked) { message.textContent = 'Confirm your database backup before starting.'; backup.focus(); return; }
    setBusy(true); start.disabled = true;
    generation++;
    fallbackAttempt++;
    expected = window.crypto?.randomUUID ? window.crypto.randomUUID() : config.requestId.slice(0, 28) + fallbackAttempt.toString(16).padStart(8, '0');
    finishedRequest = null;
    const startedId = expected;
    const startedGeneration = generation;
    message.textContent = 'Starting preparation… Other page controls are temporarily unavailable. Progress can pause while WordPress waits for a table.';
    request('picts_db_start', { backup: 'yes', request_id: expected }).then(state => {
      if (generation === startedGeneration && expected === startedId && finishedRequest !== startedId) { show(state); }
    }).catch(error => {
      if (generation !== startedGeneration || expected !== startedId || finishedRequest === startedId) { return; }
      if (error.definite) {
        message.textContent = error.message;
        setBusy(false); clearTimeout(timer); start.disabled = true;
      } else {
        message.textContent = 'The start response was lost. Preparation may still be running; keep this page open while progress is checked.';
        setBusy(true);
      }
    });
    timer = setTimeout(poll, 1000);
  });
  recover.addEventListener('click', async () => {
    const recoveryGeneration = generation;
    recover.disabled = true;
    try {
      const state = await request('picts_db_recover');
      if (generation !== recoveryGeneration) { return; }
      finishedRequest = expected;
      setBusy(false); clearTimeout(timer);
      message.textContent = 'The previous operation is no longer running. Maintenance was cleared if it belonged to this operation. Review the current table engines and your backup before starting again; reload this page to see their status.';
      detail.textContent = (state.tables || []).map(t => `${t.name}: ${t.engine}`).join(', ');
      start.disabled = true;
    } catch (error) { if (generation === recoveryGeneration) { message.textContent = error.message; } }
    finally { recover.disabled = false; }
  });
  if (['running', 'finishing'].includes(config.state.status)) {
    expected = config.state.request_id; show(config.state); timer = setTimeout(poll, 1000);
  } else if (['uncertain', 'interrupted'].includes(config.state.status)) {
    show(config.state);
  }
})();
