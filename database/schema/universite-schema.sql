-- ============================================================
-- Schéma de référence des bases d'université (structure seule).
-- Union des schémas de toutes les bases existantes au 02/10/2026 :
-- base « gesnotes » + valeur 'licence_validee' de passages_niveau.decision
-- (présente dans gesnotes_uam). DEFINER retiré pour la portabilité.
-- Sert à créer les bases de test et les nouvelles universités.
-- ============================================================


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `affectations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `enseignant_id` int(10) unsigned NOT NULL,
  `matiere_id` int(10) unsigned NOT NULL,
  `annee_id` int(10) unsigned NOT NULL,
  `type_charge` enum('cours','td','cours_td') NOT NULL DEFAULT 'cours',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_affect` (`enseignant_id`,`matiere_id`,`annee_id`),
  KEY `matiere_id` (`matiere_id`),
  KEY `annee_id` (`annee_id`),
  CONSTRAINT `affectations_ibfk_1` FOREIGN KEY (`enseignant_id`) REFERENCES `enseignants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `affectations_ibfk_2` FOREIGN KEY (`matiere_id`) REFERENCES `matieres` (`id`) ON DELETE CASCADE,
  CONSTRAINT `affectations_ibfk_3` FOREIGN KEY (`annee_id`) REFERENCES `annees_academiques` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `anab_comptes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `login` varchar(50) NOT NULL,
  `mot_de_passe` varchar(255) NOT NULL,
  `nom_complet` varchar(100) NOT NULL,
  `role` enum('admin','agent') NOT NULL DEFAULT 'agent',
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `derniere_connexion` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `login` (`login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `annees_academiques` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `libelle` varchar(20) NOT NULL,
  `date_debut` date DEFAULT NULL,
  `date_fin` date DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_libelle` (`libelle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `annonces` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `titre` varchar(200) NOT NULL,
  `contenu` text NOT NULL,
  `type` enum('info','important','urgent','evenement') NOT NULL DEFAULT 'info',
  `cible` enum('tous','etudiants','enseignants','filiere','niveau') NOT NULL DEFAULT 'tous',
  `filiere_id` int(10) unsigned DEFAULT NULL,
  `niveau_id` int(10) unsigned DEFAULT NULL,
  `date_debut` date NOT NULL DEFAULT curdate(),
  `date_fin` date DEFAULT NULL,
  `publiee` tinyint(1) NOT NULL DEFAULT 0,
  `epinglee` tinyint(1) NOT NULL DEFAULT 0,
  `vues` int(10) unsigned NOT NULL DEFAULT 0,
  `auteur_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `filiere_id` (`filiere_id`),
  KEY `niveau_id` (`niveau_id`),
  KEY `auteur_id` (`auteur_id`),
  CONSTRAINT `annonces_ibfk_1` FOREIGN KEY (`filiere_id`) REFERENCES `filieres` (`id`) ON DELETE SET NULL,
  CONSTRAINT `annonces_ibfk_2` FOREIGN KEY (`niveau_id`) REFERENCES `niveaux` (`id`) ON DELETE SET NULL,
  CONSTRAINT `annonces_ibfk_3` FOREIGN KEY (`auteur_id`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `api_rate_limits` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `action` varchar(30) NOT NULL,
  `identifiant` varchar(100) NOT NULL,
  `tentatives` int(10) unsigned NOT NULL DEFAULT 1,
  `derniere_tentative` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cle` (`ip`,`action`,`identifiant`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `app_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `niveau` enum('DEBUG','INFO','WARNING','ERROR','CRITICAL') NOT NULL DEFAULT 'INFO',
  `message` text NOT NULL,
  `contexte` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`contexte`)),
  `user_id` int(10) unsigned DEFAULT NULL,
  `page` varchar(100) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_logs_niveau` (`niveau`),
  KEY `idx_logs_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attestations_emises` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `numero` varchar(30) NOT NULL,
  `etudiant_id` int(10) unsigned NOT NULL,
  `type` varchar(20) NOT NULL,
  `nom` varchar(100) NOT NULL,
  `prenoms` varchar(100) NOT NULL,
  `filiere` varchar(200) DEFAULT NULL,
  `mention_grade` varchar(30) DEFAULT NULL,
  `moyenne` decimal(5,2) DEFAULT NULL,
  `annee_academique` varchar(50) DEFAULT NULL,
  `date_emission` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_numero` (`numero`),
  UNIQUE KEY `uk_et_type` (`etudiant_id`,`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `user_nom` varchar(150) DEFAULT NULL,
  `user_role` varchar(50) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `module` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `cible_id` int(10) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_action` (`action`),
  KEY `idx_module` (`module`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `candidats` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `telephone` varchar(30) NOT NULL,
  `mot_de_passe` varchar(255) NOT NULL,
  `token_reset` varchar(64) DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `candidatures` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `candidat_id` int(10) unsigned NOT NULL,
  `numero_dossier` varchar(20) NOT NULL,
  `numero_depot` varchar(20) DEFAULT NULL,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(150) NOT NULL,
  `date_naissance` date DEFAULT NULL,
  `lieu_naissance` varchar(200) DEFAULT NULL,
  `nationalite` varchar(100) NOT NULL DEFAULT 'Nigérienne',
  `sexe` enum('M','F') DEFAULT NULL,
  `telephone` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `adresse` text DEFAULT NULL,
  `nom_pere` varchar(200) DEFAULT NULL,
  `nom_mere` varchar(200) DEFAULT NULL,
  `tel_urgence` varchar(30) DEFAULT NULL,
  `bac_type` varchar(20) DEFAULT NULL,
  `bac_lieu` varchar(100) DEFAULT NULL,
  `bac_annee` varchar(20) DEFAULT NULL,
  `bac_mention` varchar(50) DEFAULT NULL,
  `bac_etablissement` varchar(200) DEFAULT NULL,
  `choix1_id` int(10) unsigned DEFAULT NULL,
  `choix2_id` int(10) unsigned DEFAULT NULL,
  `choix3_id` int(10) unsigned DEFAULT NULL,
  `doc_photo` varchar(255) DEFAULT NULL,
  `doc_extrait` varchar(255) DEFAULT NULL,
  `doc_nationalite` varchar(255) DEFAULT NULL,
  `doc_releve` varchar(255) DEFAULT NULL,
  `doc_attestation` varchar(255) DEFAULT NULL,
  `doc_bulletin` varchar(255) DEFAULT NULL,
  `doc_cni` varchar(255) DEFAULT NULL,
  `doc_autre` varchar(255) DEFAULT NULL,
  `statut` enum('brouillon','soumis','en_cours','accepte','refuse','incomplet') NOT NULL DEFAULT 'brouillon',
  `notes_admin` text DEFAULT NULL,
  `traite_par` int(10) unsigned DEFAULT NULL,
  `date_traitement` datetime DEFAULT NULL,
  `etudiant_id` int(10) unsigned DEFAULT NULL,
  `etape_actuelle` tinyint(4) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `date_soumission` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `numero_dossier` (`numero_dossier`),
  KEY `candidat_id` (`candidat_id`),
  KEY `choix1_id` (`choix1_id`),
  KEY `choix2_id` (`choix2_id`),
  KEY `choix3_id` (`choix3_id`),
  KEY `idx_cand_num` (`numero_dossier`),
  KEY `idx_cand_depot` (`numero_depot`),
  KEY `idx_cand_statut` (`statut`),
  CONSTRAINT `candidatures_ibfk_1` FOREIGN KEY (`candidat_id`) REFERENCES `candidats` (`id`) ON DELETE CASCADE,
  CONSTRAINT `candidatures_ibfk_2` FOREIGN KEY (`choix1_id`) REFERENCES `filieres` (`id`) ON DELETE SET NULL,
  CONSTRAINT `candidatures_ibfk_3` FOREIGN KEY (`choix2_id`) REFERENCES `filieres` (`id`) ON DELETE SET NULL,
  CONSTRAINT `candidatures_ibfk_4` FOREIGN KEY (`choix3_id`) REFERENCES `filieres` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cartes_emises` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `numero` varchar(30) NOT NULL,
  `etudiant_id` int(10) unsigned NOT NULL,
  `annee_academique` varchar(50) NOT NULL,
  `nom` varchar(100) NOT NULL,
  `prenoms` varchar(100) NOT NULL,
  `filiere` varchar(200) DEFAULT NULL,
  `date_emission` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_numero` (`numero`),
  UNIQUE KEY `uk_et_annee` (`etudiant_id`,`annee_academique`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categories_depenses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `libelle` varchar(100) NOT NULL,
  `predefinie` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_libelle` (`libelle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `certificats_emis` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `numero` varchar(30) NOT NULL,
  `etudiant_id` int(10) unsigned NOT NULL,
  `annee_academique` varchar(50) NOT NULL,
  `nom` varchar(100) NOT NULL,
  `prenoms` varchar(100) NOT NULL,
  `filiere` varchar(200) DEFAULT NULL,
  `niveau` varchar(100) DEFAULT NULL,
  `date_emission` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_numero` (`numero`),
  UNIQUE KEY `uk_et_annee` (`etudiant_id`,`annee_academique`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `choix_options_etudiants` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `etudiant_id` int(10) unsigned NOT NULL,
  `matiere_id` int(10) unsigned NOT NULL,
  `ue_id` int(10) unsigned NOT NULL,
  `annee_id` int(10) unsigned NOT NULL,
  `promotion_id` int(10) unsigned NOT NULL,
  `semestre_id` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `saisie_par` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_choix` (`etudiant_id`,`ue_id`,`matiere_id`,`annee_id`),
  KEY `idx_etudiant_annee` (`etudiant_id`,`annee_id`),
  KEY `idx_ue_annee` (`ue_id`,`annee_id`),
  KEY `fk_choix_matiere` (`matiere_id`),
  CONSTRAINT `fk_choix_etudiant` FOREIGN KEY (`etudiant_id`) REFERENCES `etudiants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_choix_matiere` FOREIGN KEY (`matiere_id`) REFERENCES `matieres` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_choix_ue` FOREIGN KEY (`ue_id`) REFERENCES `unites_enseignement` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `demandes_inscription` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `token` varchar(64) NOT NULL,
  `nom` varchar(100) NOT NULL,
  `prenoms` varchar(150) NOT NULL,
  `date_naissance` date NOT NULL,
  `lieu_naissance` varchar(200) DEFAULT NULL,
  `sexe` enum('M','F') DEFAULT NULL,
  `nationalite` varchar(100) DEFAULT 'Nigérienne',
  `telephone` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `bac_serie` varchar(50) DEFAULT NULL,
  `bac_annee` year(4) DEFAULT NULL,
  `bac_mention` varchar(50) DEFAULT NULL,
  `etablissement_origine` varchar(200) DEFAULT NULL,
  `filiere_souhaitee` int(10) unsigned DEFAULT NULL,
  `statut` enum('en_attente','acceptee','refusee') NOT NULL DEFAULT 'en_attente',
  `notes_admin` text DEFAULT NULL,
  `etudiant_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `filiere_souhaitee` (`filiere_souhaitee`),
  KEY `etudiant_id` (`etudiant_id`),
  CONSTRAINT `demandes_inscription_ibfk_1` FOREIGN KEY (`filiere_souhaitee`) REFERENCES `filieres` (`id`) ON DELETE SET NULL,
  CONSTRAINT `demandes_inscription_ibfk_2` FOREIGN KEY (`etudiant_id`) REFERENCES `etudiants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `departements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `faculte_id` int(10) unsigned NOT NULL,
  `code` varchar(30) NOT NULL,
  `libelle` varchar(200) NOT NULL,
  `chef` varchar(200) DEFAULT NULL COMMENT 'Nom du chef de département',
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dep_code` (`code`),
  KEY `fk_dep_faculte` (`faculte_id`),
  CONSTRAINT `fk_dep_faculte` FOREIGN KEY (`faculte_id`) REFERENCES `facultes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `depenses` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `categorie_id` int(10) unsigned NOT NULL,
  `libelle` varchar(200) NOT NULL,
  `montant` decimal(10,2) NOT NULL DEFAULT 0.00,
  `date_depense` date NOT NULL,
  `mode_paiement` enum('especes','virement','mobile_money','cheque') NOT NULL DEFAULT 'especes',
  `beneficiaire` varchar(150) DEFAULT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `saisi_par` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `annee_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `categorie_id` (`categorie_id`),
  CONSTRAINT `depenses_ibfk_1` FOREIGN KEY (`categorie_id`) REFERENCES `categories_depenses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `diplomes_emis` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `numero` varchar(30) NOT NULL,
  `etudiant_id` int(10) unsigned NOT NULL,
  `niveau` varchar(20) NOT NULL,
  `nom` varchar(100) NOT NULL,
  `prenoms` varchar(100) NOT NULL,
  `domaine` varchar(150) DEFAULT NULL,
  `mention_academique` varchar(150) DEFAULT NULL,
  `specialite` varchar(200) DEFAULT NULL,
  `mention_grade` varchar(30) DEFAULT NULL,
  `moyenne` decimal(5,2) DEFAULT NULL,
  `session_libelle` varchar(100) DEFAULT NULL,
  `date_emission` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_numero` (`numero`),
  UNIQUE KEY `uk_et_niveau` (`etudiant_id`,`niveau`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `documents_etudiants` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `etudiant_id` int(10) unsigned NOT NULL,
  `type_doc` enum('cni','acte_naissance','bac','photo','certificat_residence','autre') NOT NULL,
  `libelle` varchar(200) NOT NULL,
  `fichier` varchar(255) NOT NULL,
  `taille` int(11) DEFAULT NULL,
  `valide` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `etudiant_id` (`etudiant_id`),
  CONSTRAINT `documents_etudiants_ibfk_1` FOREIGN KEY (`etudiant_id`) REFERENCES `etudiants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `email_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `destinataire` varchar(150) NOT NULL,
  `sujet` varchar(255) NOT NULL,
  `statut` enum('envoye','echec','simule') NOT NULL DEFAULT 'simule',
  `contexte` varchar(50) DEFAULT NULL,
  `reference_id` int(10) unsigned DEFAULT NULL,
  `reponse_brute` text DEFAULT NULL,
  `date_envoi` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_contexte` (`contexte`,`reference_id`),
  KEY `idx_date` (`date_envoi`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `emploi_temps` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `promotion_id` int(10) unsigned NOT NULL,
  `annee_id` int(10) unsigned NOT NULL,
  `semestre_id` int(10) unsigned NOT NULL,
  `jour` enum('Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi') NOT NULL,
  `heure_debut` time NOT NULL,
  `heure_fin` time NOT NULL,
  `matiere_id` int(10) unsigned DEFAULT NULL,
  `enseignant_id` int(10) unsigned DEFAULT NULL,
  `salle` varchar(100) DEFAULT NULL,
  `type_seance` enum('cours','td','tp','exam') NOT NULL DEFAULT 'cours',
  `couleur` varchar(10) DEFAULT '#2C5F9E',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `salle_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `promotion_id` (`promotion_id`),
  KEY `annee_id` (`annee_id`),
  KEY `semestre_id` (`semestre_id`),
  KEY `matiere_id` (`matiere_id`),
  KEY `enseignant_id` (`enseignant_id`),
  CONSTRAINT `emploi_temps_ibfk_1` FOREIGN KEY (`promotion_id`) REFERENCES `promotions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `emploi_temps_ibfk_2` FOREIGN KEY (`annee_id`) REFERENCES `annees_academiques` (`id`) ON DELETE CASCADE,
  CONSTRAINT `emploi_temps_ibfk_3` FOREIGN KEY (`semestre_id`) REFERENCES `semestres` (`id`) ON DELETE CASCADE,
  CONSTRAINT `emploi_temps_ibfk_4` FOREIGN KEY (`matiere_id`) REFERENCES `matieres` (`id`) ON DELETE SET NULL,
  CONSTRAINT `emploi_temps_ibfk_5` FOREIGN KEY (`enseignant_id`) REFERENCES `enseignants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `enseignants` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `matricule` varchar(30) DEFAULT NULL,
  `nom` varchar(100) NOT NULL,
  `prenoms` varchar(150) NOT NULL,
  `grade` varchar(100) DEFAULT NULL,
  `specialite` varchar(200) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `telephone` varchar(30) DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `faculte_id` int(10) unsigned DEFAULT NULL,
  `departement_id` int(11) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `date_naissance` date DEFAULT NULL,
  `lieu_naissance` varchar(100) DEFAULT NULL,
  `diplome_plus_eleve` varchar(150) DEFAULT NULL,
  `date_recrutement` date DEFAULT NULL,
  `adresse` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `etudiants` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `numero` varchar(20) DEFAULT NULL,
  `numero_dossier` varchar(20) DEFAULT NULL COMMENT 'N° dossier portail (nouveaux bacheliers uniquement)',
  `filiere_id` int(10) unsigned DEFAULT NULL COMMENT 'Filière de l étudiant',
  `nom` varchar(100) NOT NULL,
  `prenoms` varchar(150) NOT NULL,
  `date_naissance` date DEFAULT NULL,
  `lieu_naissance` varchar(150) DEFAULT NULL,
  `sexe` enum('M','F') DEFAULT NULL,
  `nationalite` varchar(100) DEFAULT 'Nigérienne',
  `email` varchar(150) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL COMMENT 'Mot de passe app mobile (bcrypt)',
  `last_login` datetime DEFAULT NULL,
  `adresse` text DEFAULT NULL,
  `nom_pere` varchar(200) DEFAULT NULL,
  `nom_mere` varchar(200) DEFAULT NULL,
  `tel_urgence` varchar(30) DEFAULT NULL,
  `bac_serie` varchar(50) DEFAULT NULL,
  `bac_annee` year(4) DEFAULT NULL,
  `bac_mention` varchar(50) DEFAULT NULL,
  `etablissement_origine` varchar(200) DEFAULT NULL,
  `statut_etudiant` enum('actif','suspendu','transfere','abandonne','diplome') NOT NULL DEFAULT 'actif',
  `telephone` varchar(20) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `numero_depot` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_numero` (`numero`),
  UNIQUE KEY `uk_etudiants_numero` (`numero`),
  KEY `idx_nom` (`nom`),
  KEY `fk_etudiant_filiere` (`filiere_id`),
  KEY `idx_etudiant_dossier` (`numero_dossier`),
  FULLTEXT KEY `ft_etudiant` (`nom`,`prenoms`),
  CONSTRAINT `fk_etudiant_filiere` FOREIGN KEY (`filiere_id`) REFERENCES `filieres` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `examens_planning` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `pv_id` int(10) unsigned NOT NULL,
  `matiere_id` int(10) unsigned NOT NULL,
  `date_examen` date NOT NULL,
  `heure_debut` time NOT NULL,
  `heure_fin` time NOT NULL,
  `salle` varchar(100) DEFAULT NULL,
  `surveillant_1` int(10) unsigned DEFAULT NULL,
  `surveillant_2` int(10) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `salle_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_planning` (`pv_id`,`matiere_id`),
  KEY `matiere_id` (`matiere_id`),
  KEY `surveillant_1` (`surveillant_1`),
  KEY `surveillant_2` (`surveillant_2`),
  CONSTRAINT `examens_planning_ibfk_1` FOREIGN KEY (`pv_id`) REFERENCES `pv_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `examens_planning_ibfk_2` FOREIGN KEY (`matiere_id`) REFERENCES `matieres` (`id`) ON DELETE CASCADE,
  CONSTRAINT `examens_planning_ibfk_3` FOREIGN KEY (`surveillant_1`) REFERENCES `enseignants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `examens_planning_ibfk_4` FOREIGN KEY (`surveillant_2`) REFERENCES `enseignants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `facultes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `libelle` varchar(200) NOT NULL,
  `logo` varchar(255) DEFAULT NULL COMMENT 'Nom du fichier logo dans uploads/logos/',
  `couleur` varchar(10) NOT NULL DEFAULT '#1A3A5C',
  `ville` varchar(100) DEFAULT 'Tahoua',
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `filieres` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `libelle` varchar(200) NOT NULL,
  `departement` varchar(200) NOT NULL,
  `faculte` varchar(200) NOT NULL DEFAULT 'FADEG',
  `faculte_id` int(10) unsigned DEFAULT NULL,
  `filiere_parente_id` int(10) unsigned DEFAULT NULL,
  `departement_id` int(10) unsigned DEFAULT NULL COMMENT 'Lien vers la table departements',
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `domaine` varchar(150) DEFAULT NULL,
  `mention_academique` varchar(150) DEFAULT NULL,
  `type_formation` varchar(20) NOT NULL DEFAULT 'classique',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `fk_filiere_parente` (`filiere_parente_id`),
  CONSTRAINT `fk_filiere_parente` FOREIGN KEY (`filiere_parente_id`) REFERENCES `filieres` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `grille_tarifaire` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `filiere_id` int(10) unsigned NOT NULL,
  `niveau_id` int(10) unsigned NOT NULL,
  `type_paiement` enum('inscription','scolarite','rattrapage','diplome','attestation','certificat','releve_note','depot_dossier','autre') NOT NULL DEFAULT 'scolarite',
  `montant` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tarif` (`filiere_id`,`niveau_id`,`type_paiement`),
  KEY `niveau_id` (`niveau_id`),
  CONSTRAINT `grille_tarifaire_ibfk_1` FOREIGN KEY (`filiere_id`) REFERENCES `filieres` (`id`) ON DELETE CASCADE,
  CONSTRAINT `grille_tarifaire_ibfk_2` FOREIGN KEY (`niveau_id`) REFERENCES `niveaux` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inscriptions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `etudiant_id` int(10) unsigned NOT NULL,
  `promotion_id` int(10) unsigned NOT NULL,
  `niveau_id` int(10) unsigned DEFAULT NULL COMMENT 'Niveau pour cette année',
  `annee_id` int(10) unsigned DEFAULT NULL,
  `montant_paye` decimal(10,0) NOT NULL DEFAULT 10000,
  `mode_paiement` enum('especes','mobile_money','virement','cheque') NOT NULL DEFAULT 'especes',
  `recu_numero` varchar(50) DEFAULT NULL,
  `saisie_par` int(10) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `date_inscription` date NOT NULL,
  `statut` enum('inscrit','annule','transfere') NOT NULL DEFAULT 'inscrit',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_inscription` (`etudiant_id`,`promotion_id`),
  KEY `promotion_id` (`promotion_id`),
  KEY `fk_inscription_niveau` (`niveau_id`),
  KEY `fk_inscription_annee` (`annee_id`),
  KEY `idx_ins_et_statut` (`etudiant_id`,`statut`),
  CONSTRAINT `fk_inscription_annee` FOREIGN KEY (`annee_id`) REFERENCES `annees_academiques` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inscription_niveau` FOREIGN KEY (`niveau_id`) REFERENCES `niveaux` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `inscriptions_ibfk_1` FOREIGN KEY (`etudiant_id`) REFERENCES `etudiants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inscriptions_ibfk_2` FOREIGN KEY (`promotion_id`) REFERENCES `promotions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inscriptions_en_ligne` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `candidature_id` int(10) unsigned DEFAULT NULL,
  `candidat_id` int(10) unsigned DEFAULT NULL,
  `numero_dossier` varchar(20) NOT NULL,
  `numero_depot` varchar(20) NOT NULL,
  `filiere_id` int(10) unsigned DEFAULT NULL,
  `niveau_id` int(10) unsigned DEFAULT NULL,
  `annee_id` int(10) unsigned DEFAULT NULL,
  `montant` decimal(10,2) NOT NULL DEFAULT 10000.00,
  `moyen_paiement` enum('mobile_money','mynita','amana','visa_master','especes') NOT NULL,
  `operateur` varchar(30) DEFAULT NULL,
  `telephone_paiement` varchar(30) DEFAULT NULL,
  `reference_paiement` varchar(100) DEFAULT NULL,
  `code_achat_komipay` varchar(20) DEFAULT NULL COMMENT 'Code d''achat MyNITA renvoyé par KomiPay, à afficher au candidat',
  `statut_paiement` enum('en_attente','valide','echec','rembourse') NOT NULL DEFAULT 'en_attente',
  `date_paiement` datetime DEFAULT NULL,
  `statut` enum('en_cours','complete','annule') NOT NULL DEFAULT 'en_cours',
  `etudiant_id` int(10) unsigned DEFAULT NULL,
  `matricule` varchar(20) DEFAULT NULL,
  `recu_numero` varchar(30) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `candidature_id` (`candidature_id`),
  KEY `candidat_id` (`candidat_id`),
  CONSTRAINT `inscriptions_en_ligne_ibfk_1` FOREIGN KEY (`candidature_id`) REFERENCES `candidatures` (`id`),
  CONSTRAINT `inscriptions_en_ligne_ibfk_2` FOREIGN KEY (`candidat_id`) REFERENCES `candidats` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inscriptions_pedagogiques` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `etudiant_id` int(10) unsigned NOT NULL,
  `inscription_id` int(10) unsigned NOT NULL COMMENT 'Inscription scolarité parente',
  `semestre_id` int(10) unsigned NOT NULL COMMENT 'Semestre autorisé',
  `annee_id` int(10) unsigned NOT NULL COMMENT 'Année académique',
  `statut` enum('actif','annule') NOT NULL DEFAULT 'actif',
  `date_inscription` date NOT NULL DEFAULT curdate(),
  `saisie_par` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_insc_ped` (`etudiant_id`,`semestre_id`,`annee_id`),
  KEY `inscription_id` (`inscription_id`),
  KEY `semestre_id` (`semestre_id`),
  KEY `annee_id` (`annee_id`),
  CONSTRAINT `inscriptions_pedagogiques_ibfk_1` FOREIGN KEY (`etudiant_id`) REFERENCES `etudiants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inscriptions_pedagogiques_ibfk_2` FOREIGN KEY (`inscription_id`) REFERENCES `inscriptions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inscriptions_pedagogiques_ibfk_3` FOREIGN KEY (`semestre_id`) REFERENCES `semestres` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inscriptions_pedagogiques_ibfk_4` FOREIGN KEY (`annee_id`) REFERENCES `annees_academiques` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `journal_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `utilisateur_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `table_cible` varchar(50) DEFAULT NULL,
  `enregistrement_id` int(10) unsigned DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_adresse` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`utilisateur_id`),
  KEY `idx_audit_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_tentatives` (
  `ip` varchar(45) NOT NULL,
  `action` varchar(40) NOT NULL,
  `echecs` int(10) unsigned NOT NULL DEFAULT 0,
  `dernier_echec` datetime NOT NULL,
  PRIMARY KEY (`ip`,`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `logs_activite` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `user_nom` varchar(150) DEFAULT NULL,
  `user_role` varchar(50) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `module` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `entite_type` varchar(50) DEFAULT NULL,
  `entite_id` int(10) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(300) DEFAULT NULL,
  `donnees_avant` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`donnees_avant`)),
  `donnees_apres` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`donnees_apres`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_module` (`module`),
  KEY `idx_action` (`action`),
  KEY `idx_date` (`created_at`),
  KEY `idx_entite` (`entite_type`,`entite_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `matieres` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ue_id` int(10) unsigned NOT NULL,
  `code` varchar(20) NOT NULL,
  `libelle` varchar(200) NOT NULL,
  `note_max_examen` decimal(5,2) NOT NULL DEFAULT 40.00,
  `note_max_cc` decimal(5,2) NOT NULL DEFAULT 0.00,
  `note_max_totale` decimal(5,2) NOT NULL DEFAULT 40.00,
  `credits` int(10) unsigned NOT NULL DEFAULT 4,
  `coefficient` decimal(4,2) NOT NULL DEFAULT 1.00,
  `type_matiere` enum('obligatoire','optionnelle') NOT NULL DEFAULT 'obligatoire',
  `ordre` int(10) unsigned NOT NULL DEFAULT 0,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `finalisee` tinyint(1) NOT NULL DEFAULT 0,
  `finalisee_par` int(10) unsigned DEFAULT NULL,
  `finalisee_le` datetime DEFAULT NULL,
  `volume_horaire_prevu` decimal(5,1) DEFAULT NULL,
  `heures_faites` decimal(5,1) NOT NULL DEFAULT 0.0,
  `heures_maj_par` int(10) unsigned DEFAULT NULL,
  `heures_maj_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_matiere` (`ue_id`,`code`),
  CONSTRAINT `matieres_ibfk_1` FOREIGN KEY (`ue_id`) REFERENCES `unites_enseignement` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mesr_cache` (
  `cache_key` varchar(191) NOT NULL,
  `value` longtext DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`cache_key`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mesr_comptes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `login` varchar(50) NOT NULL,
  `mot_de_passe` varchar(255) NOT NULL,
  `nom_complet` varchar(100) NOT NULL,
  `role` enum('admin','lecteur') NOT NULL DEFAULT 'lecteur',
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `derniere_connexion` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `login` (`login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mesr_logs_consultation` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `login` varchar(100) NOT NULL,
  `nom_complet` varchar(150) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `cible` varchar(255) DEFAULT NULL,
  `details` varchar(255) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_login` (`login`),
  KEY `idx_action` (`action`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `expediteur_type` enum('admin','etudiant') NOT NULL DEFAULT 'admin',
  `expediteur_id` int(10) unsigned NOT NULL,
  `destinataire_type` enum('admin','etudiant') NOT NULL DEFAULT 'etudiant',
  `destinataire_id` int(10) unsigned NOT NULL,
  `sujet` varchar(200) NOT NULL,
  `corps` text NOT NULL,
  `lu` tinyint(1) NOT NULL DEFAULT 0,
  `lu_le` datetime DEFAULT NULL,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `parent_id` (`parent_id`),
  KEY `idx_dest` (`destinataire_type`,`destinataire_id`,`lu`),
  CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `messages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mobile_reset_codes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `etudiant_id` int(10) unsigned NOT NULL,
  `code_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `tentatives` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_etudiant` (`etudiant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mobile_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `etudiant_id` int(10) unsigned NOT NULL,
  `token` varchar(64) NOT NULL,
  `device_info` varchar(200) DEFAULT NULL,
  `last_used` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token` (`token`),
  KEY `idx_etudiant` (`etudiant_id`),
  CONSTRAINT `mobile_tokens_ibfk_1` FOREIGN KEY (`etudiant_id`) REFERENCES `etudiants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `niveaux` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(10) NOT NULL,
  `libelle` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `pv_id` int(10) unsigned NOT NULL,
  `etudiant_id` int(10) unsigned NOT NULL,
  `matiere_id` int(10) unsigned NOT NULL,
  `note_examen` decimal(6,2) DEFAULT NULL,
  `note_cc` decimal(6,2) DEFAULT NULL,
  `note_finale` decimal(6,2) DEFAULT NULL,
  `defaillant` tinyint(1) NOT NULL DEFAULT 0,
  `observation` varchar(50) DEFAULT NULL,
  `saisie_par` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reportee` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = note reportée depuis une année précédente',
  `pv_origine_id` int(10) unsigned DEFAULT NULL COMMENT 'PV source du report',
  `annee_origine_id` int(10) unsigned DEFAULT NULL COMMENT 'Année académique source du report',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_note` (`pv_id`,`etudiant_id`,`matiere_id`),
  KEY `etudiant_id` (`etudiant_id`),
  KEY `matiere_id` (`matiere_id`),
  KEY `saisie_par` (`saisie_par`),
  KEY `idx_note_pv_et` (`pv_id`,`etudiant_id`),
  KEY `idx_reportee` (`reportee`),
  KEY `idx_notes_pv_et` (`pv_id`,`etudiant_id`),
  KEY `idx_notes_mat` (`matiere_id`),
  CONSTRAINT `notes_ibfk_1` FOREIGN KEY (`pv_id`) REFERENCES `pv_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notes_ibfk_2` FOREIGN KEY (`etudiant_id`) REFERENCES `etudiants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notes_ibfk_3` FOREIGN KEY (`matiere_id`) REFERENCES `matieres` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notes_ibfk_4` FOREIGN KEY (`saisie_par`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications_email` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sujet` varchar(300) NOT NULL,
  `corps` text NOT NULL,
  `destinataires` enum('tous','filiere','niveau','individuel') NOT NULL DEFAULT 'tous',
  `filiere_id` int(10) unsigned DEFAULT NULL,
  `niveau_id` int(10) unsigned DEFAULT NULL,
  `etudiant_id` int(10) unsigned DEFAULT NULL,
  `nb_envoyes` int(10) unsigned NOT NULL DEFAULT 0,
  `nb_echecs` int(10) unsigned NOT NULL DEFAULT 0,
  `statut` enum('brouillon','envoye','partiel') NOT NULL DEFAULT 'brouillon',
  `envoye_le` datetime DEFAULT NULL,
  `envoye_par` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `filiere_id` (`filiere_id`),
  KEY `niveau_id` (`niveau_id`),
  KEY `etudiant_id` (`etudiant_id`),
  KEY `envoye_par` (`envoye_par`),
  CONSTRAINT `notifications_email_ibfk_1` FOREIGN KEY (`filiere_id`) REFERENCES `filieres` (`id`) ON DELETE SET NULL,
  CONSTRAINT `notifications_email_ibfk_2` FOREIGN KEY (`niveau_id`) REFERENCES `niveaux` (`id`) ON DELETE SET NULL,
  CONSTRAINT `notifications_email_ibfk_3` FOREIGN KEY (`etudiant_id`) REFERENCES `etudiants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `notifications_email_ibfk_4` FOREIGN KEY (`envoye_par`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `paiements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `etudiant_id` int(10) unsigned NOT NULL,
  `inscription_id` int(10) unsigned DEFAULT NULL,
  `type_paiement` enum('inscription','scolarite','rattrapage','diplome','attestation','certificat','releve_note','depot_dossier','autre') NOT NULL DEFAULT 'scolarite',
  `montant_du` decimal(10,2) NOT NULL DEFAULT 0.00,
  `montant_paye` decimal(10,2) NOT NULL DEFAULT 0.00,
  `date_paiement` date DEFAULT NULL,
  `mode_paiement` enum('especes','virement','mobile_money','cheque') NOT NULL DEFAULT 'especes',
  `reference` varchar(100) DEFAULT NULL,
  `statut` enum('en_attente','partiel','complet','rembourse') NOT NULL DEFAULT 'en_attente',
  `notes` text DEFAULT NULL,
  `saisi_par` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `derniere_relance` timestamp NULL DEFAULT NULL,
  `annee_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `etudiant_id` (`etudiant_id`),
  KEY `saisi_par` (`saisi_par`),
  CONSTRAINT `paiements_ibfk_1` FOREIGN KEY (`etudiant_id`) REFERENCES `etudiants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `paiements_ibfk_2` FOREIGN KEY (`saisi_par`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `paiements_annules` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `paiement_id` int(10) unsigned NOT NULL,
  `etudiant_id` int(10) unsigned NOT NULL,
  `annee_id` int(10) unsigned DEFAULT NULL,
  `type_paiement` varchar(30) NOT NULL,
  `montant_du` decimal(10,2) NOT NULL DEFAULT 0.00,
  `montant_paye` decimal(10,2) NOT NULL DEFAULT 0.00,
  `date_paiement` date DEFAULT NULL,
  `mode_paiement` varchar(30) DEFAULT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `statut` varchar(30) DEFAULT NULL,
  `saisi_par` int(10) unsigned DEFAULT NULL,
  `donnees` longtext DEFAULT NULL,
  `motif` varchar(500) NOT NULL,
  `annule_par` int(10) unsigned DEFAULT NULL,
  `annule_le` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `etudiant_id` (`etudiant_id`),
  KEY `paiement_id` (`paiement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `parametres` (
  `cle` varchar(100) NOT NULL,
  `valeur` text DEFAULT NULL,
  `label` varchar(200) DEFAULT NULL,
  `groupe` varchar(50) NOT NULL DEFAULT 'general',
  PRIMARY KEY (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `parametres_annee` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cle` varchar(100) NOT NULL,
  `annee_id` int(10) unsigned NOT NULL,
  `valeur` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cle_annee` (`cle`,`annee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `parents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) NOT NULL,
  `prenoms` varchar(100) NOT NULL,
  `telephone` varchar(30) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `mot_de_passe_hash` varchar(255) NOT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `derniere_connexion` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_telephone` (`telephone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `parents_etudiants` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int(10) unsigned NOT NULL,
  `etudiant_id` int(10) unsigned NOT NULL,
  `lien` varchar(30) NOT NULL DEFAULT 'parent',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_parent_etudiant` (`parent_id`,`etudiant_id`),
  KEY `idx_etudiant` (`etudiant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `passages_niveau` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `etudiant_id` int(10) unsigned NOT NULL,
  `filiere_id` int(10) unsigned NOT NULL,
  `niveau_actuel_id` int(10) unsigned NOT NULL,
  `niveau_suivant_id` int(10) unsigned NOT NULL,
  `annee_depart_id` int(10) unsigned NOT NULL,
  `annee_arrivee_id` int(10) unsigned NOT NULL,
  `decision` enum('passage','redoublement','licence_validee') NOT NULL DEFAULT 'passage',
  `date_decision` date NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_passage` (`etudiant_id`,`annee_arrivee_id`,`filiere_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_resets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_token` (`token_hash`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `libelle` varchar(100) NOT NULL,
  `categorie` varchar(50) NOT NULL DEFAULT 'general',
  `description` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `portail_candidats` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) NOT NULL,
  `prenoms` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `telephone` varchar(30) NOT NULL,
  `date_naissance` date DEFAULT NULL,
  `mot_de_passe` varchar(255) NOT NULL,
  `token_reset` varchar(64) DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `portail_candidats_config` (
  `cle` varchar(100) NOT NULL,
  `valeur` text DEFAULT NULL,
  PRIMARY KEY (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `portail_config` (
  `cle` varchar(100) NOT NULL,
  `valeur` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `portail_dossiers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `candidat_id` int(10) unsigned NOT NULL,
  `numero_dossier` varchar(30) NOT NULL,
  `nom` varchar(100) NOT NULL,
  `prenoms` varchar(150) NOT NULL,
  `date_naissance` date NOT NULL,
  `lieu_naissance` varchar(200) NOT NULL,
  `nationalite` varchar(100) NOT NULL DEFAULT 'Nigérienne',
  `sexe` enum('M','F') NOT NULL,
  `adresse` text DEFAULT NULL,
  `telephone` varchar(30) NOT NULL,
  `email` varchar(150) NOT NULL,
  `nom_pere` varchar(200) DEFAULT NULL,
  `nom_mere` varchar(200) DEFAULT NULL,
  `tel_urgence` varchar(30) DEFAULT NULL,
  `bac_serie` varchar(50) NOT NULL,
  `bac_annee` year(4) NOT NULL,
  `bac_mention` varchar(50) NOT NULL,
  `bac_lieu` varchar(200) DEFAULT NULL,
  `etablissement` varchar(200) DEFAULT NULL,
  `choix1_filiere_id` int(10) unsigned DEFAULT NULL,
  `choix2_filiere_id` int(10) unsigned DEFAULT NULL,
  `choix3_filiere_id` int(10) unsigned DEFAULT NULL,
  `pieces_jointes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`pieces_jointes`)),
  `statut` enum('en_attente','en_cours','accepte','refuse','incomplet') NOT NULL DEFAULT 'en_attente',
  `notes_admin` text DEFAULT NULL,
  `traite_par` int(10) unsigned DEFAULT NULL,
  `date_traitement` datetime DEFAULT NULL,
  `etudiant_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `numero_dossier` (`numero_dossier`),
  KEY `candidat_id` (`candidat_id`),
  KEY `choix1_filiere_id` (`choix1_filiere_id`),
  KEY `choix2_filiere_id` (`choix2_filiere_id`),
  KEY `choix3_filiere_id` (`choix3_filiere_id`),
  CONSTRAINT `portail_dossiers_ibfk_1` FOREIGN KEY (`candidat_id`) REFERENCES `portail_candidats` (`id`) ON DELETE CASCADE,
  CONSTRAINT `portail_dossiers_ibfk_2` FOREIGN KEY (`choix1_filiere_id`) REFERENCES `filieres` (`id`) ON DELETE SET NULL,
  CONSTRAINT `portail_dossiers_ibfk_3` FOREIGN KEY (`choix2_filiere_id`) REFERENCES `filieres` (`id`) ON DELETE SET NULL,
  CONSTRAINT `portail_dossiers_ibfk_4` FOREIGN KEY (`choix3_filiere_id`) REFERENCES `filieres` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `portail_paiements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `dossier_id` int(10) unsigned NOT NULL,
  `candidat_id` int(10) unsigned NOT NULL,
  `transaction_id` varchar(100) NOT NULL,
  `operateur` enum('airtel','moov','orange','especes') NOT NULL,
  `telephone` varchar(30) DEFAULT NULL,
  `montant` decimal(10,2) NOT NULL,
  `statut` enum('en_attente','en_cours','valide','echoue','expire') NOT NULL DEFAULT 'en_attente',
  `reference` varchar(100) DEFAULT NULL,
  `data_operateur` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data_operateur`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `transaction_id` (`transaction_id`),
  KEY `dossier_id` (`dossier_id`),
  KEY `candidat_id` (`candidat_id`),
  CONSTRAINT `portail_paiements_ibfk_1` FOREIGN KEY (`dossier_id`) REFERENCES `portail_dossiers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `portail_paiements_ibfk_2` FOREIGN KEY (`candidat_id`) REFERENCES `portail_candidats` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `presences` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `seance_id` int(10) unsigned NOT NULL,
  `etudiant_id` int(10) unsigned NOT NULL,
  `statut` enum('present','absent','retard','excuse') NOT NULL DEFAULT 'absent',
  `motif` varchar(200) DEFAULT NULL,
  `saisi_par` int(10) unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_seance_etudiant` (`seance_id`,`etudiant_id`),
  KEY `idx_etudiant` (`etudiant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `promotions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `filiere_id` int(10) unsigned NOT NULL,
  `niveau_id` int(10) unsigned NOT NULL,
  `annee_id` int(10) unsigned NOT NULL,
  `effectif_prevu` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_promo` (`filiere_id`,`niveau_id`,`annee_id`),
  KEY `niveau_id` (`niveau_id`),
  KEY `annee_id` (`annee_id`),
  CONSTRAINT `promotions_ibfk_1` FOREIGN KEY (`filiere_id`) REFERENCES `filieres` (`id`) ON DELETE CASCADE,
  CONSTRAINT `promotions_ibfk_2` FOREIGN KEY (`niveau_id`) REFERENCES `niveaux` (`id`) ON DELETE CASCADE,
  CONSTRAINT `promotions_ibfk_3` FOREIGN KEY (`annee_id`) REFERENCES `annees_academiques` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pv_sessions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `promotion_id` int(10) unsigned NOT NULL,
  `semestre_id` int(10) unsigned NOT NULL,
  `type_session` enum('normale','rattrapage','deliberation','reclamation','reclamation_depot') NOT NULL DEFAULT 'normale',
  `pv_normale_id` int(10) unsigned DEFAULT NULL,
  `pv_source_id` int(11) DEFAULT NULL,
  `libelle` varchar(200) NOT NULL,
  `date_pv` date DEFAULT NULL,
  `statut` enum('brouillon','finalise','publie') NOT NULL DEFAULT 'brouillon',
  `verrouille` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `promotion_id` (`promotion_id`),
  KEY `semestre_id` (`semestre_id`),
  KEY `created_by` (`created_by`),
  KEY `pv_normale_id` (`pv_normale_id`),
  CONSTRAINT `pv_sessions_ibfk_1` FOREIGN KEY (`promotion_id`) REFERENCES `promotions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pv_sessions_ibfk_2` FOREIGN KEY (`semestre_id`) REFERENCES `semestres` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pv_sessions_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pv_sessions_ibfk_4` FOREIGN KEY (`pv_normale_id`) REFERENCES `pv_sessions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pv_publications` (
  `pv_id` int(10) unsigned NOT NULL,
  `publie_le` datetime NOT NULL DEFAULT current_timestamp(),
  `publie_par` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`pv_id`),
  CONSTRAINT `pv_publications_pv` FOREIGN KEY (`pv_id`) REFERENCES `pv_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reclamations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `etudiant_id` int(11) NOT NULL,
  `pv_id` int(11) NOT NULL,
  `matiere_id` int(11) NOT NULL,
  `motif` text NOT NULL,
  `note_originale` decimal(6,2) DEFAULT NULL,
  `note_corrigee` decimal(6,2) DEFAULT NULL,
  `statut` enum('en_attente','fondee','non_fondee','rejetee') NOT NULL DEFAULT 'en_attente',
  `source` enum('enligne','physique') NOT NULL DEFAULT 'enligne',
  `commentaire` text DEFAULT NULL,
  `traitee_par` int(11) DEFAULT NULL,
  `traitee_le` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_recl` (`etudiant_id`,`pv_id`,`matiere_id`),
  KEY `idx_pv` (`pv_id`),
  KEY `idx_statut` (`statut`),
  KEY `idx_etudiant` (`etudiant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rectorat_cache` (
  `cache_key` varchar(191) NOT NULL,
  `value` longtext DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`cache_key`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rectorat_logs_consultation` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `nom_complet` varchar(150) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `cible` varchar(255) DEFAULT NULL,
  `details` varchar(255) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `releves_emis` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `numero` varchar(30) NOT NULL,
  `etudiant_id` int(10) unsigned NOT NULL,
  `pv_id` int(10) unsigned NOT NULL,
  `nom` varchar(100) NOT NULL,
  `prenoms` varchar(100) NOT NULL,
  `semestre` varchar(50) DEFAULT NULL,
  `mention_grade` varchar(30) DEFAULT NULL,
  `moyenne` decimal(5,2) DEFAULT NULL,
  `annee_academique` varchar(50) DEFAULT NULL,
  `date_emission` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_numero` (`numero`),
  UNIQUE KEY `uk_et_pv` (`etudiant_id`,`pv_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `resultats_semestre` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `pv_id` int(10) unsigned NOT NULL,
  `etudiant_id` int(10) unsigned NOT NULL,
  `moyenne_generale` decimal(5,2) DEFAULT NULL,
  `credits_obtenus` int(10) unsigned NOT NULL DEFAULT 0,
  `credits_total` int(10) unsigned NOT NULL DEFAULT 0,
  `valide` tinyint(1) NOT NULL DEFAULT 0,
  `defaillant` tinyint(1) NOT NULL DEFAULT 0,
  `mention` varchar(50) DEFAULT NULL,
  `rang` int(10) unsigned DEFAULT NULL,
  `decision` varchar(100) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_res_sem` (`pv_id`,`etudiant_id`),
  KEY `etudiant_id` (`etudiant_id`),
  KEY `idx_rs_pv_et` (`pv_id`,`etudiant_id`),
  KEY `idx_rs_pv_valide` (`pv_id`,`valide`),
  KEY `idx_rs_pv_rang` (`pv_id`,`rang`),
  CONSTRAINT `resultats_semestre_ibfk_1` FOREIGN KEY (`pv_id`) REFERENCES `pv_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `resultats_semestre_ibfk_2` FOREIGN KEY (`etudiant_id`) REFERENCES `etudiants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `resultats_ue` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `pv_id` int(10) unsigned NOT NULL,
  `etudiant_id` int(10) unsigned NOT NULL,
  `ue_id` int(10) unsigned NOT NULL,
  `total_points` decimal(8,2) NOT NULL DEFAULT 0.00,
  `note_max` decimal(8,2) NOT NULL DEFAULT 0.00,
  `moyenne_20` decimal(5,2) DEFAULT NULL,
  `credits_obtenus` int(10) unsigned NOT NULL DEFAULT 0,
  `valide` tinyint(1) NOT NULL DEFAULT 0,
  `defaillant` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_res_ue` (`pv_id`,`etudiant_id`,`ue_id`),
  KEY `etudiant_id` (`etudiant_id`),
  KEY `ue_id` (`ue_id`),
  CONSTRAINT `resultats_ue_ibfk_1` FOREIGN KEY (`pv_id`) REFERENCES `pv_sessions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `resultats_ue_ibfk_2` FOREIGN KEY (`etudiant_id`) REFERENCES `etudiants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `resultats_ue_ibfk_3` FOREIGN KEY (`ue_id`) REFERENCES `unites_enseignement` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_permissions` (
  `role` varchar(50) NOT NULL,
  `permission_code` varchar(50) NOT NULL,
  PRIMARY KEY (`role`,`permission_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `salles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `nom` varchar(150) NOT NULL,
  `capacite` int(10) unsigned NOT NULL DEFAULT 0,
  `batiment` varchar(100) DEFAULT NULL,
  `type` enum('amphitheatre','salle_td','laboratoire','autre') NOT NULL DEFAULT 'salle_td',
  `equipements` text DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sauvegardes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nom_fichier` varchar(200) NOT NULL,
  `taille` bigint(20) unsigned NOT NULL DEFAULT 0,
  `nb_tables` int(10) unsigned NOT NULL DEFAULT 0,
  `type` enum('manuelle','automatique') NOT NULL DEFAULT 'manuelle',
  `statut` enum('ok','erreur') NOT NULL DEFAULT 'ok',
  `message` text DEFAULT NULL,
  `cree_par` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `cree_par` (`cree_par`),
  CONSTRAINT `sauvegardes_ibfk_1` FOREIGN KEY (`cree_par`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `seances` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `matiere_id` int(10) unsigned NOT NULL,
  `promotion_id` int(10) unsigned NOT NULL,
  `date_seance` date NOT NULL,
  `heure_debut` time DEFAULT NULL,
  `heure_fin` time DEFAULT NULL,
  `type_seance` varchar(10) NOT NULL DEFAULT 'CM',
  `enseignant_id` int(10) unsigned DEFAULT NULL,
  `cree_par` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_matiere_date` (`matiere_id`,`date_seance`),
  KEY `idx_promotion_date` (`promotion_id`,`date_seance`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `semestres` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(5) NOT NULL,
  `libelle` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `signataires` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `titre` varchar(100) NOT NULL DEFAULT 'Le Doyen/pi',
  `nom` varchar(150) NOT NULL,
  `qualite` varchar(200) DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 0,
  `faculte_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sms_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `numero` varchar(30) NOT NULL,
  `message` text NOT NULL,
  `statut` enum('envoye','echec','simule') NOT NULL DEFAULT 'simule',
  `contexte` varchar(50) DEFAULT NULL,
  `reference_id` int(10) unsigned DEFAULT NULL,
  `reponse_brute` text DEFAULT NULL,
  `date_envoi` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_contexte` (`contexte`,`reference_id`),
  KEY `idx_date` (`date_envoi`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tarifs_niveaux` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `niveau_id` int(10) unsigned NOT NULL,
  `montant` decimal(10,0) NOT NULL DEFAULT 10000,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_niveau` (`niveau_id`),
  CONSTRAINT `fk_tarifs_niveau` FOREIGN KEY (`niveau_id`) REFERENCES `niveaux` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tarifs_scolarite` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `filiere_id` int(10) unsigned NOT NULL,
  `annee_id` int(10) unsigned NOT NULL,
  `type_paiement` enum('inscription','scolarite','rattrapage','diplome') NOT NULL DEFAULT 'scolarite',
  `montant` decimal(10,2) NOT NULL,
  `description` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tarif` (`filiere_id`,`annee_id`,`type_paiement`),
  KEY `annee_id` (`annee_id`),
  CONSTRAINT `tarifs_scolarite_ibfk_1` FOREIGN KEY (`filiere_id`) REFERENCES `filieres` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tarifs_scolarite_ibfk_2` FOREIGN KEY (`annee_id`) REFERENCES `annees_academiques` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `unites_enseignement` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `promotion_id` int(10) unsigned NOT NULL,
  `semestre_id` int(10) unsigned NOT NULL,
  `code` varchar(20) NOT NULL,
  `libelle` varchar(200) NOT NULL,
  `credits_total` int(10) unsigned NOT NULL DEFAULT 6,
  `nb_options_a_choisir` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `ordre` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ue` (`promotion_id`,`semestre_id`,`code`),
  KEY `semestre_id` (`semestre_id`),
  CONSTRAINT `unites_enseignement_ibfk_1` FOREIGN KEY (`promotion_id`) REFERENCES `promotions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `unites_enseignement_ibfk_2` FOREIGN KEY (`semestre_id`) REFERENCES `semestres` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `utilisateur_permissions` (
  `utilisateur_id` int(10) unsigned NOT NULL,
  `permission_code` varchar(50) NOT NULL,
  PRIMARY KEY (`utilisateur_id`,`permission_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `utilisateurs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) NOT NULL,
  `prenoms` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `mot_de_passe` varchar(255) NOT NULL,
  `role` enum('admin','secretaire','enseignant','etudiant','sc_entite','sc_centrale','user','lecture','recteur','chef_departement','doyen') NOT NULL DEFAULT 'user',
  `faculte_id` int(10) unsigned DEFAULT NULL,
  `etudiant_id` int(10) unsigned DEFAULT NULL,
  `enseignant_id` int(10) unsigned DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `derniere_connexion` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `departement_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_email` (`email`),
  KEY `idx_role` (`role`),
  KEY `idx_faculte` (`faculte_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_inscriptions` AS SELECT
 1 AS `id`,
  1 AS `etudiant_id`,
  1 AS `matricule`,
  1 AS `nom`,
  1 AS `prenoms`,
  1 AS `sexe`,
  1 AS `filiere_id`,
  1 AS `filiere`,
  1 AS `faculte`,
  1 AS `niveau_id`,
  1 AS `niveau`,
  1 AS `niveau_lib`,
  1 AS `annee_id`,
  1 AS `annee`,
  1 AS `statut`,
  1 AS `montant_paye`,
  1 AS `mode_paiement`,
  1 AS `date_inscription` */;
SET character_set_client = @saved_cs_client;
/*!50001 DROP VIEW IF EXISTS `v_inscriptions`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */

/*!50001 VIEW `v_inscriptions` AS select `i`.`id` AS `id`,`i`.`etudiant_id` AS `etudiant_id`,`e`.`numero` AS `matricule`,`e`.`nom` AS `nom`,`e`.`prenoms` AS `prenoms`,`e`.`sexe` AS `sexe`,`e`.`filiere_id` AS `filiere_id`,`f`.`libelle` AS `filiere`,`f`.`faculte` AS `faculte`,`i`.`niveau_id` AS `niveau_id`,`n`.`code` AS `niveau`,`n`.`libelle` AS `niveau_lib`,`i`.`annee_id` AS `annee_id`,`a`.`libelle` AS `annee`,`i`.`statut` AS `statut`,`i`.`montant_paye` AS `montant_paye`,`i`.`mode_paiement` AS `mode_paiement`,`i`.`date_inscription` AS `date_inscription` from ((((`inscriptions` `i` join `etudiants` `e` on(`e`.`id` = `i`.`etudiant_id`)) left join `filieres` `f` on(`f`.`id` = `e`.`filiere_id`)) left join `niveaux` `n` on(`n`.`id` = `i`.`niveau_id`)) left join `annees_academiques` `a` on(`a`.`id` = `i`.`annee_id`)) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

