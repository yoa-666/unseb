<?php
/**
 * UNSEB — Script d'initialisation
 * À exécuter UNE SEULE FOIS depuis http://localhost/unseb/api/init.php
 * Crée la BDD, les tables et l'admin
 */

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'unseb_db');

// Mot de passe admin (change-le après la première connexion)
$adminPassword = 'Admin@UNSEB2026';
$adminEmail    = 'unseb.adjarra@gmail.com';

try {
    // Connexion sans DB pour la créer
    $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Créer la base
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `" . DB_NAME . "`");

    // Lire et exécuter le SQL d'installation (sans la ligne INSERT admin)
    $sql = file_get_contents(__DIR__ . '/install.sql');

    // Supprimer l'INSERT admin placeholder et les CREATE DATABASE/USE (déjà fait)
    $sql = preg_replace('/CREATE DATABASE.*?;/si', '', $sql);
    $sql = preg_replace('/USE unseb_db;/si', '', $sql);
    $sql = preg_replace('/-- Admin par défaut.*?;/si', '', $sql);

    // Exécuter les requêtes
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $stmt) {
        if (!empty($stmt) && !preg_match('/^--/', $stmt)) {
            try {
                $pdo->exec($stmt);
            } catch (PDOException $e) {
                // Ignorer les erreurs "already exists"
                if (strpos($e->getMessage(), 'already exists') === false &&
                    strpos($e->getMessage(), 'Duplicate key') === false) {
                    echo "⚠ " . $e->getMessage() . "<br>";
                }
            }
        }
    }

    // Vérifier si l'admin existe déjà
    $check = $pdo->prepare("SELECT id FROM membres WHERE email = ?");
    $check->execute([$adminEmail]);

    if (!$check->fetch()) {
        $hash = password_hash($adminPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $insert = $pdo->prepare("
            INSERT INTO membres (prenom, nom, email, mot_de_passe, role, statut)
            VALUES ('Admin', 'UNSEB', ?, ?, 'admin', 'valide')
        ");
        $insert->execute([$adminEmail, $hash]);
        echo "✅ Admin créé — Email: <strong>$adminEmail</strong> | Mot de passe: <strong>$adminPassword</strong><br>";
    } else {
        echo "ℹ️ Admin déjà existant.<br>";
    }

    echo "✅ Base de données <strong>" . DB_NAME . "</strong> initialisée avec succès !<br>";
    echo "<br><strong style='color:red'>⚠ Supprime ce fichier init.php après utilisation !</strong>";

} catch (PDOException $e) {
    echo "❌ Erreur : " . $e->getMessage();
}
