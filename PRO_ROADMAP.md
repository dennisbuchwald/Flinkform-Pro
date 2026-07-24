# Flinkform Pro — Entwicklungsfahrplan

> Stand: 2026-07 · Quelle: Audit (Security, Architektur, UX, Pro-Features) durch Claude.
> Leitprinzip: **alles nativ** (kein externer Dienst, kein SDK außer optional gebündelter
> PDF-Lib), **DACH-Fokus** (SEPA, giropay, DSGVO, deutsche Zeichensätze).

## Status (1.2.0, Juli 2026)

- ✅ **M0** Release-Blocker (Payment-Bindung, Idempotenz, Webhook-Pruning, CSV-Injection)
- ✅ **M1** Payment Element sync (Apple/Google Pay, Link, Stripe-Quittung)
- ✅ **M2** Payment-Status-Modell (payments-Tabelle, Replay-Schutz, Admin-Anzeige)
- ✅ **M3** SEPA (processing-Status) + Stripe-Webhook-Endpoint (hash_hmac-Signatur)
- ✅ **M4** Multi-File-Upload (multiple/maxFiles, Client-Checks, Rollback, Download-Links)
- ✅ **M5** Berechnungsfeld (gespiegelter Evaluator PHP/JS, Insert-Field-Dropdown)
- ✅ **M7 (Teil)** Payment-Spalten im CSV-Export; Datumsbereich existierte bereits
- ⬜ **M5b** Payment-Betrag aus Berechnungsfeld (priceMode 'calculation' — Intent-Betrag
     serverseitig aus Formel + geposteten Werten ableiten)
- ⬜ **M3b** Redirect-Zahlarten (allow_redirects: always + Return-URL-Handling)
- ⬜ **M6** PDF-Quittung (Lib-Entscheidung: mPDF empfohlen)
- ⬜ **M7 (Rest)** Entry-Status/Notizen (Free-Core-Schema), Unread-Badge, Detail-Navigation
- ⬜ Mail-Log-Resend: NICHT umsetzbar ohne Body-Speicherung (GDPR-lean by design) —
     bewusst gestrichen

## Leitgedanke

Mehrere Features teilen sich **ein Fundament: ein sauberes Submission-Status-Modell**
(`pending / processing / paid / failed / …`). Das brauchen Payments-Stufe-B (SEPA settlet
später), die Doppel-Submission-Idempotenz und die Entry-Management-Tiefe gleichermaßen.
Einmal richtig gebaut, zahlen drei Features darauf ein → steht früh im Plan (M2).

---

## M0 — Release-Blocker (Pflicht vor Launch)

Alles nativ, kein neuer Dienst.

| Punkt | Was | Dateien | Aufwand |
|---|---|---|---|
| Payment-Betrag serverseitig binden | Betrag + Währung aus Formulardefinition ableiten statt aus Request-Body; unbekanntes Produkt = Fehler statt Skip | `Payments/RestController.php`, `Payments/Module.php` | S |
| Doppel-Submission-Idempotenz | Kurzlebiger Idempotenz-Key (Hash aus form_id + ts-Token); verarbeiteter Token → Success ohne zweiten Insert | `flinkform/includes/Submissions/Handler.php` | M |
| Webhook-Log-Pruning | `prune_old_deliveries($days)` analog `MailLog::purge_expired()`, täglicher Cron, Default 90 Tage | `Webhooks/DeliveryRepository.php`, `Webhooks/Module.php` | S |
| CSV-Injection neutralisieren | Zellen mit führendem `= + - @ \t \r` mit `'` prefixen | `Export/CsvExporter.php` | XS |

Der Payment-Fix und M1 sind dieselbe Codestelle → in einem Rutsch.

---

## M1 — Payments Stufe A: Payment Element (sync)

**Ziel:** Card Element raus, Payment Element rein. Sofort dazu: **Apple Pay, Google Pay,
Stripe Link** — ohne Seitenwechsel.

**Nativ?** Ja. Payment Element ist Teil der bereits geladenen Stripe.js — keine neue
JS-Abhängigkeit. Serverseitig nur ein zusätzlicher Body-Parameter
(`automatic_payment_methods[enabled]=true`), `wp_remote_*`-Wrapper bleibt.

**Umbau:**
- `view.js`: `confirmCardPayment(...)` → `stripe.confirmPayment({ elements, redirect: 'if_required' })`.
- `create-intent`: `automatic_payment_methods` setzen.
- Fehlerstrings i18n-fähig + `role="alert"` auf Error-Container (UX-Finding M9).
- `receipt_email` am Intent → Stripe-Quittung gratis.
- Setup-Doku: Apple Pay braucht einmalige Stripe-Domain-Verifizierung (Association-File).

**Fallstrick:** Zahlarten müssen zusätzlich im Stripe-Dashboard des Kunden aktiv sein → Doku.

---

## M2 — Submission-Status-Modell (Fundament)

**Ziel:** Geteilter Unterbau für Payments-B, Idempotenz und Entry-Management.

**Umbau:**
- Schema: strukturierte Payment-Felder (`payment_status`, `payment_amount`,
  `payment_currency`, `payment_intent_id`) statt roher `pi_`-ID im Feldwert.
- Migration über bestehendes `dbDelta`/Versionsschema.
- Repository-Methoden für Statuswechsel.
- Sichtbar danach: Betrag/Status in der Submission-Detailansicht.

---

## M3 — Payments Stufe B: SEPA, giropay & Redirect-Zahlarten

**Ziel:** Die Zahlarten, die den DACH-Markt tragen. Baut auf M2 auf.

**Nativ?** Ja. Stripe-Webhook als eigener REST-Endpoint (`register_rest_route`) mit
Signaturprüfung per `hash_hmac` — kein SDK.

**Umbau:**
- **Pending-Muster:** Submission vor Zahlung als `pending` persistieren (nutzt M2),
  `return_url` auf Flinkform-Route, nach Rückkehr per `payment_intent`-Param finalisieren.
- **Stripe-Webhook-Endpoint:** bestätigt SEPA, wenn es Tage später settlet.
- Server-Prüfung von „muss `succeeded`" auf statusbewusst umstellen (`processing` bei SEPA gültig).

---

## M4 — Multi-File-Upload

**Ziel:** Bewerbungsformular-Fall komplett (Anschreiben + Lebenslauf + Zeugnisse).

**Nativ?** Ja, kein neuer Uploader. `Uploader` validiert bereits pro Datei sauber.

**Umbau:**
- `block.json` field-file: `multiple`-Attribut + Max-Anzahl.
- `Uploader`: Schleife über `$_FILES`-Array, Werte-Array statt String.
- Frontend `view.js`: Dropzone-Mehrfachauswahl, mehrere File-Cards, client-seitiger
  Größen-Check vor Upload (UX-Finding M10).
- Mail-Anhang + Admin-Ansicht + Lösch-Kaskade auf mehrere Dateien (8-MB-Mail-Budget).

**Fallstrick:** `.htaccess`-Schutz greift auf Nginx nicht → Upload-Route-Schutz prüfen.

---

## M5 — Berechnungsfelder

**Ziel:** Angebots-/Konfigurator-Rechner. Passt zur No-External-Services-Marke.

**Nativ?** Ja. RuleEvaluator (JS/PHP 1:1 gespiegelt) ist das Vorbild.

**Umbau:**
- Neuer Feld-Block `field-calculation` (readonly Anzeige + Hidden-Value).
- **Sicherer Formel-Evaluator** in JS (Live-Vorschau) UND PHP (serverseitige
  Neuberechnung) — Shunting-Yard/AST, **kein `eval`**.
- Formel-Editor im Inspector: Feld-Referenzen einfügbar (löst UX-Finding M4).
- Bindung ans Payment-Feld: berechnete Summe als dynamischer Betrag.

---

## M6 — PDF-Eingangsbestätigung / Quittung

**Ziel:** PDF an die Bestätigungsmail. Compliance-Verkaufsargument.

**Nativ?** Kein externer Dienst, aber braucht eine **gebündelte PHP-Lib** (mPDF empfohlen
wegen Umlauten). Einzige Stelle im Plan mit Vendor-Abhängigkeit.

**Umbau:**
- Vendor-Lib bündeln (Autoloader-Anbindung).
- HTML-Template (Submission-Werte + optional Zahlungsdaten) → PDF.
- Anhang an Bestätigungsmail (nutzt Mailer), optional Download im Admin.

---

## M7 — Entry-Management-Tiefe

**Ziel:** „Agentur verwaltet Kundenformulare". Sichert Renewals. Baut teils auf M2 auf.

**Umbau (portionierbar):**
- Status pro Submission (neu / in Bearbeitung / erledigt) — nutzt M2-Statusfeld.
- Interne Notiz je Submission.
- Resend-Button im Mail-Log (SMTP-Modul).
- CSV-Export: Spaltenauswahl + Datumsbereich.
- Vor/Zurück-Navigation in Detailansicht (UX-Finding C3).
- Unread-Badge am Admin-Menü (UX-Finding C2).
- Optional: Papierkorb statt Hard-Delete (UX-Finding C4).

---

## Backlog (nach den sieben Meilensteinen)

- **HMAC-Signatur für Webhook-Payloads** — macht Webhooks enterprise-tauglich.
- **Newsletter-Mapping-Tiefe** (Custom Fields, Tags, Gruppen); MailerLite als Provider.
- **Pro-Sichtbarkeit im Free-Plugin** (ausgegraute „(Pro)"-Panels, Filter-Seam existiert
  schon) — billigster Umsatzhebel; vorziehen, sobald Pro kaufbar ist.
- **Field-Block-Duplikation refactoren** (Audit M4/M5) — macht neue Felder billiger.
- **`maxLength`-Validierung** (Audit L3).
- **Adressfeld mit Autocomplete** — Composite-Block (Straße, PLZ, Ort, Land) mit
  optionaler PLZ→Ort-Auflösung (offline, DE-Datenbank) und optionalem Google Places
  Autocomplete (API-Key-Management, DSGVO-Hinweis). Statische Variante ggf. Free Core,
  Autocomplete definitiv Pro.
- Bewusst ignorieren: Google Sheets (Marken-Widerspruch), Subscriptions (Nische),
  Conversational Forms.

---

## Empfohlene Reihenfolge

```
M0  Release-Blocker           ─┐
M1  Payment Element (sync)     ├─ ein Paket, gleiche Codestelle  → LAUNCH-fähig
M2  Status-Modell (Fundament) ─┘

M4  Multi-File-Upload            → schnellster sichtbarer Feature-Gewinn
M5  Berechnungsfelder            → ermöglicht dynamische Payment-Summen
M3  Payments Stufe B (SEPA)      → braucht M2, größtes Einzelstück
M6  PDF-Quittung                 → Compliance-Verkaufsargument
M7  Entry-Management             → Renewal-Sicherung, häppchenweise
```

**Separat (nicht in diesem Plan):** Freemius-Lizenz-/Update-Mechanik — kommerzieller
Release-Blocker, wird eigenständig eingebaut.
