export function initTableSorter() {
    const tables = document.querySelectorAll('.wp-block-table.is-sortable table, .wp-block-table.is-style-sortable table, .m3-table--sortable table');

    tables.forEach(table => {
        const headers = table.querySelectorAll('th');
        headers.forEach((header, index) => {
            if (header.dataset.m3SortReady === 'true') return;
            header.dataset.m3SortReady = 'true';
            header.setAttribute('aria-sort', 'none');

            let button = header.querySelector(':scope > button.m3-table-sort-btn');
            if (!button) {
                button = document.createElement('button');
                button.type = 'button';
                button.className = 'm3-table-sort-btn';
                button.style.cssText = 'all:inherit;display:inline-flex;align-items:center;gap:4px;width:100%;cursor:pointer;background:transparent;border:0;padding:0;text-align:inherit;';
                while (header.firstChild) {
                    button.appendChild(header.firstChild);
                }
                header.appendChild(button);
            }

            button.addEventListener('click', () => {
                const tbody = table.querySelector('tbody');
                if (!tbody) return;
                const rows = Array.from(tbody.querySelectorAll('tr'));
                const isAscending = header.classList.contains('is-asc');

                headers.forEach(h => {
                    h.classList.remove('is-asc', 'is-desc');
                    h.setAttribute('aria-sort', 'none');
                });

                rows.sort((a, b) => {
                    const aText = a.children[index]?.textContent.trim() || '';
                    const bText = b.children[index]?.textContent.trim() || '';

                    const aNum = parseFloat(aText.replace(/[^0-9.-]/g, ''));
                    const bNum = parseFloat(bText.replace(/[^0-9.-]/g, ''));

                    if (!isNaN(aNum) && !isNaN(bNum)) {
                        return isAscending ? bNum - aNum : aNum - bNum;
                    }
                    return isAscending ? bText.localeCompare(aText, 'ja') : aText.localeCompare(bText, 'ja');
                });

                header.classList.add(isAscending ? 'is-desc' : 'is-asc');
                header.setAttribute('aria-sort', isAscending ? 'descending' : 'ascending');
                while (tbody.firstChild) tbody.removeChild(tbody.firstChild);
                tbody.append(...rows);
            });
        });
    });
}
