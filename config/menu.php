<?php

// ============================================================
// Menu latéral — généré depuis menu_items() de l'ancienne application
// (includes/layout.php). Chaque entrée : page => [icône, libellé, permission].
// La permission sert AUSSI au contrôle d'accès côté serveur (middleware
// « permission »), exactement comme dans l'ancien routeur.
// Une page dont la route Laravel existe (même nom) s'ouvre ici ; sinon le
// lien mène à l'ancienne application (voir App\Navigation\Menu).
// ============================================================

return [
    'Tableau de bord' => [
        'accueil' => ['🏠', 'Tableau de bord', 'etudiants_voir'],
    ],
    'Étudiants' => [
        'etudiants' => ['👥', 'Liste des étudiants', 'etudiants_voir'],
        'etudiant_importer' => ['📥', 'Importer des étudiants', 'etudiants_gerer'],
        'import_photos' => ['📸', 'Importer des photos', 'etudiants_photos'],
        'carte_etudiant' => ['🪪', 'Cartes étudiantes', 'carte_etudiant'],
    ],
    'Inscriptions' => [
        'inscription_scolarite' => ['💳', 'Inscription scolarité', 'inscription_gerer'],
        'inscription_pedagogique' => ['🎓', 'Inscription pédagogique', 'inscription_ped'],
        'choix_options' => ['🔀', 'Choix matières optionnelles', 'inscription_ped'],
        'transfert_niveau' => ['🔄', 'Transfert / Passage', 'passage_niveau'],
        'passage_niveau' => ['🎓', 'Passage en masse', 'passage_niveau'],
        'import_etudiants' => ['📥', 'Import étudiants', 'import_etudiants'],
        'inscription_ped_masse' => ['🎓', 'Inscript. pédago en masse', 'inscription_ped'],
        'promotions' => ['📐', 'Promotions', 'inscription_ped'],
        'portail_candidats_admin' => ['🎓', 'Candidatures bacheliers', 'inscription_gerer'],
    ],
    'Listes & Rapports' => [
        'liste_inscrits' => ['📋', 'Listes des inscrits', 'inscription_voir'],
        'stats' => ['📊', 'Statistiques', 'stats_voir'],
        'historique' => ['📜', 'Historique académique', 'inscription_voir'],
        'releves' => ['📄', 'Relevés de notes', 'releve_notes'],
        'deliberation' => ['⚖️', 'Délibérations', 'pv_creer'],
        'presidents_jury' => ['🧑‍⚖️', 'Présidents de jury', 'pv_creer'],
        'liste_valides' => ['✅', 'Liste des admis', 'pv_voir'],
        'diplomes' => ['🎓', 'Diplômes & Attestations', 'inscription_ped'],
    ],
    'Notes & PV' => [
        'pv' => ['📋', 'Procès-Verbaux', 'pv_voir'],
        'notes' => ['✏️', 'Saisie des notes', 'inscription_ped'],
        'presence' => ['📅', 'Présence', 'notes_saisir'],
        'reclamations' => ['📨', 'Réclamations', 'notes_saisir'],
        'verif_resultats' => ['📋', 'Verification', 'inscription_ped'],
    ],
    'Examens & Planning' => [
        'convocations' => ['📨', 'Convocations', 'pv_creer'],
        'planning' => ['📅', 'Planning examens', 'etudiants_voir'],
        'emploi_temps' => ['🗓️', 'Emploi du temps', 'etudiants_voir'],
    ],
    'Reporting' => [
        'rapports' => ['📊', 'Rapports & Exports', 'rapports'],
    ],
    'Communication' => [
        'annonces' => ['📢', 'Annonces', 'etudiants_voir'],
        'notifications_email' => ['✉️', 'Emails étudiants', 'etudiants_gerer'],
        'messagerie' => ['💬', 'Messagerie', 'etudiants_voir'],
        'notifier_pv' => ['📨', 'Notifier un PV', 'inscription_ped'],
        'dashboard_notifications' => ['📊', 'Vue d\'ensemble', 'inscription_ped'],
    ],
    'Scolarité' => [
        'paiements' => ['💰', 'Paiements', 'paiements_voir'],
        'finances' => ['📈', 'Finances', 'paiements_voir'],
        'paiements_en_ligne' => ['💳', 'Paiements en ligne', 'paiements_voir'],
        'komipay_litiges' => ['💳', 'komipay litiges', 'paiements_voir'],
        'relances' => ['📨', 'Relances impayés', 'paiements_voir'],
        'enseignants' => ['👨‍🏫', 'Enseignants', 'etudiants_voir'],
    ],
    'Portails' => [
        'portail_etudiant' => ['🎒', 'Portail étudiant', 'portail_etudiant'],
        'portail_enseignant' => ['📝', 'Portail enseignant', 'notes_saisir'],
        'jury' => ['⚖️', 'Mes jurys', 'notes_saisir'],
    ],
    'Système' => [
        'sauvegarde' => ['💾', 'Sauvegarde BD', 'annees_gerer'],
        'logs' => ['📋', 'Logs activité', 'annees_gerer'],
    ],
    'Administration' => [
        'facultes' => ['🏛️', 'Facultés', 'inscription_ped'],
        'filieres' => ['📚', 'Filières & UE', 'inscription_ped'],
        'suivi_execution' => ['📚', 'suivi Execution', 'etudiants_voir'],
        'niveaux' => ['🏛️', 'Niveaux', 'inscription_ped'],
        'salles' => ['🚪', 'Salles', 'inscription_ped'],
        'annees' => ['📅', 'Années académiques', 'inscription_ped'],
        'tarifs_niveaux' => ['💰', 'Tarifs d\'inscription', 'inscription_ped'],
        'utilisateurs' => ['👤', 'Utilisateurs', 'utilisateurs_gerer'],
        'generer_comptes_etudiants' => ['👤', 'Generer comptes étudiants', 'utilisateurs_gerer'],
        'roles' => ['🎭', 'Rôles & Permissions', 'utilisateurs_gerer'],
        'signataires' => ['✍️', 'Signataires', 'inscription_ped'],
        'gestion_parents' => ['👨‍👩‍👧', 'Comptes Parents', 'utilisateurs_gerer'],
        'parametres_sms' => ['✍️', 'Notif SMS', 'inscription_ped'],
        'parametres_email' => ['✍️', 'Notif EMAIL', 'inscription_ped'],
        'parametres_licence' => ['🔑', 'Licence', 'inscription_ped'],
        'parametres' => ['⚙️', 'Paramètres', 'inscription_ped'],
    ],
];
