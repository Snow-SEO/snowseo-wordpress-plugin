/**
 * SnowSEO API Service
 *
 * Utility module for calling WordPress REST API endpoints.
 * Uses the localized `snowseoData` object for base URL and nonce.
 */

const getRestUrl = () => window.snowseoData?.restUrl || '/wp-json/snowseo/v1/';
const getNonce = () => window.snowseoData?.nonce || '';

/**
 * Base fetch wrapper for WP REST API calls.
 */
async function apiFetch(endpoint, method = 'GET', body = null) {
    const primaryUrl = `${getRestUrl()}${endpoint}`;
    // E.g., if getRestUrl is '/wp-json/snowseo/v1/', fallbackUrl should be '/?rest_route=/snowseo/v1/endpoint'
    // Ensure we correctly assemble the rest_route parameter with query strings
    const baseSiteUrl = window.snowseoData?.siteUrl || '';

    let fallbackRestPath = `/snowseo/v1/${endpoint}`;
    let fallbackUrl = `${baseSiteUrl}/?rest_route=${fallbackRestPath}`;

    // Fix: If endpoint contains '?', replace the first '?' with '&' for the fallback URL
    // so it becomes `/?rest_route=/path&param=value` instead of `/?rest_route=/path?param=value`
    const queryIndex = endpoint.indexOf('?');
    if (queryIndex !== -1) {
        const pathPart = endpoint.substring(0, queryIndex);
        const queryPart = endpoint.substring(queryIndex + 1);
        fallbackRestPath = `/snowseo/v1/${pathPart}`;
        fallbackUrl = `${baseSiteUrl}/?rest_route=${fallbackRestPath}&${queryPart}`;
    }

    const options = {
        method,
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': getNonce(),
        },
    };

    if (body && method !== 'GET') {
        options.body = JSON.stringify(body);
    }

    let response = await fetch(primaryUrl, options);

    // Fallback to ?rest_route= if /wp-json/ is missing or returns 404/html
    if (!response.ok || response.status === 404 || response.headers.get('content-type')?.includes('text/html')) {
        response = await fetch(fallbackUrl, options);
    }

    let data;
    try {
        const text = await response.text();
        try {
            data = JSON.parse(text);
        } catch (parseErr) {
            throw new Error(`Invalid JSON from SnowSEO API: ${(parseErr && parseErr.message) || 'Parse error'}. Response: ${text.substring(0, 100)}`);
        }
    } catch (err) {
        if (err instanceof Error && err.message.startsWith('Invalid JSON')) throw err;
        throw new Error(`Failed to read response: ${err instanceof Error ? err.message : String(err)}`);
    }

    if (!response.ok) {
        throw new Error(data.message || data.error || `Request failed with status ${response.status}`);
    }

    return data;
}

/**
 * Connect to SnowSEO by validating the API key.
 * @param {string} apiKey - The plugin API key from SnowSEO
 */
export async function connectSite(apiKey) {
    return apiFetch('connect', 'POST', { apiKey });
}

/**
 * Disconnect from SnowSEO.
 */
export async function disconnectSite() {
    return apiFetch('disconnect', 'POST');
}

/**
 * Get current connection status.
 */
export async function getStatus() {
    return apiFetch('status');
}

/**
 * Get activity logs with pagination and filtering.
 */
export async function getLogs(page = 1, perPage = 20, filter = 'all') {
    return apiFetch(`logs?page=${page}&per_page=${perPage}&status=${filter}`);
}

/**
 * Get articles from SnowSEO with pagination and status filtering.
 */
export async function getArticles(page = 1, perPage = 20, status = 'all') {
    return apiFetch(`articles?page=${page}&per_page=${perPage}&status=${status}`);
}

/**
 * Get a single article by ID.
 */
export async function getArticle(id) {
    return apiFetch(`articles/${id}`);
}

/**
 * Publish an article to WordPress.
 */
export async function publishArticle(articleSlug, status = 'publish') {
    return apiFetch('publish', 'POST', { articleSlug, status });
}

/**
 * Get settings from SnowSEO API (proxied through WP REST)
 */
export async function getSettings() {
    return apiFetch('settings');
}

/**
 * Get computed log stats from local WP logs.
 */
export async function getLogStats() {
    return apiFetch('logs/stats');
}
