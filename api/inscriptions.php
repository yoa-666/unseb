<?php
require_once __DIR__ . '/config.php';
session_start_if_not();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input  = getInput();

switch ($action) {

    // ─── SOUMETTRE UNE INSCRIPTION ───────────────
    case 'inscrire':
        requireLogin();

        // Vérifier que le membre est validé
        if ($_SESSION['statut'] !== 'valide') {
            jsonResponse(['success' => false, 'error' => 'Votre compte doit être validé par l\'administrateur avant de pouvoir vous inscrire à une section.'], 403);
        }

        $section = strtoupper(trim($input['section'] ?? ''));
        $domaine = sanitize($input['domaine'] ?? '');
        $message = sanitize($input['message'] ?? '');
        $matieres = sanitize($input['matieres'] ?? '');

        $sections_valides = ['CECAL', 'COGHERES', 'TD', 'INFORMATIQUE'];
        if (!in_array($section, $sections_valides)) {
            jsonResponse(['success' => false, 'error' => 'Section invalide.'], 400);
        }

        $db = getDB();

        // Vérifier inscription déjà existante
        $chk = $db->prepare("SELECT id, statut FROM inscriptions_sections WHERE membre_id = ? AND section = ?");
        $chk->execute([$_SESSION['user_id'], $section]);
        $existing = $chk->fetch();

        if ($existing) {
            $msg = $existing['statut'] === 'accepte' ? 'Vous êtes déjà inscrit(e) à cette section.' : 'Vous avez déjà une demande en attente pour cette section.';
            jsonResponse(['success' => false, 'error' => $msg], 409);
        }

        // Insérer l'inscription
        $stmt = $db->prepare("INSERT INTO inscriptions_sections (membre_id, section, domaine, message, statut) VALUES (?, ?, ?, ?, 'en_attente')");
        $stmt->execute([$_SESSION['user_id'], $section, $domaine ?: $matieres, $message]);

        $prenom   = $_SESSION['prenom'];
        $nom      = $_SESSION['nom'];
        $email    = $_SESSION['email'];

        $sectionLabel = [
            'CECAL' => 'CECAL — Culture, Arts et Loisirs',
            'COGHERES' => 'COGHERES — Hygiène et Bien-être',
            'TD' => 'Travaux Dirigés Gratuits',
            'INFORMATIQUE' => 'Formation Informatique Appliquée',
        ][$section] ?? $section;

        $msgAdmin = "Nouvelle demande d'inscription\n\nMembre : $prenom $nom\nEmail : $email\nSection : $sectionLabel\n" .
            ($domaine || $matieres ? "Spécialité/Matières : " . ($domaine ?: $matieres) . "\n" : '') .
            ($message ? "Message : $message\n" : '') .
            "\nConnectez-vous au panneau admin pour valider cette demande.";

        jsonResponse([
            'success' => true,
            'message' => 'Demande d\'inscription envoyée ! L\'administrateur vous contactera bientôt.',
            'emailjs_data' => [
                'service_id'  => EMAILJS_SERVICE_ID,
                'template_id' => EMAILJS_TEMPLATE_ID,
                'public_key'  => EMAILJS_PUBLIC_KEY,
                'to_email'    => ADMIN_EMAIL,
                'to_name'     => ADMIN_NOM,
                'from_name'   => "$prenom $nom",
                'from_email'  => $email,
                'subject'     => "Nouvelle inscription — $sectionLabel",
                'message'     => $msgAdmin,
            ]
        ]);
        break;

    // ─── MES INSCRIPTIONS ────────────────────────
    case 'mes_inscriptions':
        requireLogin();
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM inscriptions_sections WHERE membre_id = ? ORDER BY date_inscription DESC");
        $stmt->execute([$_SESSION['user_id']]);
        jsonResponse(['success' => true, 'inscriptions' => $stmt->fetchAll()]);
        break;

    // ─── TOUTES LES INSCRIPTIONS (admin) ─────────
    case 'liste_admin':
        requireAdmin();
        $db   = getDB();
        $section = $_GET['section'] ?? '';
        $statut  = $_GET['statut'] ?? '';

        $sql = "SELECT i.*, m.prenom, m.nom, m.email, m.telephone, m.filiere, m.niveau
                FROM inscriptions_sections i
                JOIN membres m ON i.membre_id = m.id
                WHERE 1=1";
        $params = [];
        if ($section) { $sql .= " AND i.section = ?"; $params[] = $section; }
        if ($statut)  { $sql .= " AND i.statut = ?";  $params[] = $statut; }
        $sql .= " ORDER BY i.date_inscription DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        jsonResponse(['success' => true, 'inscriptions' => $stmt->fetchAll()]);
        break;

    // ─── VALIDER INSCRIPTION (admin) ─────────────
    case 'valider':
        requireAdmin();
        $id = (int)($input['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'error' => 'ID requis'], 400);

        $db = getDB();
        $db->prepare("UPDATE inscriptions_sections SET statut='accepte' WHERE id=?")->execute([$id]);

        // Récupérer infos membre pour EmailJS
        $stmt = $db->prepare("SELECT i.section, m.prenom, m.nom, m.email FROM inscriptions_sections i JOIN membres m ON i.membre_id = m.id WHERE i.id=?");
        $stmt->execute([$id]);
        $data = $stmt->fetch();

        $sectionLabel = [
            'CECAL' => 'CECAL — Culture, Arts et Loisirs',
            'COGHERES' => 'COGHERES — Hygiène et Bien-être',
            'TD' => 'Travaux Dirigés Gratuits',
            'INFORMATIQUE' => 'Formation Informatique Appliquée',
        ][$data['section']] ?? $data['section'];

        jsonResponse([
            'success' => true,
            'message' => 'Inscription validée.',
            'emailjs_data' => [
                'service_id'  => EMAILJS_SERVICE_ID,
                'template_id' => EMAILJS_TEMPLATE_ID,
                'public_key'  => EMAILJS_PUBLIC_KEY,
                'to_email'    => $data['email'],
                'to_name'     => $data['prenom'] . ' ' . $data['nom'],
                'subject'     => 'Inscription validée — ' . $sectionLabel,
                'message'     => "Bonjour " . $data['prenom'] . ",\n\nVotre inscription à la section **$sectionLabel** a été validée par l'administrateur.\n\nPour les prochaines étapes, rendez-vous au bureau UNSEB Adjarra.\n\nL'équipe UNSEB Adjarra",
            ]
        ]);
        break;

    // ─── REFUSER INSCRIPTION (admin) ─────────────
    case 'refuser':
        requireAdmin();
        $id = (int)($input['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'error' => 'ID requis'], 400);
        $db = getDB();
        $db->prepare("UPDATE inscriptions_sections SET statut='refuse' WHERE id=?")->execute([$id]);
        jsonResponse(['success' => true, 'message' => 'Inscription refusée.']);
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Action inconnue'], 400);
}
