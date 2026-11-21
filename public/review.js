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
