import { t } from './locale.js';
const batches = new Map();

function batchFor(workspace) {
    if (!batches.has(workspace)) {
        let results = [];
        try {
            results = JSON.parse(sessionStorage.getItem('workspace-fetch:' + workspace)) ?? [];
        } catch {
            // Keep the current window usable when storage is unavailable.
        }
        batches.set(workspace, { results: new Map(Array.isArray(results) ? results : []), running: false });
    }
    return batches.get(workspace);
}

function save(workspace, batch) {
    try {
        sessionStorage.setItem('workspace-fetch:' + workspace, JSON.stringify([...batch.results]));
    } catch {
        // The in-memory batch still survives navigation within the window.
    }
}

function rowKey(box) {
    return JSON.stringify([box.value, box.dataset.branch, box.dataset.upstream]);
}

function updateSelection(form) {
    const available = [...form.querySelectorAll('[data-fetch-choice]')].filter(box => box.dataset.upstream);
    const count = available.filter(box => box.checked).length;
    const all = form.querySelector('[data-fetch-all]');
    all.checked = count > 0 && count === available.length;
    all.indeterminate = count > 0 && count < available.length;
    all.disabled = !available.length || form.hasAttribute('aria-busy');
    form.querySelector('[data-fetch-submit]').disabled = !count || form.hasAttribute('aria-busy');
}

function displayResult(row, result, fetching) {
    const output = row.querySelector('[data-fetch-result]');
    const time = row.querySelector('time');
    time.hidden = !result;
    if (result) {
        const date = new Date(result.at);
        time.dateTime = result.at;
        time.textContent = date.toLocaleTimeString(document.documentElement.lang, { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        time.title = date.toLocaleString(document.documentElement.lang);
    }
    output.textContent = fetching ? t('Fetching…') : '';
    if (fetching || !result) return;
    if (!result.ok) output.textContent = result.notice + ' ';
    if (!result.url) return;
    const url = new URL(result.url, location.href);
    if (url.origin !== location.origin) return;
    url.searchParams.set('from', 'workspace-fetch');
    const link = document.createElement('a');
    link.href = url.href;
    link.textContent = t('Inspect branch');
    if (result.ok && result.ahead !== null) {
        link.textContent = result.ahead || result.behind
            ? t(':ahead ahead · :behind behind', result) : t('Up to date');
    }
    output.append(link);
}

export function initializeWorkspaceFetch() {
    const form = document.querySelector('[data-workspace-fetch]');
    if (!form) return;
    const batch = batchFor(form.dataset.workspace);
    if (batch.running) form.setAttribute('aria-busy', 'true');
    else form.removeAttribute('aria-busy');
    let completed = 0;
    let failed = 0;
    for (const box of form.querySelectorAll('[data-fetch-choice]')) {
        box.disabled = batch.running || !box.dataset.upstream;
        const key = rowKey(box);
        const result = batch.results.get(key);
        if (result) {
            completed++;
            if (!result.ok) failed++;
        }
        const row = box.closest('[data-fetch-url]');
        const fetching = batch.running && batch.current === key;
        const version = fetching ? 'fetching' : result?.at ?? '';
        if (row.dataset.version !== version) {
            displayResult(row, result, fetching);
            row.dataset.version = version;
        }
    }
    const progress = form.querySelector('[data-fetch-progress]');
    if (batch.running) progress.textContent = t('Fetching :current of :total…', { current: batch.position, total: batch.total });
    else if (completed) progress.textContent = t('Fetched :ok of :total repositories.', { ok: completed - failed, total: completed });
    else progress.textContent = t('Select the repositories to fetch.');
    const retry = form.querySelector('[data-fetch-retry]');
    retry.hidden = !failed;
    retry.disabled = batch.running;
    retry.textContent = t('Retry failed (:count)', { count: failed });
    updateSelection(form);
}

document.addEventListener('change', event => {
    const form = event.target.closest('[data-workspace-fetch]');
    if (!form) return;
    if (event.target.matches('[data-fetch-all]')) {
        for (const box of form.querySelectorAll('[data-fetch-choice]:enabled')) box.checked = event.target.checked;
    }
    updateSelection(form);
});

document.addEventListener('submit', async event => {
    const form = event.target;
    if (!form.matches('[data-workspace-fetch]')) return;
    event.preventDefault();
    const workspace = form.dataset.workspace;
    const batch = batchFor(workspace);
    if (batch.running) return;
    const retry = event.submitter?.matches('[data-fetch-retry]');
    const selected = [...form.querySelectorAll('[data-fetch-choice]:enabled')].filter(box =>
        retry ? batch.results.get(rowKey(box))?.ok === false : box.checked);
    if (!selected.length) return;
    if (!retry) batch.results.clear();
    save(workspace, batch);
    batch.running = true;
    batch.total = selected.length;
    try {
        for (const [index, box] of selected.entries()) {
            batch.position = index + 1;
            batch.current = rowKey(box);
            initializeWorkspaceFetch();
            let result;
            try {
                const body = new URLSearchParams({ _token: form.elements._token.value, workspace, branch: box.dataset.branch, upstream: box.dataset.upstream });
                const response = await fetch(box.closest('[data-fetch-url]').dataset.fetchUrl, { method: 'POST', headers: { Accept: 'application/json' }, body });
                if (!response.ok) throw new Error();
                result = await response.json();
            } catch {
                result = { ok: false, notice: t('Fetch unavailable. Refresh the list and try again.') };
            }
            batch.results.set(batch.current, { ...result, at: new Date().toISOString() });
            save(workspace, batch);
        }
    } finally {
        batch.running = false;
        initializeWorkspaceFetch();
    }
});
