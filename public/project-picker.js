const dialog = document.querySelector('[data-project-picker]');
const input = dialog.querySelector('[data-project-search]');
const options = dialog.querySelector('[data-project-options]');
const status = dialog.querySelector('[data-project-status]');
let catalog = [];
let loadedScope;
let request;
let active = 0;

function readHistory(scope) {
    try { return JSON.parse(localStorage.getItem('gitobob:recent:' + scope)) ?? []; }
    catch { return []; }
}

function remember(main) {
    if (!main.isConnected) return;
    const scope = main.dataset.workspace;
    const history = readHistory(scope).filter(item => item.repo !== main.dataset.project);
    const pane = main.querySelector('.review-diff');
    history.unshift({ repo: main.dataset.project, name: main.querySelector('h1').textContent, file: main.dataset.file,
        top: pane?.scrollTop ?? 0, windowTop: window.scrollY });
    localStorage.setItem('gitobob:recent:' + scope, JSON.stringify(history.slice(0, 40)));
}

function projectUrl(repo, scope, href = dialog.dataset.projectUrl) {
    const url = new URL(href);
    url.searchParams.set('repo', repo);
    const last = readHistory(scope).find(item => item.repo === repo);
    if (last?.file) {
        url.searchParams.set('file', last.file);
        url.searchParams.set('resume', '1');
    }
    return url;
}

export function initializeProjectPicker() {
    if (!document.querySelector('header [data-open-picker]')) {
        const button = document.createElement('button');
        button.type = 'button';
        button.dataset.openPicker = '';
        button.setAttribute('aria-label', 'Go to project or branch');
        button.textContent = 'Go to… ⌘K';
        const header = document.querySelector('header');
        header.insertBefore(button, header.querySelector('.settings-link'));
    }
    const main = document.querySelector('main[data-project]');
    if (!main || main.dataset.historyBound) return;
    main.dataset.historyBound = '1';
    const last = readHistory(main.dataset.workspace).find(item => item.repo === main.dataset.project);
    const restore = new URLSearchParams(location.search).has('resume') && last?.file === main.dataset.file;
    requestAnimationFrame(() => {
        if (!main.isConnected) return;
        const pane = main.querySelector('.review-diff');
        if (restore) {
            if (pane) pane.scrollTop = last.top;
            window.scrollTo(0, last.windowTop);
        }
        remember(main);
    });
}

function highlight(index, scroll) {
    const rows = [...options.children];
    active = Math.max(0, Math.min(index, rows.length - 1));
    rows.forEach((row, i) => row.classList.toggle('active-option', i === active));
    if (scroll) rows[active]?.scrollIntoView({ block: 'nearest' });
}

function render() {
    const scope = dialog.dataset.workspace;
    const history = readHistory(scope);
    const text = input.value.trim().toLowerCase();
    const terms = text.split(/\s+/);
    const recent = new Map(history.map((item, i) => [item.repo, i]));
    const known = loadedScope === scope ? catalog : history.map(item => ({ ...item, branches: [], current: '' }));
    const projects = [...known].sort((a, b) => (recent.get(a.repo) ?? Infinity) - (recent.get(b.repo) ?? Infinity) || a.name.localeCompare(b.name));
    const matches = [];
    for (const project of projects) {
        if (!text || project.name.toLowerCase().includes(text) || project.repo.toLowerCase().includes(text)) {
            matches.push({ label: project.name, detail: project.current, url: projectUrl(project.repo, scope) });
        }
    }
    if (text) {
        for (const project of projects) {
            for (const branch of project.branches) {
                const branchName = branch.toLowerCase();
                const searchable = project.name.toLowerCase() + ' ' + branchName;
                if (!terms.some(term => branchName.includes(term)) || !terms.every(term => searchable.includes(term))) continue;
                const url = new URL(dialog.dataset.branchesUrl);
                url.searchParams.set('repo', project.repo);
                url.searchParams.set('find', branch);
                matches.push({ label: branch, detail: project.name + ' · Local branch', url });
            }
        }
    }
    options.replaceChildren();
    for (const match of matches.slice(0, 80)) {
        const link = document.createElement('a');
        link.href = match.url;
        const title = document.createElement('strong');
        title.textContent = match.label;
        const detail = document.createElement('span');
        detail.textContent = match.detail;
        link.append(title, detail);
        options.append(link);
    }
    status.textContent = request ? 'Loading workspace…' : (matches.length ? (matches.length > 80 ? 'Showing the first 80 matches' : (text ? matches.length + (matches.length === 1 ? ' match' : ' matches') : 'Recent projects first')) : 'No projects or branches found.');
    highlight(0, false);
}

async function openPicker() {
    if (dialog.open) return;
    const main = document.querySelector('main[data-project]');
    if (main) remember(main);
    dialog.showModal();
    input.value = '';
    input.focus();
    const controller = new AbortController();
    request = controller;
    render();
    try {
        const response = await fetch(dialog.dataset.catalog, { signal: controller.signal, cache: 'no-store' });
        if (!response.ok) throw new Error();
        const data = await response.json();
        if (controller.signal.aborted) return;
        loadedScope = data.workspace;
        catalog = data.projects;
        dialog.dataset.workspace = loadedScope;
    } catch {
        if (!controller.signal.aborted) status.textContent = 'Could not load the workspace. Reopen to retry.';
        return;
    } finally {
        if (request === controller) request = null;
    }
    render();
}

dialog.addEventListener('close', () => { request?.abort(); request = null; });
dialog.querySelector('[data-close-picker]').addEventListener('click', () => dialog.close());
input.addEventListener('input', render);
options.addEventListener('click', event => { if (event.target.closest('a')) dialog.close(); });
document.addEventListener('keydown', event => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k' && !event.altKey) {
        event.preventDefault();
        openPicker();
    } else if (dialog.open && event.key === 'Escape') {
        event.preventDefault();
        dialog.close();
    } else if (dialog.open && event.target === input) {
        if (['ArrowDown', 'ArrowUp'].includes(event.key)) {
            event.preventDefault();
            highlight(active + (event.key === 'ArrowDown' ? 1 : -1), true);
        } else if (event.key === 'Enter' && !event.isComposing) {
            event.preventDefault();
            options.children[active]?.click();
        }
    }
});
document.addEventListener('click', event => {
    if (event.target.closest('[data-open-picker]')) openPicker();
    const main = document.querySelector('main[data-project]');
    if (main && event.target.closest('a[href]')) remember(main);
    const project = event.target.closest('a[data-open-project]');
    if (project) project.href = projectUrl(project.dataset.openProject, dialog.dataset.workspace, project.href);
}, true);
let savePosition;
document.addEventListener('scroll', event => {
    const main = document.querySelector('main[data-project]');
    if (!main || (event.target !== document && !event.target.matches?.('.review-diff'))) return;
    clearTimeout(savePosition);
    savePosition = setTimeout(() => remember(main), 200);
}, true);
window.addEventListener('pagehide', () => {
    const main = document.querySelector('main[data-project]');
    if (main) remember(main);
});
