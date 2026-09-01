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
        // poslen = Laenge des gespeicherten Positionen-Textes. "[]" sind zwei
        // Zeichen - daran erkennt das Archiv ein leeres LV, ohne die ganzen
        // Positionen mitzuschicken (Erik am 28.08.2026: "und wir haben wieder
        // ein leeres LV!!!" - so ist auf einen Blick zu sehen, ob wirklich
        // nichts drin steht).
        lv_felder_sichern($pdo);
        $rows = $pdo->query("SELECT id, titel, auftraggeber, status, summe, nutzer, created_at, updated_at,
                                    abgabe_termin, abgegeben_am, abgegeben_an, abgegeben_von, abgelegt,
                                    CHAR_LENGTH(positionen) AS poslen
                             FROM lvs ORDER BY (abgabe_termin = '') ASC, abgabe_termin ASC, updated_at DESC")->fetchAll();
        echo json_encode(['ok' => true, 'lvs' => $rows]);
        break;

    // ===== EINZELNES LV LADEN (mit Positionen) =====
    case 'get_lv':
        $id = intval($_GET['id'] ?? 0);
        lv_felder_sichern($pdo);
        $stmt = $pdo->prepare("SELECT * FROM lvs WHERE id = ?");
        $stmt->execute([$id]);
        $lv = $stmt->fetch();
        if ($lv) { $lv['positionen'] = json_decode($lv['positionen'], true); }
        echo json_encode(['ok' => true, 'lv' => $lv]);
        break;

    // ===== LV SPEICHERN (neu oder update) =====
    case 'save_lv':
        $data = json_decode(file_get_contents('php://input'), true);
        lv_felder_sichern($pdo);
        $id = intval($data['id'] ?? 0);
        $liste = $data['positionen'] ?? [];
        $pos = json_encode($liste);
        // Ein neues LV ohne Positionen ist immer ein Versehen. Erik am
        // 28.08.2026: "es gibt wieder ein leeres LV nach dem aktualisieren
        // der seite. ich dachte das passiert nicht mehr?" - der Riegel in der
        // App greift nur, wenn der Browser auch die neue App geladen hat.
        // Hier greift er immer, egal welche Fassung gerade offen ist.
        // Ein bestehendes LV (id > 0) darf leer werden - das waere ein
        // ausdrueckliches Loeschen aller Positionen.
        if ($id === 0 && (!is_array($liste) || count($liste) === 0)) {
            echo json_encode(['ok' => false, 'leer' => true,
                'error' => 'Ein neues LV ohne Positionen wird nicht angelegt.']);
            break;
        }
        if ($id > 0) {
            // Erst sichern, dann ueberschreiben. Nie umgekehrt.
            $sich = lv_sichern($pdo, $id, $liste);
            $stmt = $pdo->prepare("UPDATE lvs SET titel=?, auftraggeber=?, status=?, positionen=?, summe=?, nutzer=?,
                                                  abgabe_termin=?, abgegeben_am=?, abgegeben_an=?, abgegeben_von=?, abgelegt=? WHERE id=?");
            $stmt->execute([$data['titel'] ?? '', $data['auftraggeber'] ?? '', $data['status'] ?? 'bearbeitung', $pos, $data['summe'] ?? 0, $data['nutzer'] ?? '',
                            $data['abgabe_termin'] ?? '', $data['abgegeben_am'] ?? '', $data['abgegeben_an'] ?? '', $data['abgegeben_von'] ?? '', $data['abgelegt'] ?? '', $id]);
            $antwort = ['ok' => true, 'id' => $id];
            if ($sich && $sich['verlust']) {
                $antwort['verlust'] = true;
                $antwort['gruen_vorher'] = $sich['gruen_vorher'];
                $antwort['gruen_jetzt']  = $sich['gruen_jetzt'];
                $antwort['anzahl_vorher'] = $sich['anzahl_vorher'];
                $antwort['anzahl_jetzt']  = $sich['anzahl_jetzt'];
            }
            echo json_encode($antwort);
        } else {
            $stmt = $pdo->prepare("INSERT INTO lvs (titel, auftraggeber, status, positionen, summe, nutzer,
                                                    abgabe_termin, abgegeben_am, abgegeben_an, abgegeben_von, abgelegt)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$data['titel'] ?? '', $data['auftraggeber'] ?? '', $data['status'] ?? 'bearbeitung', $pos, $data['summe'] ?? 0, $data['nutzer'] ?? '',
                            $data['abgabe_termin'] ?? '', $data['abgegeben_am'] ?? '', $data['abgegeben_an'] ?? '', $data['abgegeben_von'] ?? '', $data['abgelegt'] ?? '']);
            echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId()]);
        }
        break;

    // ===== VERSANDVERMERK UND ABLAGE =====
    // Erik am 01.09.2026: "ich kann immer noch nicht eintragen, ob ein bereits
    // bearbeitetes LV von wem und wann versand wurde."
    // Bewusst ein EIGENER Endpunkt und nicht save_lv: aus dem Archiv heraus
    // sind die Positionen gar nicht geladen. Ein save_lv von dort haette sie
    // mit einer leeren Liste ueberschrieben. Hier werden ausschliesslich die
    // fuenf Vermerk-Felder angefasst, die Positionen nie.
    case 'lv_versand':
        $data = json_decode(file_get_contents('php://input'), true);
        lv_felder_sichern($pdo);
        $id = intval($data['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'Keine LV-Nummer']); break; }
        $st = $pdo->prepare("SELECT id FROM lvs WHERE id = ?");
        $st->execute([$id]);
        if (!$st->fetch()) { echo json_encode(['ok' => false, 'error' => 'LV nicht gefunden']); break; }
        $felder = []; $werte = [];
        foreach (['abgabe_termin','abgegeben_am','abgegeben_an','abgegeben_von','abgelegt','status'] as $f) {
            if (array_key_exists($f, $data)) { $felder[] = "`$f`=?"; $werte[] = (string)$data[$f]; }
        }
        if (!$felder) { echo json_encode(['ok' => false, 'error' => 'Nichts zu ändern']); break; }
        $werte[] = $id;
        $pdo->prepare("UPDATE lvs SET " . implode(', ', $felder) . " WHERE id = ?")->execute($werte);
        echo json_encode(['ok' => true, 'id' => $id]);
        break;

    // ===== PRODUKTDATENBLAETTER: LISTE =====
    case 'get_datenblaetter':
        datenblatt_tabelle($pdo);
        $rows = $pdo->query("SELECT id, titel, lieferant, datei, groesse, zuordnung, nutzer, created_at
                             FROM datenblaetter ORDER BY lieferant, titel")->fetchAll();
        foreach ($rows as &$r) {
            $z = json_decode($r['zuordnung'] ?? '[]', true);
            $r['zuordnung'] = is_array($z) ? $z : [];
        }
        echo json_encode(['ok' => true, 'blaetter' => $rows]);
        break;

    // ===== PRODUKTDATENBLATT: HOCHLADEN =====
    case 'datenblatt_upload':
        if (!datenblatt_ordner()) {
            echo json_encode(['ok' => false, 'error' =>
                'Der Ordner datenblaetter/ laesst sich nicht anlegen oder ist schreibgeschuetzt.']);
            break;
        }
        if (empty($_FILES['datei']) || $_FILES['datei']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['ok' => false, 'error' => 'Keine Datei angekommen (Code '
                . ($_FILES['datei']['error'] ?? '?') . ') – groesser als das Serverlimit?']);
            break;
        }
        $tmp  = $_FILES['datei']['tmp_name'];
        $roh  = $_FILES['datei']['name'];
        $size = (int)$_FILES['datei']['size'];
        if ($size > 25 * 1024 * 1024) {
            echo json_encode(['ok' => false, 'error' => 'Die Datei ist groesser als 25 MB.']);
            break;
        }
        // Wirklich ein PDF? Der Dateianfang entscheidet, nicht die Endung.
        $kopf = @file_get_contents($tmp, false, null, 0, 5);
        if ($kopf !== '%PDF-') {
            echo json_encode(['ok' => false, 'error' => 'Das ist keine PDF-Datei.']);
            break;
        }
        datenblatt_tabelle($pdo);
        $basis = datenblatt_dateiname($roh);
        $datei = $basis . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.pdf';
        if (!@move_uploaded_file($tmp, db_ordner() . '/' . $datei)) {
            echo json_encode(['ok' => false, 'error' => 'Die Datei konnte nicht gespeichert werden.']);
            break;
        }
        $titel = trim($_POST['titel'] ?? '') !== '' ? trim($_POST['titel']) : pathinfo($roh, PATHINFO_FILENAME);
        $stmt = $pdo->prepare("INSERT INTO datenblaetter (titel, lieferant, datei, groesse, zuordnung, nutzer)
                               VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$titel, trim($_POST['lieferant'] ?? ''), $datei, $size,
                        $_POST['zuordnung'] ?? '[]', trim($_POST['nutzer'] ?? '')]);
        echo json_encode(['ok' => true, 'id' => $pdo->lastInsertId(), 'datei' => $datei, 'titel' => $titel]);
        break;

    // ===== PRODUKTDATENBLATT: ANZEIGEN =====
    case 'datenblatt':
        $id = intval($_GET['id'] ?? 0);
        datenblatt_tabelle($pdo);
        $st = $pdo->prepare("SELECT titel, datei FROM datenblaetter WHERE id = ?");
        $st->execute([$id]);
        $b = $st->fetch();
        $pfad = $b ? db_ordner() . '/' . basename($b['datei']) : '';
        if (!$b || !is_file($pfad)) {
            header('Content-Type: text/plain; charset=utf-8');
            http_response_code(404);
            echo 'Datenblatt nicht gefunden.';
            exit;
        }
        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($pfad));
        header('Content-Disposition: inline; filename="' . basename($b['datei']) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($pfad);
        exit;

    // ===== PRODUKTDATENBLATT: ZUORDNUNG UND TITEL AENDERN =====
    case 'datenblatt_zuordnen':
        $d = json_decode(file_get_contents('php://input'), true);
        $id = intval($d['id'] ?? 0);
        datenblatt_tabelle($pdo);
        $felder = []; $werte = [];
        if (isset($d['zuordnung'])) { $felder[] = 'zuordnung=?'; $werte[] = json_encode(array_values((array)$d['zuordnung'])); }
        if (isset($d['titel']))     { $felder[] = 'titel=?';     $werte[] = (string)$d['titel']; }
        if (isset($d['lieferant'])) { $felder[] = 'lieferant=?'; $werte[] = (string)$d['lieferant']; }
        if (!$felder || !$id) { echo json_encode(['ok' => false, 'error' => 'Nichts zu aendern.']); break; }
        $werte[] = $id;
        $stmt = $pdo->prepare("UPDATE datenblaetter SET " . implode(', ', $felder) . " WHERE id=?");
        $stmt->execute($werte);
        echo json_encode(['ok' => true]);
        break;

    // ===== PRODUKTDATENBLATT: LOESCHEN =====
    case 'delete_datenblatt':
        $id = intval($_GET['id'] ?? 0);
        datenblatt_tabelle($pdo);
        $st = $pdo->prepare("SELECT datei FROM datenblaetter WHERE id = ?");
        $st->execute([$id]);
        $b = $st->fetch();
        if ($b) {
            $pfad = db_ordner() . '/' . basename($b['datei']);
            if (is_file($pfad)) @unlink($pfad);
            $pdo->prepare("DELETE FROM datenblaetter WHERE id = ?")->execute([$id]);
        }
        echo json_encode(['ok' => true]);
        break;

    // ===== VERSIONSGESCHICHTE EINES LV =====
    case 'get_verlauf':
        $id = intval($_GET['id'] ?? 0);
        lv_verlauf_tabelle($pdo);
        $st = $pdo->prepare("SELECT id, lv_id, titel, summe, anzahl, gruen, grund, nutzer, created_at
                             FROM lv_verlauf WHERE lv_id = ? ORDER BY id DESC LIMIT 60");
        $st->execute([$id]);
        echo json_encode(['ok' => true, 'verlauf' => $st->fetchAll()]);
        break;

    // ===== EINE ALTE FASSUNG HOLEN (mit Positionen) =====
    case 'get_version':
        $vid = intval($_GET['vid'] ?? 0);
        lv_verlauf_tabelle($pdo);
        $st = $pdo->prepare("SELECT * FROM lv_verlauf WHERE id = ?");
        $st->execute([$vid]);
        $v = $st->fetch();
        if ($v) { $v['positionen'] = json_decode($v['positionen'], true); }
        echo json_encode(['ok' => (bool)$v, 'version' => $v]);
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


// ============================================================
// VERSIONSGESCHICHTE DER LVs
// Erik am 30.08.2026: "aber wie kann das passieren? Das muss doch
// zuverlaessig gespeichert werden!!!" - gespeichert WURDE zuverlaessig.
// Die App hatte sein fertiges LV beim Oeffnen selbst wieder aufgerissen
// und diesen Zustand gespeichert; eine aeltere Fassung gab es nirgends.
// Ab jetzt legt jedes Speichern, das etwas Wesentliches veraendert, den
// VORHERIGEN Stand in lv_verlauf ab. Nichts geht mehr unwiederbringlich
// verloren, egal welcher Fehler noch kommt.
// ============================================================
// ============================================================
// ABGABETERMIN UND ABGABE-VERMERK
// Erik am 01.09.2026: "nach dem Upload MUSS das Abgabedatum mit Uhrzeit
// eingetragen werden. Wenn das nicht geschieht, erscheint ein roter Hinweis
// im Archiv. Des Weiteren muss man auch markieren koennen, wann das LV
// abgegeben wurde, an wen geschickt und von wem geschickt."
// Vier eigene Spalten statt eines Eintrags in den Positionen: das Archiv
// liest die Positionen nicht mit (nur ihre Laenge), sonst waere der Termin
// in der Liste unsichtbar. Wird bei jedem Zugriff auf lvs nachgezogen, damit
// es keinen gesonderten Wartungsschritt braucht.
function lv_felder_sichern($pdo) {
    static $erledigt = false;
    if ($erledigt) return;
    $erledigt = true;
    $neu = [
        'abgabe_termin' => "VARCHAR(20)  NOT NULL DEFAULT ''",  // 2026-09-15T11:00
        'abgegeben_am'  => "VARCHAR(20)  NOT NULL DEFAULT ''",
        'abgegeben_an'  => "VARCHAR(190) NOT NULL DEFAULT ''",
        'abgegeben_von' => "VARCHAR(100) NOT NULL DEFAULT ''",
        // Erik am 01.09.2026: "wenn das erfolgt ist (die Markierung) soll das
        // LV anschliessend in einen Unterordner abgelegt und WIRKLICH
        // archiviert werden." Traegt das Datum der Ablage; leer = in Arbeit.
        'abgelegt'      => "VARCHAR(20)  NOT NULL DEFAULT ''",
    ];
    try {
        $da = [];
        foreach ($pdo->query("SHOW COLUMNS FROM `lvs`")->fetchAll(PDO::FETCH_ASSOC) as $c) $da[] = $c['Field'];
        foreach ($neu as $name => $typ) {
            if (in_array($name, $da)) continue;
            $pdo->exec("ALTER TABLE `lvs` ADD COLUMN `$name` $typ");
        }
    } catch (Exception $e) { /* Archiv laeuft auch ohne - nur ohne Termin */ }
}
function lv_verlauf_tabelle($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS lv_verlauf (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lv_id INT NOT NULL,
        titel VARCHAR(255) DEFAULT '',
        positionen LONGTEXT,
        summe DECIMAL(12,2) DEFAULT 0,
        anzahl INT DEFAULT 0,
        gruen INT DEFAULT 0,
        grund VARCHAR(32) DEFAULT '',
        nutzer VARCHAR(100) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (lv_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
// Zaehlt Positionen und fertige (gruene) Positionen einer Liste.
function lv_zaehl($liste) {
    $anzahl = 0; $gruen = 0;
    if (is_array($liste)) {
        foreach ($liste as $p) {
            if (!is_array($p)) continue;
            if (($p['typ'] ?? '') === 'ueberschrift') continue;
            $anzahl++;
            if (($p['status'] ?? '') === 'gruen') $gruen++;
        }
    }
    return ['anzahl' => $anzahl, 'gruen' => $gruen];
}
// Sichert den BISHER gespeicherten Stand, bevor er ueberschrieben wird.
// Gibt zurueck, ob dabei Fertiges verloren geht - die App warnt dann.
function lv_sichern($pdo, $id, $neueListe) {
    try {
        lv_verlauf_tabelle($pdo);
        $st = $pdo->prepare("SELECT titel, positionen, summe, nutzer FROM lvs WHERE id = ?");
        $st->execute([$id]);
        $alt = $st->fetch();
        if (!$alt) return null;
        $altListe = json_decode($alt['positionen'] ?? '[]', true);
        if (!is_array($altListe) || !count($altListe)) return null;
        $a = lv_zaehl($altListe);
        $n = lv_zaehl($neueListe);
        // Verlust heisst: weniger fertige oder weniger Positionen als vorher.
        $verlust = ($n['gruen'] < $a['gruen']) || ($n['anzahl'] < $a['anzahl']);
        $letzte = $pdo->prepare("SELECT id, created_at FROM lv_verlauf WHERE lv_id = ? ORDER BY id DESC LIMIT 1");
        $letzte->execute([$id]);
        $l = $letzte->fetch();
        $grund = '';
        if (!$l)                                                   $grund = 'erststand';
        elseif ($verlust)                                          $grund = 'rueckgang';
        elseif (strtotime($l['created_at']) < time() - 600)        $grund = 'zwischenstand';
        if ($grund !== '') {
            $ins = $pdo->prepare("INSERT INTO lv_verlauf
                (lv_id, titel, positionen, summe, anzahl, gruen, grund, nutzer)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $ins->execute([$id, $alt['titel'] ?? '', $alt['positionen'], $alt['summe'] ?? 0,
                           $a['anzahl'], $a['gruen'], $grund, $alt['nutzer'] ?? '']);
            // Alte Zwischenstaende aufraeumen - die 40 juengsten bleiben,
            // Rueckgaenge werden NIE geloescht.
            $alt40 = $pdo->prepare("SELECT id FROM lv_verlauf WHERE lv_id = ? ORDER BY id DESC LIMIT 1 OFFSET 40");
            $alt40->execute([$id]);
            $grenze = $alt40->fetchColumn();
            if ($grenze) {
                $del = $pdo->prepare("DELETE FROM lv_verlauf WHERE lv_id = ? AND id <= ? AND grund <> 'rueckgang'");
                $del->execute([$id, $grenze]);
            }
        }
        return ['verlust' => $verlust, 'gruen_vorher' => $a['gruen'], 'gruen_jetzt' => $n['gruen'],
                'anzahl_vorher' => $a['anzahl'], 'anzahl_jetzt' => $n['anzahl'], 'gesichert' => $grund !== ''];
    } catch (Exception $e) {
        return null;   // Eine Sicherung darf das Speichern nie blockieren
    }
}


// ============================================================
// PRODUKTDATENBLAETTER
// Erik am 30.08.2026: "koennen wir das PDF von Rinklake in der
// Preisdatenbank integrieren? Evtl. als eigenen Reiter mit eigenem
// Drag&drop feld ... Hier koennte man dann auch gleich die
// Produktdatenblaetter der Abdichtungsartikel erfassen."
// Die kleinen Zeichnungen in der Preisliste sind nur 190 Pixel breit -
// fuer Masse und Materialangaben reicht das nicht.
//
// Die PDFs liegen als DATEI auf dem Webspace, nicht in der Datenbank:
// ein Preisblatt kann 15 MB haben, das sprengt jede DB-Antwort. In der
// Datenbank steht nur, wie die Datei heisst und zu welchen Artikeln sie
// gehoert.
// ============================================================
// Der Pfad steckt in einer FUNKTION, nicht in einer Konstanten. Eine Konstante
// entsteht erst, wenn ihre Zeile ausgefuehrt wird - und diese Zeilen stehen am
// Dateiende, also NACH dem switch. Beim Hochladen kam deshalb
// "Undefined constant" (Erik am 30.08.2026). Funktionen kennt PHP dagegen von
// Anfang an, egal an welcher Stelle der Datei sie stehen.
function db_ordner() { return __DIR__ . '/datenblaetter'; }
function datenblatt_ordner() {
    $o = db_ordner();
    if (!is_dir($o)) { @mkdir($o, 0755, true); }
    // Nichts in diesem Ordner darf ausgefuehrt werden.
    $ht = $o . '/.htaccess';
    if (is_dir($o) && !file_exists($ht)) {
        @file_put_contents($ht, "php_flag engine off\nOptions -Indexes\n");
    }
    return is_dir($o) && is_writable($o);
}
function datenblatt_tabelle($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS datenblaetter (
        id INT AUTO_INCREMENT PRIMARY KEY,
        titel VARCHAR(255) DEFAULT '',
        lieferant VARCHAR(120) DEFAULT '',
        datei VARCHAR(255) NOT NULL,
        groesse INT DEFAULT 0,
        zuordnung TEXT,
        nutzer VARCHAR(100) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
// Aus "Rinklake Preisliste 2026.pdf" wird "Rinklake-Preisliste-2026.pdf"
function datenblatt_dateiname($roh) {
    $name = pathinfo($roh, PATHINFO_FILENAME);
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
    $name = trim($name, '-');
    if ($name === '') $name = 'datenblatt';
    return substr($name, 0, 80);
}


?>
