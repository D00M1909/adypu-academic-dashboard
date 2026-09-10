// Copying a join code or a one-time password out of the page.
//
// The admin's actual job here is getting a code to someone else — a WhatsApp
// message, a phone call — and until now that meant transcribing it by hand from
// a monospace box. The temporary password matters more: it is shown exactly
// once and there is no email on this host to send it by.
(function () {
  // navigator.clipboard needs a secure context. Live is https and dev is
  // localhost, which both qualify, but a plain-http fallback exists because
  // this host's TLS has failed before and the button must not die with it.
  //
  // The async API is also refused outright in some contexts even on https —
  // a missing user activation, a permissions policy — so a rejection falls
  // through to the same old path rather than straight to giving up.
  function copy(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text).catch(function () { return legacyCopy(text); });
    }
    return legacyCopy(text);
  }

  function legacyCopy(text) {
    return new Promise(function (resolve, reject) {
      var box = document.createElement('textarea');
      box.value = text;
      box.setAttribute('readonly', '');
      box.style.position = 'fixed';
      box.style.opacity = '0';
      document.body.appendChild(box);
      box.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      document.body.removeChild(box);
      ok ? resolve() : reject(new Error('copy refused'));
    });
  }

  document.addEventListener('click', function (e) {
    var button = e.target.closest('[data-copy]');
    if (!button) return;

    copy(button.getAttribute('data-copy')).then(function () {
      button.classList.add('is-copied');
      // role="status" on the live region, so the confirmation is spoken rather
      // than only drawn.
      var say = document.getElementById('copy-status');
      if (say) say.textContent = 'Copied to clipboard.';
      setTimeout(function () {
        button.classList.remove('is-copied');
        if (say) say.textContent = '';
      }, 1600);
    }).catch(function () {
      // Selecting it is still better than nothing: the code stays on screen and
      // the user can copy it with the keyboard.
      var code = button.parentNode.querySelector('code');
      if (!code || !window.getSelection) return;
      var range = document.createRange();
      range.selectNodeContents(code);
      var sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(range);
    });
  });
})();
