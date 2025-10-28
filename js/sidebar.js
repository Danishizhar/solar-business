/**
 * Sidebar menu functionality
 * Handles dropdown menus and active states
 */
document.addEventListener('DOMContentLoaded', function() {
    // Fix for sidebar dropdown issue
    const dropdownToggles = document.querySelectorAll('[data-bs-toggle="collapse"]');
    
    dropdownToggles.forEach(function(toggle) {
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Get the target submenu ID
            const targetId = this.getAttribute('href');
            const targetSubmenu = document.querySelector(targetId);
            
            // Close all other dropdowns except the current one
            document.querySelectorAll('.collapse.show').forEach(function(menu) {
                if (menu.id !== targetSubmenu.id) {
                    const bsCollapse = bootstrap.Collapse.getInstance(menu);
                    if (bsCollapse) {
                        bsCollapse.hide();
                    }
                }
            });
            
            // Toggle the current dropdown
            const bsCollapse = new bootstrap.Collapse(targetSubmenu, {
                toggle: true
            });
        });
    });
    
    // Set active class for current menu item
    const currentPath = window.location.pathname;
    const menuLinks = document.querySelectorAll('#sidebar ul li a');
    
    menuLinks.forEach(function(link) {
        const linkPath = link.getAttribute('href');
        if (linkPath && currentPath.includes(linkPath) && !link.classList.contains('dropdown-toggle')) {
            link.classList.add('active');
            
            // Expand parent dropdown if not already expanded
            const parentLi = link.closest('li').parentElement;
            if (parentLi && parentLi.classList.contains('collapse')) {
                const parentId = parentLi.id;
                const parentToggle = document.querySelector(`a[href="#${parentId}"]`);
                if (parentToggle) {
                    const bsCollapse = new bootstrap.Collapse(parentLi, {
                        show: true
                    });
                }
            }
        }
    });
});
