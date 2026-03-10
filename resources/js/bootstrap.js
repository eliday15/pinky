import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// CSRF Protection: Rely on axios' built-in XSRF-TOKEN cookie mechanism.
// Laravel's VerifyCsrfToken middleware sets an XSRF-TOKEN cookie on every response.
// Axios automatically reads this cookie and sends it as the X-XSRF-TOKEN header.
// This is superior to the meta tag approach because the cookie is refreshed on every
// server response, whereas the meta tag is only rendered once on initial page load
// in an Inertia SPA and becomes stale when the session rotates.

// Axios interceptor: catch 419 (CSRF token mismatch), refresh the token, and retry once.
window.axios.interceptors.response.use(
    (response) => response,
    async (error) => {
        const originalRequest = error.config;

        if (error.response?.status === 419 && !originalRequest._retried) {
            originalRequest._retried = true;

            try {
                // Sanctum endpoint refreshes the XSRF-TOKEN cookie
                await axios.get('/sanctum/csrf-cookie');

                // Retry the original request with the fresh cookie
                return axios(originalRequest);
            } catch {
                // Session is truly dead — redirect to login
                window.location.href = '/login';
                return Promise.reject(error);
            }
        }

        return Promise.reject(error);
    }
);
