function initializeHunks() {
    const nav = document.querySelector('[data-hunk-navigation]');
    if (!nav) return;
    const pane = nav.closest('.review-diff');
    const hunks = [...pane.querySelectorAll('.patch-row.hunk')];
    if (hunks.length < 2) return;

    const previous = nav.querySelector('[data-hunk-step="-1"]');
    const next = nav.querySelector('[data-hunk-step="1"]');
    const position = nav.querySelector('[data-hunk-position]');
    const toolbar = nav.closest('.pull-controls');
    let current = 0;
    let lastScroll = pane.scrollTop;

    function select(index) {
        hunks[current].classList.remove('current-hunk');
        current = index;
        hunks[index].classList.add('current-hunk');
        position.textContent = `Change ${index + 1} of ${hunks.length}`;
        previous.disabled = index === 0;
        next.disabled = index === hunks.length - 1;
    }

    function visibleTop() {
        return pane.getBoundingClientRect().top + toolbar.offsetHeight;
    }

    nav.hidden = false;
    select(Math.max(0, hunks.findIndex(row => row.classList.contains('current-hunk'))));
    nav.addEventListener('click', event => {
        const button = event.target.closest('button[data-hunk-step]');
        if (!button || button.disabled) return;
        select(current + Number(button.dataset.hunkStep));
        pane.scrollTop += hunks[current].getBoundingClientRect().top - visibleTop();
        lastScroll = pane.scrollTop;
    });
    pane.addEventListener('scroll', () => {
        if (lastScroll === pane.scrollTop) return;
        lastScroll = pane.scrollTop;
        const top = visibleTop() + 1;
        const index = hunks.findLastIndex(hunk => hunk.getBoundingClientRect().top <= top);
        select(Math.max(0, index));
    }, { passive: true });
}

function updateFileNavigation(controls, files, filter) {
    if (!controls) return;
    const visible = files.filter(file => !file.row.hidden);
    const current = files.findIndex(file => file.selected);
    const position = visible.findIndex(file => file.selected);
    const before = files.slice(0, current).findLast(file => !file.row.hidden);
    const after = files.slice(current + 1).find(file => !file.row.hidden);

    function setTarget(key, file) {
        const link = controls.querySelector(`[data-step="${key}"]`);
        if (!file) {
            link.removeAttribute('href');
            link.setAttribute('aria-disabled', 'true');
            return;
        }
        const url = new URL(file.url);
        if (filter) url.searchParams.set('filter', filter);
        url.hash = 'diff';
        link.href = url.href;
        link.removeAttribute('aria-disabled');
    }

    setTarget('k', before);
    setTarget('j', after);
    controls.querySelector('[data-file-position]').textContent = position >= 0
        ? `File ${position + 1} of ${visible.length}`
        : `${visible.length} matching ${visible.length === 1 ? 'file' : 'files'}`;
    controls.hidden = false;
}

export function initializeReview(onFileNavigation) {
    initializeHunks();
    const filterBar = document.querySelector('.file-filter');

    if (filterBar) {
        const input = filterBar.querySelector('input');
        const empty = document.querySelector('.no-matches');
        const lists = document.querySelectorAll('.files');
        const rows = [...document.querySelectorAll('.files li')].map(row => {
            const directory = row.querySelector('.file-dir')?.textContent;
            const name = row.querySelector('.filename').textContent;
            const origin = row.querySelector('.file-origin')?.textContent ?? '';
            const path = directory ? directory + '/' + name : name;

            const link = row.querySelector('a');
            return { row, url: link.href.split('#')[0], selected: link.hasAttribute('aria-current'), search: (path + '\n' + origin).toLowerCase() };
        });

        // A partially staged file has two sidebar rows but only one review position.
        const firstRows = new Map();
        for (const file of rows) {
            if (!firstRows.has(file.url)) firstRows.set(file.url, file);
        }
        const ordered = [...firstRows.values()];
        const controls = document.querySelector('[data-local-navigation]');

        function filterFiles() {
            const query = input.value.toLowerCase();
            for (const { row, search } of rows) {
                row.hidden = !search.includes(query);
            }
            for (const list of lists) {
                list.hidden = !list.querySelector('li:not([hidden])');
                const heading = list.previousElementSibling;
                if (heading?.matches('.section-title')) heading.hidden = list.hidden;
            }
            for (const repository of document.querySelectorAll('.repository-files')) {
                repository.hidden = !repository.querySelector('.files:not([hidden])');
            }
            empty.hidden = [...lists].some(list => !list.hidden);
            updateFileNavigation(controls, ordered, input.value);
            onFileNavigation();
        }

        filterBar.hidden = false;
        input.value = new URLSearchParams(location.search).get('filter') ?? '';
        filterFiles();

        input.addEventListener('input', () => {
            filterFiles();
            const url = new URL(location.href);
            if (input.value) url.searchParams.set('filter', input.value);
            else url.searchParams.delete('filter');
            history.replaceState(null, '', url);
        });

        // Carry the filter through file selection, layout switches and refresh.
        document.querySelector('main').addEventListener('click', event => {
            const link = event.target.closest('a[href]');
            if (!link) return;
            const url = new URL(link.href);
            if (url.pathname !== location.pathname) return;
            if (input.value) url.searchParams.set('filter', input.value);
            else url.searchParams.delete('filter');
            link.href = url.href;
        });
    }

    const wrapLines = document.querySelector('#wrap-lines');
    if (wrapLines) {
        wrapLines.checked = sessionStorage.getItem('wrap-lines') === 'true';
        wrapLines.addEventListener('change', () => {
            sessionStorage.setItem('wrap-lines', String(wrapLines.checked));
        });
    }
}
