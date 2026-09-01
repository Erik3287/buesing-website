<?php
// ============================================
// Büsing LV App – Datenbank-Konfiguration
// ============================================
// WICHTIG: Trage hier dein Datenbank-Passwort ein
// (das Passwort das du bei IONOS für die Datenbank gesetzt hast)

defined('DB_HOST') || define('DB_HOST', 'db5020779705.hosting-data.io');
defined('DB_PORT') || define('DB_PORT', '3306');
defined('DB_NAME') || define('DB_NAME', 'dbs15830593');
defined('DB_USER') || define('DB_USER', 'dbu5407401');
defined('DB_PASS') || define('DB_PASS', 'Buesing2026!');  // <-- HIER eintragen!

// Login für die App (später durch sicheres System ersetzbar)
defined('APP_USER') || define('APP_USER', 'Erik');
defined('APP_PASS') || define('APP_PASS', '0302');

// Schlüssel für die KI (LV und Preislisten einlesen).
// Statt 1234 deinen Schlüssel aus SecurePass eintragen, beginnt mit sk-ant-
// Anführungszeichen und Semikolon stehen lassen.
defined('ANTHROPIC_KEY') || define('ANTHROPIC_KEY', '1234');

// ============================================
// Ab hier nichts ändern
// ============================================
// Doppelt geladene config.php darf nicht mehr alles lahmlegen (26.08.2026)
if (!function_exists('getDB')) {
function getDB() {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Datenbankverbindung fehlgeschlagen: ' . $e->getMessage()]);
        exit;
    }
}
}
?>
