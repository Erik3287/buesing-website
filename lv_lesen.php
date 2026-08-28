<?php
// ============================================
// Buesing LV App - KI-Vermittler
// Liest hochgeladene PDFs (oder Text) aus.
// Zwei Modi:
//   modus = "lv"      -> LV-Positionen  (Feld "positionen")
//   modus = "angebot" -> Artikel/Preise (Feld "artikel")
//   modus = "bedarf"  -> nur Bedarfs-/Wahlpositionen (Feld "positionen")
// ============================================

// Mehr Zeit und Speicher fuer grosse PDFs erlauben
@set_time_limit(180);
@ini_set('memory_limit', '256M');
@ini_set('max_execution_time', '180');
@ini_set('post_max_size', '32M');
@ini_set('upload_max_filesize', '32M');

header('Content-Type: application/json; charset=utf-8');

// ===== FEHLER IMMER ALS JSON ZURUECKGEBEN =====
// Ohne das bricht PHP bei einem Fehler stumm ab und schickt eine LEERE
// Antwort. In der App stand dann "Server-Antwort unlesbar:" und dahinter
// nichts - der eigentliche Grund war nirgends zu sehen. Am 26.08.2026 hat
// uns das einen halben Tag gekostet.
set_exception_handler(function ($e) {
    echo json_encode(['ok' => false, 'error' => 'PHP: ' . $e->getMessage()
        . ' (' . basename($e->getFile()) . ' Zeile ' . $e->getLine() . ')']);
    exit;
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $hinweis = '';
        if (stripos($e['message'], 'memory') !== false) {
            $hinweis = ' – Der Arbeitsspeicher hat nicht gereicht. Weniger Seiten je Haeppchen helfen.';
        } elseif (stripos($e['message'], 'execution time') !== false || stripos($e['message'], 'timeout') !== false) {
            $hinweis = ' – Die Zeit hat nicht gereicht. Der Server bricht frueher ab, als die KI antwortet.';
        }
        echo json_encode(['ok' => false, 'error' => 'PHP bricht ab: ' . $e['message'] . $hinweis]);
    }
});

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

/* ===== ZUGRIFFSSCHUTZ =====
   Nur mit gueltiger Anmeldung nutzbar - entweder ueber das Buero-System
   (Buero-Sitzung) ODER ueber den LV-Login (LV-Sitzung aus api.php).
   Verhindert, dass Fremde diesen Endpunkt - und damit den API-Schluessel
   bzw. dein Guthaben - missbrauchen. Der Aufruf aus dem LV-Programm laeuft
   same-origin und schickt das Anmelde-Cookie automatisch mit. */
require_once __DIR__ . '/buero/auth.php';   // startet dieselbe Sitzung
if (!aktuellerNutzer() && empty($_SESSION['lv_user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Nicht angemeldet. Bitte ueber das Buero-Dashboard oder den LV-Login oeffnen.']);
    exit;
}

// ===== API-SCHLUESSEL =====
// Der Schluessel steht in config.php, NICHT hier:
//     define('ANTHROPIC_KEY', 'sk-ant-…');
// Denn diese Datei wird bei Aenderungen ersetzt - am 24.08.2026 hat genau das
// den Schluessel ueberschrieben. config.php dagegen bleibt liegen.
//
// WICHTIG: Es gibt auf dem Server ZWEI config.php - eine im Hauptordner und
// eine in buero/. Beide bringen getDB() mit. Die Anmeldung laedt die aus
// buero/; wird die zweite dazugeladen, bricht PHP ab
// ("Cannot redeclare function getDB()") - stumm, bei JEDER Anfrage.
// Deshalb wird config.php hier NICHT ausgefuehrt, sondern nur gelesen: der
// Schluessel wird als Text herausgesucht. Damit ist es egal, wie viele
// config.php es gibt und was darin sonst noch steht.
if (!defined('ANTHROPIC_KEY')) {
    foreach ([__DIR__ . '/config.php', __DIR__ . '/buero/config.php'] as $datei) {
        if (!is_readable($datei)) continue;
        $roh = @file_get_contents($datei);
        if ($roh && preg_match('/ANTHROPIC_KEY[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', $roh, $t)) {
            define('ANTHROPIC_KEY', $t[1]);
            break;
        }
    }
}
$API_KEY = defined('ANTHROPIC_KEY') ? ANTHROPIC_KEY : 'HIER_DEINEN_SCHLUESSEL_EINSETZEN';
// ================================================

// ===== SELBSTTEST =====
// Wird die Datei ohne Daten aufgerufen (also einfach im Browser geoeffnet),
// meldet sie, was der Server hergibt. Damit laesst sich pruefen, woran ein
// Abbruch liegt, ohne erst ein 100-Seiten-PDF hochzuladen.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'ok'              => true,
        'datei'           => 'lv_lesen.php – Fassung vom 26.08.2026',
        'php'             => PHP_VERSION,
        'schluessel'      => ($API_KEY === 'HIER_DEINEN_SCHLUESSEL_EINSETZEN' || $API_KEY === '')
                             ? 'FEHLT' : ('gefunden, beginnt mit ' . substr($API_KEY, 0, 7)),
        'quelle'          => defined('ANTHROPIC_KEY') ? 'config.php' : 'direkt in dieser Datei',
        'speicher'        => ini_get('memory_limit'),
        'max_laufzeit'    => ini_get('max_execution_time') . ' s',
        'max_upload'      => ini_get('post_max_size'),
        'curl'            => function_exists('curl_init') ? 'vorhanden' : 'FEHLT',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Pruefen ob Schluessel gesetzt wurde
if ($API_KEY === 'HIER_DEINEN_SCHLUESSEL_EINSETZEN' || $API_KEY === '') {
    echo json_encode(['ok' => false, 'error' =>
        'API-Schluessel fehlt. In config.php eintragen: '
        . "define('ANTHROPIC_KEY', 'sk-ant-…'); - dann bleibt er auch erhalten, "
        . 'wenn lv_lesen.php spaeter ersetzt wird.']);
    exit;
}

// Daten von der App empfangen
$input = json_decode(file_get_contents('php://input'), true);
$pdfBase64 = $input['pdf'] ?? '';
$textRoh   = $input['text'] ?? '';
$modus     = $input['modus'] ?? 'lv';   // Standard: lv (damit der bestehende LV-Upload unveraendert laeuft)

if ($pdfBase64 === '' && $textRoh === '') {
    echo json_encode(['ok' => false, 'error' => 'Kein PDF und kein Text empfangen.']);
    exit;
}

// Sehr langen Text begrenzen (Kostenschutz bei CSV/Excel)
if ($textRoh !== '' && strlen($textRoh) > 40000) {
    $textRoh = substr($textRoh, 0, 40000);
}

// ---- Anweisung je nach Modus ----
if ($modus === 'bedarf') {
    // NUR die Bedarfs-/Wahlpositionen aus einem LV holen - fuer den Nachtrag zu
    // einem schon eingelesenen LV. Kurze, klare Aufgabe: keine Langtexte, keine
    // Mengen, nur Positionsnummer und Ja/Nein. Erik am 28.08.2026: "Ich
    // benoetige eine verlaessliche Erkennung."
    $anweisung = 'Du bist ein Assistent fuer einen Fliesenleger-Betrieb. '
        . 'Im beigefuegten Leistungsverzeichnis (LV) sind manche Positionen als Bedarfsposition, '
        . 'Eventualposition, Wahlposition, Alternativposition oder mit dem Vermerk "nur EP" gekennzeichnet. '
        . 'Solche Vermerke stehen als eigene Zeile ueber oder unter der Position, oft in Sternchen '
        . '(z.B. "***Bedarfsposition - Nur EP -"), in Klammern, in Fettdruck, in einer eigenen Spalte '
        . 'oder als Zusatz hinter der Positionsnummer. Ein Vermerk gehoert immer zu der Position, bei der er steht. '
        . 'Gehe das LV Position fuer Position durch und gib fuer JEDE Position an, ob sie so gekennzeichnet ist. '
        . 'Gib das Ergebnis AUSSCHLIESSLICH als JSON-Array zurueck, ohne weiteren Text, ohne Markdown, ohne Backticks. '
        . 'Jede Position ist ein Objekt mit genau diesen Feldern: '
        . '"pos" (Positionsnummer als Text, genau wie im LV geschrieben, z.B. "15.03.26"), '
        . '"bedarf" (true oder false), '
        . '"vermerk" (der gefundene Vermerk woertlich, leerer String wenn keiner da ist). '
        . 'Erfinde keine Positionsnummern und lass keine aus. Im Zweifel false. '
        . 'Beispiel: [{"pos":"15.03.25","bedarf":false,"vermerk":""},'
        . '{"pos":"15.03.26","bedarf":true,"vermerk":"***Bedarfsposition - Nur EP -"}]';
    $ergebnisFeld = 'positionen';
} else if ($modus === 'angebot') {
    // Artikel/Preise aus einem Lieferanten-Angebot oder einer Rechnung herausziehen
    $anweisung = 'Du bist ein Assistent fuer einen Fliesenleger-Betrieb. '
        . 'Analysiere das beigefuegte Lieferanten-Angebot bzw. die Rechnung und extrahiere ALLE Artikel mit ihren Preisen. '
        . 'Gib das Ergebnis AUSSCHLIESSLICH als JSON-Array zurueck, ohne weiteren Text, ohne Markdown, ohne Backticks. '
        . 'Jeder Artikel ist ein Objekt mit genau diesen Feldern: '
        . '"name" (Artikelbezeichnung, moeglichst vollstaendig), '
        . '"kat" (genau EINER dieser Werte, waehle den passendsten: fliesen, kleber, fuge, grundierung, abdichtung, profile, emco, sonstiges), '
        . '"ek" (Einkaufspreis als Zahl, Punkt als Dezimaltrennzeichen, ohne Waehrungszeichen; 0 wenn kein Preis erkennbar), '
        . '"eu" (Einheit, z.B. "m2", "Sack", "Stk", "kg", "Eimer", "lfm"), '
        . '"format" (Format oder Groesse falls angegeben, z.B. "30x60" oder "25 kg"; sonst leerer String), '
        . '"lief" (Lieferant / Firmenname aus dem Dokument; leerer String wenn nicht erkennbar). '
        . 'Nimm nur echte Artikel mit Preisbezug auf, keine Ueberschriften, Summen, Versandkosten oder Rabattzeilen. '
        . 'Beispiel des erwarteten Formats: '
        . '[{"name":"Sakret Objektkleber Flex OK","kat":"kleber","ek":12.90,"eu":"Sack","format":"25 kg","lief":"Keramundo"}]';
    $ergebnisFeld = 'artikel';
} else {
    // LV-Positionen herausziehen (unveraendert gegenueber der bisherigen Version)
    // Wo eine Position AUFHOERT, stand hier frueher nicht drin - nur "lass nichts
    // weg". Ergebnis: in 15 von 40 Positionen des Test-LV hing der Text der
    // naechsten Position mit im Langtext, dazu Tabellenkoepfe und Preiszeilen.
    // Deshalb steht die Abgrenzung jetzt vor der Vollstaendigkeit.
    $anweisung = 'Du bist ein Assistent fuer einen Fliesenleger-Betrieb. '
        . 'Analysiere das beigefuegte Leistungsverzeichnis (LV) und extrahiere ALLE Positionen. '
        . 'WICHTIGSTE REGEL: Eine Position beginnt mit ihrer Positionsnummer und endet genau dort, '
        . 'wo die naechste Positionsnummer beginnt. Der Text einer Position darf NIEMALS Text einer '
        . 'anderen Position enthalten. Lieber einen Satz zu wenig als einen Satz aus der Nachbarposition. '
        . 'Innerhalb dieser Grenze gilt: gib den Text vollstaendig wieder, Wort fuer Wort, genau wie im LV - '
        . 'kuerze nichts und fasse nichts zusammen. '
        . 'Eine LV-Position hat meist einen kurzen Titel (Ueberschrift) UND einen ausfuehrlichen Beschreibungstext (Langtext) darunter. '
        . 'NICHT in den Langtext gehoeren: Tabellenkoepfe wie "POS. BESCHREIBUNG MENGE EINH. EP GP", '
        . 'Kopf- und Fusszeilen der Seite, Seitenzahlen, Trennlinien aus Strichen, '
        . 'die Mengen- und Preiszeile der Position selbst (z.B. "1 m2 - -" oder "23,5 m2 3,90 91,65") '
        . 'sowie Zwischensummen und Endsummen. Menge, Einheit und Preis gehoeren in ihre eigenen Felder. '
        . 'Wenn eine Position am Ende des Ausschnitts abbricht, gib nur den vorhandenen Teil wieder und erfinde nichts dazu. '
        . 'Gib das Ergebnis AUSSCHLIESSLICH als JSON-Array zurueck, ohne weiteren Text, ohne Markdown, ohne Backticks. '
        . 'Jede Position ist ein Objekt mit genau diesen Feldern: '
        . '"pos" (Positionsnummer als Text, z.B. "01.001"), '
        . '"beschreibung" (der kurze Titel / die Ueberschrift der Position), '
        . '"langtext" (der KOMPLETTE ausfuehrliche Beschreibungstext, Wort fuer Wort, mit allen Details, Massen, Normen, Materialangaben; leerer String wenn es keinen gibt), '
        . '"menge" (Zahl, nur der Wert ohne Einheit), '
        . '"einheit" (z.B. "m2", "m", "Stk", "psch"), '
        . '"bedarf" (true oder false). '
        . 'ZU "bedarf": true, wenn die Position eine Bedarfsposition, Eventualposition, Wahlposition, '
        . 'Alternativposition oder eine Position mit dem Vermerk "nur EP" ist. Solche Vermerke stehen '
        . 'im LV oft als eigene Zeile ueber oder unter der Position, in Sternchen eingefasst '
        . '(z.B. "***Bedarfsposition - Nur EP -"), in Klammern, in Fettdruck, in einer eigenen Spalte '
        . 'oder als Zusatz hinter der Positionsnummer. Sie gehoeren zu der Position, bei der sie stehen. '
        . 'Pruefe das bei JEDER Position ausdruecklich und uebernimm den Vermerk ZUSAETZLICH woertlich '
        . 'in den Langtext - er darf nicht verloren gehen. Im Zweifel false. '
        . 'MENGE UND EINHEIT SIND PFLICHT: uebernimm beide exakt so, wie sie im LV gedruckt sind '
        . '(auch m, lfdm, Stk, St, psch, h). Die Menge steht oft rechts neben oder unter der '
        . 'Positionsnummer, manchmal in einer eigenen Spalte. Verwechsle NIEMALS die Einheit: '
        . 'eine Zulage- oder Laibungsposition kann in m ausgeschrieben sein, auch wenn rundherum '
        . 'alles in m2 steht. Erfinde keine Menge. NUR wenn im LV wirklich keine Menge lesbar ist, '
        . 'nimm 0 - solche Positionen werden in der App rot markiert und von Hand geprueft. '
        . 'Wenn ein anderer Wert fehlt, nimm bei den Texten einen leeren String. '
        . 'Beispiel des erwarteten Formats: '
        . '[{"pos":"01.001","beschreibung":"Bodenfliesen 30x60 verlegen","langtext":"Liefern und Verlegen von Bodenfliesen im Format 30x60 cm, Farbe nach Wahl des AG, im Duennbettverfahren auf vorbereitetem Untergrund, inkl. Verfugung...","menge":45.5,"einheit":"m2","bedarf":false},'
        . '{"pos":"01.002","beschreibung":"Sockelfliesen","langtext":"***Bedarfsposition - Nur EP -  Sockelfliesen liefern und einbauen, Hoehe ca. 60 mm...","menge":97,"einheit":"m","bedarf":true}]';
    $ergebnisFeld = 'positionen';
}

// ---- Inhalt fuer die KI zusammenbauen: PDF (Dokument) oder Text ----
if ($pdfBase64 !== '') {
    $inhalt = [
        [
            'type' => 'document',
            'source' => [
                'type' => 'base64',
                'media_type' => 'application/pdf',
                'data' => $pdfBase64
            ]
        ],
        [
            'type' => 'text',
            'text' => $anweisung
        ]
    ];
} else {
    $inhalt = [
        [
            'type' => 'text',
            'text' => $anweisung . "\n\nHier der Inhalt:\n" . $textRoh
        ]
    ];
}

// Anfrage an die Claude API zusammenbauen
$payload = [
    'model' => 'claude-sonnet-4-6',
    'max_tokens' => 16000,
    'messages' => [
        [
            'role' => 'user',
            'content' => $inhalt
        ]
    ]
];

// Anfrage absenden
$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'x-api-key: ' . $API_KEY,
    'anthropic-version: 2023-06-01'
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 120);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError !== '') {
    echo json_encode(['ok' => false, 'error' => 'Verbindungsfehler: ' . $curlError]);
    exit;
}

$data = json_decode($response, true);

// Fehler von der API abfangen
if ($httpCode !== 200) {
    $msg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
    echo json_encode(['ok' => false, 'error' => 'KI-Fehler: ' . $msg]);
    exit;
}

// Antworttext der KI herausziehen
$text = '';
if (isset($data['content']) && is_array($data['content'])) {
    foreach ($data['content'] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= $block['text'];
        }
    }
}

// Falls die KI doch Backticks drumherum gesetzt hat, entfernen
$text = trim($text);
$text = preg_replace('/^```(json)?/', '', $text);
$text = preg_replace('/```$/', '', $text);
$text = trim($text);

// Versuchen, das JSON-Array zu pruefen
$ergebnis = json_decode($text, true);

// Falls das nicht klappt: das Array zwischen erstem [ und letztem ] herausschneiden
if (!is_array($ergebnis)) {
    $start = strpos($text, '[');
    $ende = strrpos($text, ']');
    if ($start !== false && $ende !== false && $ende > $start) {
        $ausschnitt = substr($text, $start, $ende - $start + 1);
        $ergebnis = json_decode($ausschnitt, true);
    }
}

if (!is_array($ergebnis)) {
    echo json_encode(['ok' => false, 'error' => 'Antwort konnte nicht gelesen werden.', 'roh' => substr($text, 0, 300)]);
    exit;
}

// Erfolg: Ergebnis unter dem passenden Feld zurueckgeben
echo json_encode(['ok' => true, $ergebnisFeld => $ergebnis]);
