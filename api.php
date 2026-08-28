<?php
// ============================================
// Büsing LV App – API
// Schnittstelle zwischen App und Datenbank
// ============================================

require_once 'config.php';

// Sitzung starten – damit ein LV-Login serverseitig gemerkt wird
// (gleiche PHPSESSID wie das Büro-System, da selbe Domain).
if (session_status() === PHP_SESSION_NONE) { session_start(); }

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

// ===== FEHLER IMMER ALS JSON ZURUECKGEBEN =====
// Vorher: Bei einem DB-Fehler brach PHP ab und lieferte eine LEERE Seite.
// Die App meldete trotzdem "gespeichert". Jetzt kommt der echte Fehlertext.
set_exception_handler(function ($e) {
    http_response_code(200);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
});
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo json_encode(['ok' => false, 'error' => 'PHP: ' . $e['message']]);
    }
});

$action = $_GET['action'] ?? '';

// Schalter fuer den Zugriffsschutz weiter unten. Steht hier oben, damit er
// leicht zu finden ist: false = aus (Stand heute), true = an.
define('ZUGRIFFSSCHUTZ', false);

// ===== SELBSTTEST =====
// Ohne Anmeldung erreichbar, verraet keine Zugangsdaten. Im Browser aufrufen:
//   api.php?action=status
// Damit laesst sich von jedem Geraet aus pruefen, ob PHP laeuft, die Datenbank
// antwortet und eine Sitzung besteht - ohne sich erst anmelden zu muessen.
if ($action === 'status') {
    $db = 'nicht geprueft'; $artikel = null; $nutzer = null;
    try {
        $t = getDB();
        $artikel = (int) $t->query("SELECT COUNT(*) FROM preise")->fetchColumn();
        $nutzer  = (int) $t->query("SELECT COUNT(*) FROM nutzer")->fetchColumn();
        $db = 'verbunden';
    } catch (Throwable $e) {
        $db = 'FEHLER: ' . $e->getMessage();
    }
    echo json_encode([
        'ok'             => true,
        'php'            => PHP_VERSION,
        'datenbank'      => $db,
        'artikel'        => $artikel,
        'nutzer'         => $nutzer,
        'sitzung'        => session_id() ? 'vorhanden' : 'keine',
        'angemeldet'     => (!empty($_SESSION['nutzer_id']) || !empty($_SESSION['lv_user'])) ? 'ja' : 'nein',
        'zugriffsschutz' => ZUGRIFFSSCHUTZ ? 'an' : 'aus',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ===== ZUGRIFFSSCHUTZ =====
// Sperrt jede Aktion ausser Login und Selbsttest fuer nicht angemeldete
// Aufrufer. ABGESCHALTET, bis geprueft ist, dass die Sitzung auf dem Server
// zuverlaessig ankommt: Am 22.08.2026 kam Erik weder ins Dashboard, noch
// funktionierte der Upload. Ein Schutz, der den Betrieb lahmlegt, ist keiner -
// erst pruefen, dann wieder einschalten. Zum Einschalten: false -> true.
$eingeloggt = !empty($_SESSION['nutzer_id']) || !empty($_SESSION['lv_user']);
if (ZUGRIFFSSCHUTZ && $action !== 'login' && !$eingeloggt) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Nicht angemeldet.']);
    exit;
}

$pdo = getDB();

switch ($action) {

    // ===== SETUP: Fehlende Spalten anlegen (gefahrlos, mehrfach ausfuehrbar) =====
    case 'setup_tabellen':
        $spalten = [
            'ansprechpartner' => [
                'funktion' => "VARCHAR(100) NOT NULL DEFAULT ''",
                'telefon'  => "VARCHAR(50)  NOT NULL DEFAULT ''",
                'mobil'    => "VARCHAR(50)  NOT NULL DEFAULT ''",
                'email'    => "VARCHAR(150) NOT NULL DEFAULT ''",
                'notiz'    => "TEXT",
            ],
            'preise' => [
                'artnr' => "VARCHAR(60) NOT NULL DEFAULT ''",
                // Wann Erik zuletzt bestaetigt hat, dass der Preis noch gilt.
                // Getrennt von 'dat' - das bleibt das Datum des Lieferantenbelegs.
                'geprueft' => "VARCHAR(20) NOT NULL DEFAULT ''",
            ],
            'lieferanten' => [
                'strasse'  => "VARCHAR(150) NOT NULL DEFAULT ''",
                'plz'      => "VARCHAR(10)  NOT NULL DEFAULT ''",
                'ort'      => "VARCHAR(100) NOT NULL DEFAULT ''",
                'telefon'  => "VARCHAR(50)  NOT NULL DEFAULT ''",
                'email'    => "VARCHAR(150) NOT NULL DEFAULT ''",
                'website'  => "VARCHAR(150) NOT NULL DEFAULT ''",
                'kundennr' => "VARCHAR(50)  NOT NULL DEFAULT ''",
            ],
        ];
        $bericht = [];
        // Tabelle fuer die Kalkulationswerte. Sie liegen sonst nur im Browser
        // und gelten dann an jedem Rechner anders (Erik 28.08.2026: "ja, ich
        // brauche das als Datenbank").
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS einstellungen (
                schluessel VARCHAR(64) NOT NULL PRIMARY KEY,
                wert       VARCHAR(64) NOT NULL DEFAULT '',
                geaendert  VARCHAR(30) NOT NULL DEFAULT ''
            ) DEFAULT CHARSET=utf8mb4");
            $bericht[] = "einstellungen — Tabelle vorhanden";
        } catch (Exception $e) {
            $bericht[] = "einstellungen — FEHLER: " . $e->getMessage();
        }
        foreach ($spalten as $tabelle => $felder) {
            // Welche Spalten hat die Tabelle jetzt?
            $da = [];
            foreach ($pdo->query("SHOW COLUMNS FROM `$tabelle`")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $da[] = $c['Field'];
            }
            foreach ($felder as $name => $typ) {
                if (in_array($name, $da)) {
                    $bericht[] = "$tabelle.$name — war schon da";
                    continue;
                }
                try {
                    $pdo->exec("ALTER TABLE `$tabelle` ADD COLUMN `$name` $typ");
                    $bericht[] = "$tabelle.$name — NEU ANGELEGT";
                } catch (Exception $e) {
                    $bericht[] = "$tabelle.$name — FEHLER: " . $e->getMessage();
                }
            }
        }
        echo json_encode(['ok' => true, 'ergebnis' => $bericht], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    // ===== DIAGNOSE: Tabellenaufbau anzeigen =====
    case 'debug_schema':
        $out = [];
        foreach (['lieferanten', 'ansprechpartner', 'preise', 'lvs', 'nutzer'] as $t) {
            try {
                $cols = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
                $out[$t] = array_map(function ($c) {
                    return $c['Field'] . ' (' . $c['Type'] . ($c['Null'] === 'NO' ? ', NOT NULL' : '')
                         . ($c['Default'] === null && $c['Null'] === 'NO' ? ', kein Default' : '') . ')';
                }, $cols);
            } catch (Exception $e) {
                $out[$t] = 'FEHLER: ' . $e->getMessage();
            }
        }
        echo json_encode(['ok' => true, 'tabellen' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    // ===== PREISE LADEN =====
    case 'get_preise':
        $rows = $pdo->query("SELECT * FROM preise ORDER BY id")->fetchAll();
        echo json_encode(['ok' => true, 'preise' => $rows]);
        break;

    // ===== ALLE PREISE SPEICHERN (überschreibt alles) =====
    case 'save_preise':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!isset($data['preise']) || !is_array($data['preise'])) {
            echo json_encode(['ok' => false, 'error' => 'Keine Daten empfangen - nichts gespeichert.']);
            break;
        }

        // ===== SCHUTZ GEGEN VERSEHENTLICHES LEERRAEUMEN =====
        // Die App schickt bei jeder Aenderung die GANZE Preisliste, und hier
        // wird alles geloescht und neu eingetragen. War die Datenbank beim
        // Laden nicht erreichbar, ist die Liste im Browser leer - dann wuerde
        // hier die komplette Preisdatenbank verschwinden, und die App meldet
        // trotzdem "gespeichert".
        // Deshalb: leere Liste nie annehmen, und einen starken Schwund nur
        // nach ausdruecklicher Bestaetigung.
        $neu  = count($data['preise']);
        $alt  = (int) $pdo->query("SELECT COUNT(*) FROM preise")->fetchColumn();
        $ok   = !empty($data['bestaetigt']);   // Notausgang, falls wirklich gewollt

        if ($neu === 0 && $alt > 0 && !$ok) {
            echo json_encode(['ok' => false, 'schutz' => true, 'alt' => $alt, 'neu' => 0,
                'error' => 'Es wurde eine LEERE Preisliste geschickt, in der Datenbank stehen aber '
                         . $alt . ' Artikel. Nichts geloescht. Meist ist die Datenbank beim Laden '
                         . 'nicht erreichbar gewesen - Seite neu laden und pruefen.']);
            break;
        }
        if ($alt >= 10 && $neu < $alt / 2 && !$ok) {
            echo json_encode(['ok' => false, 'schutz' => true, 'alt' => $alt, 'neu' => $neu,
                'error' => 'Die geschickte Liste hat nur ' . $neu . ' Artikel, in der Datenbank '
                         . 'stehen ' . $alt . '. Das sieht nach einem Fehler aus - nichts geaendert. '
                         . 'Wenn das so gewollt ist, bitte bestaetigen.']);
            break;
        }

        // Welche Zusatzspalten gibt es schon? Fehlt eine, wird sie einfach
        // weggelassen - so laeuft das Speichern auch auf einer aelteren
        // Datenbank weiter, statt mit einem SQL-Fehler abzubrechen.
        $vorhanden = [];
        foreach ($pdo->query("SHOW COLUMNS FROM preise")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $vorhanden[$c['Field']] = true;
        }
        $spalten = ['name', 'kat', 'ek', 'eu', 'format', 'lief', 'dat'];
        foreach (['artnr', 'geprueft'] as $zusatz) {
            if (!empty($vorhanden[$zusatz])) $spalten[] = $zusatz;
        }
        $liste  = implode(', ', $spalten);
        $frage  = implode(', ', array_fill(0, count($spalten), '?'));
        $pdo->beginTransaction();
        try {
            $pdo->exec("DELETE FROM preise");
            $stmt = $pdo->prepare("INSERT INTO preise ($liste) VALUES ($frage)");
            foreach ($data['preise'] as $p) {
                $werte = [];
                foreach ($spalten as $sp) {
                    $werte[] = ($sp === 'ek') ? ($p['ek'] ?? 0) : ($p[$sp] ?? '');
                }
                $stmt->execute($werte);
            }
            $pdo->commit();
            echo json_encode(['ok' => true, 'count' => count($data['preise']),
                              'spalten' => $spalten]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // ===== LVs LADEN (Übersicht) =====
    case 'get_lvs':
        $rows = $pdo->query("SELECT id, titel, auftraggeber, status, summe, nutzer, created_at, updated_at FROM lvs ORDER BY updated_at DESC")->fetchAll();
        echo json_encode(['ok' => true, 'lvs' => $rows]);
        break;

    // ===== EINZELNES LV LADEN (mit Positionen) =====
    case 'get_lv':
        $id = intval($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM lvs WHERE id = ?");
        $stmt->execute([$id]);
        $lv = $stmt->fetch();
        if ($lv) { $lv['positionen'] = json_decode($lv['positionen'], true); }
        echo json_encode(['ok' => true, 'lv' => $lv]);
        break;

    // ===== LV SPEICHERN (neu oder update) =====
    case 'save_lv':
        $data = json_decode(file_get_contents('php://input'), true);
        $id = intval($data['id'] ?? 0);
        $pos = json_encode($data['positionen'] ?? []);
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE lvs SET titel=?, auftraggeber=?, status=?, positionen=?, summe=?, nutzer=? WHERE id=?");
            $stmt->execute([$data['titel'] ?? '', $data['auftraggeber'] ?? '', $data['status'] ?? 'bearbeitung', $pos, $data['summe'] ?? 0, $data['nutzer'] ?? '', $id]);
            echo json_encode(['ok' => true, 'id' => $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO lvs (titel, auftraggeber, status, positionen, summe, nutzer) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$data['titel'] ?? '', $data['auftraggeber'] ?? '', $data['status'] ?? 'bearbeitung', $pos, $data['summe'] ?? 0, $data['nutzer'] ?? '']);
            echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
        }
        break;

    // ===== LV LÖSCHEN =====
    case 'delete_lv':
        $id = intval($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM lvs WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['ok' => true]);
        break;

    // ===== LOGIN PRÜFEN (gegen Nutzer-Tabelle) =====
    case 'login':
        $data = json_decode(file_get_contents('php://input'), true);
        $u = $data['user'] ?? '';
        $p = $data['pass'] ?? '';
        $stmt = $pdo->prepare("SELECT * FROM nutzer WHERE benutzername = ?");
        $stmt->execute([$u]);
        $nutzer = $stmt->fetch();
        if ($nutzer && password_verify($p, $nutzer['passwort'])) {
            $_SESSION['lv_user'] = $nutzer['benutzername'];   // echte Sitzung merken
            echo json_encode(['ok' => true, 'name' => $nutzer['name'], 'rolle' => $nutzer['rolle'], 'benutzername' => $nutzer['benutzername']]);
        } else if ($u === APP_USER && $p === APP_PASS) {
            // Fallback auf config-Login
            $_SESSION['lv_user'] = $u;                        // echte Sitzung merken
            echo json_encode(['ok' => true, 'name' => $u, 'rolle' => 'admin', 'benutzername' => $u]);
        } else {
            echo json_encode(['ok' => false]);
        }
        break;


    // ===== LIEFERANTEN LADEN (mit Ansprechpartnern) =====
    case 'get_lieferanten':
        $liefs = $pdo->query("SELECT * FROM lieferanten ORDER BY name")->fetchAll();
        foreach ($liefs as &$l) {
            $stmt = $pdo->prepare("SELECT * FROM ansprechpartner WHERE lieferant_id = ? ORDER BY id");
            $stmt->execute([$l['id']]);
            $l['ansprechpartner'] = $stmt->fetchAll();
        }
        echo json_encode(['ok' => true, 'lieferanten' => $liefs]);
        break;

    // ===== LIEFERANT SPEICHERN (neu/update) =====
    case 'save_lieferant':
        $d = json_decode(file_get_contents('php://input'), true);
        $id = intval($d['id'] ?? 0);
        // Welche Adress-Spalten gibt es? (nur die schreiben, die wirklich existieren)
        $vorhanden = [];
        foreach ($pdo->query("SHOW COLUMNS FROM lieferanten")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $vorhanden[] = $c['Field'];
        }
        $extra = ['strasse','plz','ort','telefon','email','website','kundennr'];
        $felder = ['name','logo','notiz'];
        foreach ($extra as $f) { if (in_array($f, $vorhanden)) $felder[] = $f; }

        $werte = [];
        foreach ($felder as $f) { $werte[] = $d[$f] ?? ''; }

        if ($id > 0) {
            $set = implode('=?, ', $felder) . '=?';
            $stmt = $pdo->prepare("UPDATE lieferanten SET $set WHERE id=?");
            $werte[] = $id;
            $stmt->execute($werte);
        } else {
            $spalten = implode(', ', $felder);
            $frage   = implode(', ', array_fill(0, count($felder), '?'));
            $stmt = $pdo->prepare("INSERT INTO lieferanten ($spalten) VALUES ($frage)");
            $stmt->execute($werte);
            $id = $pdo->lastInsertId();
        }
        echo json_encode(['ok' => true, 'id' => $id, 'felder' => $felder]);
        break;

    // ===== LIEFERANT LÖSCHEN =====
    case 'delete_lieferant':
        $id = intval($_GET['id'] ?? 0);
        $pdo->prepare("DELETE FROM ansprechpartner WHERE lieferant_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM lieferanten WHERE id = ?")->execute([$id]);
        echo json_encode(['ok' => true]);
        break;

    // ===== ANSPRECHPARTNER SPEICHERN =====
    case 'save_ansprechpartner':
        $d = json_decode(file_get_contents('php://input'), true);
        $id = intval($d['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE ansprechpartner SET name=?, telefon=?, mobil=?, email=?, funktion=?, notiz=? WHERE id=?");
            $stmt->execute([$d['name'] ?? '', $d['telefon'] ?? '', $d['mobil'] ?? '', $d['email'] ?? '', $d['funktion'] ?? '', $d['notiz'] ?? '', $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO ansprechpartner (lieferant_id, name, telefon, mobil, email, funktion, notiz) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([intval($d['lieferant_id']), $d['name'] ?? '', $d['telefon'] ?? '', $d['mobil'] ?? '', $d['email'] ?? '', $d['funktion'] ?? '', $d['notiz'] ?? '']);
            $id = $pdo->lastInsertId();
        }
        echo json_encode(['ok' => true, 'id' => $id]);
        break;

    // ===== ANSPRECHPARTNER LÖSCHEN =====
    case 'delete_ansprechpartner':
        $id = intval($_GET['id'] ?? 0);
        $pdo->prepare("DELETE FROM ansprechpartner WHERE id = ?")->execute([$id]);
        echo json_encode(['ok' => true]);
        break;

    // ===== NUTZER LADEN =====
    case 'get_nutzer':
        $rows = $pdo->query("SELECT id, benutzername, name, rolle, email, telefon, created_at FROM nutzer ORDER BY id")->fetchAll();
        echo json_encode(['ok' => true, 'nutzer' => $rows]);
        break;

    // ===== NUTZER SPEICHERN =====
    case 'save_nutzer':
        $d = json_decode(file_get_contents('php://input'), true);
        $id = intval($d['id'] ?? 0);
        if ($id > 0) {
            if (!empty($d['passwort'])) {
                $stmt = $pdo->prepare("UPDATE nutzer SET benutzername=?, name=?, rolle=?, email=?, telefon=?, passwort=? WHERE id=?");
                $stmt->execute([$d['benutzername'], $d['name'] ?? '', $d['rolle'] ?? 'mitarbeiter', $d['email'] ?? '', $d['telefon'] ?? '', password_hash($d['passwort'], PASSWORD_DEFAULT), $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE nutzer SET benutzername=?, name=?, rolle=?, email=?, telefon=? WHERE id=?");
                $stmt->execute([$d['benutzername'], $d['name'] ?? '', $d['rolle'] ?? 'mitarbeiter', $d['email'] ?? '', $d['telefon'] ?? '', $id]);
            }
        } else {
            $stmt = $pdo->prepare("INSERT INTO nutzer (benutzername, name, rolle, email, telefon, passwort) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$d['benutzername'], $d['name'] ?? '', $d['rolle'] ?? 'mitarbeiter', $d['email'] ?? '', $d['telefon'] ?? '', password_hash($d['passwort'] ?? '0000', PASSWORD_DEFAULT)]);
            $id = $pdo->lastInsertId();
        }
        echo json_encode(['ok' => true, 'id' => $id]);
        break;

    // ===== NUTZER LÖSCHEN =====
    case 'delete_nutzer':
        $id = intval($_GET['id'] ?? 0);
        $pdo->prepare("DELETE FROM nutzer WHERE id = ?")->execute([$id]);
        echo json_encode(['ok' => true]);
        break;


    // ===== ABDICHTUNG LADEN =====
    // ===== KALKULATIONSWERTE =====
    // Ein Schluessel, ein Wert. Was hier nicht steht, gilt mit dem
    // Auslieferungswert aus der App.
    case 'get_einstellungen':
        try {
            $rows = $pdo->query("SELECT schluessel, wert FROM einstellungen")->fetchAll();
        } catch (Exception $e) {
            // Tabelle noch nicht angelegt: leer zurueckgeben statt Fehler
            echo json_encode(['ok' => true, 'werte' => new stdClass()]);
            break;
        }
        $w = [];
        foreach ($rows as $r) { $w[$r['schluessel']] = $r['wert']; }
        echo json_encode(['ok' => true, 'werte' => (object)$w]);
        break;

    case 'save_einstellungen':
        $d = json_decode(file_get_contents('php://input'), true);
        $werte = isset($d['werte']) && is_array($d['werte']) ? $d['werte'] : [];
        $pdo->exec("CREATE TABLE IF NOT EXISTS einstellungen (
            schluessel VARCHAR(64) NOT NULL PRIMARY KEY,
            wert       VARCHAR(64) NOT NULL DEFAULT '',
            geaendert  VARCHAR(30) NOT NULL DEFAULT ''
        ) DEFAULT CHARSET=utf8mb4");
        // Vollstaendig ersetzen: was die App nicht mehr schickt, ist auf den
        // Auslieferungswert zurueckgesetzt worden.
        $pdo->exec("DELETE FROM einstellungen");
        $stmt = $pdo->prepare("INSERT INTO einstellungen (schluessel, wert, geaendert) VALUES (?, ?, ?)");
        $jetzt = date('d.m.Y H:i');
        $n = 0;
        foreach ($werte as $k => $v) {
            if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', (string)$k)) continue;
            $stmt->execute([(string)$k, (string)$v, $jetzt]);
            $n++;
        }
        echo json_encode(['ok' => true, 'anzahl' => $n]);
        break;

    case 'get_abdichtung':
        $rows = $pdo->query("SELECT * FROM abdichtung ORDER BY klasse")->fetchAll();
        echo json_encode(['ok' => true, 'abdichtung' => $rows]);
        break;

    // ===== ABDICHTUNG SPEICHERN (Faktoren aktualisieren) =====
    case 'save_abdichtung':
        $d = json_decode(file_get_contents('php://input'), true);
        $stmt = $pdo->prepare("UPDATE abdichtung SET f_abdichtung=?, f_dichtband=?, f_innenecke=?, f_aussenecke=?, f_wandmanschette=?, f_bodenmanschette=?, verschnitt=? WHERE klasse=?");
        $stmt->execute([
            $d['f_abdichtung'] ?? 0, $d['f_dichtband'] ?? 0, $d['f_innenecke'] ?? 0,
            $d['f_aussenecke'] ?? 0, $d['f_wandmanschette'] ?? 0, $d['f_bodenmanschette'] ?? 0,
            $d['verschnitt'] ?? 0, $d['klasse']
        ]);
        echo json_encode(['ok' => true]);
        break;

    default:
        echo json_encode(['error' => 'Unbekannte Aktion']);
}
?>
