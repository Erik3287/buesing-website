<?php
/* ------------------------------------------------------------------
 * sso.php  –  Single-Sign-On-Brücke für das LV-Programm
 *
 * Liegt im Wurzelverzeichnis (public/), neben Buesing_LV_App_v2.html
 * und api.php. Prüft, ob im Büro-System (public/buero/) gerade eine
 * gültige Anmeldung besteht, und meldet NUR Name + Rolle zurück –
 * niemals ein Passwort.
 *
 * Dadurch kann das LV-Programm einen im Dashboard angemeldeten Nutzer
 * automatisch durchlassen (einmal anmelden, überall drin).
 * ------------------------------------------------------------------ */

header('Content-Type: application/json; charset=utf-8');

/* Büro-System einbinden: startet dieselbe PHP-Sitzung und verbindet
   mit der Büro-Datenbank. Beide liegen auf derselben Domain, daher
   gilt die Sitzung auch hier. */
require_once __DIR__ . '/buero/auth.php';

$u = aktuellerNutzer();   // liest die Büro-Sitzung gegen die Büro-Nutzertabelle

if ($u) {
    echo json_encode([
        'ok'           => true,
        'benutzername' => $u['email'],   // Büro-System nutzt die E-Mail als Kennung
        'name'         => $u['name'],
        'rolle'        => $u['rolle'],
    ]);
} else {
    echo json_encode(['ok' => false]);
}
