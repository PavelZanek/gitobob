function selection(form) {
    const choices = [...form.querySelectorAll('[data-fetch-choice]:enabled')];
    const selected = choices.filter(box => box.checked);
    const all = form.querySelector('[data-fetch-all]');
    all.checked = !!choices.length && selected.length === choices.length;
    all.indeterminate = selected.length > 0 && selected.length < choices.length;
    form.querySelector('[data-fetch-submit]').disabled = !selected.length || form.hasAttribute('aria-busy');
    return selected;
}

document.addEventListener('change', event => {
    const form = event.target.closest('[data-workspace-fetch]');
    if (!form) return;
    if (event.target.matches('[data-fetch-all]')) {
        for (const box of form.querySelectorAll('[data-fetch-choice]:enabled')) box.checked = event.target.checked;
    }
    selection(form);
});

document.addEventListener('submit', async event => {
    const form = event.target;
    if (!form.matches('[data-workspace-fetch]')) return;
    event.preventDefault();
    if (form.hasAttribute('aria-busy')) return;
    const selected = selection(form);
    const progress = form.querySelector('[data-fetch-progress]');
    form.setAttribute('aria-busy', 'true');
    const controls = [...form.querySelectorAll('button:enabled, input[type=checkbox]:enabled')];
    controls.forEach(control => { control.disabled = true; });
    let done = 0;
    let failed = 0;
    for (const box of selected) {
        const row = box.closest('[data-fetch-url]');
        const result = row.querySelector('[data-fetch-result]');
        progress.textContent = `Fetching ${done + 1} of ${selected.length}…`;
        result.textContent = 'Fetching…';
        try {
            const body = new URLSearchParams({ _token: form.elements._token.value, workspace: form.dataset.workspace, branch: box.dataset.branch, upstream: box.dataset.upstream });
            const response = await fetch(row.dataset.fetchUrl, { method: 'POST', headers: { Accept: 'application/json' }, body });
            if (!response.ok) throw new Error('Fetch unavailable. Refresh the list and try again.');
            const data = await response.json();
            const link = document.createElement('a');
            link.href = data.url;
            link.textContent = data.ok ? (data.ahead === null ? 'Inspect branch' : (data.ahead || data.behind ? `${data.ahead} ahead · ${data.behind} behind` : 'Up to date')) : 'Inspect branch';
            result.replaceChildren(...(data.ok ? [] : [data.notice + ' ']), link);
            if (!data.ok) failed++;
        } catch (error) {
            failed++;
            result.textContent = error.message;
        }
        done++;
    }
    progress.textContent = `Fetched ${done - failed} of ${done} repositories.`;
    form.removeAttribute('aria-busy');
    controls.forEach(control => { control.disabled = false; });
    selection(form);
});
