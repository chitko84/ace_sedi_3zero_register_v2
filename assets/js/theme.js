(function () {
    if (window.__zeroThemeLoaded) return;
    window.__zeroThemeLoaded = true;

    const storageKey = 'zeroClubTheme';
    const root = document.documentElement;

    function systemTheme() {
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function getSavedTheme() {
        const saved = localStorage.getItem(storageKey);
        return saved === 'dark' || saved === 'light' ? saved : 'light';
    }

    function chartColors(theme) {
        const dark = theme === 'dark';
        return {
            text: dark ? '#b7c6d8' : '#5f7180',
            grid: dark ? 'rgba(148, 210, 255, .12)' : 'rgba(26, 82, 118, .08)',
            tooltipBg: dark ? 'rgba(5, 12, 22, .96)' : 'rgba(18, 48, 68, .94)',
            tooltipText: dark ? '#eef6ff' : '#ffffff'
        };
    }

    function refreshCharts(theme) {
        if (!window.Chart) return;

        const colors = chartColors(theme);
        Chart.defaults.color = colors.text;
        Chart.defaults.plugins.tooltip.backgroundColor = colors.tooltipBg;
        Chart.defaults.plugins.tooltip.titleColor = colors.tooltipText;
        Chart.defaults.plugins.tooltip.bodyColor = colors.tooltipText;

        const source = Chart.instances || {};
        const charts = source instanceof Map ? Array.from(source.values()) : Object.keys(source).map((key) => source[key]);
        charts.forEach((chart) => {
            if (!chart || !chart.options) return;

            if (chart.options.scales) {
                Object.values(chart.options.scales).forEach((scale) => {
                    if (!scale) return;
                    scale.ticks = scale.ticks || {};
                    scale.grid = scale.grid || {};
                    scale.ticks.color = colors.text;
                    scale.grid.color = colors.grid;
                });
            }

            if (chart.options.plugins && chart.options.plugins.legend && chart.options.plugins.legend.labels) {
                chart.options.plugins.legend.labels.color = colors.text;
            }

            chart.update('none');
        });
    }

    function setToggleState(theme) {
        document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
            const icon = button.querySelector('i');
            const dark = theme === 'dark';
            button.setAttribute('aria-pressed', dark ? 'true' : 'false');
            button.setAttribute('title', dark ? 'Switch to light mode' : 'Switch to dark mode');
            button.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
            if (icon) {
                icon.className = dark ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
            }
        });
    }

    function applyTheme(theme, persist) {
        root.setAttribute('data-theme', theme);
        document.body && document.body.classList.toggle('dark-mode', theme === 'dark');
        if (persist) localStorage.setItem(storageKey, theme);
        setToggleState(theme);
        refreshCharts(theme);
        window.dispatchEvent(new CustomEvent('zeroThemeChange', { detail: { theme } }));
    }

    window.zeroTheme = {
        get: () => root.getAttribute('data-theme') || getSavedTheme(),
        set: (theme) => applyTheme(theme === 'dark' ? 'dark' : 'light', true),
        toggle: () => applyTheme((root.getAttribute('data-theme') || getSavedTheme()) === 'dark' ? 'light' : 'dark', true)
    };

    applyTheme(root.getAttribute('data-theme') || getSavedTheme(), false);

    document.addEventListener('DOMContentLoaded', function () {
        root.classList.add('theme-ready');
        setToggleState(window.zeroTheme.get());

        document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
            button.addEventListener('click', function () {
                window.zeroTheme.toggle();
            });
        });

        refreshCharts(window.zeroTheme.get());
    });

        if (window.matchMedia) {
        const media = window.matchMedia('(prefers-color-scheme: dark)');
        const onSystemChange = function () {
            if (!localStorage.getItem(storageKey)) {
                applyTheme('light', false);
            }
        };

        if (media.addEventListener) media.addEventListener('change', onSystemChange);
        else if (media.addListener) media.addListener(onSystemChange);
    }
})();
