// Админка: подтверждение необратимых действий и выделение ссылки на календарь.
(function () {
  'use strict';

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-confirm]');
    if (btn && !window.confirm(btn.getAttribute('data-confirm'))) {
      e.preventDefault();
    }
    var copy = e.target.closest('.copy');
    if (copy) copy.select();
  });
})();
