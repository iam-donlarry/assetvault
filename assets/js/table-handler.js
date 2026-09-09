/**
 * table-handler.js
 * Provides client-side sorting and column-based filtering for .table elements
 */

document.addEventListener('DOMContentLoaded', () => {
    // Auto-init all tables with class 'table' unless they have 'no-features'
    const tables = document.querySelectorAll('table.table:not(.no-features)');
    tables.forEach(table => initTableFeatures(table));
});

function initTableFeatures(table) {
    if (table.dataset.tableInited) return;
    table.dataset.tableInited = 'true';

    const thead = table.querySelector('thead');
    const tbody = table.querySelector('tbody');
    if (!thead || !tbody) return;

    const headers = thead.querySelectorAll('th');
    const rows = Array.from(tbody.querySelectorAll('tr'));

    headers.forEach((th, index) => {
        // Skip if header is empty or has 'no-sort'
        if (!th.innerText.trim() || th.classList.contains('no-sort')) return;

        // 1. Setup Sorting
        th.style.cursor = 'pointer';
        th.classList.add('sortable-header');
        const sortIcon = document.createElement('span');
        sortIcon.className = 'sort-icon';
        sortIcon.innerHTML = '<ion-icon name="caret-up-outline" class="up"></ion-icon><ion-icon name="caret-down-outline" class="down"></ion-icon>';
        th.appendChild(sortIcon);

        th.addEventListener('click', (e) => {
            if (e.target.closest('.filter-container')) return;
            const isAsc = th.classList.contains('sort-asc');
            sortTable(table, index, !isAsc);
            headers.forEach(h => h.classList.remove('sort-asc', 'sort-desc'));
            th.classList.add(!isAsc ? 'sort-asc' : 'sort-desc');
        });

        // 2. Setup Filtering
        if (!th.classList.contains('no-filter')) {
            const filterContainer = document.createElement('div');
            filterContainer.className = 'filter-container';
            filterContainer.innerHTML = `
                <button type="button" class="filter-btn" title="Filter this column">
                    <ion-icon name="filter-outline"></ion-icon>
                </button>
                <div class="filter-popover" style="display: none;">
                    <input type="text" placeholder="Search ${th.innerText.trim()}..." class="filter-input">
                </div>
            `;

            const btn = filterContainer.querySelector('.filter-btn');
            const popover = filterContainer.querySelector('.filter-popover');
            const input = filterContainer.querySelector('.filter-input');

            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                // Close other popovers
                table.querySelectorAll('.filter-popover').forEach(p => {
                    if (p !== popover) p.style.display = 'none';
                });

                // Smart Positioning: If column is in the first half, align left, else align right
                if (index < headers.length / 2) {
                    popover.style.left = '0';
                    popover.style.right = 'auto';
                } else {
                    popover.style.right = '0';
                    popover.style.left = 'auto';
                }

                popover.style.display = popover.style.display === 'none' ? 'block' : 'none';
                if (popover.style.display === 'block') input.focus();
            });

            input.addEventListener('click', (e) => e.stopPropagation());
            input.addEventListener('input', () => {
                applyFilters(table);
            });

            th.appendChild(filterContainer);
        }
    });

    // Close popovers on outside click
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.filter-container')) {
            document.querySelectorAll('.filter-popover').forEach(p => p.style.display = 'none');
        }
    });
}

function sortTable(table, columnIndex, asc = true) {
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr:not(.no-data)'));

    const sortedRows = rows.sort((a, b) => {
        const aCol = a.children[columnIndex].innerText.trim();
        const bCol = b.children[columnIndex].innerText.trim();

        // Check if numeric
        const aNum = parseFloat(aCol.replace(/[^0-9.-]+/g, ""));
        const bNum = parseFloat(bCol.replace(/[^0-9.-]+/g, ""));

        if (!isNaN(aNum) && !isNaN(bNum) && !aCol.includes('/') && !aCol.includes('-')) {
            return asc ? aNum - bNum : bNum - aNum;
        }

        // Check if date (simple check for now)
        const aDate = Date.parse(aCol);
        const bDate = Date.parse(bCol);
        if (!isNaN(aDate) && !isNaN(bDate)) {
            return asc ? aDate - bDate : bDate - aDate;
        }

        return asc ? aCol.localeCompare(bCol) : bCol.localeCompare(aCol);
    });

    while (tbody.firstChild) {
        tbody.removeChild(tbody.firstChild);
    }

    tbody.append(...sortedRows);
}

function applyFilters(table) {
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr:not(.no-data)'));
    const filters = Array.from(table.querySelectorAll('.filter-input')).map(input => ({
        index: Array.from(input.closest('th').parentElement.children).indexOf(input.closest('th')),
        value: input.value.toLowerCase()
    })).filter(f => f.value);

    rows.forEach(row => {
        const isMatch = filters.every(f => {
            const cellText = row.children[f.index].innerText.toLowerCase();
            return cellText.includes(f.value);
        });
        row.style.display = isMatch ? '' : 'none';
    });

    // Handle 'No data' row visibility
    const noDataRow = tbody.querySelector('.no-data');
    if (noDataRow) {
        const anyVisible = rows.some(r => r.style.display !== 'none');
        noDataRow.style.display = anyVisible ? 'none' : '';
    }
}
