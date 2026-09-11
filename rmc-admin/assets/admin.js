/* RMC Quiz & Exam — admin shell interactions */
(function () {
  'use strict';

  var THEME_KEY = 'rmc_theme';
  var SIDEBAR_KEY = 'rmc_sidebar';

  // ---------- Sidebar collapse ----------
  var btnSidebar = document.getElementById('btnSidebar');
  if (btnSidebar) {
    btnSidebar.addEventListener('click', function () {
      document.body.classList.toggle('sidebar-collapsed');
      try { localStorage.setItem(SIDEBAR_KEY, document.body.classList.contains('sidebar-collapsed') ? '1' : '0'); } catch (e) {}
    });
  }
  try {
    if (localStorage.getItem(SIDEBAR_KEY) === '1') {
      document.body.classList.add('sidebar-collapsed');
    }
  } catch (e) {}

  // ---------- Avatar dropdown ----------
  var btnAvatar = document.getElementById('btnAvatar');
  var avatarDropdown = document.getElementById('avatarDropdown');
  if (btnAvatar && avatarDropdown) {
    btnAvatar.addEventListener('click', function (ev) {
      ev.stopPropagation();
      avatarDropdown.classList.toggle('open');
    });
    document.addEventListener('click', function (ev) {
      if (!avatarDropdown.contains(ev.target)) {
        avatarDropdown.classList.remove('open');
      }
    });
  }

  // Close any open dropdown on Escape
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') {
      if (avatarDropdown) avatarDropdown.classList.remove('open');
    }
  });

  // ---------- Theme preferences ----------
  function resolveTheme(pref) {
    if (pref === 'auto') {
      return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    return pref;
  }
  function applyTheme(pref) {
    try { localStorage.setItem(THEME_KEY, pref); } catch (e) {}
    var html = document.documentElement;
    html.setAttribute('data-theme', resolveTheme(pref));
    var opts = document.querySelectorAll('.theme-opt[data-theme-option]');
    for (var i = 0; i < opts.length; i++) {
      opts[i].classList.toggle('is-selected', opts[i].getAttribute('data-theme-option') === pref);
    }
  }
  var themeOpts = document.querySelectorAll('.theme-opt[data-theme-option]');
  for (var j = 0; j < themeOpts.length; j++) {
    themeOpts[j].addEventListener('click', function () { applyTheme(this.getAttribute('data-theme-option')); });
  }
  if (themeOpts.length) {
    try {
      var current = localStorage.getItem(THEME_KEY) || 'light';
      applyTheme(current);
    } catch (e) {}
  }

  // ---------- Apply preferences button ----------
  var btnApply = document.getElementById('btnApplyPrefs');
  if (btnApply) {
    btnApply.addEventListener('click', function () {
      btnApply.disabled = true;
      var original = btnApply.textContent;
      btnApply.textContent = 'Saved \u2713';
      btnApply.classList.add('saved');
      setTimeout(function () {
        btnApply.textContent = original;
        btnApply.classList.remove('saved');
        btnApply.disabled = false;
      }, 1600);
    });
  }
})();