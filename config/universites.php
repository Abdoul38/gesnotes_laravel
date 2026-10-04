<?php

// ============================================================
// Registre des universités (reprend l'ancien config/universities.php).
// ============================================================
// Une base MySQL par université, même code pour toutes. L'université est
// choisie d'après le sous-domaine (voir App\Http\Middleware\ResoudreUniversite).
// Aucun mot de passe ici : hôte, utilisateur et mot de passe sont communs
// (DB_HOST, DB_USERNAME, DB_PASSWORD dans .env) ; seul le nom de la base
// change, et peut être remplacé par UNI_<CODE>_DB (ex. UNI_UDH_DB=gesnotes).
// ============================================================

$universite = fn (string $code, array $infos) => $infos + [
    'code' => $code,
    'base' => env('UNI_'.strtoupper($code).'_DB', 'edufile_'.$code),
    'actif' => (bool) env('UNI_'.strtoupper($code).'_ACTIF', true),
    'espace' => 'gesnotes',
];

return [

    // Domaine principal : <code>.<domaine> (ex. udh.cursus-niger.net).
    // En local : <code>.localhost.
    'domaine' => env('UNIVERSITES_DOMAINE', 'cursus-niger.net'),

    // Université utilisée hors requête web (commandes, tests) et pour
    // les hôtes *.alwaysdata.net (comme l'ancien loader).
    'defaut' => env('UNIVERSITE_DEFAUT', 'udh'),

    // L'ancien code permettait de changer d'université avec ?uni=xxx.
    // Gardé uniquement en local : en production, cela permettait à une
    // session ouverte sur une université d'interroger la base d'une autre.
    'autoriser_parametre_uni' => (bool) env('UNIVERSITES_PARAMETRE_UNI', false),

    'liste' => [
        'udh' => $universite('udh', ['nom' => 'Université Djibo Hamani de Tahoua', 'sigle' => 'UDH', 'ville' => 'Tahoua', 'couleur' => '#1A3A5C', 'logo' => 'logo_udh.png', 'base' => env('UNI_UDH_DB', 'edufile_gesnotes')]),
        'uam' => $universite('uam', ['nom' => 'Université Abdou Moumouni de Niamey', 'sigle' => 'UAM', 'ville' => 'Niamey', 'couleur' => '#166534', 'logo' => 'logo_uam.png']),
        'udo' => $universite('udo', ['nom' => 'Université de Dosso', 'sigle' => 'UDO', 'ville' => 'Dosso', 'couleur' => '#166534', 'logo' => 'logo_udo.png']),
        'uda' => $universite('uda', ['nom' => 'Université de Diffa', 'sigle' => 'UDA', 'ville' => 'Diffa', 'couleur' => '#166534', 'logo' => 'logo_udi.png']),
        'uddm' => $universite('uddm', ['nom' => 'Université de Maradi', 'sigle' => 'UDDM', 'ville' => 'Maradi', 'couleur' => '#166534', 'logo' => 'logo_uddm.png']),
        'uas' => $universite('uas', ['nom' => 'Université de Zinder', 'sigle' => 'UAS', 'ville' => 'Zinder', 'couleur' => '#166534', 'logo' => 'logo_udz.png']),
        'ubb' => $universite('ubb', ['nom' => 'Université de Tillaberi', 'sigle' => 'UBB', 'ville' => 'Tillaberi', 'couleur' => '#166534', 'logo' => 'logo_ubb.png']),
        'uaz' => $universite('uaz', ['nom' => "Université d'Agadez", 'sigle' => 'UAZ', 'ville' => 'Agadez', 'couleur' => '#166534', 'logo' => 'logo_uaz.png']),
        'uim' => $universite('uim', ['nom' => 'Université Islamique de Niamey', 'sigle' => 'UIM', 'ville' => 'Niamey', 'couleur' => '#166534', 'logo' => 'logo_uaz.png']),

        // Espaces nationaux : pas de base propre, ils lisent les bases des
        // universités (leurs comptes sont dans la base UDH, voir
        // gesnotes.espaces_comptes) — routes/espaces.php.
        'ministere' => $universite('ministere', ['nom' => "Ministère de l'Enseignement Supérieur", 'sigle' => 'MESR', 'ville' => 'Niamey', 'couleur' => '#1A3A5C', 'logo' => 'logo_niger.png', 'espace' => 'ministere', 'base' => null]),
        'anab' => $universite('anab', ['nom' => 'Agence Nationale des Bourses', 'sigle' => 'ANAB', 'ville' => 'Niamey', 'couleur' => '#7C3AED', 'logo' => 'logo_anab.png', 'espace' => 'anab', 'base' => null]),
    ],
];
