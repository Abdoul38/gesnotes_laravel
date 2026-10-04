{{-- Page de présentation du projet Cursus (domaine nu) — portage de pages/landing.php --}}
@php
  $port = request()->getPort() && ! in_array(request()->getPort(), [80, 443]) ? ':'.request()->getPort() : '';
  $lien = fn (string $code, string $chemin) => '//'.$code.'.'.config('universites.domaine').$port.'/'.$chemin;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cursus — Système académique multi-université</title>
<link rel="stylesheet" href="{{ asset('assets/css/presentation.css') }}">
</head>
<body>

<div class="hero">
  <div class="badge">🎓</div>
  <h1>Cursus</h1>
  <p>Système de gestion académique multi-université, conçu pour accompagner tout le parcours étudiant — de la candidature au diplôme — avec un système de paramétrage qui permet à chaque université de configurer ses propres seuils de validation, mentions et règles académiques, en toute indépendance des autres établissements du réseau.</p>
</div>

<div class="section">
  <h2>Universités partenaires</h2>
  <div class="unis">
    @foreach ($universites as $u)
    <a href="{{ $lien($u->code, 'connexion') }}" class="uni-card">
      <div class="sigle">{{ $u->sigle }}</div>
      <div class="nom">{{ $u->nom }}</div>
      @if ($u->ville)<div class="ville">📍 {{ $u->ville }}</div>@endif
    </a>
    @endforeach
  </div>
</div>

@if ($anab)
<div class="section" style="padding-top:0;padding-bottom:0">
  <a href="{{ $lien($anab->code, 'anab/connexion') }}" class="uni-card" style="display:flex;align-items:center;gap:16px;border-color:rgba(124,58,237,.4)">
    <div style="width:48px;height:48px;border-radius:12px;background:rgba(124,58,237,.25);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0">🎓</div>
    <div><div class="sigle" style="color:#a78bfa">{{ $anab->sigle }}</div><div class="nom">{{ $anab->nom }} — portail national des bourses</div></div>
  </a>
</div>
@endif

@if ($ministere)
<div class="section" style="padding-top:16px">
  <a href="{{ $lien($ministere->code, 'ministere/connexion') }}" class="uni-card" style="display:flex;align-items:center;gap:16px;border-color:rgba(212,166,74,.4)">
    <div style="width:48px;height:48px;border-radius:12px;background:rgba(212,166,74,.2);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0">🏛️</div>
    <div><div class="sigle" style="color:#D4A64A">{{ $ministere->sigle }}</div><div class="nom">{{ $ministere->nom }} — tableau de bord national</div></div>
  </a>
</div>
@endif

<div class="section">
  <h2>Des problèmes réels, pas juste des fonctionnalités</h2>
  <div class="problems">

    <div class="problem">
      <div class="ic">💥</div>
      <div>
        <div class="avant">AVANT — Toutes les données sur un seul PC, sans sauvegarde</div>
        <div class="apres">Les données de tous les étudiants reposaient sur l'ordinateur d'une seule personne — en cas de panne, de vol ou simplement de casse, des années de notes, dossiers et résultats pouvaient disparaître d'un coup, sans aucune trace ni moyen de les récupérer. <b>Avec Cursus</b>, les données sont hébergées en ligne, sauvegardées régulièrement, et ne dépendent d'aucun ordinateur en particulier.</div>
      </div>
    </div>

    <div class="problem">
      <div class="ic">📉</div>
      <div>
        <div class="avant">AVANT — Des notes validées, perdues des années plus tard</div>
        <div class="apres">Un étudiant ayant validé une matière depuis plusieurs années se voyait, une fois tout son cursus terminé, refuser son diplôme : la trace de cette validation n'avait jamais été retrouvée, et il devait reprendre la matière — parfois des années après l'avoir réellement réussie. <b>Avec Cursus</b>, chaque note validée reste consultable en permanence, sans dépendre d'un classeur ou d'une archive papier qui peut se perdre.</div>
      </div>
    </div>

    <div class="problem">
      <div class="ic">📄</div>
      <div>
        <div class="avant">AVANT — PV papier et tableurs isolés</div>
        <div class="apres">Les notes circulaient entre enseignants, scolarité et secrétariat sur des feuilles Excel disparates, avec des colonnes qui ne correspondaient jamais tout à fait d'une matière à l'autre. <b>Avec Cursus</b>, chaque matière garde sa propre structure (avec ou sans contrôle continu) sans jamais désaligner les colonnes suivantes — import comme export.</div>
      </div>
    </div>

    <div class="problem">
      <div class="ic">🧮</div>
      <div>
        <div class="avant">AVANT — Les moyennes et totaux se calculaient à la main dans le PV</div>
        <div class="apres">Moyennes, totaux, mentions et validations se calculaient manuellement ou via des formules Excel bricolées, avec le risque constant d'une erreur de formule qui fausse tout un PV sans que personne ne le remarque. <b>Avec Cursus</b>, tous ces calculs se font automatiquement à partir des notes saisies, éliminant ce type d'erreur.</div>
      </div>
    </div>

    <div class="problem">
      <div class="ic">📍</div>
      <div>
        <div class="avant">AVANT — Des résultats affichés uniquement sur un PV papier</div>
        <div class="apres">Pour connaître ses résultats, l'étudiant devait se déplacer physiquement voir le PV affiché — impossible pour celui qui vit loin de l'université, ou simplement absent ce jour-là. <b>Avec Cursus</b>, chaque étudiant consulte ses notes en ligne, à tout moment, depuis n'importe où.</div>
      </div>
    </div>

    <div class="problem">
      <div class="ic">🔀</div>
      <div>
        <div class="avant">AVANT — L'inscription pédagogique reposait sur des copies de relevés</div>
        <div class="apres">L'étudiant a toujours poursuivi son cursus selon les semestres qu'il a réellement validés — mais cette inscription pédagogique se faisait à la main : il devait apporter les copies de ses relevés de notes pour qu'on confirme ce qu'il avait validé. Sans ces copies, impossible de savoir avec certitude où il en était, ce qui causait sans cesse des erreurs et des blocages au moment de l'inscrire pour le semestre suivant. <b>Avec Cursus</b>, l'inscription pédagogique s'appuie directement sur les résultats déjà enregistrés dans le système — plus besoin d'une copie papier pour prouver ce qui a été validé.</div>
      </div>
    </div>

    <div class="problem">
      <div class="ic">⏭️</div>
      <div>
        <div class="avant">AVANT — Le passage au niveau supérieur se décidait à la main</div>
        <div class="apres">Faute d'une vue fiable sur les résultats de chaque étudiant, le passage au niveau supérieur reposait sur une vérification manuelle — avec le risque bien réel de faire passer un étudiant qui aurait dû redoubler, ou inversement de faire redoubler un étudiant qui avait pourtant validé son année. <b>Avec Cursus</b>, le passage de niveau se base directement sur les résultats validés dans le système, réduisant ce genre d'erreur.</div>
      </div>
    </div>

    <div class="problem">
      <div class="ic">🗂️</div>
      <div>
        <div class="avant">AVANT — Candidatures traitées au cas par cas, sans trace</div>
        <div class="apres">Les dossiers de bacheliers arrivaient par téléphone, papier ou email, sans suivi centralisé ni moyen de vérifier rapidement un dossier. <b>Cursus</b> offre un vrai dossier candidat par bachelier — photo, bac, choix de filières — avec bascule directe vers un compte étudiant une fois accepté.</div>
      </div>
    </div>

    <div class="problem">
      <div class="ic">🔒</div>
      <div>
        <div class="avant">AVANT — Données en local, accessibles à quiconque avait l'ordinateur</div>
        <div class="apres">Les données résidaient directement sur l'ordinateur de la scolarité — n'importe qui y ayant accès, physiquement ou sur le réseau, pouvait consulter, modifier ou supprimer des notes et des dossiers, sans qu'aucune protection ne l'en empêche. <b>Avec Cursus</b>, l'accès passe par un compte protégé : mots de passe chiffrés (jamais stockés en clair), vérification du rôle à chaque page consultée, et blocage automatique après plusieurs tentatives de connexion échouées.</div>
      </div>
    </div>

    <div class="problem">
      <div class="ic">🕵️</div>
      <div>
        <div class="avant">AVANT — Aucune trace de qui avait fait quoi sur les données</div>
        <div class="apres">Si une note était modifiée de façon suspecte ou qu'un dossier disparaissait, impossible de savoir qui était intervenu, quand, ni pourquoi — aucun moyen d'enquêter ou de corriger en connaissance de cause. <b>Avec Cursus</b>, chaque action sensible (modification, suppression, changement de statut) est enregistrée avec son auteur et sa date, consultable à tout moment.</div>
      </div>
    </div>

    <div class="problem">
      <div class="ic">🏛️</div>
      <div>
        <div class="avant">AVANT — Le ministère recevait des chiffres agrégés, souvent en retard</div>
        <div class="apres">Chaque université produisait ses propres statistiques, sur son propre calendrier, difficiles à comparer. <b>Cursus</b> donne au ministère une vue nationale en direct — résultats, mentions, PV — consultable université par université sans attendre un envoi.</div>
      </div>
    </div>

    <div class="problem">
      <div class="ic">🎓</div>
      <div>
        <div class="avant">AVANT — Attestations et diplômes refaits à la main, sans garantie</div>
        <div class="apres">Chaque document officiel était retapé à la main, avec des erreurs de saisie fréquentes et un risque de falsification difficile à détecter. <b>Cursus</b> génère attestations, relevés et diplômes automatiquement à partir des données validées de l'étudiant, avec QR code de vérification.</div>
      </div>
    </div>

    <div class="problem">
      <div class="ic">🎫</div>
      <div>
        <div class="avant">AVANT — L'ANAB n'avait aucun moyen d'authentifier un relevé de notes</div>
        <div class="apres">Pour décider d'une bourse, l'ANAB recevait des relevés de notes sans pouvoir vérifier facilement qu'ils étaient authentiques et non modifiés — un vrai risque pour l'attribution des bourses. <b>Avec Cursus</b>, l'ANAB dispose de son propre compte pour consulter et vérifier directement les résultats des étudiants auprès des données officielles de l'université, sans dépendre du document qu'on lui présente.</div>
      </div>
    </div>

  </div>
</div>

<footer>
  Cursus — Plateforme académique pour les universités du Niger
</footer>

</body>
</html>