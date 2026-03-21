<?php
require_once __DIR__ . '/config.php';
session_start_if_not();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input  = getInput();

switch ($action) {

    // ─── LISTE MEMBRES (admin) ────────────────────
    case 'liste':
        requireAdmin();
        $db = getDB();
        $statut = $_GET['statut'] ?? '';
        $sql = "SELECT id, prenom, nom, email, telephone, filiere, niveau, role, statut, photo, date_inscription, derniere_connexion FROM membres";
        if ($statut) $sql .= " WHERE statut = " . $db->quote($statut);
        $sql .= " ORDER BY date_inscription DESC";
        $membres = $db->query($sql)->fetchAll();
        jsonResponse(['success' => true, 'membres' => $membres]);
        break;

    // ─── MON PROFIL ──────────────────────────────
    case 'profil':
        requireLogin();
        $db   = getDB();
        $stmt = $db->prepare("SELECT id, prenom, nom, email, telephone, filiere, niveau, role, statut, photo, date_inscription, derniere_connexion FROM membres WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user) jsonResponse(['success' => false, 'error' => 'Membre introuvable'], 404);
        jsonResponse(['success' => true, 'membre' => $user]);
        break;

    // ─── MODIFIER PROFIL ─────────────────────────
    case 'modifier':
        requireLogin();
        $prenom    = sanitize($input['prenom'] ?? '');
        $nom       = sanitize($input['nom'] ?? '');
        $telephone = sanitize($input['telephone'] ?? '');
        $filiere   = sanitize($input['filiere'] ?? '');
        $niveau    = sanitize($input['niveau'] ?? '');

        if (!$prenom || !$nom) jsonResponse(['success' => false, 'error' => 'Prénom et nom requis'], 400);

        $db = getDB();
        $db->prepare("UPDATE membres SET prenom=?, nom=?, telephone=?, filiere=?, niveau=? WHERE id=?")
           ->execute([$prenom, $nom, $telephone, $filiere, $niveau, $_SESSION['user_id']]);

        // Mettre à jour la session
        $_SESSION['prenom'] = $prenom;
        $_SESSION['nom']    = $nom;

        jsonResponse(['success' => true, 'message' => 'Profil mis à jour.']);
        break;

    // ─── UPLOAD PHOTO PROFIL ─────────────────────
    case 'upload_photo':
        requireLogin();
        if (empty($_FILES['photo'])) jsonResponse(['success' => false, 'error' => 'Aucun fichier'], 400);

        $file = $_FILES['photo'];
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($file['type'], $allowed)) jsonResponse(['success' => false, 'error' => 'Format non autorisé (JPG/PNG/WEBP)'], 400);
        if ($file['size'] > MAX_FILE_SIZE) jsonResponse(['success' => false, 'error' => 'Fichier trop lourd (max 10 MB)'], 400);

        $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'membre_' . $_SESSION['user_id'] . '_' . time() . '.' . $ext;
        $dest     = UPLOAD_MEMBRES . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest))
            jsonResponse(['success' => false, 'error' => 'Échec upload'], 500);

        $db = getDB();
        $db->prepare("UPDATE membres SET photo = ? WHERE id = ?")->execute([$filename, $_SESSION['user_id']]);
        jsonResponse(['success' => true, 'photo' => $filename]);
        break;

    // ─── VALIDER MEMBRE (admin) ──────────────────
    case 'valider':
        requireAdmin();
        $id = (int)($input['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'error' => 'ID requis'], 400);
        $db = getDB();
        $db->prepare("UPDATE membres SET statut='valide' WHERE id=?")->execute([$id]);

        // Récupérer infos pour EmailJS
        $m = $db->prepare("SELECT prenom, nom, email FROM membres WHERE id=?");
        $m->execute([$id]);
        $membre = $m->fetch();

        jsonResponse([
            'success' => true,
            'message' => 'Membre validé.',
            'emailjs_data' => [
                'service_id'  => EMAILJS_SERVICE_ID,
                'template_id' => EMAILJS_TEMPLATE_ID,
                'public_key'  => EMAILJS_PUBLIC_KEY,
                'to_email'    => $membre['email'],
                'to_name'     => $membre['prenom'] . ' ' . $membre['nom'],
                'subject'     => 'Votre compte UNSEB Adjarra a été validé',
                'message'     => "Bonjour " . $membre['prenom'] . ",\n\nVotre compte UNSEB Adjarra a été validé par l'administrateur. Vous pouvez maintenant vous connecter et accéder à tous les services.\n\nBienvenue dans la communauté !\n\nL'équipe UNSEB Adjarra",
            ]
        ]);
        break;

    // ─── SUSPENDRE MEMBRE (admin) ────────────────
    case 'suspendre':
        requireAdmin();
        $id = (int)($input['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'error' => 'ID requis'], 400);
        // Protéger l'admin
        $db = getDB();
        $chk = $db->prepare("SELECT role FROM membres WHERE id=?");
        $chk->execute([$id]);
        $r = $chk->fetch();
        if ($r && $r['role'] === 'admin') jsonResponse(['success' => false, 'error' => 'Impossible de suspendre un admin'], 403);
        $db->prepare("UPDATE membres SET statut='suspendu' WHERE id=?")->execute([$id]);
        jsonResponse(['success' => true, 'message' => 'Membre suspendu.']);
        break;

    // ─── SUPPRIMER MEMBRE (admin) ────────────────
    case 'supprimer':
        requireAdmin();
        $id = (int)($input['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'error' => 'ID requis'], 400);
        $db = getDB();
        $chk = $db->prepare("SELECT role FROM membres WHERE id=?");
        $chk->execute([$id]);
        $r = $chk->fetch();
        if ($r && $r['role'] === 'admin') jsonResponse(['success' => false, 'error' => 'Impossible de supprimer un admin'], 403);
        $db->prepare("DELETE FROM membres WHERE id=?")->execute([$id]);
        jsonResponse(['success' => true, 'message' => 'Membre supprimé.']);
        break;

    // ─── LISTE MEMBRES POUR CHAT ─────────────────
    case 'liste_chat':
        requireLogin();
        $db = getDB();
        $stmt = $db->prepare("SELECT id, prenom, nom, photo, filiere, niveau, derniere_connexion FROM membres WHERE statut='valide' AND id != ? ORDER BY prenom ASC");
        $stmt->execute([$_SESSION['user_id']]);
        jsonResponse(['success' => true, 'membres' => $stmt->fetchAll()]);
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Action inconnue'], 400);
}
