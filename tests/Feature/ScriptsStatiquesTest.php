<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Scripts servis tels quels (pas de test navigateur) : garde-fous sur leur contenu. */
class ScriptsStatiquesTest extends TestCase
{
    public function test_service_worker_reseau_d_abord_et_sans_reponse_d_erreur_en_cache(): void
    {
        $sw = (string) file_get_contents(public_path('sw.js'));
        // Nouveau nom de cache : l'ancien (scripts périmés, réponses d'erreur) est vidé à l'activation
        $this->assertStringNotContainsString("'edufile-static-v1'", $sw);
        $this->assertMatchesRegularExpression("/const CACHE_NOM = 'edufile-static-v\\d+'/", $sw);
        // Réseau d'abord, cache seulement hors ligne, et seulement les réponses valides
        $this->assertStringContainsString('fetch(event.request).then((reponseReseau)', $sw);
        $this->assertStringContainsString('reponseReseau.ok', $sw);
        $this->assertStringNotContainsString('if (reponseEnCache) return reponseEnCache;', $sw);
    }

    public function test_chaque_graphique_dans_un_cadre_de_hauteur_fixe(): void
    {
        // Un canevas Chart.js posé directement dans une grille s'agrandit sans fin (statistiques, ministère, rectorat…)
        $vues = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS));
        foreach ($vues as $vue) {
            $vue = $vue->getPathname();
            preg_match_all('/(.{0,200})<canvas\b/s', (string) file_get_contents($vue), $m);
            foreach ($m[1] as $avant) {
                $this->assertMatchesRegularExpression('/class="(graphe|stats-graphe)"[^\n]*>\s*$/', $avant, basename($vue).' : graphique sans cadre');
            }
        }
        foreach (['accueil', 'candidatures-admin', 'finances', 'ministere', 'rectorat'] as $js) {
            $this->assertStringContainsString('Chart.defaults.maintainAspectRatio = false', (string) file_get_contents(public_path("assets/js/$js.js")), $js);
        }
    }

    public function test_qr_code_ajuste_a_son_cadre(): void
    {
        $js = (string) file_get_contents(public_path('assets/js/impression.js'));
        $this->assertStringContainsString("svg.setAttribute('width', '100%')", $js);
    }
}
