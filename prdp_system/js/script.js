// Add this to your existing script.js
// Global utility functions for PRDP Monitoring System

// Format number as currency
function formatCurrency(amount) {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(amount);
}

// Format date to local string
function formatDate(dateString) {
    if (!dateString) return '';
    return new Date(dateString).toLocaleDateString('en-PH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

// Show loading spinner
function showLoading() {
    $('body').append(`
        <div class="spinner-overlay">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>
    `);
}

// Hide loading spinner
function hideLoading() {
    $('.spinner-overlay').remove();
}

// Show notification toast
function showNotification(message, type = 'success') {
    const toastId = 'toast-' + Date.now();
    const bgColor = type === 'success' ? 'bg-success' : 
                   type === 'error' ? 'bg-danger' : 
                   type === 'warning' ? 'bg-warning' : 'bg-info';
    
    const toast = `
        <div id="${toastId}" class="toast align-items-center text-white ${bgColor} border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body">
                    ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    `;
    
    $('body').append(toast);
    const toastElement = new bootstrap.Toast(document.getElementById(toastId), {
        autohide: true,
        delay: 3000
    });
    toastElement.show();
    
    // Remove toast after it's hidden
    $(`#${toastId}`).on('hidden.bs.toast', function() {
        $(this).remove();
    });
}

// Export to CSV
function exportToCSV(data, filename) {
    const csvContent = data.map(row => 
        row.map(cell => {
            if (typeof cell === 'string' && cell.includes(',')) {
                return `"${cell}"`;
            }
            return cell;
        }).join(',')
    ).join('\n');
    
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    
    link.setAttribute('href', url);
    link.setAttribute('download', filename);
    link.style.visibility = 'hidden';
    
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Print report
function printReport(elementId) {
    const printContent = document.getElementById(elementId).innerHTML;
    const originalContent = document.body.innerHTML;
    
    document.body.innerHTML = printContent;
    window.print();
    document.body.innerHTML = originalContent;
    window.location.reload();
}

// Validate form inputs
function validateForm(formId) {
    let isValid = true;
    $(`#${formId} [required]`).each(function() {
        if (!$(this).val()) {
            $(this).addClass('is-invalid');
            isValid = false;
        } else {
            $(this).removeClass('is-invalid');
        }
    });
    return isValid;
}

// Calculate summary from table
function calculateTableSummary(tableId, columnIndex) {
    let total = 0;
    $(`#${tableId} tbody tr`).each(function() {
        const value = parseFloat($(this).find('td').eq(columnIndex).text().replace(/[^0-9.-]+/g, '')) || 0;
        total += value;
    });
    return total;
}

// Auto-complete for payee names (example implementation)
function initializePayeeAutocomplete(inputId, sourceUrl) {
    $(`#${inputId}`).autocomplete({
        source: function(request, response) {
            $.ajax({
                url: sourceUrl,
                data: { term: request.term },
                success: function(data) {
                    response(data);
                }
            });
        },
        minLength: 2
    });
}

// Chart configuration defaults
Chart.defaults.font.family = "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif";
Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(0, 0, 0, 0.8)';
Chart.defaults.plugins.tooltip.titleColor = '#fff';
Chart.defaults.plugins.tooltip.bodyColor = '#fff';
Chart.defaults.plugins.legend.labels.usePointStyle = true;

// Initialize all tooltips
$(document).ready(function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function(tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Initialize all popovers
    var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    popoverTriggerList.map(function(popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });
});
$(document).ready(function() {
    // Sidebar Toggle
    $('#sidebarCollapse').on('click', function() {
        $('#sidebar, #content').toggleClass('active');
        $('.collapse.in').toggleClass('in');
        $('a[aria-expanded=true]').attr('aria-expanded', 'false');
        
        // Save state to localStorage
        localStorage.setItem('sidebarCollapsed', $('#sidebar').hasClass('active'));
    });

    // Check saved sidebar state
    if (localStorage.getItem('sidebarCollapsed') === 'true') {
        $('#sidebar, #content').addClass('active');
    }

    // Auto-hide submenus when sidebar is collapsed
    $('#sidebar').on('hidden.bs.collapse', function() {
        if ($('#sidebar').hasClass('active')) {
            $('.collapse').collapse('hide');
        }
    });

    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();

    // Initialize popovers
    $('[data-toggle="popover"]').popover();

    // Smooth scroll to top
    $(window).scroll(function() {
        if ($(this).scrollTop() > 100) {
            $('.scroll-to-top').fadeIn();
        } else {
            $('.scroll-to-top').fadeOut();
        }
    });

    $('.scroll-to-top').click(function() {
        $('html, body').animate({scrollTop: 0}, 600);
        return false;
    });

    // Add scroll to top button if not exists
    if ($('.scroll-to-top').length === 0) {
        $('body').append('<button class="scroll-to-top btn btn-primary" style="display:none; position:fixed; bottom:20px; right:20px; z-index:9999;"><i class="fas fa-arrow-up"></i></button>');
    }

    // Handle window resize
    $(window).resize(function() {
        if ($(window).width() <= 768) {
            if (!localStorage.getItem('sidebarCollapsed')) {
                $('#sidebar, #content').addClass('active');
            }
        } else {
            if (localStorage.getItem('sidebarCollapsed') !== 'true') {
                $('#sidebar, #content').removeClass('active');
            }
        }
    });

    // Trigger resize on load
    $(window).resize();
});

// Custom scrollbar for sidebar
$('#sidebar').niceScroll({
    cursorcolor: "rgba(255,255,255,0.3)",
    cursorwidth: "5px",
    cursorborder: "none",
    autohidemode: false,
    background: "rgba(0,0,0,0.1)"
});