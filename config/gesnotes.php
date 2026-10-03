<?php

return [

    // Déconnexion après N minutes sans activité (ancien session_start_secure()).
    'inactivite_minutes' => (int) env('INACTIVITE_MINUTES', 30),

    // Pendant la migration, les pages pas encore converties s'ouvrent dans
    // l'ancienne application. {code} = code de l'université (udh, uam…).
    'ancienne_url' => env('ANCIENNE_APP_URL', 'https://{code}.cursus-niger.net'),

    // Dossier uploads/ de l'ancienne application : photos, logos, pièces y
    // restent pendant la migration, lus et écrits par les deux versions
    // (App\Services\Fichiers, FichierController).
    'dossier_uploads' => env('DOSSIER_UPLOADS', env('ANCIENNE_APP_CHEMIN') ? rtrim(env('ANCIENNE_APP_CHEMIN'), '/\\').'/uploads' : null),

    // Racine de l/x27ancienne application (images du portail candidats…)./n    /x27ancienne_app_chemin/x27 => env(/x27ANCIENNE_APP_CHEMIN/x27),/n/n    // Pièces déposées par les candidats sur le portail (CNI, extrait de
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
