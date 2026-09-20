'use strict';
document.addEventListener('DOMContentLoaded', () => {
    document.querySelector('[data-offline-retry]')?.addEventListener('click', () => {
        window.location.href = './';
    });
});
