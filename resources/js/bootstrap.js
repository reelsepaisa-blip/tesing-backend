import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Interceptor to handle 401 responses and logout for customer app
window.axios.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response) {
            const { status, data, config } = error.response;

            // Check if it's a 401 error with token mismatch/invalid token
            if (status === 401) {
                const message = data?.message || '';
                const requiresLogout = data?.requires_logout === true ||
                    data?.error_code === 'TOKEN_INVALID' ||
                    data?.error_code === 'TOKEN_MISSING' ||
                    data?.error_code === 'TOKEN_MISMATCH' ||
                    message.toLowerCase().includes('token') ||
                    message.includes('logged in on another device');

                if (requiresLogout) {
                    // Check if this is a customer/user app request
                    // Check by URL pattern (starts with /api/user/) or by stored user role
                    const requestUrl = config?.url || error.config?.url || '';
                    const isUserEndpoint = requestUrl.includes('/api/user/') ||
                        requestUrl.includes('/api/bookings/') ||
                        requestUrl.includes('/api/trips/') ||
                        requestUrl.includes('/api/payments/') ||
                        requestUrl.includes('/api/locations/') ||
                        requestUrl.includes('/api/promos/');

                    // Also check stored user role
                    const userRole = localStorage.getItem('user_role') || sessionStorage.getItem('user_role');
                    const isCustomer = userRole === '3' || userRole === 'user' || isUserEndpoint;

                    // Always logout for user endpoints or if explicitly required
                    if (isCustomer || isUserEndpoint || data?.requires_logout === true) {
                        // Clear authentication data
                        localStorage.removeItem('bearer_token');
                        localStorage.removeItem('user');
                        localStorage.removeItem('user_role');
                        sessionStorage.clear();

                        // Clear axios default headers
                        delete window.axios.defaults.headers.common['Authorization'];

                        // Trigger logout event or redirect to login
                        if (typeof window.handleLogout === 'function') {
                            window.handleLogout();
                        } else {
                            // Default behavior: redirect to login
                            const loginPath = '/login';
                            if (window.location.pathname !== loginPath && !window.location.pathname.includes('/login')) {
                                window.location.href = loginPath;
                            }
                        }
                    }
                }
            }
        }

        return Promise.reject(error);
    }
);
