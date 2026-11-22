// Anchor the visible hunk to the pane even if another change inserts lines above it.
function restoreDiffPosition(previous, current) {
    const before = previous.querySelector('.review-diff');
    const after = current.querySelector('.review-diff');
    if (!before || !after || previous.dataset.file !== current.dataset.file) return () => {};
    const top = before.getBoundingClientRect().top;
    const patches = [...before.querySelectorAll('.patch')];
    const hunks = [...before.querySelectorAll('.hunk')];
    const anchor = hunks.findLast(row => row.getBoundingClientRect().top <= top + (before.querySelector('.pull-controls')?.offsetHeight ?? 0) + 1);
    const offset = anchor && anchor.getBoundingClientRect().top - top;
    const newPatches = [...after.querySelectorAll('.patch')];
    function matchingHunk(row) {
        if (!row) return null;
        const section = row.closest('.patch');
        const target = newPatches.find(node => node.getAttribute('aria-label') === section.getAttribute('aria-label'));
        const rows = [...(target?.querySelectorAll('.hunk') ?? [])];
        return rows.find(candidate => candidate.textContent === row.textContent)
            ?? rows[[...section.querySelectorAll('.hunk')].indexOf(row)];
    }
    const match = matchingHunk(anchor);
    matchingHunk(before.querySelector('.current-hunk'))?.classList.add('current-hunk');
    const scroll = before.scrollTop;
    const open = [...before.querySelectorAll('.patch-headers')].map(details => details.open);
    const horizontal = new Map(patches.map(node => [node.getAttribute('aria-label'), node.scrollLeft]));

    return () => {
        after.querySelectorAll('.patch-headers').forEach((details, index) => { details.open = open[index] ?? false; });
        for (const node of newPatches) node.scrollLeft = horizontal.get(node.getAttribute('aria-label')) ?? 0;
        after.scrollTop = match ? match.getBoundingClientRect().top - after.getBoundingClientRect().top - offset : scroll;
    };
}

export function enableFocusRefresh(initialize, navigating) {
    let timer;
    let pending;
    const lastCloudCheck = new Map();

    function cancel() {
        clearTimeout(timer);
        pending?.abort();
    }

    async function refresh(main) {
        if (document.hidden || !main.isConnected || navigating()) return;
        pending?.abort();
        const controller = new AbortController();
        pending = controller;
        const url = new URL(main.dataset.refreshUrl);
        const filter = new URLSearchParams(location.search).get('filter');
        if (filter) url.searchParams.set('filter', filter);
        if (main.dataset.refreshScope) lastCloudCheck.set(main.dataset.refreshScope, Date.now());
        try {
            const response = await fetch(url, { signal: controller.signal, cache: 'no-store', headers: { 'X-GitoBob-Revision': main.dataset.revision } });
            if (response.status === 204 || !response.ok) return;
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const next = page.querySelector('main.repository-review');
            if (main.querySelector('.inline-compose, .thread-actions details[open], [data-workflow-content]:not([hidden])') || !next || !main.isConnected || controller.signal.aborted || navigating()) return;

            const restoreDiff = restoreDiffPosition(main, next);
            const listTop = main.querySelector('.review-files')?.scrollTop ?? 0;
            const windowTop = window.scrollY;
            const field = document.activeElement;
            const focusSelector = ['#pr-comment', '.file-filter input', '[data-pull-filter] input[type=search]', '#wrap-lines', '[name=hide]', '.files [aria-current=true]'].find(selector => field?.matches(selector));
            const caret = (field?.type === 'search' || field?.type === 'textarea') ? [field.selectionStart, field.selectionEnd] : null;
            const canonical = new URL(next.dataset.refreshUrl ?? url);
            canonical.searchParams.delete('slot');
            if (!main.dataset.refreshScope) {
                const latestFilter = new URLSearchParams(location.search).get('filter');
                if (latestFilter) canonical.searchParams.set('filter', latestFilter);
            }
            canonical.hash = location.hash;
            history.replaceState(null, '', canonical);
            main.replaceWith(next);
            document.title = page.title;
            initialize();
            const files = next.querySelector('.review-files');
            if (files) files.scrollTop = listTop;
            restoreDiff();
            const input = focusSelector && next.querySelector(focusSelector);
            if (input) {
                input.focus({ preventScroll: true });
                if (caret) input.setSelectionRange(...caret);
            }
            window.scrollTo(0, windowTop);
        } catch (error) {
            if (!controller.signal.aborted) console.warn('Could not refresh the review.', error);
        } finally {
            if (pending === controller) pending = null;
        }
    }

    function enter() {
        clearTimeout(timer);
        if (document.hidden) return;
        const main = document.querySelector('main[data-refresh-url]');
        if (!main) return;
        const last = lastCloudCheck.get(main.dataset.refreshScope) ?? 0;
        timer = setTimeout(() => refresh(main), Math.max(150, last + 30000 - Date.now()));
    }

    window.Native?.on('Native\\Desktop\\Events\\Windows\\WindowFocused', enter);
    window.Native?.on('Native\\Desktop\\Events\\Windows\\WindowBlurred', cancel);
    window.addEventListener('blur', cancel);
    window.addEventListener('focus', enter);
    document.addEventListener('visibilitychange', () => document.hidden ? cancel() : enter());
    return cancel;
}
