<?php
declare(strict_types=1);
class Utils {
    public static function formatPrice(float|int $price): string {
        return number_format((float)$price, 0, ',', ' ') . ' FCFA';
    }
    public static function formatDate(string $datetime, string $format = 'd/m/Y à H:i'): string {
        $ts = strtotime($datetime);
        return $ts !== false ? date($format, $ts) : 'Date inconnue';
    }
    public static function paginate(int $total, int $page, int $perPage = 10): array {
        $page     = max(1, $page);
        $lastPage = max(1, (int)ceil($total / $perPage));
        $page     = min($page, $lastPage);
        return ['total'=>$total,'per_page'=>$perPage,'page'=>$page,'last_page'=>$lastPage,'offset'=>($page-1)*$perPage,'has_prev'=>$page>1,'has_next'=>$page<$lastPage];
    }
    public static function commandeStatutLabel(string $statut): string {
        return match($statut) {
            'en_attente'=>'En attente','confirmee'=>'Confirmée','en_cours'=>'En cours',
            'prete'=>'Prête','en_livraison'=>'En livraison','livree'=>'Livrée',
            'annulee'=>'Annulée','refusee'=>'Refusée',default=>ucfirst($statut)
        };
    }
    public static function commandeStatutBadge(string $statut): string {
        return match($statut) {
            'en_attente'=>'warning','confirmee'=>'info','en_cours'=>'primary',
            'prete'=>'success','en_livraison'=>'info','livree'=>'success',
            'annulee'=>'secondary','refusee'=>'danger',default=>'secondary'
        };
    }
}
