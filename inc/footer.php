    <footer class="tool-status-text mt-10 border-t border-slate-200 pt-6 text-center dark:border-slate-800">
      &copy; <?= date('Y') ?> <?= e(trans('footer_copyright', 'Online tools. All rights reserved.')) ?>
    </footer>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const btn = document.getElementById('themeToggle');
      const label = document.getElementById('themeToggleLabel');
      if (!btn || !label) {
        return;
      }

      const lightText = <?= json_encode(trans('theme_light', 'Light mode')) ?>;
      const darkText = <?= json_encode(trans('theme_dark', 'Dark mode')) ?>;

      function updateLabel() {
        label.textContent = document.documentElement.classList.contains('dark') ? lightText : darkText;
      }

      btn.addEventListener('click', () => {
        document.documentElement.classList.toggle('dark');
        const isDarkNow = document.documentElement.classList.contains('dark');
        localStorage.setItem('theme', isDarkNow ? 'dark' : 'light');
        updateLabel();
      });

      updateLabel();
    });
  </script>
</body>
</html>
