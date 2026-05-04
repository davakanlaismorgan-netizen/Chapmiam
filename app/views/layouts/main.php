<!DOCTYPE html><html lang="fr"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= Security::e($title ?? "Chap'miam") ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>:root{--burgundy:#6b1a2a}.btn-primary{background:var(--burgundy);border-color:var(--burgundy)}</style>
</head><body>
<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
  <div class="container"><a class="navbar-brand fw-bold" href="index.php">🍽️ Chap'miam</a>
  <div class="ms-auto">
    <?php if(Session::isLogged()): ?>
      <a href="index.php?page=logout" class="btn btn-outline-danger btn-sm">Déconnexion</a>
    <?php else: ?>
      <a href="index.php?page=login" class="btn btn-primary btn-sm">Connexion</a>
    <?php endif; ?>
  </div></div>
</nav>
<?php $flash = Session::getFlash(); if($flash): ?>
<div class="container mt-3">
  <div class="alert alert-<?= Security::e($flash['type']) ?> alert-dismissible fade show">
    <?= $flash['message'] ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
</div>
<?php endif; ?>
<main><?= $content ?? '' ?></main>
<footer class="bg-dark text-white text-center py-3 mt-5"><small>&copy; <?= date('Y') ?> Chap'miam</small></footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
