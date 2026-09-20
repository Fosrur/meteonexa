'use strict';
(() => {
  document.addEventListener('DOMContentLoaded', () => {
    const button = document.getElementById('error-back');
    button?.addEventListener('click', () => {
      if (history.length > 1) history.back();
      else location.href = button.dataset.home || '/';
    });
  });
})();
