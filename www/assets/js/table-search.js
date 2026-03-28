/**
 * Filter table body rows by a search input (case-insensitive substring match on row text).
 *
 * @param {string} searchInputId - Element id of the search field
 * @param {string} tableId - Element id of the table
 * @param {{ event?: 'input' | 'keyup' }} [options]
 * @returns {void}
 */
function initializeTableSearch(searchInputId, tableId, options) {
    const eventType = options && options.event ? options.event : 'input';
    const searchInput = document.getElementById(searchInputId);
    const table = document.getElementById(tableId);
    if (!searchInput || !table) {
        return;
    }
    searchInput.addEventListener(eventType, function() {
        const searchTerm = this.value.toLowerCase();
        const rows = table.querySelectorAll('tbody tr');
        rows.forEach(function(row) {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(searchTerm) ? '' : 'none';
        });
    });
}
