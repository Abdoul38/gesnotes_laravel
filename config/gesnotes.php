<?php

return [

    // Déconnexion après N minutes sans activité (ancien session_start_secure()).
    'inactivite_minutes' => (int) env('INACTIVITE_MINUTES', 30),

    // Fichiers déposés (photos, logos, documents) : DOSSIER_UPLOADS, sinon le
    // dossier uploads/ de l'ancienne application (lu et écrit par les deux
    // versions pendant la migration — voir BASCULE.md pour le déplacer).
    // App\Services\Fichiers, FichierController.
    'dossier_uploads' => env('DOSSIER_UPLOADS', env('ANCIENNE_APP_CHEMIN') ? rtrim(env('ANCIENNE_APP_CHEMIN'), '/\\').'/uploads' : null),

    // Racine de l'ancienne application (pendant la migration seulement).
    'ancienne_app_chemin' => env('ANCIENNE_APP_CHEMIN'),

    // Images du carrousel du portail candidats : <dossier>/<sigle>/slide-N.jpg
    // (ancien candidature/assets/img/).
    'dossier_images_portail' => env('DOSSIER_IMAGES_PORTAIL', env('ANCIENNE_APP_CHEMIN') ? rtrim(env('ANCIENNE_APP_CHEMIN'), '/\\').'/candidature/assets/img' : null),

    // Espaces Ministère (MESR) et ANAB : leurs comptes (mesr_comptes,
    // anab_comptes) et leur journal sont dans la base de cette université,
    // comme dans l'ancienne application (base UDH).
    'espaces_comptes' => env('ESPACES_UNIVERSITE_COMPTES', 'udh'),

    // Compte MySQL avec lequel le Ministère et l'ANAB lisent les bases des
    // universités (SELECT partout + INSERT sur audit_log). Vide : compte principal.
    'espaces_db' => ['username' => env('ESPACES_DB_USERNAME'), 'password' => env('ESPACES_DB_PASSWORD')],

    // Clé des QR codes de vérification (App\Services\CleVerification) : ne jamais
    // la changer une fois des documents imprimés. À défaut : APP_KEY.
    'verification_secret' => env('VERIFICATION_SECRET'),

    // Sauvegardes SQL (page Sauvegarde) : hors du dossier public, un
    // sous-dossier par université.
    'dossier_sauvegardes' => env('DOSSIER_SAUVEGARDES', storage_path('app/private/sauvegardes')),

    // Pièces déposées par les candidats sur le portail (CNI, extrait de
    // naissance…), consultées depuis le dossier étudiant.
    'dossier_pieces_candidats' => env('DOSSIER_PIECES_CANDIDATS', env('ANCIENNE_APP_CHEMIN') ? rtrim(env('ANCIENNE_APP_CHEMIN'), '/\\').'/candidature/uploads/pieces' : null),

    // Pages hors menu : même permission que leur page parente
    // (copie de $permissionsPages dans l'ancien index.php).
    'permissions_pages' => [
        'pv_detail' => 'pv_voir', 'pv_creer' => 'pv_creer',
        'pv_imprimer' => 'pv_imprimer', 'pv_exporter' => 'pv_imprimer',
        'notes_importer' => 'inscription_ped', 'import_ue_matieres' => 'inscription_ped',
        'reclamation_traitement' => 'notes_saisir', 'reclamations_export' => 'notes_saisir',
        'etudiant_form' => 'etudiants_gerer', 'dossier_etudiant' => 'etudiants_voir',
        'api_etudiants' => 'etudiants_voir', 'api_recherche_etudiants' => 'etudiants_voir',
        'liste_inscrits_pdf' => 'inscription_voir', 'liste_inscrits_excel' => 'inscription_voir',
        'recu_paiement' => 'paiements_voir', 'recu_inscription' => 'paiements_voir',
        'attestation' => 'inscription_ped', 'attestations' => 'inscription_ped',
        'diplome' => 'inscription_ped', 'registre_diplomes' => 'inscription_ped',
        'certificat_scolarite' => 'certificat_sco',
        'rapports_export' => 'rapports',
        'portail_export' => 'inscription_gerer', 'dossier_candidat' => 'inscription_gerer',
        'demandes_inscription' => 'inscription_gerer',
        'promotion_form' => 'inscription_ped', 'filiere_form' => 'inscription_ped',
    ],
];
