/* Admin panel — tiny, dependency-free helpers */
(function () {
  'use strict';

  // Confirm destructive actions.
  document.addEventListener('click', function (event) {
    var el = event.target.closest('[data-confirm]');
    if (!el) return;
    var message = el.getAttribute('data-confirm') || 'مطمئن هستید؟';
    if (!window.confirm(message)) {
      event.preventDefault();
      event.stopPropagation();
    }
  });

  // Copy to clipboard.
  document.addEventListener('click', function (event) {
    var el = event.target.closest('[data-copy]');
    if (!el) return;
    var text = el.getAttribute('data-copy');
    event.preventDefault();
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () {
        var old = el.textContent;
        el.textContent = 'کپی شد ✓';
        setTimeout(function () { el.textContent = old; }, 1200);
      });
    }
  });

  // Auto-hide success alerts after 6 seconds.
  window.setTimeout(function () {
    document.querySelectorAll('.alert-ok').forEach(function (alert) {
      alert.style.transition = 'opacity .6s';
      alert.style.opacity = '0';
      setTimeout(function () { alert.remove(); }, 700);
    });
  }, 6000);

  // OTP inputs: digits only.
  document.querySelectorAll('input[data-otp]').forEach(function (input) {
    input.addEventListener('input', function () {
      input.value = input.value.replace(/[^0-9]/g, '').slice(0, 10);
    });
  });

  // Submit buttons get a busy state so double clicks are harmless.
  document.querySelectorAll('form[data-busy]').forEach(function (form) {
    form.addEventListener('submit', function () {
      var btn = form.querySelector('button[type=submit]');
      if (!btn) return;
      setTimeout(function () {
        btn.disabled = true;
        btn.dataset.label = btn.textContent;
        btn.textContent = 'در حال انجام…';
      }, 10);
    });
  });
})();
