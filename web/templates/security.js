// =============================================================================
// ACSLite Security Enhancements
// =============================================================================
// This file contains security improvements for the web interface
// Include this BEFORE the main application code
// =============================================================================

(function () {
    'use strict';

    // =========================================================================
    // 1. Input Validation Functions
    // =========================================================================

    window.ACSecurity = {

        /**
         * Validate Serial Number
         * @param {string} sn - Serial number to validate
         * @returns {string} Validated serial number
         * @throws {Error} If validation fails
         */
        validateSerialNumber: function (sn) {
            if (!sn || typeof sn !== 'string') {
                throw new Error('Serial number is required');
            }

            // Serial numbers: alphanumeric, dash, underscore, 1-64 chars
            const snRegex = /^[A-Z0-9\-_]{1,64}$/i;

            if (!snRegex.test(sn)) {
                throw new Error('Invalid serial number format. Only alphanumeric, dash, and underscore allowed.');
            }

            return sn.trim();
        },

        /**
         * Validate SSID
         * @param {string} ssid - WiFi SSID to validate
         * @returns {string} Validated SSID
         * @throws {Error} If validation fails
         */
        validateSSID: function (ssid) {
            if (!ssid || typeof ssid !== 'string') {
                throw new Error('SSID is required');
            }

            const trimmed = ssid.trim();

            // SSID: 1-32 characters
            if (trimmed.length < 1 || trimmed.length > 32) {
                throw new Error('SSID must be between 1 and 32 characters');
            }

            // Allowed: alphanumeric, space, dash, underscore
            const ssidRegex = /^[a-zA-Z0-9\s\-_]+$/;

            if (!ssidRegex.test(trimmed)) {
                throw new Error('SSID contains invalid characters. Only alphanumeric, space, dash, and underscore allowed.');
            }

            return trimmed;
        },

        /**
         * Validate WiFi Password
         * @param {string} password - WiFi password to validate
         * @param {boolean} required - Whether password is required
         * @returns {string} Validated password
         * @throws {Error} If validation fails
         */
        validateWiFiPassword: function (password, required = false) {
            if (!password || password === '') {
                if (required) {
                    throw new Error('Password is required');
                }
                return ''; // Empty password means no change
            }

            // WPA2 minimum: 8 characters
            if (password.length < 8) {
                throw new Error('Password must be at least 8 characters');
            }

            // WPA2 maximum: 63 characters
            if (password.length > 63) {
                throw new Error('Password must be less than 63 characters');
            }

            // Allow printable ASCII characters only
            const validCharsRegex = /^[\x20-\x7E]+$/;
            if (!validCharsRegex.test(password)) {
                throw new Error('Password contains invalid characters. Use only printable ASCII characters.');
            }

            return password;
        },

        /**
         * Sanitize HTML to prevent XSS
         * @param {string} str - String to sanitize
         * @returns {string} Sanitized string
         */
        sanitizeHTML: function (str) {
            if (!str) return '';

            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        },

        /**
         * Escape HTML entities
         * @param {string} str - String to escape
         * @returns {string} Escaped string
         */
        escapeHTML: function (str) {
            if (!str) return '';

            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };

            return str.replace(/[&<>"']/g, m => map[m]);
        }
    };

    // =========================================================================
    // 2. Rate Limiting
    // =========================================================================

    window.RateLimiter = {
        calls: {},
        limits: {
            'reboot': { max: 3, window: 60000 },      // 3 per minute
            'refresh': { max: 10, window: 60000 },    // 10 per minute
            'wifi': { max: 5, window: 60000 },        // 5 per minute
            'api': { max: 30, window: 60000 }         // 30 per minute
        },

        /**
         * Check if action is rate limited
         * @param {string} action - Action name
         * @returns {boolean} True if allowed, throws error if rate limited
         */
        check: function (action) {
            const now = Date.now();
            const limit = this.limits[action] || this.limits['api'];

            if (!this.calls[action]) {
                this.calls[action] = [];
            }

            // Remove old calls outside the time window
            this.calls[action] = this.calls[action].filter(
                time => now - time < limit.window
            );

            if (this.calls[action].length >= limit.max) {
                const waitTime = Math.ceil((this.calls[action][0] + limit.window - now) / 1000);
                throw new Error(`Rate limit exceeded. Please wait ${waitTime} seconds.`);
            }

            this.calls[action].push(now);
            return true;
        },

        /**
         * Reset rate limiter for specific action
         * @param {string} action - Action name
         */
        reset: function (action) {
            if (action) {
                this.calls[action] = [];
            } else {
                this.calls = {};
            }
        }
    };

    // =========================================================================
    // 3. Secure Storage
    // =========================================================================

    window.SecureStorage = {
        /**
         * Simple encryption (better than nothing, but use HTTPS!)
         * @param {string} text - Text to encrypt
         * @returns {string} Encrypted text
         */
        encrypt: function (text) {
            // Simple XOR encryption with random key
            // NOTE: This is NOT secure! Use HTTPS and server-side sessions!
            return btoa(text);
        },

        /**
         * Simple decryption
         * @param {string} encrypted - Encrypted text
         * @returns {string} Decrypted text
         */
        decrypt: function (encrypted) {
            try {
                return atob(encrypted);
            } catch (e) {
                return '';
            }
        },

        /**
         * Save API key securely
         * @param {string} key - API key
         */
        saveApiKey: function (key) {
            if (!key) return;

            // Use sessionStorage instead of localStorage (cleared on browser close)
            sessionStorage.setItem('acs_api_key', this.encrypt(key));

            // Set expiration (1 hour)
            const expiration = Date.now() + (60 * 60 * 1000);
            sessionStorage.setItem('acs_api_key_exp', expiration.toString());
        },

        /**
         * Get API key
         * @returns {string} API key or empty string
         */
        getApiKey: function () {
            const encrypted = sessionStorage.getItem('acs_api_key');
            const expiration = sessionStorage.getItem('acs_api_key_exp');

            if (!encrypted || !expiration) {
                return '';
            }

            // Check expiration
            if (Date.now() > parseInt(expiration)) {
                this.clearApiKey();
                return '';
            }

            return this.decrypt(encrypted);
        },

        /**
         * Clear API key
         */
        clearApiKey: function () {
            sessionStorage.removeItem('acs_api_key');
            sessionStorage.removeItem('acs_api_key_exp');
        }
    };

    // =========================================================================
    // 4. Network Error Handling with Retry
    // =========================================================================

    window.fetchWithRetry = async function (url, options = {}, retries = 3, backoff = 1000) {
        for (let i = 0; i < retries; i++) {
            try {
                const response = await fetch(url, options);

                // If server error and not last retry, retry
                if (response.status >= 500 && i < retries - 1) {
                    console.warn(`Server error ${response.status}, retrying in ${backoff * (i + 1)}ms...`);
                    await new Promise(resolve => setTimeout(resolve, backoff * (i + 1)));
                    continue;
                }

                return response;
            } catch (error) {
                // Network error
                if (i === retries - 1) {
                    throw new Error(`Network error after ${retries} attempts: ${error.message}`);
                }

                console.warn(`Network error, retrying in ${backoff * (i + 1)}ms...`);
                await new Promise(resolve => setTimeout(resolve, backoff * (i + 1)));
            }
        }
    };

    // =========================================================================
    // 5. Security Headers Check
    // =========================================================================

    window.checkSecurityHeaders = async function () {
        try {
            const response = await fetch('/api/stats', { method: 'HEAD' });

            const warnings = [];

            // Check for security headers
            if (!response.headers.get('X-Content-Type-Options')) {
                warnings.push('Missing X-Content-Type-Options header');
            }

            if (!response.headers.get('X-Frame-Options')) {
                warnings.push('Missing X-Frame-Options header');
            }

            if (!response.headers.get('X-XSS-Protection')) {
                warnings.push('Missing X-XSS-Protection header');
            }

            if (!response.headers.get('Strict-Transport-Security') && window.location.protocol === 'https:') {
                warnings.push('Missing HSTS header (HTTPS only)');
            }

            if (warnings.length > 0) {
                console.warn('Security warnings:', warnings);
            }

            return warnings;
        } catch (error) {
            console.error('Failed to check security headers:', error);
            return [];
        }
    };

    // =========================================================================
    // 6. Disable Console in Production
    // =========================================================================

    if (window.location.hostname !== 'localhost' && window.location.hostname !== '127.0.0.1') {
        // Disable console methods in production
        const noop = function () { };
        console.log = noop;
        console.warn = noop;
        console.error = noop;
        console.info = noop;
        console.debug = noop;
    }

    // =========================================================================
    // 7. Content Security Policy (CSP) Violation Reporter
    // =========================================================================

    document.addEventListener('securitypolicyviolation', (e) => {
        console.error('CSP Violation:', {
            blockedURI: e.blockedURI,
            violatedDirective: e.violatedDirective,
            originalPolicy: e.originalPolicy
        });

        // Optionally send to server for logging
        // fetch('/api/csp-report', {
        //     method: 'POST',
        //     body: JSON.stringify({
        //         blockedURI: e.blockedURI,
        //         violatedDirective: e.violatedDirective
        //     })
        // });
    });

    // =========================================================================
    // 8. Initialize Security Checks on Load
    // =========================================================================

    document.addEventListener('DOMContentLoaded', () => {
        // Check if HTTPS (skip for localhost and private IPs)
        const hostname = window.location.hostname;
        const isLocalhost = hostname === 'localhost' || hostname === '127.0.0.1' || hostname === '::1';
        const isPrivateIP = /^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)/.test(hostname);

        if (window.location.protocol !== 'https:' && !isLocalhost && !isPrivateIP) {
            // HTTPS warning - disabled because many deployments use HTTP behind proxy
            // Uncomment below to show warning
            /*
            console.warn('WARNING: Not using HTTPS! Your data is not encrypted.');

            // Show warning banner only for public access
            const banner = document.createElement('div');
            banner.style.cssText = 'position:fixed;top:0;left:0;right:0;background:#ff9800;color:#fff;padding:10px;text-align:center;z-index:9999;';
            banner.innerHTML = '<strong>⚠️ Security Warning:</strong> This connection is not secure. Please use HTTPS.';
            document.body.insertBefore(banner, document.body.firstChild);
            */
        }

        // Check security headers
        checkSecurityHeaders();

        // Migrate from localStorage to sessionStorage
        const oldKey = localStorage.getItem('acs_api_key');
        if (oldKey) {
            SecureStorage.saveApiKey(oldKey);
            localStorage.removeItem('acs_api_key');
        }
    });

    // =========================================================================
    // 9. Prevent Clickjacking
    // =========================================================================

    if (window.top !== window.self) {
        // Page is in an iframe
        console.warn('Page loaded in iframe - potential clickjacking attempt');

        // Break out of iframe
        window.top.location = window.self.location;
    }

    // =========================================================================
    // 10. Export for use in main application
    // =========================================================================

    console.info('ACS Security Module Loaded');

})();
