<?php
require_once __DIR__ . '/config.php';
session_start_if_not();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input  = getInput();

switch ($action) {

    // ─── GÉNÉRER ET ENVOYER OTP ──────────────────
    case 'envoyer':
        $email  = strtolower(trim($input['email'] ?? ''));
        $prenom = sanitize($input['prenom'] ?? 'Étudiant');

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL))
            jsonResponse(['success' => false, 'error' => 'Email invalide.'], 400);

        $db = getDB();

        // Vérifier que l'email n'est pas déjà utilisé par un compte validé
        $chk = $db->prepare("SELECT id, statut FROM membres WHERE email = ?");
        $chk->execute([$email]);
        $existing = $chk->fetch();
        if ($existing && $existing['statut'] !== 'en_attente')
            jsonResponse(['success' => false, 'error' => 'Cet email est déjà associé à un compte.'], 409);

        // Générer code OTP 6 chiffres
        $code    = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expire  = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        // Supprimer anciens codes pour cet email
        $db->prepare("DELETE FROM otp_codes WHERE email = ?")->execute([$email]);

        // Insérer nouveau code
        $db->prepare("INSERT INTO otp_codes (email, code, expire_at) VALUES (?,?,?)")
           ->execute([$email, $code, $expire]);

        // Retourner les données pour EmailJS
        jsonResponse([
            'success'  => true,
            'message'  => 'Code OTP généré. Envoyez-le par EmailJS.',
            'emailjs_data' => [
                'service_id'  => EMAILJS_SERVICE_ID,
                'template_id' => EMAILJS_TEMPLATE_ID,
                'public_key'  => EMAILJS_PUBLIC_KEY,
                'to_email'    => $email,
                'to_name'     => $prenom,
                'subject'     => 'Votre code de vérification UNSEB Adjarra',
                'message'     => "Bonjour $prenom,\n\nVotre code de vérification UNSEB Adjarra est :\n\n🔐 " . $code . "\n\nCe code est valable 10 minutes.\n\nSi vous n'avez pas demandé ce code, ignorez cet email.\n\nL'équipe UNSEB Adjarra",
                'otp_code'    => $code,
            ]
        ]);
        break;

    // ─── VÉRIFIER OTP ────────────────────────────
    case 'verifier':
        $email = strtolower(trim($input['email'] ?? ''));
        $code  = trim($input['code'] ?? '');

        if (!$email || !$code)
            jsonResponse(['success' => false, 'error' => 'Email et code requis.'], 400);

        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM otp_codes WHERE email = ? AND code = ? AND utilise = 0 AND expire_at > NOW() ORDER BY id DESC LIMIT 1");
        $stmt->execute([$email, $code]);
        $otp = $stmt->fetch();

        if (!$otp)
            jsonResponse(['success' => false, 'error' => 'Code incorrect ou expiré. Vérifiez votre email ou redemandez un code.'], 400);

        // Marquer comme utilisé
        $db->prepare("UPDATE otp_codes SET utilise = 1 WHERE id = ?")->execute([$otp['id']]);

        // Marquer l'email comme vérifié dans membres si existe
        $db->prepare("UPDATE membres SET email_verifie = 1 WHERE email = ?")->execute([$email]);

        jsonResponse(['success' => true, 'message' => 'Email vérifié avec succès !', 'email' => $email]);
        break;

    // ─── VÉRIFIER STATUT VÉRIFICATION ───────────
    case 'statut':
        $email = strtolower(trim($_GET['email'] ?? ''));
        if (!$email) jsonResponse(['success' => false, 'error' => 'Email requis.'], 400);

        $db   = getDB();
        // Vérifier si un OTP valide non utilisé existe (pas encore vérifié)
        $stmt = $db->prepare("SELECT COUNT(*) as n FROM otp_codes WHERE email = ? AND utilise = 1");
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        jsonResponse(['success' => true, 'verifie' => $row['n'] > 0]);
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Action inconnue.'], 400);
}
