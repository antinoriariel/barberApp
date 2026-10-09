<?php
/**
 * layouts/base.php — Documento HTML común a todas las páginas.
 *
 * Variables:
 *   $content   HTML de la página (lo agrega View::page)
 *   $title     título de la pestaña
 *   $bodyClass clase opcional del <body> (opcional)
 *   $scripts   módulos JS a cargar, relativos a public/assets/ (opcional)
 */
use App\Core\Config;
?>
<!doctype html>
<html lang="<?= e(Config::get('app.lang')) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= e($title ?? Config::get('app.name')) ?></title>
  <meta name="description" content="<?= e(Config::get('app.description')) ?>">
  <meta name="theme-color" content="<?= e(Config::get('app.theme_color')) ?>">
  <script src="<?= e(asset('js/theme-init.js')) ?>"></script>
  <meta name="csrf-token" content="<?= e(\App\Core\Security::csrfToken()) ?>">
  <link rel="manifest" href="/manifest.webmanifest">
  <link rel="icon" href="/icon.png?s=64">
  <link rel="apple-touch-icon" href="/icon.png?s=192">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="mobile-web-app-capable" content="yes">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="<?= e(Config::get('assets.fonts_url')) ?>" rel="stylesheet">
<?php foreach (Config::get('assets.styles') as $css): ?>
  <link rel="stylesheet" href="<?= e(asset($css)) ?>">
<?php endforeach; ?>
</head>
<body<?= !empty($bodyClass) ? ' class="' . e($bodyClass) . '"' : '' ?>>
<?= $content ?>
<?php foreach ($scripts ?? [] as $js): ?>
  <script type="module" src="<?= e(asset($js)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
