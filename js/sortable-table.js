document.addEventListener('DOMContentLoaded', function() {
    document.addEventListener('click', function(event) {
        const link = event.target.closest('.sortable-header-link');
        if (!link) {
            return;
        }

        const table = link.closest('table');
        if (!table || !table.id) {
            return;
        }

        const url = new URL(link.getAttribute('href'), window.location.href);
        event.preventDefault();

        fetch(url.toString(), {
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('Failed to load sorted table');
                }
                return response.text();
            })
            .then(function(html) {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const nextTable = doc.getElementById(table.id);

                if (!nextTable) {
                    throw new Error('Sorted table not found');
                }

                table.outerHTML = nextTable.outerHTML;
                document.dispatchEvent(new CustomEvent('sortableTable:replaced', {
                    detail: {
                        tableId: table.id,
                        url: url.toString()
                    }
                }));
                window.history.replaceState({}, '', url.pathname + url.search);
            })
            .catch(function() {
                window.location.href = url.toString();
            });
    });
});