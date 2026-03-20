<?php
require_once __DIR__ . '/config.php';
session_start_if_not();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input  = getInput();

function requireMembre(): void {
    if (!isLoggedIn()) jsonResponse(['success' => false, 'error' => 'Non authentifié.'], 401);
    if ($_SESSION['statut'] !== 'valide') jsonResponse(['success' => false, 'error' => 'Compte non validé.'], 403);
}

function requireCecal(): void {
    requireMembre();
    $db   = getDB();
    $stmt = $db->prepare("SELECT est_cecal FROM membres WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $m = $stmt->fetch();
    if (!$m || !$m['est_cecal'])
        jsonResponse(['success' => false, 'error' => 'Accès CECAL réservé aux membres du CECAL.', 'cecal_requis' => true], 403);
}

switch ($action) {

    case 'rejoindre_cecal':
        requireMembre();
        $mdp = trim($input['cecal_mdp'] ?? '');
        if (!$mdp) jsonResponse(['success' => false, 'error' => 'Mot de passe requis.'], 400);
        $db   = getDB();
        $stmt = $db->prepare("SELECT cecal_mdp FROM membres WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $m = $stmt->fetch();
        if (!$m || $m['cecal_mdp'] !== $mdp)
            jsonResponse(['success' => false, 'error' => 'Mot de passe CECAL incorrect.'], 401);
        $db->prepare("UPDATE membres SET est_cecal = 1 WHERE id = ?")->execute([$_SESSION['user_id']]);
        jsonResponse(['success' => true, 'message' => 'Accès CECAL activé !']);
        break;

    case 'mon_cecal_mdp':
        requireMembre();
        $db   = getDB();
        $stmt = $db->prepare("SELECT matricule, cecal_mdp, est_cecal FROM membres WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $m = $stmt->fetch();
        jsonResponse(['success' => true, 'matricule' => $m['matricule'], 'cecal_mdp' => $m['cecal_mdp'], 'est_cecal' => (bool)$m['est_cecal']]);
        break;

    case 'messages_groupe':
        requireMembre();
        $canal  = sanitize($_GET['canal'] ?? 'general');
        $depuis = (int)($_GET['depuis'] ?? 0);
        $canaux_valides = ['general', 'cecal', 'cogheres', 'academique', 'annonces', 'informatique'];
        if (!in_array($canal, $canaux_valides)) $canal = 'general';
        if ($canal === 'cecal') requireCecal();
        $db = getDB();
        $sql = "SELECT mg.id, mg.contenu, mg.date_envoi, mg.membre_id,
                       COALESCE(mg.type_msg,'texte') AS type_msg,
                       mg.media_data, mg.media_type,
                       m.prenom, m.nom, m.photo, m.role
                FROM messages_groupe mg
                JOIN membres m ON mg.membre_id = m.id
                WHERE mg.canal = ?";
        $params = [$canal];
        if ($depuis) {
            $sql .= " AND UNIX_TIMESTAMP(mg.date_envoi) > ? ORDER BY mg.date_envoi ASC";
            $params[] = $depuis;
        } else {
            $sql .= " ORDER BY mg.date_envoi DESC LIMIT 50";
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $messages = $stmt->fetchAll();
        if (!$depuis) $messages = array_reverse($messages);
        foreach ($messages as &$msg) {
            $msg['photo_url'] = $msg['photo'] ? 'uploads/photos_membres/' . $msg['photo'] : null;
            $msg['est_moi']   = $msg['membre_id'] == $_SESSION['user_id'];
            $msg['est_admin'] = $msg['role'] === 'admin';
        }
        jsonResponse(['success' => true, 'messages' => $messages, 'timestamp' => time()]);
        break;

    case 'envoyer_groupe':
        requireMembre();
        $canal    = sanitize($input['canal'] ?? 'general');
        $contenu  = trim($input['contenu'] ?? '');
        $type_msg = $input['type_msg'] ?? 'texte';
        $media    = $input['media_data'] ?? null;
        $mtype    = $input['media_type'] ?? null;
        $canaux_valides = ['general', 'cecal', 'cogheres', 'academique', 'annonces', 'informatique'];
        if (!in_array($canal, $canaux_valides)) $canal = 'general';
        if ($canal === 'cecal') requireCecal();
        if ($canal === 'annonces' && !isAdmin())
            jsonResponse(['success' => false, 'error' => 'Seul l\'administrateur peut écrire dans #annonces.'], 403);
        if (in_array($type_msg, ['texte','sticker']) && empty($contenu))
            jsonResponse(['success' => false, 'error' => 'Contenu vide.'], 400);
        $db = getDB();
        $db->prepare("INSERT INTO messages_groupe (membre_id, canal, contenu, type_msg, media_data, media_type) VALUES (?,?,?,?,?,?)")
           ->execute([$_SESSION['user_id'], $canal, $contenu ?: '[MEDIA]', $type_msg, $media, $mtype]);
        jsonResponse(['success' => true, 'message_id' => $db->lastInsertId()]);
        break;

    case 'messages_prives':
        requireMembre();
        $dest_id = (int)($_GET['avec'] ?? 0);
        if (!$dest_id) jsonResponse(['success' => false, 'error' => 'Destinataire requis.'], 400);
        $db  = getDB();
        $uid = $_SESSION['user_id'];
        $chk = $db->prepare("SELECT id FROM membres WHERE id=? AND statut='valide'");
        $chk->execute([$dest_id]);
        if (!$chk->fetch()) jsonResponse(['success' => false, 'error' => 'Destinataire invalide.'], 404);
        $stmt = $db->prepare("
            SELECT mp.id, mp.expediteur_id, mp.destinataire_id, mp.contenu, mp.lu, mp.date_envoi,
                   COALESCE(mp.type_msg,'texte') AS type_msg, mp.audio_data, mp.media_data, mp.media_type,
                   e.prenom AS exp_prenom, e.nom AS exp_nom, e.photo AS exp_photo,
                   d.prenom AS dest_prenom, d.nom AS dest_nom
            FROM messages_prives mp
            JOIN membres e ON mp.expediteur_id = e.id
            JOIN membres d ON mp.destinataire_id = d.id
            WHERE (mp.expediteur_id=? AND mp.destinataire_id=?)
               OR (mp.expediteur_id=? AND mp.destinataire_id=?)
            ORDER BY mp.date_envoi ASC LIMIT 100
        ");
        $stmt->execute([$uid, $dest_id, $dest_id, $uid]);
        $messages = $stmt->fetchAll();
        $db->prepare("UPDATE messages_prives SET lu=1 WHERE destinataire_id=? AND expediteur_id=? AND lu=0")->execute([$uid, $dest_id]);
        foreach ($messages as &$msg) {
            $msg['est_moi']   = $msg['expediteur_id'] == $uid;
            $msg['photo_url'] = $msg['exp_photo'] ? 'uploads/photos_membres/' . $msg['exp_photo'] : null;
        }
        jsonResponse(['success' => true, 'messages' => $messages]);
        break;

    case 'envoyer_prive':
        requireMembre();
        $dest_id  = (int)($input['destinataire_id'] ?? 0);
        $contenu  = trim($input['contenu'] ?? '');
        $type_msg = $input['type_msg'] ?? 'texte';
        $audio    = $input['audio_data'] ?? null;
        $media    = $input['media_data'] ?? null;
        $mtype    = $input['media_type'] ?? null;
        if (!$dest_id) jsonResponse(['success' => false, 'error' => 'Destinataire requis.'], 400);
        if ($dest_id === $_SESSION['user_id']) jsonResponse(['success' => false, 'error' => 'Impossible de s\'envoyer un message à soi-même.'], 400);
        if (in_array($type_msg, ['texte','sticker']) && empty($contenu)) jsonResponse(['success' => false, 'error' => 'Contenu requis.'], 400);
        $db = getDB();
        $chk = $db->prepare("SELECT id FROM membres WHERE id=? AND statut='valide'");
        $chk->execute([$dest_id]);
        if (!$chk->fetch()) jsonResponse(['success' => false, 'error' => 'Destinataire invalide.'], 404);
        $db->prepare("INSERT INTO messages_prives (expediteur_id, destinataire_id, contenu, type_msg, audio_data, media_data, media_type) VALUES (?,?,?,?,?,?,?)")
           ->execute([$_SESSION['user_id'], $dest_id, $contenu ?: '[MEDIA]', $type_msg, $audio, $media, $mtype]);
        jsonResponse(['success' => true, 'message_id' => $db->lastInsertId()]);
        break;

    case 'conversations':
        requireMembre();
        $db  = getDB();
        $uid = $_SESSION['user_id'];
        $stmt = $db->prepare("
            SELECT CASE WHEN mp.expediteur_id=? THEN mp.destinataire_id ELSE mp.expediteur_id END AS interlocuteur_id,
                m.prenom, m.nom, m.photo,
                MAX(mp.date_envoi) AS derniere_activite,
                SUM(CASE WHEN mp.destinataire_id=? AND mp.lu=0 THEN 1 ELSE 0 END) AS non_lus,
                (SELECT contenu FROM messages_prives WHERE (expediteur_id=? AND destinataire_id=m.id) OR (expediteur_id=m.id AND destinataire_id=?) ORDER BY date_envoi DESC LIMIT 1) AS dernier_message
            FROM messages_prives mp
            JOIN membres m ON m.id = CASE WHEN mp.expediteur_id=? THEN mp.destinataire_id ELSE mp.expediteur_id END
            WHERE mp.expediteur_id=? OR mp.destinataire_id=?
            GROUP BY interlocuteur_id, m.prenom, m.nom, m.photo
            ORDER BY derniere_activite DESC
        ");
        $stmt->execute([$uid,$uid,$uid,$uid,$uid,$uid,$uid]);
        $convs = $stmt->fetchAll();
        foreach ($convs as &$c) $c['photo_url'] = $c['photo'] ? 'uploads/photos_membres/'.$c['photo'] : null;
        jsonResponse(['success' => true, 'conversations' => $convs]);
        break;

    case 'non_lus':
        requireMembre();
        $db  = getDB();
        $uid = $_SESSION['user_id'];
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM messages_prives WHERE destinataire_id=? AND lu=0");
        $stmt->execute([$uid]);
        jsonResponse(['success' => true, 'non_lus' => (int)$stmt->fetch()['total']]);
        break;

    case 'supprimer':
        requireMembre();
        $id   = (int)($input['id'] ?? 0);
        $type = $input['type'] ?? 'groupe';
        if (!$id) jsonResponse(['success' => false, 'error' => 'ID requis.'], 400);
        $db  = getDB();
        $uid = $_SESSION['user_id'];
        if ($type === 'groupe') {
            $chk = $db->prepare("SELECT membre_id FROM messages_groupe WHERE id=?");
            $chk->execute([$id]);
            $msg = $chk->fetch();
            if (!$msg) jsonResponse(['success' => false, 'error' => 'Message introuvable.'], 404);
            if ($msg['membre_id'] != $uid && !isAdmin()) jsonResponse(['success' => false, 'error' => 'Non autorisé.'], 403);
            $db->prepare("DELETE FROM messages_groupe WHERE id=?")->execute([$id]);
        } else {
            $chk = $db->prepare("SELECT expediteur_id FROM messages_prives WHERE id=?");
            $chk->execute([$id]);
            $msg = $chk->fetch();
            if (!$msg) jsonResponse(['success' => false, 'error' => 'Message introuvable.'], 404);
            if ($msg['expediteur_id'] != $uid && !isAdmin()) jsonResponse(['success' => false, 'error' => 'Non autorisé.'], 403);
            $db->prepare("DELETE FROM messages_prives WHERE id=?")->execute([$id]);
        }
        jsonResponse(['success' => true, 'message' => 'Message supprimé.']);
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Action inconnue.'], 400);
}
