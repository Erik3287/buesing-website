<?php
// ============================================
// Büsing LV App – Datenbank-Konfiguration
// ============================================
// WICHTIG: Trage hier dein Datenbank-Passwort ein
// (das Passwort das du bei IONOS für die Datenbank gesetzt hast)

define('DB_HOST', 'db5020779705.hosting-data.io');
define('DB_PORT', '3306');
define('DB_NAME', 'dbs15830593');
define('DB_USER', 'dbu5407401');
define('DB_PASS', 'HIER_DB_PASSWORT_EINTRAGEN');  // <-- HIER eintragen!

// Login für die App (später durch sicheres System ersetzbar)
define('APP_USER', 'Erik');
define('APP_PASS', '');

// ============================================
// Ab hier nichts ändern
// ============================================
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
?>
