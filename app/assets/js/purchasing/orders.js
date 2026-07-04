/**
 * table_query.js
 * Universal table filtering, sorting, and export functionality
 * Works with any data table across the application
 */

(function() {
    'use strict';

    // Wait for DOM to be fully loaded
    document.addEventListener('DOMContentLoaded', function() {
        const tableId = window.table_id || '#ordersTable';
        const targetCols = window.target_cols || 7;
        const table = document.querySelector(tableId);
        
        if (!table) {
            console.log('Table not found:', tableId);
            return;
        }

        // Initialize search functionality
        initSearch(table);
        
        // Initialize column sorting
        initSorting(table);
        
        // Initialize row highlighting
        initRowHighlighting(table);
        
        // Initialize export buttons if present
        initExport(table, targetCols);
        
        // Initialize pagination if needed
        initPagination(table);
        
        // Initialize row click handling if needed
        initRowClick(table);
    });

    /**
     * Initialize search/filter functionality
     */
    function initSearch(table) {
        // Create search input if it doesn't exist
        let searchInput = document.getElementById('table-search');
        
        if (!searchInput) {
            // Find the search input or create one
            const searchContainer = document.querySelector('.table-search-container');
            if (searchContainer) {
                searchInput = document.createElement('input');
                searchInput.type = 'text';
                searchInput.id = 'table-search';
                searchInput.className = 'form-control form-control-sm';
                searchInput.placeholder = 'Search table...';
                searchContainer.prepend(searchInput);
            }
        }

        if (searchInput) {
            searchInput.addEventListener('keyup', function(e) {
                filterTable(table, this.value.toLowerCase());
            });
        }
    }

    /**
     * Filter table rows based on search term
     */
    function filterTable(table, searchTerm) {
        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        const rows = tbody.querySelectorAll('tr');
        let visibleCount = 0;

        rows.forEach(row => {
            // Skip if it's a "no results" row or has colspan
            if (row.querySelector('td[colspan]')) {
                return;
            }

            const text = row.textContent.toLowerCase();
            const isVisible = searchTerm === '' || text.includes(searchTerm);
            
            row.style.display = isVisible ? '' : 'none';
            if (isVisible) visibleCount++;
        });

        // Show/hide "no results" message
        updateNoResultsMessage(table, visibleCount);
    }

    /**
     * Update or remove "no results" message
     */
    function updateNoResultsMessage(table, visibleCount) {
        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        const existingNoResult = tbody.querySelector('.no-results-row');
        
        if (visibleCount === 0) {
            if (!existingNoResult) {
                const colCount = table.querySelector('thead tr')?.children.length || 1;
                const noResultRow = document.createElement('tr');
                noResultRow.className = 'no-results-row';
                noResultRow.innerHTML = `
                    <td colspan="${colCount}" class="text-center py-4">
                        <div class="text-muted">
                            <i class="fas fa-search fa-2x mb-2"></i>
                            <p class="mb-0">No matching records found</p>
                            <small>Try adjusting your search or filters</small>
                        </div>
                    </td>
                `;
                tbody.appendChild(noResultRow);
            }
        } else if (existingNoResult) {
            existingNoResult.remove();
        }
    }

    /**
     * Initialize column sorting
     */
    function initSorting(table) {
        const headers = table.querySelectorAll('th');
        
        headers.forEach((header, index) => {
            // Skip action columns (usually last column or columns with no sort)
            const isActionColumn = header.textContent.toLowerCase().includes('action') ||
                                  header.querySelector('.btn-group') ||
                                  (index === headers.length - 1 && header.textContent.trim() === '');
            
            if (!isActionColumn) {
                header.style.cursor = 'pointer';
                header.classList.add('sortable');
                
                // Add sort icon container
                const sortIcon = document.createElement('span');
                sortIcon.className = 'sort-icon ms-1';
                sortIcon.innerHTML = '<i class="fas fa-sort"></i>';
                header.appendChild(sortIcon);
                
                header.addEventListener('click', function() {
                    sortTable(table, index, this);
                });
            }
        });
    }

    /**
     * Sort table by column
     */
    function sortTable(table, columnIndex, headerElement) {
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        
        // Skip if no rows or if it's a "no results" row
        if (rows.length === 0) return;
        
        // Filter out "no results" row
        const dataRows = rows.filter(row => !row.querySelector('td[colspan]'));
        if (dataRows.length === 0) return;
        
        // Determine sort direction
        const currentDirection = headerElement.getAttribute('data-sort');
        let direction = 'asc';
        
        if (currentDirection === 'asc') {
            direction = 'desc';
        } else if (currentDirection === 'desc') {
            direction = 'asc';
        }
        
        // Update sort icons
        clearSortIcons(table);
        updateSortIcon(headerElement, direction);
        
        // Sort rows
        dataRows.sort((a, b) => {
            let aValue = getCellValue(a, columnIndex);
            let bValue = getCellValue(b, columnIndex);
            
            // Handle numeric values
            if (!isNaN(parseFloat(aValue)) && !isNaN(parseFloat(bValue))) {
                aValue = parseFloat(aValue);
                bValue = parseFloat(bValue);
            } else {
                aValue = aValue.toString().toLowerCase();
                bValue = bValue.toString().toLowerCase();
            }
            
            if (direction === 'asc') {
                return aValue > bValue ? 1 : -1;
            } else {
                return aValue < bValue ? 1 : -1;
            }
        });
        
        // Reorder rows in DOM
        dataRows.forEach(row => tbody.appendChild(row));
    }

    /**
     * Get cell value for sorting
     */
    function getCellValue(row, columnIndex) {
        const cell = row.querySelectorAll('td')[columnIndex];
        if (!cell) return '';
        
        // Try to get data-sort-value attribute first
        if (cell.hasAttribute('data-sort-value')) {
            return cell.getAttribute('data-sort-value');
        }
        
        // Get text content, stripping out HTML tags and extra spaces
        let value = cell.textContent || cell.innerText || '';
        value = value.replace(/[^\w\s.-]/g, '').trim();
        
        // Handle currency values (remove RWF, $, etc.)
        value = value.replace(/[RWF$\s,]/g, '');
        
        return value;
    }

    /**
     * Clear all sort icons
     */
    function clearSortIcons(table) {
        const headers = table.querySelectorAll('th');
        headers.forEach(header => {
            header.removeAttribute('data-sort');
            const icon = header.querySelector('.sort-icon i');
            if (icon) {
                icon.className = 'fas fa-sort';
            }
        });
    }

    /**
     * Update sort icon for current column
     */
    function updateSortIcon(header, direction) {
        header.setAttribute('data-sort', direction);
        const icon = header.querySelector('.sort-icon i');
        if (icon) {
            icon.className = direction === 'asc' ? 'fas fa-sort-up' : 'fas fa-sort-down';
        }
    }

    /**
     * Initialize row highlighting on hover
     */
    function initRowHighlighting(table) {
        const rows = table.querySelectorAll('tbody tr');
        
        rows.forEach(row => {
            row.addEventListener('mouseenter', function() {
                this.classList.add('table-hover-highlight');
            });
            
            row.addEventListener('mouseleave', function() {
                this.classList.remove('table-hover-highlight');
            });
        });
    }

    /**
     * Initialize export functionality
     */
    function initExport(table, targetCols) {
        const exportBtn = document.getElementById('export-table');
        if (!exportBtn) return;
        
        exportBtn.addEventListener('click', function(e) {
            e.preventDefault();
            exportToCSV(table, targetCols);
        });
    }

    /**
     * Export table to CSV
     */
    function exportToCSV(table, targetCols) {
        const filename = `export_${new Date().toISOString().slice(0, 19)}.csv`;
        const headers = [];
        const rows = [];
        
        // Get headers
        const headerRow = table.querySelectorAll('thead tr th');
        headerRow.forEach((th, index) => {
            if (targetCols && index >= targetCols) return;
            let headerText = th.textContent.trim();
            // Remove sort icons if present
            headerText = headerText.replace(/[↑↓↕]/g, '').trim();
            headers.push(headerText);
        });
        rows.push(headers);
        
        // Get data rows
        const dataRows = table.querySelectorAll('tbody tr');
        dataRows.forEach(row => {
            // Skip "no results" row
            if (row.querySelector('td[colspan]')) return;
            
            const rowData = [];
            const cells = row.querySelectorAll('td');
            
            cells.forEach((cell, index) => {
                if (targetCols && index >= targetCols) return;
                
                let cellText = cell.textContent.trim();
                
                // Handle special cells
                if (cell.querySelector('.badge')) {
                    cellText = cell.querySelector('.badge').textContent.trim();
                } else if (cell.querySelector('.progress')) {
                    const progressText = cell.querySelector('.progress + small')?.textContent || '';
                    cellText = progressText;
                }
                
                // Escape CSV
                if (cellText.includes(',') || cellText.includes('"') || cellText.includes('\n')) {
                    cellText = '"' + cellText.replace(/"/g, '""') + '"';
                }
                
                rowData.push(cellText);
            });
            
            if (rowData.length > 0) {
                rows.push(rowData);
            }
        });
        
        // Create and download CSV
        const csvContent = rows.map(row => row.join(',')).join('\n');
        const blob = new Blob(["\uFEFF" + csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        
        link.setAttribute('href', url);
        link.setAttribute('download', filename);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    /**
     * Initialize pagination
     */
    function initPagination(table) {
        const rowsPerPageSelect = document.getElementById('rows-per-page');
        if (!rowsPerPageSelect) return;
        
        let currentPage = 1;
        let rowsPerPage = parseInt(rowsPerPageSelect.value);
        
        function paginate() {
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            // Filter out "no results" row
            const dataRows = rows.filter(row => !row.querySelector('td[colspan]'));
            const totalPages = Math.ceil(dataRows.length / rowsPerPage);
            
            // Hide all rows first
            dataRows.forEach(row => row.style.display = 'none');
            
            // Show rows for current page
            const start = (currentPage - 1) * rowsPerPage;
            const end = start + rowsPerPage;
            dataRows.slice(start, end).forEach(row => row.style.display = '');
            
            // Update pagination controls
            updatePaginationControls(totalPages, currentPage, (page) => {
                currentPage = page;
                paginate();
            });
        }
        
        rowsPerPageSelect.addEventListener('change', function() {
            rowsPerPage = parseInt(this.value);
            currentPage = 1;
            paginate();
        });
        
        // Initial pagination
        paginate();
    }

    /**
     * Update pagination controls
     */
    function updatePaginationControls(totalPages, currentPage, onPageChange) {
        let paginationContainer = document.getElementById('table-pagination');
        
        if (!paginationContainer) {
            // Create pagination container if it doesn't exist
            const tableContainer = document.querySelector('.card-body');
            if (tableContainer && totalPages > 1) {
                paginationContainer = document.createElement('div');
                paginationContainer.id = 'table-pagination';
                paginationContainer.className = 'd-flex justify-content-center mt-3';
                tableContainer.appendChild(paginationContainer);
            } else {
                return;
            }
        }
        
        if (totalPages <= 1) {
            paginationContainer.innerHTML = '';
            return;
        }
        
        let paginationHtml = '<nav><ul class="pagination pagination-sm">';
        
        // Previous button
        paginationHtml += `
            <li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
                <a class="page-link" href="#" data-page="${currentPage - 1}">« Previous</a>
            </li>
        `;
        
        // Page numbers
        const maxVisible = 5;
        let startPage = Math.max(1, currentPage - Math.floor(maxVisible / 2));
        let endPage = Math.min(totalPages, startPage + maxVisible - 1);
        
        if (endPage - startPage + 1 < maxVisible) {
            startPage = Math.max(1, endPage - maxVisible + 1);
        }
        
        if (startPage > 1) {
            paginationHtml += `
                <li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>
                ${startPage > 2 ? '<li class="page-item disabled"><span class="page-link">...</span></li>' : ''}
            `;
        }
        
        for (let i = startPage; i <= endPage; i++) {
            paginationHtml += `
                <li class="page-item ${i === currentPage ? 'active' : ''}">
                    <a class="page-link" href="#" data-page="${i}">${i}</a>
                </li>
            `;
        }
        
        if (endPage < totalPages) {
            paginationHtml += `
                ${endPage < totalPages - 1 ? '<li class="page-item disabled"><span class="page-link">...</span></li>' : ''}
                <li class="page-item"><a class="page-link" href="#" data-page="${totalPages}">${totalPages}</a></li>
            `;
        }
        
        // Next button
        paginationHtml += `
            <li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
                <a class="page-link" href="#" data-page="${currentPage + 1}">Next »</a>
            </li>
        `;
        
        paginationHtml += '</ul></nav>';
        paginationContainer.innerHTML = paginationHtml;
        
        // Add click handlers
        paginationContainer.querySelectorAll('.page-link').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const page = parseInt(this.getAttribute('data-page'));
                if (!isNaN(page) && page !== currentPage && page >= 1 && page <= totalPages) {
                    onPageChange(page);
                }
            });
        });
    }

    /**
     * Initialize row click handling (for making rows clickable)
     */
    function initRowClick(table) {
        const rows = table.querySelectorAll('tbody tr');
        
        rows.forEach(row => {
            // Skip if row has colspan (no results row)
            if (row.querySelector('td[colspan]')) return;
            
            // Check if row has data-href attribute
            const href = row.getAttribute('data-href');
            if (href) {
                row.style.cursor = 'pointer';
                row.addEventListener('click', function(e) {
                    // Don't navigate if clicking on a button or link
                    if (e.target.tagName === 'A' || e.target.tagName === 'BUTTON' || 
                        e.target.closest('a') || e.target.closest('button')) {
                        return;
                    }
                    window.location.href = href;
                });
            }
        });
    }

})();

// Add CSS styles for table enhancements
const tableStyles = document.createElement('style');
tableStyles.textContent = `
    .table-hover-highlight {
        background-color: #f8f9fa !important;
        transition: background-color 0.2s ease;
    }
    
    .sortable {
        user-select: none;
        transition: background-color 0.2s;
    }
    
    .sortable:hover {
        background-color: #f8f9fa;
    }
    
    .sort-icon {
        display: inline-block;
        margin-left: 5px;
        font-size: 0.8em;
        opacity: 0.6;
    }
    
    .sortable:hover .sort-icon {
        opacity: 1;
    }
    
    #table-search {
        max-width: 300px;
        margin-bottom: 15px;
    }
    
    .table-responsive {
        overflow-x: auto;
    }
    
    @media (max-width: 768px) {
        .table {
            font-size: 0.85rem;
        }
        
        .btn-group-sm .btn {
            padding: 0.2rem 0.4rem;
            font-size: 0.7rem;
        }
    }
`;

document.head.appendChild(tableStyles);