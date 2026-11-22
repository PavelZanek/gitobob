import { t } from './locale.js';
let activeCloudRequest;

export function initializeCloud() {
    activeCloudRequest?.abort();
    const view = document.querySelector('main[data-cloud-url]');
    if (!view) return;

    const content = view.querySelector('[data-cloud-content]');
    const notice = view.querySelector('[data-cloud-status]');
    const refresh = view.querySelector('[data-cloud-refresh]');
    const filter = view.querySelector('[data-cloud-filter]');
    function filterRepositories() {
        if (!filter) return;
        const query = filter.value.trim().toLowerCase();
        const rows = content.querySelectorAll('[data-repository-row]');
        let matches = 0;
        for (const row of rows) {
            row.hidden = !row.textContent.toLowerCase().includes(query);
            if (!row.hidden) matches++;
        }
        const empty = content.querySelector('[data-no-repositories]');
        if (empty) empty.hidden = matches > 0;
    }
    filter?.addEventListener('input', filterRepositories);
    const formatter = new Intl.DateTimeFormat(document.documentElement.lang, { dateStyle: 'medium', timeStyle: 'short' });
    function formatTimes() {
        for (const time of content.querySelectorAll('time')) {
            time.textContent = formatter.format(new Date(time.dateTime));
        }
    }
    async function update() {
        activeCloudRequest?.abort();
        const controller = new AbortController();
        activeCloudRequest = controller;
        notice.hidden = false;
        notice.textContent = t('Updating from GitHub…');
        refresh.disabled = true;
        try {
            const response = await fetch(view.dataset.cloudUrl, { signal: controller.signal, cache: 'no-store' });
            const body = await response.text();
            if (controller.signal.aborted) return;
            if (!response.ok) {
                if (response.status === 401 || response.status === 403) content.replaceChildren();
                notice.textContent = response.headers.get('Content-Type')?.startsWith('text/plain')
                    ? body : t('Could not load GitHub data. Try refreshing.');
                return;
            }
            content.innerHTML = body;
            formatTimes();
            filterRepositories();
            notice.hidden = true;
        } catch {
            if (!controller.signal.aborted) notice.textContent = t('Could not load GitHub data. Try refreshing.');
        } finally {
            if (!controller.signal.aborted) refresh.disabled = false;
        }
    }
    refresh.addEventListener('click', update);
    formatTimes();
    update();
}
