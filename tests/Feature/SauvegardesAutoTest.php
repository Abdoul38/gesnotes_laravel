<?php

namespace Tests\Feature;

use App\Services\AlerteSauvegarde;
use App\Services\SauvegardesAuto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Sauvegardes automatiques : complète, différentielle, incrémentielle,
 * restauration (éventuellement à une date), conservation.
 * Les cas avec journal binaire ne s'exécutent que si log_bin est actif sur
 * le serveur de test (sinon : refus vérifié).
 */
class SauvegardesAutoTest extends TestCase
{
    private string $dossier;

    private const CIBLE = 'gesnotes_test_udh_restauration';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dossier = storage_path('framework/testing/sauvegardes-auto-'.uniqid());
        config(['gesnotes.sauvegardes_auto.dossier' => $this->dossier, 'gesnotes.sauvegardes_auto.alerte' => null]);
        DB::statement('DROP DATABASE IF EXISTS `'.self::CIBLE.'`');
    }

    protected function tearDown(): void
    {
        DB::statement('DROP DATABASE IF EXISTS `'.self::CIBLE.'`');
        if (is_dir($this->dossier)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dossier, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->dossier);
        }
        parent::tearDown();
    }

    private function etudiant(string $nom): void
    {
        DB::table('etudiants')->insert(['nom' => $nom, 'prenoms' => 'Test', 'numero' => substr(md5($nom), 0, 8)]);
    }

    private function restaures(): array
    {
        return DB::table(self::CIBLE.'.etudiants')->orderBy('nom')->pluck('nom')->all();
    }

    private function binlog(): bool
    {
        return app(SauvegardesAuto::class)->journalBinaireActif();
    }

    public function test_complete_et_restauration_a_cote(): void
    {
        $this->etudiant('ALPHA');
        $this->artisan('gesnotes:sauvegarder', ['--type' => 'complete', '--universite' => ['udh']])
            ->expectsOutputToContain('✔ Sauvegarde complete')->assertSuccessful();
        $catalogue = app(SauvegardesAuto::class)->catalogue();
        $this->assertCount(1, $catalogue);
        $base = $catalogue[0]['bases']['gesnotes_test_udh'];
        $this->assertSame(hash_file('sha256', $catalogue[0]['chemin'].'/'.$base['fichier']), $base['sha256']);
        $this->assertSame($this->binlog(), $base['journal'] !== null);
        $this->assertSame(['complete' => 'ok'], array_map(fn ($e) => $e['statut'], app(SauvegardesAuto::class)->etat()));
        // Aucun mot de passe ni fichier d'options laissé derrière
        $this->assertSame([], glob($this->dossier.'/.tmp/*.cnf'));

        $this->etudiant('BRAVO');
        // Plan seulement : rien n'est modifié
        $this->artisan('gesnotes:restaurer', ['--universite' => 'udh'])->expectsOutputToContain('Rien n\'a été modifié')->assertSuccessful();
        $this->assertFalse((bool) DB::scalar('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?', [self::CIBLE]));
        // Une base de production ne peut être écrasée que par --remplacer, et seulement la base restaurée
        $this->artisan('gesnotes:restaurer', ['--universite' => 'udh', '--vers' => 'gesnotes_test_uam', '--appliquer' => true])
            ->expectsOutputToContain('base de production')->assertFailed();

        $this->artisan('gesnotes:restaurer', ['--universite' => 'udh', '--appliquer' => true])->expectsOutputToContain('✔ '.self::CIBLE.' restaurée')->assertSuccessful();
        $this->assertSame(['ALPHA'], $this->restaures());

        // Fichier altéré : restauration refusée
        file_put_contents($catalogue[0]['chemin'].'/'.$base['fichier'], 'abime');
        $this->artisan('gesnotes:restaurer', ['--universite' => 'udh', '--appliquer' => true])->expectsOutputToContain('altéré')->assertFailed();
    }

    public function test_differentielle_incrementielle_et_restauration_a_une_date(): void
    {
        $this->artisan('gesnotes:sauvegarder', ['--type' => 'incrementielle'])->assertFailed();
        if (! $this->binlog()) {
            $this->assertSame('echec', app(SauvegardesAuto::class)->etat()['incrementielle']['statut']);
            $this->assertStringContainsString('Journal binaire désactivé', app(SauvegardesAuto::class)->etat()['incrementielle']['erreur']);
            $this->markTestSkipped('log_bin désactivé sur le serveur de test : seul le refus est vérifié.');
        }
        $this->assertStringContainsString('Aucune sauvegarde complète', app(SauvegardesAuto::class)->etat()['incrementielle']['erreur']);

        $this->artisan('gesnotes:sauvegarder', ['--type' => 'complete', '--universite' => ['udh']])->assertSuccessful();
        $this->etudiant('ALPHA');
        DB::connection()->table('gesnotes_test_uam.etudiants')->insert(['nom' => 'AUTRE_UNIVERSITE', 'prenoms' => 'X', 'numero' => 'U1']);
        $this->artisan('gesnotes:sauvegarder', ['--type' => 'incrementielle'])->expectsOutputToContain('✔ Sauvegarde incrementielle')->assertSuccessful();
        $this->etudiant('BRAVO');
        $this->artisan('gesnotes:sauvegarder', ['--type' => 'differentielle'])->assertSuccessful();
        $this->etudiant('CHARLIE');
        sleep(2);
        $date = now();
        sleep(2);
        $this->etudiant('DELTA');
        $this->artisan('gesnotes:sauvegarder', ['--type' => 'incrementielle'])->assertSuccessful();
        $this->etudiant('ECHO');

        $s = app(SauvegardesAuto::class);
        [$complete, $inc1, $diff, $inc2] = $s->catalogue();
        $this->assertSame([$complete['id'], $complete['id'], $diff['id']], [$inc1['precedente'], $diff['complete'], $inc2['precedente']]);
        // Différentielle : tout depuis la complète (contient ce que la 1re incrémentielle avait copié)
        $this->assertSame($inc1['du'], $diff['du']);
        $this->assertSame(SauvegardesAuto::numero($diff['jusqua']) + 1, SauvegardesAuto::numero($inc2['du']));

        // Dernière sauvegarde : complète + différentielle + incrémentielle suivante
        $plan = $s->plan('gesnotes_test_udh');
        $this->assertSame([$complete['id'], $diff['id'], [$inc2['id']]], [$plan['complete']['id'], $plan['differentielle']['id'], array_column($plan['incrementielles'], 'id')]);
        $this->artisan('gesnotes:restaurer', ['--universite' => 'udh', '--appliquer' => true])->assertSuccessful();
        $this->assertSame(['ALPHA', 'BRAVO', 'CHARLIE', 'DELTA'], $this->restaures());

        // À une date : la sauvegarde suivante fournit la fin de la période, les modifications d'après sont écartées
        $this->artisan('gesnotes:restaurer', ['--universite' => 'udh', '--jusqua' => $date->format('Y-m-d H:i:s'), '--appliquer' => true])->assertSuccessful();
        $this->assertSame(['ALPHA', 'BRAVO', 'CHARLIE'], $this->restaures());

        // Journaux encore sur le serveur : jusqu'à la dernière modification
        $this->artisan('gesnotes:restaurer', ['--universite' => 'udh', '--journaux-serveur' => true, '--appliquer' => true])->assertSuccessful();
        $this->assertSame(['ALPHA', 'BRAVO', 'CHARLIE', 'DELTA', 'ECHO'], $this->restaures());
        // Les autres universités ne sont pas rejouées dans la base restaurée
        $this->assertSame(0, DB::table(self::CIBLE.'.etudiants')->where('nom', 'AUTRE_UNIVERSITE')->count());

        // Chaîne cassée (une incrémentielle perdue) : refus plutôt qu'une base incomplète
        $this->supprimer($diff['chemin']);
        $this->artisan('gesnotes:restaurer', ['--universite' => 'udh'])->expectsOutputToContain('incomplète')->assertFailed();
    }

    public function test_copie_hors_serveur_chiffree_et_recuperation(): void
    {
        $rclone = 'C:\xampp00\rclone\rclone.exe';
        if (! is_file($rclone)) {
            $this->markTestSkipped('rclone absent.');
        }
        // « Drive » de test : dossier local, chiffré par un remote crypt (mots de passe obscurcis par rclone)
        $distant = $this->dossier.'-distant';
        $obscur = fn ($v) => trim((new Process([$rclone, 'obscure', $v]))->mustRun()->getOutput());
        @mkdir($this->dossier, 0700, true);
        file_put_contents($conf = $this->dossier.'-rclone.conf', "[chiffre]
type = crypt
remote = $distant
password = ".$obscur('essai-1').'
password2 = '.$obscur('essai-2').'
');
        config(['gesnotes.sauvegardes_auto.hors_serveur' => ['rclone' => $rclone, 'config' => $conf, 'destination' => 'chiffre:']]);

        try {
            $this->etudiant('ALPHA');
            // La sauvegarde est suivie de la copie
            $this->artisan('gesnotes:sauvegarder', ['--type' => 'complete', '--universite' => ['udh']])
                ->expectsOutputToContain('Copiée hors du serveur (chiffre:)')->assertSuccessful();
            $id = app(SauvegardesAuto::class)->catalogue()[0]['id'];
            $this->assertSame('ok', app(SauvegardesAuto::class)->etat()['hors_serveur']['statut']);

            // Chez le prestataire : noms et contenus chiffrés, rien de lisible
            $fichiers = iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($distant, \FilesystemIterator::SKIP_DOTS)));
            $this->assertCount(2, $fichiers); // manifest.json + gesnotes_test_udh.sql.gz
            foreach ($fichiers as $f) {
                $chemin = substr($f->getPathname(), strlen($distant));
                $this->assertStringNotContainsString('gesnotes', $chemin);
                $this->assertStringNotContainsString('manifest', $chemin);
                $this->assertStringNotContainsString('complete', $chemin);
                $this->assertStringNotContainsString('"statut"', file_get_contents($f->getPathname()));
            }

            // Serveur perdu : dossier local vidé, sauvegardes rapatriées puis restaurées
            $this->supprimerTout($this->dossier);
            $this->artisan('gesnotes:copier-hors-serveur', ['--recuperer' => true])->expectsOutputToContain('rapatriées')->assertSuccessful();
            $this->assertSame([$id], array_column(app(SauvegardesAuto::class)->catalogue(), 'id'));
            $this->artisan('gesnotes:restaurer', ['--universite' => 'udh', '--appliquer' => true])->assertSuccessful();
            $this->assertSame(['ALPHA'], $this->restaures());

            // Rien n'est effacé à distance quand une sauvegarde disparaît du serveur
            $this->supprimerTout($this->dossier.'/'.$id);
            $this->artisan('gesnotes:copier-hors-serveur')->assertSuccessful();
            $this->assertCount(2, iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($distant, \FilesystemIterator::SKIP_DOTS))));

            // Destination inaccessible : échec signalé dans l'état
            config(['gesnotes.sauvegardes_auto.hors_serveur.destination' => 'inexistant:']);
            $this->artisan('gesnotes:copier-hors-serveur')->expectsOutputToContain('rclone a échoué')->assertFailed();
            $this->assertSame('echec', app(SauvegardesAuto::class)->etat()['hors_serveur']['statut']);
        } finally {
            $this->supprimerTout($distant);
            @unlink($conf);
        }
    }

    public function test_copie_sur_cle_usb(): void
    {
        $rclone = 'C:\\xampp00\\rclone\\rclone.exe';
        if (! is_file($rclone)) {
            $this->markTestSkipped('rclone absent.');
        }
        // « Clé USB » de test : un dossier ; remote « usb » chiffré dont le dossier est fixé à chaque copie
        $cle = $this->dossier.'-cle';
        @mkdir($cle, 0700, true);
        @mkdir($this->dossier, 0700, true);
        $obscur = fn ($v) => trim((new Process([$rclone, 'obscure', $v]))->mustRun()->getOutput());
        file_put_contents($conf = $this->dossier.'-rclone.conf', "[usb]\ntype = crypt\nremote = X:\\inutilise\npassword = ".$obscur('essai-1')."\npassword2 = ".$obscur('essai-2')."\n");
        config(['gesnotes.sauvegardes_auto.hors_serveur' => ['rclone' => $rclone, 'config' => $conf, 'destination' => null, 'remote_support' => 'usb']]);

        try {
            // Jamais copiée : rappel ; support absent ou sur le même disque : refus
            $this->artisan('gesnotes:copier-usb', ['--rappel' => true])->expectsOutputToContain('Aucune copie sur clé USB')->assertSuccessful();
            $this->artisan('gesnotes:copier-usb')->expectsOutputToContain('lettre du lecteur')->assertFailed();
            $this->artisan('gesnotes:copier-usb', ['support' => $cle.'\\absent'])->expectsOutputToContain('Support introuvable')->assertFailed();
            $this->artisan('gesnotes:copier-usb', ['support' => $cle])->expectsOutputToContain('même disque')->assertFailed();

            $this->etudiant('ALPHA');
            $this->artisan('gesnotes:sauvegarder', ['--type' => 'complete', '--universite' => ['udh']])->assertSuccessful();
            $id = app(SauvegardesAuto::class)->catalogue()[0]['id'];
            $this->artisan('gesnotes:copier-usb', ['support' => $cle, '--meme-disque' => true])
                ->expectsOutputToContain('copiées et vérifiées')->assertSuccessful();
            $this->assertSame('ok', app(SauvegardesAuto::class)->etat()['support']['statut']);

            // Sur la clé : un dossier EDUFILE-sauvegardes, contenu et noms chiffrés
            $fichiers = iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($cle.'/EDUFILE-sauvegardes', \FilesystemIterator::SKIP_DOTS)));
            $this->assertCount(2, $fichiers);
            foreach ($fichiers as $f) {
                $chemin = substr($f->getPathname(), strlen($cle));
                $this->assertStringNotContainsString('complete', $chemin);
                $this->assertStringNotContainsString('"statut"', file_get_contents($f->getPathname()));
            }
            // Copie récente : pas de rappel
            $this->artisan('gesnotes:copier-usb', ['--rappel' => true])->expectsOutputToContain('pas de rappel')->assertSuccessful();

            // Clé abîmée : la relecture le détecte
            $un = array_values(array_filter(array_map(fn ($f) => $f->getPathname(), $fichiers), fn ($f) => filesize($f) > 1000))[0];
            // (même taille et même date : la copie ne le remplace pas, seule la relecture peut le voir)
            $date = filemtime($un);
            file_put_contents($un, str_repeat('x', filesize($un)));
            touch($un, $date);
            $this->artisan('gesnotes:copier-usb', ['support' => $cle, '--meme-disque' => true])->expectsOutputToContain('rclone a échoué')->assertFailed();
            $this->assertSame('echec', app(SauvegardesAuto::class)->etat()['support']['statut']);
            unlink($un);
            $this->artisan('gesnotes:copier-usb', ['support' => $cle, '--meme-disque' => true])->assertSuccessful();

            // Serveur perdu : rapatriement depuis la clé, puis restauration
            $this->supprimerTout($this->dossier);
            $this->artisan('gesnotes:copier-usb', ['support' => $cle, '--recuperer' => true])->expectsOutputToContain('rapatriées')->assertSuccessful();
            $this->assertSame([$id], array_column(app(SauvegardesAuto::class)->catalogue(), 'id'));
            $this->artisan('gesnotes:restaurer', ['--universite' => 'udh', '--appliquer' => true])->assertSuccessful();
            $this->assertSame(['ALPHA'], $this->restaures());
        } finally {
            $this->supprimerTout($cle);
            @unlink($conf);
        }
    }

    public function test_mode_automatique_rattrape_les_creneaux_manques(): void
    {
        $s = app(SauvegardesAuto::class);
        @mkdir($this->dossier, 0700, true);
        $faux = function (string $date, string $type, ?string $complete = null, bool $journal = true) {
            $id = Carbon::parse($date)->format('Y-m-d_His').'-000_'.$type;
            @mkdir($this->dossier.'/'.$id, 0700, true);
            file_put_contents($this->dossier.'/'.$id.'/manifest.json', json_encode(['id' => $id, 'type' => $type, 'statut' => 'ok',
                'debut' => Carbon::parse($date)->toIso8601String(), 'fin' => Carbon::parse($date)->toIso8601String(), 'complete' => $complete,
                'bases' => $type === 'complete' ? ['b' => ['journal' => $journal ? ['fichier' => 'mysql-bin.000001', 'position' => 4] : null]] : [], 'journaux' => []]));

            return $id;
        };
        $a = fn (string $quand) => $s->aFaire(Carbon::parse($quand), true);

        // Rien : complète
        $this->assertSame('complete', $a('2026-10-14 10:00'));
        // Complète du dimanche 11/10 à 2 h : lundi 12 à 10 h, la différentielle de la nuit manque
        $c = $faux('2026-10-11 02:00:05', 'complete');
        $this->assertSame('incrementielle', $a('2026-10-11 09:30'));  // dimanche 9 h 30 : rien depuis 2 h
        $faux('2026-10-11 09:30:03', 'incrementielle', $c);
        $this->assertNull($a('2026-10-11 09:30'));
        $this->assertSame('incrementielle', $a('2026-10-11 10:01'));
        $this->assertSame('differentielle', $a('2026-10-12 10:00'));  // nuit du lundi manquée (ordinateur éteint)
        $faux('2026-10-12 10:00:30', 'differentielle', $c);
        $this->assertNull($a('2026-10-12 10:40'));
        $this->assertSame('incrementielle', $a('2026-10-12 11:00'));  // heure suivante
        $faux('2026-10-12 11:00:20', 'incrementielle', $c);
        // Soir puis nuit : l'incrémentielle de 20 h manquée est rattrapée à 23 h, rien entre 20 h et 7 h ensuite
        $this->assertSame('incrementielle', $a('2026-10-12 23:00'));
        $faux('2026-10-12 23:00:10', 'incrementielle', $c);
        $this->assertNull($a('2026-10-13 01:30'));
        $this->assertSame('differentielle', $a('2026-10-13 02:00'));
        // Ordinateur éteint du mardi au lundi suivant : complète d'abord
        $this->assertSame('complete', $a('2026-10-19 08:00'));
        // Sans journal binaire : complètes seulement ; complète sans position de journal : nouvelle complète
        $this->assertNull($s->aFaire(Carbon::parse('2026-10-13 15:00'), false));
        $faux('2026-10-13 15:30', 'complete', null, false);
        $this->assertSame('complete', $a('2026-10-13 15:40'));
    }

    public function test_panne_du_serveur_mysql_notee_puis_signalee(): void
    {
        $alertes = new \ArrayObject;
        $this->app->instance(AlerteSauvegarde::class, new class($alertes) extends AlerteSauvegarde
        {
            public function __construct(private \ArrayObject $liste) {}

            public function envoyer(string $sujet, string $corpsHtml): bool
            {
                $this->liste[] = $sujet;

                return true;
            }
        });
        $port = config('database.connections.universite.port');
        config(['database.connections.universite.port' => 1]); // aucun serveur sur ce port
        $this->artisan('gesnotes:sauvegarder', ['--auto' => true])->expectsOutputToContain('injoignable')->assertFailed();
        $this->artisan('gesnotes:sauvegarder', ['--auto' => true])->assertFailed();
        $panne = app(SauvegardesAuto::class)->etat()['panne'];
        $this->assertSame(2, $panne['tentatives']);
        $this->assertCount(0, $alertes);                       // e-mail impossible pendant la panne
        $this->assertSame([], glob($this->dossier.'/*', GLOB_ONLYDIR));   // aucun dossier vide
        $this->artisan('gesnotes:restaurer', ['--liste' => true])->expectsOutputToContain('Serveur MySQL injoignable depuis')->assertSuccessful();

        // Retour du serveur : panne signalée, sauvegarde due faite (ici une complète)
        config(['database.connections.universite.port' => $port]);
        DB::purge('sauvegardes');
        $this->artisan('gesnotes:sauvegarder', ['--auto' => true, '--universite' => ['udh']])
            ->expectsOutputToContain('de nouveau joignable')->expectsOutputToContain('✔ Sauvegarde complete')->assertSuccessful();
        $this->assertSame(['⚠️ Sauvegardes interrompues : serveur MySQL arrêté'], $alertes->getArrayCopy());
        $this->assertArrayNotHasKey('panne', app(SauvegardesAuto::class)->etat());
    }

    public function test_conservation(): void
    {
        $s = app(SauvegardesAuto::class);
        config(['gesnotes.sauvegardes_auto.garder_completes' => 2, 'gesnotes.sauvegardes_auto.garder_mois' => 3]);
        $faux = function (string $date, string $type, ?string $complete = null, string $statut = 'ok', bool $archive = false) {
            $id = Carbon::parse($date)->format('Y-m-d_His').'_'.$type;
            @mkdir($this->dossier.'/'.$id, 0700, true);
            file_put_contents($this->dossier.'/'.$id.'/manifest.json', json_encode(['id' => $id, 'type' => $type, 'statut' => $statut, 'archive' => $archive,
                'debut' => Carbon::parse($date)->toIso8601String(), 'fin' => Carbon::parse($date)->toIso8601String(), 'complete' => $complete, 'bases' => [], 'journaux' => []]));

            return $id;
        };
        $archive = $faux('2025-06-01 02:00', 'complete', archive: true);
        $vieille = $faux('2026-05-03 02:00', 'complete');
        $faux('2026-05-04 02:00', 'differentielle', $vieille);
        $aout1 = $faux('2026-08-02 02:00', 'complete');
        $aout2 = $faux('2026-08-09 02:00', 'complete');
        $incAout2 = $faux('2026-08-09 10:00', 'incrementielle', $aout2);
        $oct1 = $faux('2026-10-04 02:00', 'complete');
        $oct2 = $faux('2026-10-11 02:00', 'complete');
        $echecRecent = $faux('2026-10-10 10:00', 'incrementielle', $oct1, 'echec');
        $echecAncien = $faux('2026-09-01 10:00', 'incrementielle', $aout2, 'echec');

        $supprimes = $s->purger(Carbon::parse('2026-10-12 03:00'));
        // Gardées : 2 dernières complètes (octobre), première complète d'août (3 derniers mois : août à octobre), archive
        $this->assertEqualsCanonicalizing([$vieille, '2026-05-04_020000_differentielle', $aout2, $incAout2, $echecAncien], $supprimes);
        $this->assertSame([$archive, $aout1, $oct1, $echecRecent, $oct2], array_column($s->catalogue(true), 'id'));
    }

    private function supprimerTout(string $dossier): void
    {
        if (! is_dir($dossier)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dossier);
    }

    private function supprimer(string $dossier): void
    {
        foreach (glob($dossier.'/*') as $f) {
            unlink($f);
        }
        rmdir($dossier);
    }
}
