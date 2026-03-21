<?php
require_once __DIR__ . '/config.php';
session_start_if_not();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input  = getInput();

switch ($action) {

    // ─── INSCRIPTION ─────────────────────────────
    case 'register':
        // Accepter JSON ou FormData
        $prenom    = trim($input['prenom'] ?? $_POST['prenom'] ?? '');
        $nom       = trim($input['nom'] ?? $_POST['nom'] ?? '');
        $email     = strtolower(trim($input['email'] ?? $_POST['email'] ?? ''));
        $password  = $input['mot_de_passe'] ?? $_POST['mot_de_passe'] ?? '';
        $telephone = trim($input['telephone'] ?? $_POST['telephone'] ?? '');
        $filiere   = trim($input['filiere'] ?? $_POST['filiere'] ?? '');
        $niveau    = trim($input['niveau'] ?? $_POST['niveau'] ?? '');
        $email_verifie = (int)($input['email_verifie'] ?? $_POST['email_verifie'] ?? 0);

        if (!$prenom || !$nom || !$email || !$password)
            jsonResponse(['success' => false, 'error' => 'Champs obligatoires manquants.'], 400);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            jsonResponse(['success' => false, 'error' => 'Email invalide.'], 400);

        if (strlen($password) < 8)
            jsonResponse(['success' => false, 'error' => 'Mot de passe trop court (8 caractères minimum).'], 400);

        $db = getDB();

        // Vérifier si email existe déjà
        $check = $db->prepare("SELECT id, statut FROM membres WHERE email = ?");
        $check->execute([$email]);
        $existing = $check->fetch();
        if ($existing && $existing['statut'] !== 'en_attente')
            jsonResponse(['success' => false, 'error' => 'Cet email est déjà utilisé.'], 409);
        if ($existing)
            jsonResponse(['success' => false, 'error' => 'Une inscription est déjà en cours avec cet email.'], 409);

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        // Gérer upload photo
        $photo = null;
        if (!empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $file    = $_FILES['photo'];
            $allowed = ['image/jpeg', 'image/png', 'image/webp'];
            if (in_array($file['type'], $allowed) && $file['size'] <= 5 * 1024 * 1024) {
                $ext   = pathinfo($file['name'], PATHINFO_EXTENSION);
                $photo = 'membre_' . uniqid() . '.' . $ext;
                if (!is_dir(UPLOAD_MEMBRES)) mkdir(UPLOAD_MEMBRES, 0755, true);
                move_uploaded_file($file['tmp_name'], UPLOAD_MEMBRES . $photo);
            }
        }

        $stmt = $db->prepare("
            INSERT INTO membres (prenom, nom, email, mot_de_passe, telephone, filiere, niveau, role, statut, photo, email_verifie)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'membre', 'en_attente', ?, ?)
        ");
        // email_verifie column may not exist yet — handle gracefully
        try {
            $stmt->execute([$prenom, $nom, $email, $hash, $telephone, $filiere, $niveau, $photo, $email_verifie]);
        } catch (PDOException $e) {
            // fallback sans email_verifie
            $stmt2 = $db->prepare("INSERT INTO membres (prenom, nom, email, mot_de_passe, telephone, filiere, niveau, role, statut, photo) VALUES (?, ?, ?, ?, ?, ?, ?, 'membre', 'en_attente', ?)");
            $stmt2->execute([$prenom, $nom, $email, $hash, $telephone, $filiere, $niveau, $photo]);
        }
        $newId = $db->lastInsertId();

        // Générer matricule et mot de passe CECAL
        $annee = date("Y");
        $matricule = "UNSEB-" . $annee . "-" . str_pad($newId, 4, "0", STR_PAD_LEFT);
        $cecal_mdp = substr($annee, -4) . "-" . str_pad($newId * 7 + 13, 4, "0", STR_PAD_LEFT);
        try { $db->prepare("UPDATE membres SET matricule=?, cecal_mdp=? WHERE id=?")->execute([$matricule, $cecal_mdp, $newId]); } catch(Exception $e) {}

        jsonResponse([
            'success' => true,
            'message' => 'Inscription réussie ! Votre compte est en attente de validation par l\'administrateur.',
            'membre_id' => $newId,
            'emailjs_data' => [
                'service_id'  => EMAILJS_SERVICE_ID,
                'template_id' => EMAILJS_TEMPLATE_ID,
                'public_key'  => EMAILJS_PUBLIC_KEY,
                'to_email'    => ADMIN_EMAIL,
                'to_name'     => ADMIN_NOM,
                'from_name'   => "$prenom $nom",
                'from_email'  => $email,
                'filiere'     => $filiere,
                'niveau'      => $niveau,
                'telephone'   => $telephone,
                'message'     => "Nouvelle inscription en attente de validation.\nMembre: $prenom $nom\nEmail: $email\nFilière: $filiere\nNiveau: $niveau\nTél: $telephone",
            ]
        ]);
        break;

    // ─── CONNEXION ───────────────────────────────
    case 'login':
        $email    = strtolower(trim($input['email'] ?? ''));
        $password = $input['mot_de_passe'] ?? '';

        if (!$email || !$password)
            jsonResponse(['success' => false, 'error' => 'Email et mot de passe requis.'], 400);

        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM membres WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['mot_de_passe']))
            jsonResponse(['success' => false, 'error' => 'Email ou mot de passe incorrect.'], 401);

        if ($user['statut'] === 'en_attente')
            jsonResponse(['success' => false, 'error' => 'Votre compte est en attente de validation par l\'administrateur.'], 403);

        if ($user['statut'] === 'suspendu')
            jsonResponse(['success' => false, 'error' => 'Votre compte a été suspendu. Contactez l\'administrateur.'], 403);

        // Mettre à jour dernière connexion
        $db->prepare("UPDATE membres SET derniere_connexion = NOW() WHERE id = ?")->execute([$user['id']]);

        // Créer la session
        $_SESSION['user_id']  = $user['id'];
        $_SESSION['role']     = $user['role'];
        $_SESSION['prenom']   = $user['prenom'];
        $_SESSION['nom']      = $user['nom'];
        $_SESSION['email']    = $user['email'];
        $_SESSION['statut']   = $user['statut'];

        jsonResponse([
            'success' => true,
            'user' => [
                'id'      => $user['id'],
                'prenom'  => $user['prenom'],
                'nom'     => $user['nom'],
                'email'   => $user['email'],
                'role'    => $user['role'],
                'statut'  => $user['statut'],
                'photo'   => $user['photo'],
                'filiere' => $user['filiere'],
                'niveau'  => $user['niveau'],
            ]
        ]);
        break;

    // ─── DÉCONNEXION ─────────────────────────────
    case 'logout':
        session_destroy();
        jsonResponse(['success' => true, 'message' => 'Déconnecté.']);
        break;

    // ─── SESSION ACTUELLE ─────────────────────────
    case 'me':
        if (!isLoggedIn())
            jsonResponse(['success' => false, 'error' => 'Non connecté.'], 401);

        $db   = getDB();
        $stmt = $db->prepare("SELECT id, prenom, nom, email, role, statut, photo, filiere, niveau, telephone FROM membres WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        jsonResponse(['success' => true, 'user' => $user]);
        break;

    // ─── CHANGER MOT DE PASSE ────────────────────
    case 'change_password':
        requireLogin();
        $ancien   = $input['ancien_mdp'] ?? '';
        $nouveau  = $input['nouveau_mdp'] ?? '';

        if (strlen($nouveau) < 8)
            jsonResponse(['success' => false, 'error' => 'Nouveau mot de passe trop court.'], 400);

        $db   = getDB();
        $stmt = $db->prepare("SELECT mot_de_passe FROM membres WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (!password_verify($ancien, $user['mot_de_passe']))
            jsonResponse(['success' => false, 'error' => 'Ancien mot de passe incorrect.'], 400);

        $hash = password_hash($nouveau, PASSWORD_BCRYPT, ['cost' => 12]);
        $db->prepare("UPDATE membres SET mot_de_passe = ? WHERE id = ?")->execute([$hash, $_SESSION['user_id']]);

        jsonResponse(['success' => true, 'message' => 'Mot de passe modifié avec succès.']);
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Action inconnue.'], 400);
}
