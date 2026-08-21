<?php
// ============================================
// Buesing LV App - KI-Vermittler
// Liest hochgeladene PDFs (oder Text) aus.
// Zwei Modi:
//   modus = "lv"      -> LV-Positionen  (Feld "positionen")
//   modus = "angebot" -> Artikel/Preise (Feld "artikel")
// ============================================

// Mehr Zeit und Speicher fuer grosse PDFs erlauben
@set_time_limit(180);
@ini_set('memory_limit', '256M');
@ini_set('max_execution_time', '180');
@ini_set('post_max_size', '32M');
@ini_set('upload_max_filesize', '32M');

// ===== HIER DEINEN API-SCHLUESSEL EINSETZEN =====
// Ersetze die Zeile unten: zwischen die Anfuehrungszeichen
// kommt dein Schluessel aus Bitdefender SecurePass.
// Er beginnt mit sk-ant-
$API_KEY = 'HIER_DEINEN_SCHLUESSEL_EINSETZEN';
// ================================================

header('Content-Type: application/json; charset=utf-8');

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

// Pruefen ob Schluessel gesetzt wurde
if ($API_KEY === 'HIER_DEINEN_SCHLUESSEL_EINSETZEN' || $API_KEY === '') {
    echo json_encode(['ok' => false, 'error' => 'API-Schluessel wurde noch nicht eingesetzt.']);
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
if ($modus === 'angebot') {
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
    $anweisung = 'Du bist ein Assistent fuer einen Fliesenleger-Betrieb. '
        . 'Analysiere das beigefuegte Leistungsverzeichnis (LV) und extrahiere ALLE Positionen. '
        . 'WICHTIG: Gib zu jeder Position den KOMPLETTEN Text wieder, Wort fuer Wort, genau wie im LV. '
        . 'Eine LV-Position hat meist einen kurzen Titel (Ueberschrift) UND einen ausfuehrlichen Beschreibungstext (Langtext) darunter. '
        . 'Lass NICHTS weg, kuerze NICHTS, fasse NICHTS zusammen. Uebernimm den vollstaendigen Wortlaut. '
        . 'Gib das Ergebnis AUSSCHLIESSLICH als JSON-Array zurueck, ohne weiteren Text, ohne Markdown, ohne Backticks. '
        . 'Jede Position ist ein Objekt mit genau diesen Feldern: '
        . '"pos" (Positionsnummer als Text, z.B. "01.001"), '
        . '"beschreibung" (der kurze Titel / die Ueberschrift der Position), '
        . '"langtext" (der KOMPLETTE ausfuehrliche Beschreibungstext, Wort fuer Wort, mit allen Details, Massen, Normen, Materialangaben; leerer String wenn es keinen gibt), '
        . '"menge" (Zahl, nur der Wert ohne Einheit), '
        . '"einheit" (z.B. "m2", "m", "Stk", "psch"). '
        . 'Wenn ein Wert fehlt, nimm bei menge 0 und bei den Texten einen leeren String. '
        . 'Beispiel des erwarteten Formats: '
        . '[{"pos":"01.001","beschreibung":"Bodenfliesen 30x60 verlegen","langtext":"Liefern und Verlegen von Bodenfliesen im Format 30x60 cm, Farbe nach Wahl des AG, im Duennbettverfahren auf vorbereitetem Untergrund, inkl. Verfugung...","menge":45.5,"einheit":"m2"}]';
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
