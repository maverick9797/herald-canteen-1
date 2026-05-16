/**
 * theme.js — Herald Canteen
 * Reads saved preference from localStorage and applies it to <html>
 * BEFORE the browser paints, preventing any white/dark flash.
 *
 * Place this <script src="../assets/js/theme.js"></script>
 * as the FIRST thing inside <head>, BEFORE the <link> to style.css.
 */
(function () {
  var saved = localStorage.getItem('hc-theme');
  // Default: dark for layout/container pages, light for page-wrapper pages.
  // But we honour whatever the user last chose.
  if (saved === 'dark') {
    document.documentElement.setAttribute('data-theme', 'dark');
  } else if (saved === 'light') {
    document.documentElement.setAttribute('data-theme', 'light');
  }
  // No saved value → CSS defaults take over (each section has its own natural default).
})();

/**
 * Called by the toggle checkbox (onchange).
 * Also wired up automatically to any .theme-checkbox on DOMContentLoaded.
 */
function hcToggleTheme(isDark) {
  var theme = isDark ? 'dark' : 'light';
  document.documentElement.setAttribute('data-theme', theme);
  localStorage.setItem('hc-theme', theme);
  // Sync all toggles on the page (in case there are multiple)
  document.querySelectorAll('.theme-checkbox').forEach(function (cb) {
    cb.checked = isDark;
  });
}

// Wire up checkboxes once DOM is ready
document.addEventListener('DOMContentLoaded', function () {
  var saved = localStorage.getItem('hc-theme');
  var isDark = saved === 'dark'; // default = light unless explicitly dark

  document.querySelectorAll('.theme-checkbox').forEach(function (cb) {
    cb.checked = isDark;
    cb.addEventListener('change', function () {
      hcToggleTheme(this.checked);
    });
  });
});
