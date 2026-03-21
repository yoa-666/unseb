-- ═══════════════════════════════════════════════
--  UNSEB ADJARRA — Mise à jour BDD v2
--  À exécuter dans phpMyAdmin > unseb_db > SQL
-- ═══════════════════════════════════════════════

USE unseb_db;

-- ─── AJOUTER COLONNES OTP + EMAIL_VERIFIE ──────
ALTER TABLE membres
  ADD COLUMN IF NOT EXISTS email_verifie  TINYINT(1)   DEFAULT 0 AFTER statut,
  ADD COLUMN IF NOT EXISTS otp_code       VARCHAR(6)   DEFAULT NULL AFTER email_verifie,
  ADD COLUMN IF NOT EXISTS otp_expire     DATETIME     DEFAULT NULL AFTER otp_code,
  ADD COLUMN IF NOT EXISTS photo_path     VARCHAR(255) DEFAULT NULL AFTER photo;

-- ─── TABLE OTP (codes temporaires) ─────────────
CREATE TABLE IF NOT EXISTS otp_codes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    email       VARCHAR(150) NOT NULL,
    code        VARCHAR(6)   NOT NULL,
    expire_at   DATETIME     NOT NULL,
    utilise     TINYINT(1)   DEFAULT 0,
    date_creation DATETIME   DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_otp_email (email),
    INDEX idx_otp_code  (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── CORRIGER EMAIL ADMIN ───────────────────────
UPDATE membres SET email = 'unsebadjarra@gmail.com', email_verifie = 1
WHERE email IN ('unseb.adjarra@gmail.com','unsebadjarra@gmail.com') AND role = 'admin'
LIMIT 1;
