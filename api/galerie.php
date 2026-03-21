<?php
require_once __DIR__ . '/config.php';
session_start_if_not();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input  = getInput();

switch ($action) {

    // ─── LISTE GALERIE (membres validés) ─────────
    case 'liste':
        requireLogin();
        $db   = getDB();
        $type = $_GET['type'] ?? '';
        $sql  = "SELECT g.*, m.prenom, m.nom FROM galerie g JOIN membres m ON g.created_by = m.id";
        if ($type) $sql .= " WHERE g.type = " . $db->quote($type);
        $sql .= " ORDER BY g.ordre ASC, g.date_ajout DESC";
        $items = $db->query($sql)->fetchAll();
        foreach ($items as &$item) {
            $item['url'] = 'uploads/galerie/' . $item['fichier'];
        }
        jsonResponse(['success' => true, 'galerie' => $items]);
        break;

    // ─── AJOUTER MÉDIA (admin uniquement) ────────
    case 'ajouter':
        requireAdmin();

        $legende   = sanitize($input['legende'] ?? '');
        $categorie = sanitize($input['categorie'] ?? 'general');
        $ordre     = (int)($input['ordre'] ?? 0);
        $type_item = $input['type'] ?? 'photo';

        if (empty($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(['success' => false, 'error' => 'Aucun fichier envoyé.'], 400);
        }

        $file = $_FILES['fichier'];
        $mime = $file['type'];

        if ($type_item === 'photo') {
            $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            if (!in_array($mime, $allowed))
                jsonResponse(['success' => false, 'error' => 'Format photo non autorisé (JPG/PNG/WEBP/GIF).'], 400);
        } elseif ($type_item === 'video') {
            $allowed = ['video/mp4', 'video/webm', 'video/ogg'];
            if (!in_array($mime, $allowed))
                jsonResponse(['success' => false, 'error' => 'Format vidéo non autorisé (MP4/WEBM/OGG).'], 400);
        } else {
            jsonResponse(['success' => false, 'error' => 'Type invalide.'], 400);
        }

        $maxSize = $type_item === 'video' ? 100 * 1024 * 1024 : MAX_FILE_SIZE; // 100MB pour vidéos
        if ($file['size'] > $maxSize)
            jsonResponse(['success' => false, 'error' => 'Fichier trop lourd.'], 400);

        $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = $type_item . '_' . time() . '_' . rand(1000,9999) . '.' . $ext;
        $dest     = UPLOAD_GALERIE . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest))
            jsonResponse(['success' => false, 'error' => 'Échec de l\'upload.'], 500);

        $db = getDB();
        $db->prepare("INSERT INTO galerie (type, fichier, legende, categorie, ordre, created_by) VALUES (?,?,?,?,?,?)")
           ->execute([$type_item, $filename, $legende, $categorie, $ordre, $_SESSION['user_id']]);

        jsonResponse([
            'success'  => true,
            'message'  => 'Média ajouté à la galerie.',
            'fichier'  => $filename,
            'url'      => 'uploads/galerie/' . $filename,
        ]);
        break;

    // ─── MODIFIER LÉGENDE/ORDRE (admin) ──────────
    case 'modifier':
        requireAdmin();
        $id        = (int)($input['id'] ?? 0);
        $legende   = sanitize($input['legende'] ?? '');
        $categorie = sanitize($input['categorie'] ?? 'general');
        $ordre     = (int)($input['ordre'] ?? 0);

        if (!$id) jsonResponse(['success' => false, 'error' => 'ID requis.'], 400);

        $db = getDB();
        $db->prepare("UPDATE galerie SET legende=?, categorie=?, ordre=? WHERE id=?")
           ->execute([$legende, $categorie, $ordre, $id]);

        jsonResponse(['success' => true, 'message' => 'Mise à jour effectuée.']);
        break;

    // ─── SUPPRIMER MÉDIA (admin) ─────────────────
    case 'supprimer':
        requireAdmin();
        $id = (int)($input['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'error' => 'ID requis.'], 400);

        $db  = getDB();
        $row = $db->prepare("SELECT fichier FROM galerie WHERE id=?");
        $row->execute([$id]);
        $item = $row->fetch();

        if ($item && file_exists(UPLOAD_GALERIE . $item['fichier'])) {
            unlink(UPLOAD_GALERIE . $item['fichier']);
        }

        $db->prepare("DELETE FROM galerie WHERE id=?")->execute([$id]);
        jsonResponse(['success' => true, 'message' => 'Média supprimé.']);
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Action inconnue.'], 400);
}
