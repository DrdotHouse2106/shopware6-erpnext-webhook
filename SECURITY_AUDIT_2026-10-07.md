# Sicherheitsaudit 2026-10-07 (`main` @ 6587180, Plugin v1.0.1)

Issues sind in diesem Fork deaktiviert, daher liegen die Befunde hier als Datei.

**Nachtrag:** Commit `4967d0e` (v1.1.0, gleicher Tag) behebt H1, M2, N1 und Teile von M1/N7. Die
abgehakten Punkte unten sind damit erledigt; die Zeilennummern beziehen sich auf den Stand vor diesem Commit.

## Hoch

### H1 – Webhook-Versand läuft synchron im Checkout-Request (Verfügbarkeit)
**Status:** bestätigt
**Stellen:** `src/Service/WebhookService.php:89-135`; Aufrufer `src/Subscriber/OrderWebhookSubscriber.php:310, 321`
(im `order.written`-Event) und `TransactionWebhookSubscriber.php:416` (Payment-Callback).

Keine Message Queue. Mit Defaults (`retryCount=3`, `retryDelay=1000`, Timeout 30 s hart) blockiert ein
hängendes ERPNext den Kunden-Request bis zu 4×30 s + 7 s Backoff ≈ 127 s, weit über FPM-Timeouts, nachdem die
Bestellung geschrieben wurde. `retryCount`/`retryDelay` sind ungeclampt (`config.xml:119-133`); das
Config-Feld `timeout` (`config.xml:137`) wird nie gelesen. Exceptions werden korrekt gefangen, das Problem ist
ausschließlich die Blockierzeit.

- [x] Versand über Symfony Messenger (`AsyncMessageInterface`), Retry über Messenger-Retry-Strategie statt `usleep`
- [x] Falls synchron bleiben muss: nur 1 Versuch, `timeout` 3–5 s, `max_duration`, Retries in Scheduled Task
- [x] `retryCount`/`retryDelay`/`timeout` clampen und das `timeout`-Feld tatsächlich lesen

## Mittel

### M1 – Ziel-URL ohne Validierung (SSRF durch Shop-Admin), Redirects werden gefolgt
**Status:** Mechanik bestätigt, Impact plausibel
**Stellen:** `config.xml:31-40` (`type="url"` nur UI-Hinweis), `WebhookService.php:48, 54-57, 91, 108-113`.
Kein https-Zwang, keine Sperre privater Hosts (`127.0.0.1`, `10/8`, `169.254.169.254`), bis zu 20 Redirects
mit `X-Shopware-Signature`-Header, Response-Body interner Dienste landet im Log.
- [x] Nur `https` (http nur für localhost) – erledigt in 4967d0e
- [ ] Host per DNS auflösen und private/loopback/link-local verwerfen (inkl. IPv6) – noch offen
- [ ] `'max_redirects' => 0`
- [x] Response-Body im Log auf 500 Zeichen kürzen

### M2 – Event-Schalter in der Admin-Config sind wirkungslos
**Status:** bestätigt
**Stelle:** `WebhookService.php:147-161` liest `enabledEvents`; `config.xml:55-100` definiert aber
`eventOrderPlaced`, `eventOrderUpdated`, `eventOrderStateChanged`, `eventTransactionStateChanged`,
`eventCustomerWritten`. `empty()` ist immer wahr → jedes Event wird gesendet, auch `customer.written` mit
E-Mail bei jedem Customer-Write (Registrierung, Passwort-Reset, Newsletter-Flag). Bei n8n/Custom-Zielen ein
nicht abschaltbarer PII-Abfluss.
- [x] Mapping `eventType → Config-Key`, `(bool) $this->getConfig($key, $salesChannelId)`, unbekannte Events default-deny
- [ ] Test dafür schreiben

### M3 – Kein Replay-Schutz, Dedup-Kollision mit dem ERPNext-Empfänger
**Status:** Code beidseitig bestätigt, Auswirkung plausibel
**Stelle:** `src/Preset/ERPNextPreset.php:73-81`: kein `sw-context-message-id`-Header, keine UUID, kein
`data.id`, `timestamp` in Sekunden. Der ERPNext-Empfänger (`connection.py:706-724`) bildet daraus den
Dedup-Key `order.placed:unknown:<sekunde>` → zwei Bestellungen in derselben Sekunde: die zweite wird als
Duplikat verworfen. Mitgeschnittene Nachrichten sind nach 60 s beliebig replaybar; keine Sequenznummer.
- [ ] Pro Versand UUID als `X-Webhook-Id` **und** `sw-context-message-id` (über Retries stabil)
- [ ] `data.id` = Entity-ID, `updatedAt`/`versionId` im Payload, Timestamp in Millisekunden

## Niedrig

- [x] N1 `ERPNextPreset.php:83-90` signiert auch mit leerem Secret (anders als `N8nPreset.php:146`, `CustomPreset.php:203`); Hilfetext `config.xml:45-46` widerspricht dem ERPNext-Empfänger. Secret für das ERPNext-Preset verpflichtend machen.
- [ ] N2 `OrderWebhookSubscriber.php:215, 254-259`: statischer `$processedOrders`-Cache überlebt Requests in Long-Running-Workern → weitere Änderungen derselben Bestellung werden still verschluckt. `kernel.reset` oder request-scoped State; „neu" über `EntityWriteResult::OPERATION_INSERT` statt `isset($payload['orderNumber'])`.
- [ ] N3 Sales-Channel-Konfiguration wird nie genutzt: alle Subscriber rufen `sendWebhook($eventType, $data)` ohne `salesChannelId`. README korrigieren oder durchreichen.
- [ ] N4 `TransactionWebhookSubscriber.php:398-401` nutzt `Context::createDefaultContext()` statt `$event->getContext()`.
- [ ] N5 Stilles `catch (\Exception $e) {}` in `CustomFieldInstaller.php:387-389` und `ShopwareWebhookConnector.php:324-326`. Logger injizieren.
- [ ] N6 `OrderWebhookSubscriber.php:302-307`: `invoice_email`/`custom_po_number` ohne Längen-/Format-Prüfung weitergereicht. `FILTER_VALIDATE_EMAIL`, Kürzung.
- [ ] N7 `composer.json:14` `shopware/core >=6.5.0` ohne Obergrenze (offen). Actions-Pinning auf SHA ist in 4967d0e erledigt.

## Geprüft und sauber
Keine eingehenden Endpunkte (keine Routen, Controller, Admin-JS, Twig). `webhookSecret` ist `type="password"`,
nichts Sensibles geloggt. Signatur HMAC-SHA256 über exakt den gesendeten Body, kompatibel zum
ERPNext-Empfänger. TLS-Defaults aktiv. Exceptions im Versand werden gefangen. Datensparsamkeit gut (nur IDs,
Bestellnummer, Status, Kunden-E-Mail). Keine Fremdbibliotheken, keine Secrets oder echten Domains in Code
oder Historie.
