<?php
declare(strict_types=1);
class HomeController {
    public function index(): void {
        $title   = "Bienvenue sur Chap'miam";
        $content = '<div class="container py-5 text-center"><h1>🍽️ Chap\'miam</h1><p class="lead">Application de commande de repas en ligne.</p><a href="index.php?page=login" class="btn btn-primary mt-3">Se connecter</a></div>';
        require dirname(__DIR__) . '/views/layouts/main.php';
    }
    public function about(): void   { $this->index(); }
    public function contact(): void { $this->index(); }
    public function search(): void  { $this->index(); }
}
