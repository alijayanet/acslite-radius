/**
 * Theme Toggle Script
 * Supports dark/light theme switching with localStorage persistence
 */

(function () {
    'use strict';

    // Theme constants
    const THEME_KEY = 'acs_theme';
    const DARK_THEME = 'dark';
    const LIGHT_THEME = 'light';

    // Initialize theme on page load
    function initTheme() {
        const savedTheme = localStorage.getItem(THEME_KEY) || DARK_THEME;
        setTheme(savedTheme, false);
        createToggleButton();
    }

    // Set theme
    function setTheme(theme, animate = true) {
        if (animate) {
            document.body.style.transition = 'background-color 0.3s ease, color 0.3s ease';
        }

        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem(THEME_KEY, theme);

        // Update toggle button icon
        updateToggleIcon(theme);

        if (animate) {
            setTimeout(() => {
                document.body.style.transition = '';
            }, 300);
        }
    }

    // Toggle between themes
    function toggleTheme() {
        const currentTheme = document.documentElement.getAttribute('data-theme') || DARK_THEME;
        const newTheme = currentTheme === DARK_THEME ? LIGHT_THEME : DARK_THEME;
        setTheme(newTheme);

        // Trigger custom event for other components that may need to update
        window.dispatchEvent(new CustomEvent('themechange', { detail: { theme: newTheme } }));
    }

    // Create the floating toggle button
    function createToggleButton() {
        // Check if button already exists
        if (document.getElementById('theme-toggle-btn')) {
            return;
        }

        const button = document.createElement('button');
        button.id = 'theme-toggle-btn';
        button.className = 'theme-toggle';
        button.title = 'Toggle Theme (Dark/Light)';
        button.innerHTML = '<i class="fas fa-moon"></i>';
        button.addEventListener('click', toggleTheme);

        document.body.appendChild(button);

        // Update icon based on current theme
        const currentTheme = document.documentElement.getAttribute('data-theme') || DARK_THEME;
        updateToggleIcon(currentTheme);
    }

    // Update toggle button icon
    function updateToggleIcon(theme) {
        const button = document.getElementById('theme-toggle-btn');
        if (!button) return;

        const icon = button.querySelector('i');
        if (icon) {
            if (theme === LIGHT_THEME) {
                icon.className = 'fas fa-sun';
                button.title = 'Switch to Dark Theme';
            } else {
                icon.className = 'fas fa-moon';
                button.title = 'Switch to Light Theme';
            }
        }
    }

    // Get current theme
    function getCurrentTheme() {
        return document.documentElement.getAttribute('data-theme') || DARK_THEME;
    }

    // Expose functions globally for external use
    window.ThemeToggle = {
        toggle: toggleTheme,
        setTheme: setTheme,
        getCurrentTheme: getCurrentTheme,
        DARK: DARK_THEME,
        LIGHT: LIGHT_THEME
    };

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTheme);
    } else {
        initTheme();
    }
})();
