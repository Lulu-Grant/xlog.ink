<?php
require_once __DIR__ . '/../config.php';

extract(tool_context('time_clock'));

include __DIR__ . '/../inc/header.php';
?>

<section class="mx-auto max-w-3xl space-y-6">
  <div class="tool-panel">
    <p class="tool-hero-eyebrow"><?= e(trans('time_clock', 'World Clock')) ?></p>
    <h2 class="tool-hero-title"><?= e(trans('select_timezone', 'Select time zone')) ?></h2>
    <p class="tool-hero-copy"><?= e(trans('time-clock_description', 'Check the current time in major world time zones instantly.')) ?></p>
  </div>

  <div class="tool-panel space-y-5">
    <label class="tool-label">
      <?= e(trans('select_timezone', 'Select time zone')) ?>
      <select id="tz-select" class="tool-control mt-2"></select>
    </label>

    <div id="clock" class="tool-display-board py-10 text-5xl font-mono tracking-tight sm:text-6xl">
      --:--:--
    </div>
  </div>
</section>

<script>
  (function () {
    const select = document.getElementById('tz-select');
    const clock = document.getElementById('clock');

    if (!select || !clock) {
      return;
    }

    const fallbackZones = [
      'UTC',
      'Europe/London',
      'Europe/Paris',
      'Asia/Shanghai',
      'Asia/Tokyo',
      'America/New_York',
      'America/Los_Angeles'
    ];

    const zones = typeof Intl.supportedValuesOf === 'function'
      ? Intl.supportedValuesOf('timeZone')
      : fallbackZones;

    zones.forEach((timeZone) => {
      const option = document.createElement('option');
      option.value = timeZone;
      option.textContent = timeZone;
      select.appendChild(option);
    });

    select.value = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';

    function updateClock() {
      const formatter = new Intl.DateTimeFormat([], {
        timeZone: select.value,
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false
      });

      clock.textContent = formatter.format(new Date());
    }

    select.addEventListener('change', updateClock);
    setInterval(updateClock, 1000);
    updateClock();
  })();
</script>

<?php include __DIR__ . '/../inc/footer.php'; ?>
