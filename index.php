
<?php
require_once __DIR__ . '/config.php';

$pageTitle = build_page_title();
$toolCards = tool_registry();

include __DIR__ . '/inc/header.php';
?>

<section class="tool-panel bg-gradient-to-br from-white to-slate-50 dark:from-slate-900 dark:to-slate-950">
  <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
    <div class="max-w-2xl">
      <p class="tool-hero-eyebrow"><?= e(trans('tools_available', 'Tools available')) ?></p>
      <h2 class="tool-hero-title"><?= e(trans('title_full', '猫柠咔百宝箱')) ?></h2>
      <p class="tool-hero-copy"><?= e(trans('home_intro', 'A lightweight toolbox built for adding new tools with minimal wiring.')) ?></p>
    </div>
    <label class="block w-full lg:max-w-sm">
      <span class="sr-only"><?= e(trans('search_placeholder', 'Search tools...')) ?></span>
      <input
        id="toolSearch"
        type="search"
        placeholder="<?= e(trans('search_placeholder', 'Search tools...')) ?>"
        class="tool-control tool-control-filled"
      />
    </label>
  </div>
</section>

<section class="mt-6">
  <div id="toolGrid" class="tool-grid">
    <?php foreach ($toolCards as $tool): ?>
      <?php
      $toolTitle = trans($tool['title_key']);
      $toolDescription = trans($tool['description_key'], '');
      $searchText = strtolower($toolTitle . ' ' . $toolDescription . ' ' . $tool['slug']);
      ?>
      <a
        href="<?= e(tool_url($tool)) ?>"
        class="tool-card"
        data-search="<?= e($searchText) ?>"
      >
        <span class="tool-icon"><?= e($tool['icon']) ?></span>
        <span class="tool-title-lg mt-4 block"><?= e($toolTitle) ?></span>
        <span class="tool-copy mt-2 block"><?= e($toolDescription) ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <p id="toolSearchEmpty" class="empty-state hidden"><?= e(trans('search_no_results', 'No tools matched your search.')) ?></p>
</section>

<script>
  (function () {
    const searchInput = document.getElementById('toolSearch');
    const cards = Array.from(document.querySelectorAll('[data-search]'));
    const emptyState = document.getElementById('toolSearchEmpty');

    if (!searchInput || cards.length === 0 || !emptyState) {
      return;
    }

    function filterCards() {
      const query = searchInput.value.trim().toLowerCase();
      let visibleCount = 0;

      cards.forEach((card) => {
        const isVisible = query === '' || card.dataset.search.includes(query);
        card.classList.toggle('hidden', !isVisible);
        if (isVisible) {
          visibleCount += 1;
        }
      });

      emptyState.classList.toggle('hidden', visibleCount !== 0);
    }

    searchInput.addEventListener('input', filterCards);
    filterCards();
  })();
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>
