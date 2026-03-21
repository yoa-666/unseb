-- ═══════════════════════════════════════════════
--  UNSEB ADJARRA — Base de données
--  À importer dans phpMyAdmin ou via CLI MySQL
-- ═══════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS unseb_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE unseb_db;

-- ─── MEMBRES ───────────────────────────────────
CREATE TABLE IF NOT EXISTS membres (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    prenom        VARCHAR(80)  NOT NULL,
    nom           VARCHAR(80)  NOT NULL,
    email         VARCHAR(150) NOT NULL UNIQUE,
    mot_de_passe  VARCHAR(255) NOT NULL,
    telephone     VARCHAR(20)  DEFAULT NULL,
    filiere       VARCHAR(100) DEFAULT NULL,
    niveau        VARCHAR(20)  DEFAULT NULL,
    role          ENUM('admin','membre') DEFAULT 'membre',
    statut        ENUM('en_attente','valide','suspendu') DEFAULT 'en_attente',
    photo         VARCHAR(255) DEFAULT NULL,
    token_reset   VARCHAR(100) DEFAULT NULL,
    date_inscription DATETIME DEFAULT CURRENT_TIMESTAMP,
    derniere_connexion DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin par défaut (mot de passe: Admin@UNSEB2026)
INSERT INTO membres (prenom, nom, email, mot_de_passe, role, statut)
VALUES (
    'Admin', 'UNSEB',
    'unseb.adjarra@gmail.com',
    '$2y$12$unsebadminhashedpasswordplaceholder',
    'admin', 'valide'
);

-- ─── CARTES MEMBRES ────────────────────────────
CREATE TABLE IF NOT EXISTS cartes_membres (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    membre_id   INT NOT NULL,
    numero_carte VARCHAR(30) NOT NULL UNIQUE,
    data_json   LONGTEXT NOT NULL,
    statut      ENUM('generee','approuvee','imprimee','livree') DEFAULT 'generee',
    date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_approbation DATETIME DEFAULT NULL,
    FOREIGN KEY (membre_id) REFERENCES membres(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── ÉVÉNEMENTS ────────────────────────────────
CREATE TABLE IF NOT EXISTS evenements (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    titre       VARCHAR(200) NOT NULL,
    description TEXT         NOT NULL,
    date_event  DATETIME     NOT NULL,
    lieu        VARCHAR(200) DEFAULT NULL,
    type        ENUM('academique','culturel','AG','formation','autre') DEFAULT 'autre',
    photo       VARCHAR(255) DEFAULT NULL,
    est_annonce TINYINT(1)   DEFAULT 0,
    created_by  INT          NOT NULL,
    date_creation DATETIME   DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES membres(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── GALERIE ───────────────────────────────────
CREATE TABLE IF NOT EXISTS galerie (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    type        ENUM('photo','video') DEFAULT 'photo',
    fichier     VARCHAR(255) NOT NULL,
    legende     VARCHAR(300) DEFAULT NULL,
    categorie   VARCHAR(100) DEFAULT 'general',
    ordre       INT          DEFAULT 0,
    created_by  INT          NOT NULL,
    date_ajout  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES membres(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── INSCRIPTIONS SECTIONS ─────────────────────
CREATE TABLE IF NOT EXISTS inscriptions_sections (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    membre_id   INT          NOT NULL,
    section     ENUM('CECAL','COGHERES','TD','INFORMATIQUE') NOT NULL,
    domaine     VARCHAR(100) DEFAULT NULL,
    message     TEXT         DEFAULT NULL,
    statut      ENUM('en_attente','accepte','refuse') DEFAULT 'en_attente',
    date_inscription DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_inscription (membre_id, section),
    FOREIGN KEY (membre_id) REFERENCES membres(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── MESSAGES GROUPE ───────────────────────────
CREATE TABLE IF NOT EXISTS messages_groupe (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    membre_id   INT          NOT NULL,
    canal       VARCHAR(50)  DEFAULT 'general',
    contenu     TEXT         NOT NULL,
    date_envoi  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (membre_id) REFERENCES membres(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── MESSAGES PRIVÉS ───────────────────────────
CREATE TABLE IF NOT EXISTS messages_prives (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    expediteur_id   INT NOT NULL,
    destinataire_id INT NOT NULL,
    contenu         TEXT NOT NULL,
    lu              TINYINT(1) DEFAULT 0,
    date_envoi      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (expediteur_id)   REFERENCES membres(id) ON DELETE CASCADE,
    FOREIGN KEY (destinataire_id) REFERENCES membres(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── INDEX pour performances ───────────────────
CREATE INDEX idx_messages_canal     ON messages_groupe(canal, date_envoi);
CREATE INDEX idx_messages_prives    ON messages_prives(expediteur_id, destinataire_id, date_envoi);
CREATE INDEX idx_evenements_date    ON evenements(date_event);
CREATE INDEX idx_galerie_type       ON galerie(type, ordre);
CREATE INDEX idx_membres_email      ON membres(email);
CREATE INDEX idx_cartes_statut      ON cartes_membres(statut);
