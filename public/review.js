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
    select(0);
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

export function initializeReview() {
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

            return { row, search: (path + '\n' + origin).toLowerCase() };
        });

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
