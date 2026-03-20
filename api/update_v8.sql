-- ═══════════════════════════════════════════════
-- UNSEB v8 — Mise à jour BDD
-- Exécuter dans phpMyAdmin > unseb_db > SQL
-- ═══════════════════════════════════════════════

USE unseb_db;

-- 1. Ajouter matricule et mot de passe CECAL aux membres
ALTER TABLE membres
  ADD COLUMN IF NOT EXISTS matricule VARCHAR(20) DEFAULT NULL AFTER niveau,
  ADD COLUMN IF NOT EXISTS cecal_mdp VARCHAR(20) DEFAULT NULL AFTER matricule,
  ADD COLUMN IF NOT EXISTS est_cecal TINYINT(1) DEFAULT 0 AFTER cecal_mdp;

-- 2. Générer matricule pour membres existants (format UNSEB-ANNEE-ID)
UPDATE membres 
SET matricule = CONCAT('UNSEB-', YEAR(date_inscription), '-', LPAD(id, 4, '0'))
WHERE matricule IS NULL;

-- 3. Générer mot de passe CECAL basé sur matricule pour membres existants
UPDATE membres
SET cecal_mdp = CONCAT(SUBSTRING(matricule, 7, 4), '-', LPAD(id*7+13, 4, '0'))
WHERE cecal_mdp IS NULL;

-- 4. Ajouter colonnes media dans messages_prives
ALTER TABLE messages_prives
  ADD COLUMN IF NOT EXISTS type_msg ENUM('texte','audio','image','video','sticker') DEFAULT 'texte' AFTER contenu,
  ADD COLUMN IF NOT EXISTS audio_data MEDIUMTEXT DEFAULT NULL AFTER type_msg,
  ADD COLUMN IF NOT EXISTS media_data MEDIUMTEXT DEFAULT NULL AFTER audio_data,
  ADD COLUMN IF NOT EXISTS media_type VARCHAR(50) DEFAULT NULL AFTER media_data;

-- 5. Ajouter colonnes media dans messages_groupe
ALTER TABLE messages_groupe
  ADD COLUMN IF NOT EXISTS type_msg ENUM('texte','audio','image','video','sticker') DEFAULT 'texte' AFTER contenu,
  ADD COLUMN IF NOT EXISTS media_data MEDIUMTEXT DEFAULT NULL AFTER type_msg,
  ADD COLUMN IF NOT EXISTS media_type VARCHAR(50) DEFAULT NULL AFTER media_data;

-- 6. Corriger statut des comptes validés
UPDATE membres SET statut = 'valide' WHERE role = 'admin';
UPDATE membres SET statut = 'valide' WHERE email_verifie = 1 AND statut = 'en_attente';

-- Vérification
SELECT id, prenom, nom, matricule, cecal_mdp, statut, role FROM membres;
