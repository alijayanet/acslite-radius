/**
 * Security Helper for ACS-Lite
 * Provides API key management and secure headers for API requests
 */

const ACSAuth = {
    // Get API key from session
    getApiKey: function () {
        const session = sessionStorage.getItem('acs_session');
        if (session) {
            try {
                const sessionData = JSON.parse(session);
                return sessionData.apikey || null;
            } catch (e) {
                console.error('Error parsing session:', e);
                return null;
            }
        }
        return null;
    },

    // Get authorization headers for API requests
    getHeaders: function () {
        const apiKey = this.getApiKey();
        const headers = {
            'Content-Type': 'application/json'
        };

        if (apiKey) {
            headers['X-API-Key'] = apiKey;
            headers['Authorization'] = `Bearer ${apiKey}`;
        }

        return headers;
    },

    // Check if user is authenticated
    isAuthenticated: function () {
        const session = sessionStorage.getItem('acs_session');
        if (!session) return false;

        try {
            const sessionData = JSON.parse(session);
            const now = Date.now();

            // Check if session expired
            if (sessionData.expiry && sessionData.expiry < now) {
                this.logout();
                return false;
            }

            return true;
        } catch (e) {
            return false;
        }
    },

    // Logout and clear session
    logout: function () {
        sessionStorage.removeItem('acs_session');
        window.location.href = 'login.html';
    },

    // Save API key to session after login
    saveApiKey: function (apikey) {
        const session = sessionStorage.getItem('acs_session');
        if (session) {
            try {
                const sessionData = JSON.parse(session);
                sessionData.apikey = apikey;
                sessionStorage.setItem('acs_session', JSON.stringify(sessionData));
            } catch (e) {
                console.error('Error saving API key:', e);
            }
        }
    }
};

// Export for use in other scripts
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ACSAuth;
}
