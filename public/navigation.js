import { initializeCloud } from './cloud.js';
import { initializeReview } from './review.js';

const loadingPanel = document.querySelector('.loading-panel');
const statusMessage = document.querySelector('#navigation-status');
let activeRequest;
let preload;
let skeletonTimer;

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

// Only warm the workspace overview on intent. Refresh and layout links have side effects.
function prefetch(event) {
    const link = event.target.closest('a[data-prefetch]');
    if (!link || activeRequest) return;
    if (preload?.url === link.href && performance.now() < preload.until) return;
    preload?.controller.abort();
    const controller = new AbortController();
    preload = {
        url: link.href, until: performance.now() + 2000, controller,
        review: link.dataset.prefetch === 'review',
        result: loadPage(link.href, controller.signal, { 'X-GitoBob-Preview': '1' }).catch(() => null),
    };
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
    clearTimeout(filterTimer);
    activeRequest?.abort();
    const warm = preload?.url === next.href && performance.now() < preload.until ? preload : null;
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
        document.body.className = loaded.page.body.className;
        document.title = loaded.page.title;
        initializeCloud();
        initializeReview();
        const files = main.querySelector('.review-files');
        if (files) files.scrollTop = scrollTop;
        main.tabIndex = -1;
        const focus = filterField && document.activeElement === document.body
            ? main.querySelector(`[data-pull-filter] [name="${filterField}"]`) : null;
        (focus ?? main).focus({ preventScroll: true });
        if (focus?.type === 'search') focus.setSelectionRange(filterCursor, filterCursor);
        const current = files?.querySelector('[aria-current=true]');
        if (current && main.querySelector('[data-pull-filter]')) current.scrollIntoView({ block: 'nearest' });
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
    clearTimeout(filterTimer);
    activeRequest?.abort();
    preload?.controller.abort();
    activeRequest = preload = null;
    resetNavigation();
}

window.addEventListener('pagehide', cancelNavigation);
window.addEventListener('popstate', () => {
    cancelNavigation();
    location.reload();
});
document.addEventListener('submit', event => { if (!event.defaultPrevented) cancelNavigation(); });
window.addEventListener('keydown', event => {
    if (event.key === 'Escape') cancelNavigation();
    if (event.defaultPrevented || event.repeat || event.isComposing || event.metaKey || event.ctrlKey || event.altKey || event.shiftKey ||
        event.target.isContentEditable || event.target.closest('input, textarea, select')) return;
    const shortcuts = {
        j: '[data-step="j"]', k: '[data-step="k"]',
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
initializeReview();
initializeCloud();
