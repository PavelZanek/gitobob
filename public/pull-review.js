import { t } from './locale.js';
async function loadSummary(panel) {
    const label = panel.querySelector('[data-summary-label]');
    try {
        const response = await fetch(panel.dataset.reviewSummary, { cache: 'no-store' });
        if (!response.ok) throw new Error(response.status === 503 ? await response.text() : t('Review status unavailable. Refresh to retry.'));
        const html = new DOMParser().parseFromString(await response.text(), 'text/html');
        label.textContent = html.querySelector('[data-result-label]').textContent;
        panel.querySelector('[data-summary-details]').replaceChildren(html.querySelector('[data-result-details]'));
    } catch (error) {
        label.textContent = error.message;
    }
}

function threadForm(nav, thread, operation) {
    const form = nav.querySelector('[data-thread-form]').content.firstElementChild.cloneNode(true);
    form.elements.thread.value = thread.id;
    form.elements.submission.value = crypto.randomUUID();
    form.elements.operation.value = operation;
    form.querySelector('button').textContent = operation === 'resolve' ? t('Resolve conversation') : t('Post reply');
    if (operation === 'resolve') return form;
    const field = document.createElement('textarea');
    field.name = 'body';
    field.required = true;
    field.maxLength = 65536;
    field.rows = 2;
    field.setAttribute('aria-label', t('Reply to conversation'));
    form.prepend(field);
    const details = document.createElement('details');
    const summary = document.createElement('summary');
    summary.textContent = t('Reply');
    details.append(summary, form);
    return details;
}

async function loadThreads(nav) {
    const status = nav.querySelector('[data-thread-count]');
    try {
        const response = await fetch(nav.dataset.url, { cache: 'no-store' });
        if (!response.ok) throw new Error(response.status === 409 ? t('The diff changed. Refresh to see conversations.') : t('Conversations unavailable. Refresh to retry.'));
        const { threads, comments } = await response.json();
        if (!nav.isConnected) return;
        const open = threads.filter(thread => !thread.isResolved);
        status.textContent = t(open.length === 1 ? ':count unresolved conversation' : ':count unresolved conversations', { count: open.length });
        const active = nav.dataset.thread;
        const next = nav.querySelector('[data-next-thread]');
        if (open.length) {
            const current = threads.findIndex(thread => thread.id === active);
            const target = threads.slice(current + 1).find(thread => !thread.isResolved) ?? open[0];
            next.href = target.url;
            next.hidden = false;
        }
        for (const thread of threads) {
            if (thread.path !== nav.dataset.file && thread.id !== active) continue;
            const root = thread.comments.nodes[0];
            if (!root) continue;
            const row = !thread.isOutdated && thread.path === nav.dataset.file && Number.isInteger(thread.line)
                ? document.querySelector(`.patch-row[data-${thread.diffSide === 'LEFT' ? 'old' : 'new'}="${thread.line}"]`) : null;
            const card = document.createElement('section');
            card.className = 'line-thread';
            const heading = document.createElement('strong');
            heading.className = 'thread-state';
            heading.textContent = [thread.isResolved ? t('Resolved') : t('Open conversation'), row ? null : thread.path, thread.isOutdated ? t('Outdated') : null].filter(Boolean).join(' · ');
            card.append(heading);
            for (const entry of [{ author: root.author?.login, body: root.body }, ...comments.filter(c => c.parent === root.databaseId)]) {
                const author = document.createElement('strong');
                author.textContent = entry.author ?? t('Deleted account');
                const body = document.createElement('p');
                body.textContent = entry.body;
                card.append(author, body);
            }
            const actions = document.createElement('div');
            actions.className = 'thread-actions';
            if (thread.viewerCanReply) actions.append(threadForm(nav, thread, 'reply'));
            if (!thread.isResolved && thread.viewerCanResolve) actions.append(threadForm(nav, thread, 'resolve'));
            card.append(actions);
            if (row) row.after(card);
            else nav.querySelector('[data-detached-threads]').append(card);
            if (thread.id === active) {
                card.tabIndex = -1;
                card.focus({ preventScroll: true });
                card.scrollIntoView({ block: 'center' });
            }
        }
    } catch (error) {
        status.textContent = error.message;
    }
}

function enableLineEditor(pane) {
    const template = pane.querySelector('[data-line-template]');
    let form;
    let trigger;
    const drafts = new Map();

    function closeEditor(remember) {
        if (!form) return;
        const key = trigger.dataset.side + trigger.dataset.line;
        if (remember) drafts.set(key, form.elements.body.value);
        else drafts.delete(key);
        form.remove();
        form = null;
    }

    pane.addEventListener('click', event => {
        if (event.target.closest('[data-cancel-line]')) {
            closeEditor(false);
            trigger.focus();
            return;
        }
        const button = event.target.closest('button[data-line]');
        if (!button) return;
        closeEditor(true);
        trigger = button;
        form = template.content.firstElementChild.cloneNode(true);
        form.elements.submission.value = crypto.randomUUID();
        form.elements.side.value = button.dataset.side;
        form.elements.line.value = button.dataset.line;
        form.querySelector('[data-line-label]').textContent = button.getAttribute('aria-label');
        form.elements.body.value = drafts.get(button.dataset.side + button.dataset.line) ?? '';
        button.closest('.patch-row').after(form);
        form.elements.body.focus();
    });
}

export function initializePullReview() {
    const panel = document.querySelector('[data-review-summary]');
    if (panel) loadSummary(panel);
    const nav = document.querySelector('[data-threads]');
    if (nav) loadThreads(nav);
    const pane = document.querySelector('[data-line-review]');
    if (pane) {
        enableLineEditor(pane);
    }
}

document.addEventListener('submit', event => {
    const form = event.target;
    if (!form.matches('[data-review-write]') || event.defaultPrevented) return;
    if (form.getAttribute('aria-busy') === 'true') {
        event.preventDefault();
        return;
    }
    form.setAttribute('aria-busy', 'true');
    const button = form.querySelector('button');
    button.textContent = t('Sending…');
    button.disabled = true;
});
window.addEventListener('pageshow', event => {
    if (event.persisted && document.querySelector('[data-review-write][aria-busy]')) location.reload();
});
