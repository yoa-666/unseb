<?php
require_once __DIR__ . '/config.php';
session_start_if_not();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input  = getInput();

switch ($action) {

    // ─── GÉNÉRER MA CARTE (membre validé) ────────
    case 'generer':
        requireLogin();

        if ($_SESSION['statut'] !== 'valide')
            jsonResponse(['success' => false, 'error' => 'Compte non validé.'], 403);

        $db = getDB();

        // Vérifier si carte déjà générée
        $chk = $db->prepare("SELECT id, numero_carte, statut FROM cartes_membres WHERE membre_id = ?");
        $chk->execute([$_SESSION['user_id']]);
        $existing = $chk->fetch();

        if ($existing)
            jsonResponse(['success' => true, 'carte' => $existing, 'message' => 'Carte déjà existante.']);

        // Récupérer profil complet
        $m = $db->prepare("SELECT * FROM membres WHERE id = ?");
        $m->execute([$_SESSION['user_id']]);
        $membre = $m->fetch();

        // Générer numéro de carte unique
        $numero = 'UNSEB-' . date('Y') . '-' . str_pad($_SESSION['user_id'], 4, '0', STR_PAD_LEFT);

        $data = json_encode([
            'prenom'    => $membre['prenom'],
            'nom'       => $membre['nom'],
            'filiere'   => $membre['filiere'] ?? '',
            'niveau'    => $membre['niveau'] ?? '',
            'email'     => $membre['email'],
            'telephone' => $membre['telephone'] ?? '',
            'photo'     => $membre['photo'] ?? '',
            'id_membre' => $membre['id'],
            'numero'    => $numero,
            'date_gen'  => date('Y-m-d H:i:s'),
            'validite'  => 'Décembre ' . date('Y'),
            'badge'     => 'Standard',
        ], JSON_UNESCAPED_UNICODE);

        $stmt = $db->prepare("INSERT INTO cartes_membres (membre_id, numero_carte, data_json, statut) VALUES (?,?,?,'generee')");
        $stmt->execute([$_SESSION['user_id'], $numero, $data]);
        $carte_id = $db->lastInsertId();

        // Notifier l'admin via EmailJS
        $msgAdmin = "Nouvelle carte membre générée\n\nMembre : {$membre['prenom']} {$membre['nom']}\nEmail : {$membre['email']}\nFilière : {$membre['filiere']}\nNiveau : {$membre['niveau']}\nNuméro de carte : $numero\n\nConnectez-vous au panel admin pour approuver et gérer le tirage.";

        jsonResponse([
            'success'  => true,
            'message'  => 'Carte générée et envoyée à l\'administrateur pour impression.',
            'carte_id' => $carte_id,
            'numero'   => $numero,
            'data'     => json_decode($data, true),
            'emailjs_data' => [
                'service_id'  => EMAILJS_SERVICE_ID,
                'template_id' => EMAILJS_TEMPLATE_ID,
                'public_key'  => EMAILJS_PUBLIC_KEY,
                'to_email'    => ADMIN_EMAIL,
                'to_name'     => ADMIN_NOM,
                'from_name'   => $membre['prenom'] . ' ' . $membre['nom'],
                'from_email'  => $membre['email'],
                'subject'     => 'Nouvelle carte membre — ' . $numero,
                'message'     => $msgAdmin,
            ]
        ]);
        break;

    // ─── VOIR MA CARTE (aperçu membre) ───────────
    case 'ma_carte':
        requireLogin();

        if ($_SESSION['statut'] !== 'valide')
            jsonResponse(['success' => false, 'error' => 'Compte non validé.'], 403);

        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM cartes_membres WHERE membre_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $carte = $stmt->fetch();

        if (!$carte)
            jsonResponse(['success' => false, 'error' => 'Aucune carte générée.', 'code' => 'no_card'], 404);

        $data = json_decode($carte['data_json'], true);
        jsonResponse([
            'success' => true,
            'carte'   => $carte,
            'data'    => $data,
            // NB: Le membre voit uniquement l'aperçu — pas de téléchargement
        ]);
        break;

    // ─── LISTE TOUTES CARTES (admin) ─────────────
    case 'liste_admin':
        requireAdmin();
        $db     = getDB();
        $statut = $_GET['statut'] ?? '';
        $sql    = "SELECT c.*, m.prenom, m.nom, m.email, m.filiere, m.niveau, m.photo
                   FROM cartes_membres c JOIN membres m ON c.membre_id = m.id";
        $params = [];
        if ($statut) { $sql .= " WHERE c.statut = ?"; $params[] = $statut; }
        $sql .= " ORDER BY c.date_creation DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $cartes = $stmt->fetchAll();
        foreach ($cartes as &$c) {
            $c['data'] = json_decode($c['data_json'], true);
        }
        jsonResponse(['success' => true, 'cartes' => $cartes]);
        break;

    // ─── DÉTAIL CARTE (admin) ────────────────────
    case 'detail':
        requireAdmin();
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'error' => 'ID requis.'], 400);

        $db   = getDB();
        $stmt = $db->prepare("SELECT c.*, m.prenom, m.nom, m.email, m.filiere, m.niveau, m.photo, m.telephone
                               FROM cartes_membres c JOIN membres m ON c.membre_id = m.id WHERE c.id = ?");
        $stmt->execute([$id]);
        $carte = $stmt->fetch();

        if (!$carte) jsonResponse(['success' => false, 'error' => 'Carte introuvable.'], 404);

        $carte['data'] = json_decode($carte['data_json'], true);
        jsonResponse(['success' => true, 'carte' => $carte]);
        break;

    // ─── CHANGER STATUT CARTE (admin) ────────────
    case 'changer_statut':
        requireAdmin();
        $id     = (int)($input['id'] ?? 0);
        $statut = $input['statut'] ?? '';
        $statuts_valides = ['generee', 'approuvee', 'imprimee', 'livree'];

        if (!$id || !in_array($statut, $statuts_valides))
            jsonResponse(['success' => false, 'error' => 'Paramètres invalides.'], 400);

        $db = getDB();
        $db->prepare("UPDATE cartes_membres SET statut=?, date_approbation = IF(? = 'approuvee', NOW(), date_approbation) WHERE id=?")
           ->execute([$statut, $statut, $id]);

        // Si livrée, notifier le membre
        if ($statut === 'livree') {
            $stmt = $db->prepare("SELECT m.prenom, m.nom, m.email, c.numero_carte FROM cartes_membres c JOIN membres m ON c.membre_id = m.id WHERE c.id=?");
            $stmt->execute([$id]);
            $data = $stmt->fetch();

            jsonResponse([
                'success' => true,
                'message' => 'Carte marquée comme livrée.',
                'emailjs_data' => [
                    'service_id'  => EMAILJS_SERVICE_ID,
                    'template_id' => EMAILJS_TEMPLATE_ID,
                    'public_key'  => EMAILJS_PUBLIC_KEY,
                    'to_email'    => $data['email'],
                    'to_name'     => $data['prenom'] . ' ' . $data['nom'],
                    'subject'     => 'Votre carte membre UNSEB est prête !',
                    'message'     => "Bonjour " . $data['prenom'] . ",\n\nVotre carte membre UNSEB Adjarra (N° " . $data['numero_carte'] . ") est prête et vous a été livrée.\n\nBienvenue officiellement dans la communauté UNSEB Adjarra !\n\nL'équipe UNSEB Adjarra",
                ]
            ]);
        }

        jsonResponse(['success' => true, 'message' => 'Statut mis à jour.']);
        break;

    // ─── TÉLÉCHARGER CARTE (admin SEULEMENT) ─────
    case 'telecharger':
        requireAdmin();
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'error' => 'ID requis.'], 400);

        $db   = getDB();
        $stmt = $db->prepare("SELECT c.*, m.prenom, m.nom, m.email, m.filiere, m.niveau, m.photo, m.telephone
                               FROM cartes_membres c JOIN membres m ON c.membre_id = m.id WHERE c.id = ?");
        $stmt->execute([$id]);
        $carte = $stmt->fetch();

        if (!$carte) jsonResponse(['success' => false, 'error' => 'Carte introuvable.'], 404);

        // Retourner les données complètes pour génération Canvas côté admin
        $carte['data'] = json_decode($carte['data_json'], true);

        // Marquer comme approuvée si ce n'est pas encore fait
        if ($carte['statut'] === 'generee') {
            $db->prepare("UPDATE cartes_membres SET statut='approuvee', date_approbation=NOW() WHERE id=?")->execute([$id]);
        }

        jsonResponse([
            'success'   => true,
            'carte'     => $carte,
            'photo_url' => $carte['data']['photo'] ? 'uploads/photos_membres/' . $carte['data']['photo'] : null,
            'autorisation' => 'ADMIN_DOWNLOAD_AUTHORIZED',
        ]);
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Action inconnue.'], 400);
}
