<?php
// ─── CONFIGURATION BASE DE DONNÉES ───
define('DB_HOST', 'localhost');
define('DB_NAME', 'unseb_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ─── EMAILJS ───
define('EMAILJS_SERVICE_ID', 'service_jvaq2r9');
define('EMAILJS_TEMPLATE_ID', 'template_v4ynadk');
define('EMAILJS_PUBLIC_KEY',  '9-Ta7lluOweVMqDUa');

// ─── ADMIN ───
define('ADMIN_EMAIL', 'unseb.adjarra@gmail.com');
define('ADMIN_NOM',   'Administrateur UNSEB');

// ─── UPLOADS ───
define('UPLOAD_DIR',        __DIR__ . '/../uploads/');
define('UPLOAD_GALERIE',    __DIR__ . '/../uploads/galerie/');
define('UPLOAD_EVENEMENTS', __DIR__ . '/../uploads/evenements/');
define('UPLOAD_MEMBRES',    __DIR__ . '/../uploads/photos_membres/');
define('MAX_FILE_SIZE',     10 * 1024 * 1024); // 10 MB

// ─── CONNEXION PDO ───
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['success' => false, 'error' => 'Erreur DB: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

// ─── HELPERS ───
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getInput(): array {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    return $json ?? $_POST;
}

function isLoggedIn(): bool {
    session_start_if_not();
    return isset($_SESSION['user_id']);
}

function isAdmin(): bool {
    session_start_if_not();
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        jsonResponse(['success' => false, 'error' => 'Non authentifié'], 401);
    }
}

function requireAdmin(): void {
    if (!isAdmin()) {
        jsonResponse(['success' => false, 'error' => 'Accès administrateur requis'], 403);
    }
}

function session_start_if_not(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function sanitize(string $str): string {
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}

function generateToken(int $length = 32): string {
    return bin2hex(random_bytes($length));
}

// ─── CORS preflight ───
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    http_response_code(200);
    exit;
}
