<?php
require_once __DIR__ . '/config.php';
session_start_if_not();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input  = getInput();

switch ($action) {

    // ─── LISTE ÉVÉNEMENTS (public pour membres validés) ──
    case 'liste':
        requireLogin();
        $db  = getDB();
        $sql = "SELECT e.*, m.prenom, m.nom FROM evenements e JOIN membres m ON e.created_by = m.id ORDER BY e.date_event ASC";
        $events = $db->query($sql)->fetchAll();
        // Ajouter l'URL de la photo
        foreach ($events as &$ev) {
            $ev['photo_url'] = $ev['photo'] ? 'uploads/evenements/' . $ev['photo'] : null;
        }
        jsonResponse(['success' => true, 'evenements' => $events]);
        break;

    // ─── ANNONCES UNIQUEMENT ─────────────────────
    case 'annonces':
        requireLogin();
        $db = getDB();
        $stmt = $db->query("SELECT e.*, m.prenom, m.nom FROM evenements e JOIN membres m ON e.created_by = m.id WHERE e.est_annonce = 1 ORDER BY e.date_creation DESC LIMIT 10");
        $annonces = $stmt->fetchAll();
        foreach ($annonces as &$a) {
            $a['photo_url'] = $a['photo'] ? 'uploads/evenements/' . $a['photo'] : null;
        }
        jsonResponse(['success' => true, 'annonces' => $annonces]);
        break;

    // ─── CRÉER ÉVÉNEMENT (admin) ─────────────────
    case 'creer':
        requireAdmin();

        $titre       = sanitize($input['titre'] ?? '');
        $description = sanitize($input['description'] ?? '');
        $date_event  = $input['date_event'] ?? '';
        $lieu        = sanitize($input['lieu'] ?? '');
        $type        = $input['type'] ?? 'autre';
        $est_annonce = (int)($input['est_annonce'] ?? 0);

        if (!$titre || !$description || !$date_event)
            jsonResponse(['success' => false, 'error' => 'Titre, description et date sont requis.'], 400);

        $types_valides = ['academique', 'culturel', 'AG', 'formation', 'autre'];
        if (!in_array($type, $types_valides)) $type = 'autre';

        // Gérer l'upload photo
        $photo = null;
        if (!empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $file    = $_FILES['photo'];
            $allowed = ['image/jpeg', 'image/png', 'image/webp'];
            if (in_array($file['type'], $allowed) && $file['size'] <= MAX_FILE_SIZE) {
                $ext    = pathinfo($file['name'], PATHINFO_EXTENSION);
                $photo  = 'event_' . time() . '_' . rand(1000,9999) . '.' . $ext;
                move_uploaded_file($file['tmp_name'], UPLOAD_EVENEMENTS . $photo);
            }
        }

        $db   = getDB();
        $stmt = $db->prepare("INSERT INTO evenements (titre, description, date_event, lieu, type, photo, est_annonce, created_by) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$titre, $description, $date_event, $lieu, $type, $photo, $est_annonce, $_SESSION['user_id']]);

        jsonResponse(['success' => true, 'message' => 'Événement créé.', 'id' => $db->lastInsertId()]);
        break;

    // ─── MODIFIER ÉVÉNEMENT (admin) ──────────────
    case 'modifier':
        requireAdmin();
        $id          = (int)($input['id'] ?? 0);
        $titre       = sanitize($input['titre'] ?? '');
        $description = sanitize($input['description'] ?? '');
        $date_event  = $input['date_event'] ?? '';
        $lieu        = sanitize($input['lieu'] ?? '');
        $type        = $input['type'] ?? 'autre';
        $est_annonce = (int)($input['est_annonce'] ?? 0);

        if (!$id || !$titre) jsonResponse(['success' => false, 'error' => 'ID et titre requis.'], 400);

        $db = getDB();

        // Gérer nouvelle photo
        $photo_sql = '';
        $params    = [$titre, $description, $date_event, $lieu, $type, $est_annonce];

        if (!empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['photo'];
            $allowed = ['image/jpeg', 'image/png', 'image/webp'];
            if (in_array($file['type'], $allowed) && $file['size'] <= MAX_FILE_SIZE) {
                $ext   = pathinfo($file['name'], PATHINFO_EXTENSION);
                $fname = 'event_' . time() . '_' . rand(1000,9999) . '.' . $ext;
                move_uploaded_file($file['tmp_name'], UPLOAD_EVENEMENTS . $fname);
                $photo_sql = ', photo = ?';
                $params[]  = $fname;
            }
        }

        $params[] = $id;
        $db->prepare("UPDATE evenements SET titre=?, description=?, date_event=?, lieu=?, type=?, est_annonce=? $photo_sql WHERE id=?")
           ->execute($params);

        jsonResponse(['success' => true, 'message' => 'Événement modifié.']);
        break;

    // ─── SUPPRIMER ÉVÉNEMENT (admin) ─────────────
    case 'supprimer':
        requireAdmin();
        $id = (int)($input['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'error' => 'ID requis.'], 400);

        $db = getDB();
        // Supprimer le fichier photo
        $ev = $db->prepare("SELECT photo FROM evenements WHERE id=?");
        $ev->execute([$id]);
        $row = $ev->fetch();
        if ($row && $row['photo'] && file_exists(UPLOAD_EVENEMENTS . $row['photo'])) {
            unlink(UPLOAD_EVENEMENTS . $row['photo']);
        }
        $db->prepare("DELETE FROM evenements WHERE id=?")->execute([$id]);
        jsonResponse(['success' => true, 'message' => 'Événement supprimé.']);
        break;

    // ─── ÉVÉNEMENTS PUBLICS (page d'accueil) ───
    case 'publics':
        $db = getDB();
        $events = $db->query("SELECT id, titre, description, date_event, lieu, type, photo, est_annonce FROM evenements ORDER BY date_event ASC LIMIT 10")->fetchAll();
        foreach ($events as &$ev) {
            $ev['photo_url'] = $ev['photo'] ? 'uploads/evenements/' . $ev['photo'] : null;
        }
        jsonResponse(['success' => true, 'evenements' => $events]);
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Action inconnue.'], 400);
}
