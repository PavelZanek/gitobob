async function loadSummary(panel) {
    const label = panel.querySelector('[data-summary-label]');
    try {
        const response = await fetch(panel.dataset.reviewSummary, { cache: 'no-store' });
        if (!response.ok) throw new Error(response.status === 503 ? await response.text() : 'Review status unavailable. Refresh to retry.');
        const html = new DOMParser().parseFromString(await response.text(), 'text/html');
        label.textContent = html.querySelector('[data-result-label]').textContent;
        panel.querySelector('[data-summary-details]').replaceChildren(html.querySelector('[data-result-details]'));
    } catch (error) {
        label.textContent = error.message;
    }
}

async function loadComments(pane) {
    const status = pane.querySelector('[data-line-status]');
    try {
        const response = await fetch(pane.dataset.commentsUrl, { cache: 'no-store' });
        if (!response.ok) throw new Error(response.status === 409 ? 'The diff changed. Refresh to see current comments.' : 'Line comments unavailable. Refresh to retry.');
        const comments = await response.json();
        const replies = new Map();
        for (const comment of comments) {
            if (!comment.parent) continue;
            if (!replies.has(comment.parent)) replies.set(comment.parent, []);
            replies.get(comment.parent).push(comment);
        }
        for (const root of comments) {
            if (root.parent || !Number.isInteger(root.line)) continue;
            const attr = root.side === 'LEFT' ? 'old' : 'new';
            const row = pane.querySelector(`.patch-row[data-${attr}="${root.line}"]`);
            if (!row) continue;
            const thread = document.createElement('section');
            thread.className = 'line-thread';
            for (const entry of [root, ...(replies.get(root.id) ?? [])]) {
                const author = document.createElement('strong');
                author.textContent = entry.author ?? 'Deleted account';
                const body = document.createElement('p');
                body.textContent = entry.body;
                thread.append(author, body);
            }
            row.after(thread);
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
    const pane = document.querySelector('[data-line-review]');
    if (pane) {
        enableLineEditor(pane);
        loadComments(pane);
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
    button.textContent = 'Sending…';
    button.disabled = true;
});
window.addEventListener('pageshow', event => {
    if (event.persisted && document.querySelector('[data-review-write][aria-busy]')) location.reload();
});
