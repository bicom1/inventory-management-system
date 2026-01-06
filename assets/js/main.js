// BI Inventory System - Main JavaScript

// SweetAlert Helper Functions
function showAlert(type, title, message, timer = 3000) {
    const iconMap = {
        'success': 'success',
        'danger': 'error',
        'warning': 'warning',
        'info': 'info'
    };
    
    Swal.fire({
        icon: iconMap[type] || 'info',
        title: title,
        text: message,
        timer: timer,
        timerProgressBar: true,
        showConfirmButton: true,
        confirmButtonText: 'OK',
        confirmButtonColor: '#667eea'
    });
}

function confirmDelete(message = 'Are you sure you want to delete this item? This action cannot be undone.') {
    return Swal.fire({
        title: 'Are you sure?',
        text: message,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel'
    });
}

// Confirm delete actions
document.addEventListener('DOMContentLoaded', function() {
    // Replace Bootstrap alerts with SweetAlert
    const alertElements = document.querySelectorAll('.alert[data-swal]');
    alertElements.forEach(alert => {
        const type = alert.classList.contains('alert-success') ? 'success' :
                     alert.classList.contains('alert-danger') ? 'danger' :
                     alert.classList.contains('alert-warning') ? 'warning' : 'info';
        const message = alert.textContent.trim();
        const icon = alert.querySelector('i');
        const title = icon ? icon.nextSibling?.textContent?.trim() || 'Notification' : 'Notification';
        
        if (message) {
            showAlert(type, title, message);
            alert.remove();
        }
    });
    
    // Confirm delete with SweetAlert
    const deleteButtons = document.querySelectorAll('[data-confirm-delete]');
    deleteButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const message = button.getAttribute('data-message') || 'Are you sure you want to delete this item? This action cannot be undone.';
            confirmDelete(message).then((result) => {
                if (result.isConfirmed) {
                    // If it's a form, submit it
                    const form = button.closest('form');
                    if (form) {
                        form.submit();
                    } else if (button.href) {
                        window.location.href = button.href;
                    }
                }
            });
        });
    });
    
    // Handle form delete confirmations
    const deleteForms = document.querySelectorAll('form[data-confirm-delete]');
    deleteForms.forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const message = form.getAttribute('data-message') || 'Are you sure you want to delete this item? This action cannot be undone.';
            confirmDelete(message).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });
    
    // Form validation
    const forms = document.querySelectorAll('.needs-validation');
    forms.forEach(form => {
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        });
    });
    
    // Sidebar toggle for mobile
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const mobileSidebarToggle = document.getElementById('mobileSidebarToggle');
    
    function toggleSidebar() {
        if (sidebar && mainContent) {
            sidebar.classList.toggle('active');
            mainContent.classList.toggle('sidebar-open');
        }
    }
    
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', toggleSidebar);
    }
    
    if (mobileSidebarToggle) {
        mobileSidebarToggle.addEventListener('click', toggleSidebar);
    }
    
    // Close sidebar when clicking outside on mobile
    if (window.innerWidth < 768) {
        document.addEventListener('click', function(e) {
            if (sidebar && sidebar.classList.contains('active')) {
                if (!sidebar.contains(e.target) && 
                    (!mobileSidebarToggle || !mobileSidebarToggle.contains(e.target))) {
                    toggleSidebar();
                }
            }
        });
    }
});

// Format numbers with commas
function formatNumber(num) {
    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}

// Show loading spinner
function showLoading(element) {
    element.innerHTML = '<div class="loading"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>';
}

