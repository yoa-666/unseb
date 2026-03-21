-- ═══════════════════════════════════════════════
-- UNSEB v6 → v7 : Ajout audio + type_msg dans messages_prives
-- Exécuter dans phpMyAdmin > unseb_db > SQL
-- ═══════════════════════════════════════════════

ALTER TABLE messages_prives
  ADD COLUMN IF NOT EXISTS type_msg ENUM('texte','audio') DEFAULT 'texte' AFTER contenu,
  ADD COLUMN IF NOT EXISTS audio_data MEDIUMTEXT DEFAULT NULL AFTER type_msg;

-- Vérification
SELECT 'Migration v7 OK : colonnes type_msg et audio_data ajoutées.' AS status;
