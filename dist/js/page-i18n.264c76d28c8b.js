'use strict';
(() => {
    const runtime = window.MeteoNexaI18n;
    const apply = () => runtime?.apply?.(document);
    document.addEventListener('DOMContentLoaded', async () => {
        try {
            await runtime?.ready;
        }
        catch { }
        apply();
    });
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden)
            runtime?.sync?.().finally(apply);
    });
    addEventListener('focus', () => runtime?.sync?.().finally(apply));
})();
