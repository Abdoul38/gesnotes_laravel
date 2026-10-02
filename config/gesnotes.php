<?php

return [

    // Déconnexion après N minutes sans activité (ancien session_start_secure()).
    'inactivite_minutes' => (int) env('INACTIVITE_MINUTES', 30),

    // Pendant la migration, les pages pas encore converties s'ouvrent dans
    // l'ancienne application. {code} = code de l'université (udh, uam…).
    'ancienne_url' => env('ANCIENNE_APP_URL', 'https://{code}.cursus-niger.net'),

    // Dossier de l'ancienne application : photos, logos, pièces y restent
    // jusqu'à la migration des fichiers (servis par FichierController).
    'ancienne_app_chemin' => env('ANCIENNE_APP_CHEMIN'),

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
        'promotion_form' => 'inscription_ped', 'filiere_form' => 'inscription_ped',
    ],
];
