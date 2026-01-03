/**
 * Bottom Navigation for Admin Pages
 * Handles active state management and navigation
 */

// Initialize bottom nav on page load
document.addEventListener('DOMContentLoaded', function () {
    initBottomNav();
});

/**
 * Initialize bottom navigation
 */
function initBottomNav() {
    // Get current page from URL
    const currentPage = getCurrentPage();

    // Set active state based on current page
    setActiveNavItem(currentPage);
}

/**
 * Get current page from URL
 * @returns {string} Current page identifier
 */
function getCurrentPage() {
    const path = window.location.pathname;
    const page = path.substring(path.lastIndexOf('/') + 1);

    // Map pages to nav items
    const pageMap = {
        'dashboard.html': 'dashboard',
        'customers.html': 'customers',
        'packages.html': 'customers',
        'invoices.html': 'customers',
        'payments.html': 'customers',
        'acs.html': 'network',
        'mikrotik.html': 'network',
        'map.html': 'network',
        'reports.html': 'reports',
        'settings.html': 'settings',
        'db_admin.html': 'settings'
    };

    return pageMap[page] || 'dashboard';
}

/**
 * Set active state for navigation item
 * @param {string} pageId - Page identifier
 */
function setActiveNavItem(pageId) {
    // Remove active class from all items
    const navItems = document.querySelectorAll('.bottom-nav-admin .nav-item-admin');
    navItems.forEach(item => {
        item.classList.remove('active');
    });

    // Add active class to current item
    const activeItem = document.getElementById(`nav-admin-${pageId}`);
    if (activeItem) {
        activeItem.classList.add('active');
    }
}

/**
 * Navigate to page and update active state
 * @param {string} pageId - Page identifier
 * @param {string} url - Target URL
 */
function navigateToPage(pageId, url) {
    // Add loading animation
    const navItem = document.getElementById(`nav-admin-${pageId}`);
    if (navItem) {
        const icon = navItem.querySelector('i');
        if (icon) {
            icon.classList.add('fa-spin');
        }
    }

    // Navigate to page
    window.location.href = url;
}
