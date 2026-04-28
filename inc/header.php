<?php
require_once __DIR__ . '/../config.php';

$pageTitle = $pageTitle ?? build_page_title($tool_key ?? null);
$headerTitle = $tool_title ?? $pageTitle;
$metaDescription = trim($tool_description ?? trans('site_description', trans('title_full')));
$metaKeywords = trim($tool_keywords ?? trans('site_keywords', ''));
$canonicalUrl = build_canonical_url();
$hreflangLinks = build_hreflang_links();
$brandName = trans('brand_name', '猫柠咔');
$siteTitle = trans('title_full', (string) app_config('site_name_fallback'));
$ogType = 'website';
$ogLocale = current_og_locale();
$alternateOgLocales = alternate_og_locales();
$jsonLd = build_json_ld_payload($pageTitle, $metaDescription, $canonicalUrl);
?>
<!DOCTYPE html>
<html lang="<?= e($lang_code) ?>">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= e($pageTitle) ?></title>
  <meta name="description" content="<?= e($metaDescription) ?>" />
  <meta name="robots" content="index,follow,max-image-preview:large" />
  <meta name="author" content="<?= e($brandName) ?>" />
  <meta name="application-name" content="<?= e($siteTitle) ?>" />
  <meta name="apple-mobile-web-app-title" content="<?= e($siteTitle) ?>" />
  <meta name="theme-color" content="#ffffff" />
  <?php if ($metaKeywords !== ''): ?>
    <meta name="keywords" content="<?= e($metaKeywords) ?>" />
  <?php endif; ?>
  <link rel="canonical" href="<?= e($canonicalUrl) ?>" />
  <?php foreach ($hreflangLinks as $link): ?>
    <link rel="alternate" hreflang="<?= e($link['hreflang']) ?>" href="<?= e($link['href']) ?>" />
  <?php endforeach; ?>
  <meta property="og:type" content="<?= e($ogType) ?>" />
  <meta property="og:site_name" content="<?= e($siteTitle) ?>" />
  <meta property="og:title" content="<?= e($pageTitle) ?>" />
  <meta property="og:description" content="<?= e($metaDescription) ?>" />
  <meta property="og:url" content="<?= e($canonicalUrl) ?>" />
  <meta property="og:locale" content="<?= e($ogLocale) ?>" />
  <?php foreach ($alternateOgLocales as $locale): ?>
    <meta property="og:locale:alternate" content="<?= e($locale) ?>" />
  <?php endforeach; ?>
  <meta name="twitter:card" content="summary" />
  <meta name="twitter:title" content="<?= e($pageTitle) ?>" />
  <meta name="twitter:description" content="<?= e($metaDescription) ?>" />
  <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>

  <script>
    (function() {
      const userPref = localStorage.getItem('theme');
      const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
      if (userPref === 'dark' || (!userPref && prefersDark)) {
        document.documentElement.classList.add('dark');
      }
    })();
  </script>
  <link rel="stylesheet" href="/assets/css/tailwind.min.css" />
  <link rel="stylesheet" href="/assets/css/custom.css" />
</head>
<body class="bg-white text-gray-900 transition dark:bg-slate-950 dark:text-white">
  <div class="mx-auto max-w-screen-lg p-4 sm:p-6">
    <header class="tool-panel-compact mb-8 bg-white/90 backdrop-blur dark:bg-slate-900/85">
      <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <a href="/" class="tool-status-text inline-flex items-center gap-2 font-medium transition hover:text-indigo-600 dark:hover:text-sky-300">
            <span>🏠</span>
            <span><?= e(trans('back_home', 'Return to homepage')) ?></span>
          </a>
          <h1 class="tool-hero-title sm:text-3xl"><?= e($headerTitle) ?></h1>
          <p class="tool-hero-copy"><?= e($metaDescription) ?></p>
        </div>

        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
          <button id="themeToggle" type="button" class="tool-button-secondary">
            <span id="themeToggleLabel"><?= e(trans('theme_dark', 'Dark mode')) ?></span>
          </button>

          <form method="get" action="<?= e(current_request_path()) ?>">
            <label class="sr-only" for="lang-switcher"><?= e(trans('language_switcher_label', 'Language')) ?></label>
            <select id="lang-switcher" name="lang" onchange="this.form.submit()" class="tool-control tool-control-sm w-full sm:min-w-[11rem]">
              <?php foreach (language_switcher_options() as $option): ?>
                <option value="<?= e($option['code']) ?>" <?= $lang_code === $option['code'] ? 'selected' : '' ?>><?= e($option['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>
      </div>
    </header>
