import { enableFocusRefresh } from './focus-refresh.js';
import { initializeCloud } from './cloud.js';
import { initializeReview } from './review.js';

const loadingPanel = document.querySelector('body > .loading-panel');
const statusMessage = document.querySelector('#navigation-status');
let activeRequest;
let preload;
let skeletonTimer;
let neighborTimer;
let workspaceRequest;

// Each scan batch extends the previous tree in display order. Keep existing rows
// connected so links retain focus and expanded or collapsed folders stay as chosen.
function appendWorkspaceRows(list, nextList) {
    for (const [index, item] of [...nextList.children].entries()) {
        const current = list.children[index];
        if (!current) {
            list.append(item);
            continue;
        }
        const nextBranch = item.firstElementChild;
        if (nextBranch.tagName !== 'DETAILS') continue;
        const branch = current.firstElementChild;
        if (branch.tagName === 'DETAILS') {
            appendWorkspaceRows(branch.lastElementChild, nextBranch.lastElementChild);
        } else {
            const focused = branch.contains(document.activeElement) ? document.activeElement : null;
            nextBranch.firstElementChild.replaceChildren(...branch.childNodes);
            branch.replaceWith(nextBranch);
            focused?.focus({ preventScroll: true });
        }
    }
}

async function scanWorkspace() {
    const results = document.querySelector('[data-scan-url]');
    if (!results) return;
    const controller = new AbortController();
    workspaceRequest = controller;
    try {
        while (results.dataset.scanUrl && results.isConnected) {
            const { page } = await loadPage(results.dataset.scanUrl, controller.signal);
            const next = page.querySelector('[data-workspace-results]');
            if (controller.signal.aborted || !results.isConnected) return;
            if (!next) throw new Error('Workspace unavailable');
            if (next.dataset.scanUrl && next.dataset.repositoryCount === results.dataset.repositoryCount) continue;
            const list = results.querySelector('[data-workspace-list]');
            const nextList = next.querySelector('[data-workspace-list]');
            if (!nextList) {
                results.replaceWith(next);
                return;
            }
            const tree = list.querySelector('.tree');
            const nextTree = nextList.querySelector('.tree');
            if (tree && nextTree) appendWorkspaceRows(tree, nextTree);
            else list.replaceChildren(...nextList.childNodes);
            results.querySelector('.scan-status').replaceChildren(...next.querySelector('.scan-status').childNodes);
            results.dataset.repositoryCount = next.dataset.repositoryCount;
            if (!next.dataset.scanUrl) {
                delete results.dataset.scanUrl;
                results.querySelector('[data-all-changes]').replaceWith(next.querySelector('[data-all-changes]'));
            }
        }
    } catch {
        if (controller.signal.aborted || !results.isConnected) return;
        const message = results.querySelector('.scan-status');
        message.textContent = 'Workspace scan interrupted. ';
        const retry = document.createElement('a');
        retry.href = '/';
        retry.textContent = 'Scan again';
        message.append(retry);
    }
}

function resetNavigation() {
    clearTimeout(skeletonTimer);
    document.body.append(loadingPanel);
    loadingPanel.hidden = true;
    const main = document.querySelector('main');
    main.classList.remove('loading-content');
    main.removeAttribute('aria-busy');
    document.body.classList.remove('navigating');
    statusMessage.textContent = '';
}

function indicateLoading() {
    resetNavigation();
    const main = document.querySelector('main');
    main.setAttribute('aria-busy', 'true');
    document.body.classList.add('navigating');
    statusMessage.textContent = 'Loading…';
    skeletonTimer = setTimeout(() => {
        const panel = main.querySelector('.panel, .empty');
        if (!panel) return;
        panel.after(loadingPanel);
        loadingPanel.hidden = false;
        main.classList.add('loading-content');
    }, 150);
}

async function loadPage(url, signal, headers = {}) {
    const response = await fetch(url, { signal, headers, cache: 'no-store' });
    if (!response.ok) throw new Error('Navigation failed');
    const page = new DOMParser().parseFromString(await response.text(), 'text/html');
    if (!page.querySelector('main') || !page.querySelector('header')) throw new Error('Unexpected page');
    return { page, url: response.url };
}

function discardPreload() {
    clearTimeout(neighborTimer);
    preload?.controller.abort();
    preload = null;
}

function warmPage(link, neighbor = false) {
    const key = link.href.split('#')[0];
    if (preload?.key === key && performance.now() < preload.until) return;
    discardPreload();
    const controller = new AbortController();
    preload = {
        key, until: performance.now() + (neighbor ? 15000 : 2000), controller,
        review: link.dataset.prefetch === 'review',
        result: loadPage(key, controller.signal, { 'X-GitoBob-Preview': '1' }).catch(() => null),
    };
}

function prefetch(event) {
    const link = event.target.closest('a[data-prefetch]');
    if (link && !activeRequest) warmPage(link);
}

function scheduleNeighbor() {
    discardPreload();
    const link = document.querySelector('[data-local-navigation] [data-step="j"][href]');
    if (!link || document.hidden) return;
    neighborTimer = setTimeout(() => {
        if (link.isConnected && !activeRequest && !document.hidden) warmPage(link, true);
    }, 80);
}

function initializePageReview() {
    initializeReview(scheduleNeighbor);
}

document.addEventListener('pointerover', prefetch);
document.addEventListener('focusin', prefetch);
document.addEventListener('click', event => {
    const link = event.target.closest('a[href]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey ||
        event.ctrlKey || event.shiftKey || event.altKey || link.target || link.hasAttribute('download')) return;
    const next = new URL(link.href);
    if (next.origin !== location.origin || (next.hash && next.pathname === location.pathname && next.search === location.search)) return;
    event.preventDefault();

    navigate(next);
});

async function navigate(next, filterField = null) {
    workspaceRequest?.abort();
    cancelRefresh();
    clearTimeout(filterTimer);
    activeRequest?.abort();
    clearTimeout(neighborTimer);
    const warm = preload?.key === next.href.split('#')[0] && performance.now() < preload.until ? preload : null;
    if (!warm) preload?.controller.abort();
    preload = null;
    const controller = warm?.controller ?? new AbortController();
    activeRequest = controller;
    indicateLoading();

    try {
        const prefetched = warm ? await warm.result : null;
        const loaded = prefetched && warm.review
            ? await loadPage(next, controller.signal, { 'X-GitoBob-Warmed': '1' })
            : prefetched ?? await loadPage(next, controller.signal);
        if (controller.signal.aborted) return;
        const target = new URL(loaded.url);
        target.hash = next.hash;
        const main = loaded.page.querySelector('main');
        const scrollTop = next.pathname === location.pathname
            ? document.querySelector('.review-files')?.scrollTop ?? 0
            : 0;
        resetNavigation();
        if (target.href !== location.href) {
            if (filterField) history.replaceState(null, '', target);
            else history.pushState(null, '', target);
        }
        document.querySelector('header').replaceWith(loaded.page.querySelector('header'));
        document.querySelector('main').replaceWith(main);
        document.documentElement.dataset.theme = loaded.page.documentElement.dataset.theme;
        document.body.className = loaded.page.body.className;
        document.title = loaded.page.title;
        scanWorkspace();
        initializeCloud();
        initializePageReview();
        const files = main.querySelector('.review-files');
        if (files) files.scrollTop = scrollTop;
        main.tabIndex = -1;
        const focus = filterField && document.activeElement === document.body
            ? main.querySelector(`[data-pull-filter] [name="${filterField}"]`) : null;
        (focus ?? main).focus({ preventScroll: true });
        if (focus?.type === 'search') focus.setSelectionRange(filterCursor, filterCursor);
        const current = files?.querySelector('[aria-current=true]');
        if (current && (main.querySelector('[data-pull-filter]') || main.querySelector('[data-local-navigation]'))) current.scrollIntoView({ block: 'nearest' });
        const anchor = target.hash && document.getElementById(target.hash.slice(1));
        if (anchor) anchor.scrollIntoView({ block: 'nearest' });
        else window.scrollTo(0, 0);
    } catch {
        if (!controller.signal.aborted) location.assign(next);
    } finally {
        if (activeRequest === controller) {
            activeRequest = null;
            resetNavigation();
        }
    }
}

let filterTimer;
let filterCursor = 0;

function applyPullFilter(form, field) {
    const url = new URL(form.action);
    url.search = new URLSearchParams(new FormData(form));
    if (!url.searchParams.get('filter')) url.searchParams.delete('filter');
    navigate(url, field);
}

document.addEventListener('input', event => {
    const form = event.target.closest('[data-pull-filter]');
    if (!form) return;
    cancelNavigation();
    if (event.isComposing) return;
    const field = event.target.name;
    filterCursor = event.target.selectionStart ?? 0;
    filterTimer = setTimeout(() => applyPullFilter(form, field), field === 'hide' ? 0 : 180);
});

document.addEventListener('submit', event => {
    if (!event.target.matches('[data-pull-filter]')) return;
    event.preventDefault();
    applyPullFilter(event.target, 'filter');
});

function cancelNavigation() {
    cancelRefresh();
    clearTimeout(filterTimer);
    activeRequest?.abort();
    discardPreload();
    activeRequest = null;
    resetNavigation();
}

window.addEventListener('pagehide', () => { workspaceRequest?.abort(); cancelNavigation(); });
window.addEventListener('popstate', () => {
    cancelNavigation();
    location.reload();
});
document.addEventListener('submit', event => { if (!event.defaultPrevented) cancelNavigation(); });
window.addEventListener('keydown', event => {
    if (event.key === 'Escape' && activeRequest) {
        cancelNavigation();
        scanWorkspace();
    }
    if (event.defaultPrevented || event.repeat || event.isComposing || event.metaKey || event.ctrlKey || event.altKey || event.shiftKey ||
        event.target.isContentEditable || event.target.closest('input, textarea, select')) return;
    const shortcuts = {
        j: '[data-step="j"][href]', k: '[data-step="k"][href]',
        n: '[data-hunk-navigation]:not([hidden]) [data-hunk-step="1"]:enabled',
        p: '[data-hunk-navigation]:not([hidden]) [data-hunk-step="-1"]:enabled',
    };
    if (!Object.hasOwn(shortcuts, event.key)) return;
    const step = document.querySelector(shortcuts[event.key]);
    if (step && !activeRequest) {
        event.preventDefault();
        step.click();
    }
});
initializePageReview();
initializeCloud();

scanWorkspace();

const cancelRefresh = enableFocusRefresh(initializePageReview, () => Boolean(activeRequest));
window.addEventListener('blur', discardPreload);
window.Native?.on('Native\\Desktop\\Events\\Windows\\WindowBlurred', discardPreload);
document.addEventListener('visibilitychange', () => { if (document.hidden) discardPreload(); });

document.addEventListener('submit', event => {
    if (event.target.matches('[data-index-form]')) {
        event.target.elements.filter.value = document.querySelector('.file-filter input')?.value ?? '';
    }
});

document.addEventListener('change', event => {
    if (event.target.matches('.settings-form input[name="theme"]')) {
        document.documentElement.dataset.theme = event.target.value;
    }
});
