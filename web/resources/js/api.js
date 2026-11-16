// Appels JSON vers l'application, avec le jeton CSRF fourni par Laravel
function csrf() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export async function api(url, { method = 'GET', body } = {}) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(body ? { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrf() } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const first = data.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw new Error(first || data.message || 'Une erreur est survenue.');
    }
    return data;
}
