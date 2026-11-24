import './bootstrap';

// Light/dark theme: follows the OS unless the viewer picks one (remembered per browser).
const THEME_KEY = 'dcp-theme';

function applyStoredTheme() {
    try {
        const t = localStorage.getItem(THEME_KEY);
        if (t === 'light' || t === 'dark') document.documentElement.dataset.theme = t;
    } catch (_) { /* storage blocked: follow OS */ }
}

window.dcpToggleTheme = function () {
    const root = document.documentElement;
    const current = root.dataset.theme
        || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    const next = current === 'dark' ? 'light' : 'dark';
    root.dataset.theme = next;
    try { localStorage.setItem(THEME_KEY, next); } catch (_) { /* ignore */ }
};

applyStoredTheme();
