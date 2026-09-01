# Werkzeug – Prüfstand für die LV-App

Läuft mit Node, ohne Browser. Vom Ordner `werkzeug/` aus starten.

## Kontrollblatt – „bleiben meine fertigen LVs unberührt?"

Erik am 01.09.2026: *„ich habe angst, dass wir durch neue Anpassungen den
Stand, den wir beim letzten LV hatten, kaputt machen."*

Das Kontrollblatt nimmt seine echten, fertig gerechneten LVs, lässt das
**aktuelle** Regelwerk jede Position noch einmal rechnen und meldet jede
Abweichung zum gespeicherten Preis. Es ändert nichts – es misst.

```
node run.js kontrollblatt.js
```

Es erwartet die LV-Exporte („💾 Sichern" im LV) als Dateien in diesem
Ordner. Welche, steht oben in `kontrollblatt.js` in der Liste `AKTEN`.
Die Dateien liegen bewusst **nicht** im Projekt – es sind echte
Projektdaten mit Auftraggebern und Adressen.

Wichtig beim Lesen: Die App rechnet gespeicherte LVs **nicht** neu. Eine
Position mit Preis bleibt unangetastet, ein von Hand eingetragener Preis
erst recht. Das Blatt zeigt, was passieren *würde*, wenn man sie neu
rechnen ließe – also wie weit sich das Regelwerk vom damaligen Stand
entfernt hat.

## Einzeltests

```
node run.js t291.js
```

`run.js` lädt alle `<script>`-Blöcke aus `../Buesing_LV_App_v2.html` und
führt danach die Testdatei im selben Gültigkeitsbereich aus. Ein DOM-Ersatz
steht bereit; jede `id` bekommt ihr eigenes Element.
