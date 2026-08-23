# Corsivo Forms

Moduli di contatto nativi Gutenberg per la suite Corsivo: form composti a blocchi nell'editor, submission REST con anti-abuso a più livelli, upload privati, archivio richieste in bacheca e registro attività idempotente.

## Scopo

Fornire ai siti della suite un form builder senza CPT dedicati, shortcode o pannelli di costruzione: il form è contenuto Gutenberg a tutti gli effetti, componibile e traducibile come il resto della pagina. Il plugin garantisce che ogni submission sia validata server-side, non duplicabile, archiviata in modo verificabile e notificata con esito tracciato, mantenendo al minimo i dati personali persistiti. Destinatari: sviluppatori e redazioni che gestiscono siti Corsivo con esigenze di contatto e consenso GDPR documentato.

## Come funziona

### Definizione

Il blocco `corsivo/form` (registrato in PHP tramite Define Blocks, dipendenza obbligatoria) contiene come inner block i campi, il pulsante `corsivo/form-submit` e alcuni blocchi core di layout. Le impostazioni — titolo, destinatari, oggetto, messaggio di conferma, flag archivio — vivono negli attributi del blocco: non esiste un post type per i form. L'identità è `form_id`, un SHA-256 stabile della definizione normalizzata (impostazioni + campi + flag strutturali). Ogni campo ha una chiave (`field_key`, derivata dall'etichetta se vuota) usata nei merge delle notifiche.

| Blocco | Tipo | Note |
| --- | --- | --- |
| `corsivo/form-text` | testo | `field_key` `sender_name` alimenta il Reply-To |
| `corsivo/form-email` | email | indicizzato per gli strumenti privacy |
| `corsivo/form-tel` | telefono | |
| `corsivo/form-textarea` | testo lungo | max 5000 caratteri |
| `corsivo/form-select` | scelta | opzioni deduplicate, valore verificato server-side |
| `corsivo/form-file` | file singolo o multiplo | archivio obbligatorio; formati, quantità e dimensione configurabili |
| `corsivo/form-checkbox` | spunta | accetta solo valore esplicito |
| `corsivo/form-privacy` | consenso | sempre obbligatorio, con link all'informativa |

### Token

Al render la definizione completa — impostazioni, campi con evidenza della privacy policy (URL, ID, hash SHA-256 del contenuto, data di modifica), pagina, lingua, modalità sorgente — viene cifrata in un token AES-256-GCM con chiave derivata dai salt di WordPress e contesto network/blog: nulla della configurazione è esposto al client. La sorgente viene classificata (`post`; `template` con catena di hash di template, template part e blocchi riutilizzabili; `rendered` come fallback) e il TTL è 1 giorno per `rendered`, 90 giorni per `post`/`template` (filtro `corsivo_forms_form_token_ttl`). Questo consente il form anche in template FSE, template part, pattern sincronizzati e Query Loop. A ogni submit la sorgente viene riverificata: se il post non contiene più quel form, la catena template ha hash diversi o l'informativa privacy è cambiata, il token è revocato (`form_changed`, `privacy_policy_changed`); cambiare la lingua della pagina lo invalida (`form_language_changed`).

### Render

Il markup include honeypot (`corsivo_forms_hp`), feedback `aria-live` e un avviso "JavaScript necessario": il pulsante resta `type="button"` finché lo script frontend non lo attiva, quindi non esiste un percorso di invio senza JS. Un campo file espone `accept`, `multiple` e la descrizione accessibile dei limiti, ma questi attributi sono solo UX: il contratto viene sempre applicato dal server. Se la configurazione è invalida il form viene reso disabilitato con l'errore esplicito: destinatari malformati, select obbligatoria senza opzioni, campo privacy senza informativa pubblicata, oltre 50 campi, struttura troppo profonda, submit mancante, OpenSSL assente, campo file senza formati, archivio disattivato in presenza di file o storage privato non attestabile.

### Submit

Lo script invia `multipart/form-data` a `POST /wp-json/corsivo/v1/forms/submit` costruendo `FormData` dal form e aggiungendo `token`, `request_id` (UUID generato dal client, conservato quando l'esito di rete è ignoto) e `formId`; non imposta manualmente `Content-Type`, così il browser genera il boundary. I campi ordinari restano scalari, mentre i file singoli e multipli arrivano tramite i file params della REST request. Pipeline server, nell'ordine:

1. limite body 27 MB per multipart e 2 MB per gli altri payload, applicato su `rest_pre_dispatch`, e limite cumulativo dei file di 25 MB;
2. controllo same-origin: `Sec-Fetch-Site: cross-site` rifiutato, header `Origin` normalizzato e confrontato con `home_url`;
3. fingerprint SHA-256 dei parametri normalizzati e dell'identità di ogni file (`field`, nome originale, errore PHP, byte e SHA-256 del contenuto), esclusi token, honeypot e `request_id`; replay lookup su `request_id`: risposta già salvata restituita senza rieseguire nulla, mentre la stessa chiave con contenuto o form diverso produce 409 `request_id_conflict`;
4. pre-limit e honeypot;
5. decodifica del token e riverifica di pubblicità della pagina, lingua, sorgente ed evidenza policy;
6. validazione server-side dei campi e preparazione dei file; gli errori consumano un budget dedicato e non toccano quello degli invii validi;
7. rate limit submission e claim idempotente sul registro attività (chiave unica `request_id`);
8. archiviazione (se attiva), notifica, action `corsivo_forms_submission`, aggiornamento del record e persistenza della risposta per i replay futuri.

Un claim rimasto `processing` oltre il timeout (5 minuti, filtro `corsivo_forms_processing_timeout`) viene marcato `unknown` e reso ritentabile con esito esplicito; il replay di una risposta persa funziona anche a token scaduto.

### Archiviazione

Se il flag archivio è attivo la richiesta diventa un post del CPT privato `corsivo_submission`: tutte le capability mappate su `manage_options`, `create_posts` negato, niente REST né ricerca frontend. La scrittura è draft → verifica di ogni meta → publish: se un meta non risulta scritto, il post parziale viene eliminato con compensazione verificata (post e meta orfani inclusi) e la submission fallisce in modo pulito. Ogni campo email è indicizzato in `_corsivo_forms_email_index`; i consensi sono salvati con l'evidenza congelata nel token (`evidence_hash`, data di accettazione, riferimenti alla policy).

### File privati

Il blocco `corsivo/form-file` può essere obbligatorio, singolo o multiplo. L’inspector configura l’allowlist delle estensioni, il numero massimo per campo (1–10; un campo singolo forza 1) e la dimensione massima per file (1–20 MB, default 5 MB); i default sono PDF, JPG/JPEG e PNG. Il registry server ammette esclusivamente `pdf`, `jpg`, `jpeg`, `png`, `doc`, `docx`, `xls`, `xlsx`, `csv`, `txt`, `odt` e `ods`: valori esterni al registry vengono eliminati durante la normalizzazione e un’allowlist vuota rende il form indisponibile.

Ogni upload viene verificato per errore PHP, presenza e leggibilità del temporaneo, assenza di symlink, dimensione dichiarata/reale, estensione, risultato di `wp_check_filetype_and_ext()` e MIME rilevato dal contenuto. Immagini, documenti ZIP-based e file testuali hanno controlli aggiuntivi: decodifica immagine, struttura DOCX/XLSX/ODF e rifiuto di contenuti eseguibili o markup attivo. Se MIME detection, `ZipArchive` quando necessario, hashing o qualunque controllo di integrità non sono disponibili, il file viene rifiutato; non si usa mai il MIME dichiarato dal browser come fonte di verità.

I file non entrano nella Media Library e non hanno URL pubblico. Lo storage predefinito è `corsivo-forms-private/<hash-installazione>` fuori dal webroot più esterno rilevato; può essere sostituito definendo `CORSIVO_FORMS_PRIVATE_DIR` con un percorso assoluto esterno a tutti i webroot prima del caricamento del plugin. La struttura è `sites/<blog_id>/submissions/<submission_id>/`: root, sito e richiesta sono attestati da marker plain deterministici. Percorsi relativi, root del filesystem, directory interne o antenate dei webroot, symlink, marker mancanti/diversi e directory con elementi inattesi vengono rifiutati senza tentare adozioni o cancellazioni.

I file vengono validati e preparati, poi spostati con nome casuale `<upload_id>.<estensione>` e permessi `0640` nella directory della submission draft. Dimensione e SHA-256 sono riverificati dopo lo spostamento; `_corsivo_forms_uploads` conserva in chiaro `id`, campo, nome originale, nome storage, estensione, MIME, byte, SHA-256 e timestamp GMT. Marker e metadati persistenti non dipendono dai salt WordPress, quindi una rotazione dei salt non li rende illeggibili. Qualunque errore elimina in compensazione file, directory e post parziale; un form con file richiede sempre l’archiviazione e non invia notifiche se la richiesta non è stata archiviata integralmente.

Nel dettaglio amministrativo gli allegati sono scaricabili soltanto tramite `admin-post.php` da utenti con `manage_options` e nonce legato a submission e upload. Prima dello stream vengono ricontrollati metadati, directory attestata, contenimento del path, dimensione e SHA-256; la risposta forza attachment, `nosniff`, sandbox CSP, no-cache e noindex. Le email e i merge tag contengono soltanto i nomi originali: nessun binario, allegato email, URL pubblico o link amministrativo viene inviato.

Il ciclo di vita dei file coincide con quello della submission. Eliminazione definitiva, retention ed eraser privacy rimuovono prima i file e bloccano l’eliminazione del post se lo storage non può essere ripulito; l’exporter include nome, MIME e dimensione, non il contenuto. Con `delete_data_on_uninstall=false` submission e file restano; con opt-in l’uninstall multisite elimina per sito soltanto directory e file riconosciuti dai marker, fermandosi fail-closed su elementi estranei. Una root personalizzata resta condivisa ma isolata per `blog_id`.

### Notifiche

Destinatari dalla configurazione (max 10, deduplicati case-insensitive; vuoto = email amministratore), oggetto con merge `{chiave}`, Reply-To composto da campo email e campo `sender_name`. Il trasporto preferito è Corsivo Mail (provider template `corsivo_forms_form`, merge tag tra cui `{{fields_html}}`, `{{field_chiave}}` e `{{field_chiave_url}}`), con fallback per destinatario a `wp_mail()` HTML. L'esito è registrato per submission: `accepted`, `partial`, `failed`, `pending`. I valori nei merge sono sanitizzati contro header/attribute injection e protocolli pericolosi anche offuscati con entità HTML; per i campi file il valore è la sola lista dei nomi originali.

### Attività, retention e cron

La tabella `wp_corsivo_forms_activity` (InnoDB, `request_id` UNIQUE) registra gli eventi `submission`, `integration`, `deleted`, `deleted_permanently`, `erased`, `retention_cleanup`, `activity_clear`, `settings_updated` con soli identificativi, stati, codici errore e timestamp GMT. I messaggi vengono redatti (email → `[email]`, percorsi → `[path]`, eccezioni → classe + fingerprint HMAC): mai IP, valori dei campi, body email o destinatari. Il cron giornaliero `corsivo_forms_daily_cleanup` applica la retention alle attività, elimina le submission parziali più vecchie di un'ora e quelle scadute — sul timestamp canonico di invio, non sulla data del post — a batch da 250 con cursore; `corsivo_forms_cleanup_batch` prosegue le code lunghe e `corsivo_forms_activity_schema_verify` è il one-shot di verifica fisica dello storage.

La tab Attività usa `@corsivo-suite/wp-admin-dataviews` 0.1.0 compilato nel plugin. La collection autenticata `GET /wp-json/corsivo/v1/forms/activity` espone soltanto il DTO amministrativo necessario, applica `manage_options` e delega ricerca, filtri e paginazione alle query PHP esistenti. La pulizia non passa dalla REST API: resta l'handler `admin-post` con nonce e conserva i claim tecnici recenti.

### Storage

Lo schema della tabella attività è current-only: installazione fresh-only a due fasi sotto lock MySQL, con verifica fisica di colonne, indici ed engine InnoDB. Una tabella completa conforme al blueprint viene riattestata anche se contiene righe; namespace inattesi, struttura incoerente o stato non verificabile mantengono il plugin fail-closed, con route submit a 503 e notice amministrativa.

## Punti rilevanti

- **Anti-spam a più livelli senza CAPTCHA:** honeypot, same-origin, pre-limit (60 richieste/5 min), budget errori di validazione separato (30/15 min per form) e budget invii validi (10/15 min per form). Chi sbaglia non consuma il budget degli utenti legittimi; il replay di una risposta già salvata non consuma nulla.
- **Pseudonimizzazione dei rate limit:** gli scope sono `HMAC(IP | bucket)` con salt di WordPress, quindi le chiavi di throttling non contengono IP in chiaro e non sono ricollegabili senza la chiave del sito. L'IP è `REMOTE_ADDR`; dietro proxy o CDN va valorizzato con il filtro `corsivo_forms_client_ip`, fidandosi degli header inoltrati solo da proxy noti.
- **Idempotenza end-to-end:** `request_id` client + fingerprint del contenuto + chiave unica + risposta persistita = stessa richiesta, stessa risposta, una sola submission, un solo set di file e una sola notifica; un payload diverso con la stessa chiave viene rifiutato anche se cambia soltanto il contenuto binario.
- **Fail-closed:** configurazione invalida → form disabilitato con motivo; activity storage o upload storage non verificati → submit indisponibile; OpenSSL assente → nessun token; mismatch di tipo, hash, marker, path o cleanup → operazione interrotta. Nessun percorso degrada in silenzio.
- **Minimizzazione:** il registro attività non duplica dati inseriti; le submission esistono solo se l'archivio è attivo; l'evidenza consenso è hash e riferimento, non copia dell'informativa; per gli upload restano soltanto file privato e metadati necessari a integrità, amministrazione e privacy.
- **Retention e strumenti privacy WP:** attività 1–3650 giorni (default 90; a registro disattivato restano per un giorno solo gli eventi amministrativi di disattivazione/pulizia e i claim tecnici di idempotenza), richieste 0–3650 giorni (0 = nessuna scadenza). Exporter ed eraser WordPress indicizzano tutte le email, esportano evidenze e metadati file, eliminano submission e file a lotti da 100 con cursore e segnalano gli elementi non eliminabili senza proseguire in modo distruttivo.
- **Confini dichiarati:** nessun invio senza JavaScript. La route REST è pubblica by design (riceve richieste anonime): le difese sono tutte server-side. Gli upload sono limitati al registry interno, non passano dalla Media Library e non vengono mai esposti o spediti per email. Alla disinstallazione i dati vengono conservati salvo opt-in esplicito.

## Integrazioni con la suite

- **Define Blocks** — unica dipendenza obbligatoria (`Requires Plugins: define-blocks`): registra blocchi e controlli editor dallo schema PHP.
- **Corsivo Mail** — opzionale e disattivabile dalla tab Integrazioni: Forms registra il provider `corsivo_forms_form` via filtro `corsivo_mail_core_emails` e consegna con `corsivo_mail_deliver()`; senza template disponibile usa `wp_mail()` senza coinvolgere la coda globale.
- **Corsivo Privacy** — opzionale e disattivabile: il campo consenso usa la pagina policy da `corsivo_privacy_get_pages_state()` con risoluzione della lingua corrente; in assenza vale la privacy policy nativa di WordPress (`wp_page_for_privacy_policy`).
- **Corsivo Newsletter** — ascolta l'action `corsivo_forms_submission($submission, $clean, $submission_id)`: se la submission contiene un campo spuntato con chiave `newsletter` e un'email mittente, iscrive il contatto con evidenza di consenso. Forms non carica né conosce codice Newsletter; un'eccezione nell'integrazione viene isolata e registrata senza far fallire la submission.
- **WPML** — `wpml-config.xml` espone alla traduzione solo label, placeholder, opzioni, oggetto, messaggio di conferma e testi del campo privacy; `corsivo_submission` non è traducibile. La lingua della submission è risolta per pagina (`wpml_post_language_details`) e registrata nella richiesta.
- **Filtri per l'estensione:** `corsivo_forms_form_definition`, `corsivo_forms_allow_post`, `corsivo_forms_is_public_render_context`, `corsivo_forms_submission_language`, più i filtri di rate limit (`corsivo_forms_rate_limit`/`_window`, varianti `validation` e `preflight`) e TTL citati sopra.

## Test

```
wp eval-file wp-content/plugins/corsivo-forms/tests/run.php
php wp-content/plugins/corsivo-forms/tests/storage-static.php
php wp-content/plugins/corsivo-forms/tests/admin-dataviews-static.php
wp eval-file wp-content/plugins/corsivo-forms/tests/admin-dataviews-wordpress.php
cd wp-content/plugins/corsivo-forms/admin-app && npm run lint:js && npm run test:unit && npm run build
```

`run.php` (stile TAP, exit code 1 al primo fallimento) copre: normalizzazione di impostazioni e campi, validazione, ciclo di vita del token, destinatari e merge anti-injection, redazione del registro, claim/replay/conflitti/claim interrotti, storage submission con compensazione, retention, exporter/eraser, cicli di blocchi riutilizzabili e un E2E REST con i tre budget di rate limit. I casi upload verificano schema/render del blocco, multipart singolo e multiplo, allowlist estensione/MIME e contenuto, limiti per file/campo/request, fingerprint binario e conflitto idempotente, storage fuori webroot con marker plain, metadati e hash, compensazione, download autorizzato, privacy/retention e uninstall multisite fail-closed. Il runner ripristina hook, post, file e directory temporanee, opzioni e chiavi di throttling create.

`storage-static.php` (PHP diretto, senza WordPress) fa guardie statiche sul contratto storage current-only: vieta `dbDelta` e `ALTER TABLE`, richiede installazione a due fasi con lock e verifica fisica dello schema.

## Configurazione

Pagina `Strumenti → Corsivo Forms`, tab Attività (DataViews con ricerca, filtri, paginazione e pulizia manuale che conserva i claim in elaborazione), Impostazioni e Integrazioni. Opzione unica `corsivo_forms_settings`:

| Chiave | Default | Note |
| --- | --- | --- |
| `log_enabled` | `true` | registro attività |
| `log_retention_days` | `90` | 1–3650 |
| `submission_retention_days` | `0` | 0–3650, 0 = nessuna scadenza |
| `delete_data_on_uninstall` | `false` | elimina richieste, attività, tabella e impostazioni alla disinstallazione |
| `corsivo_mail_enabled` | `true` | usa Corsivo Mail se disponibile |
| `corsivo_privacy_enabled` | `true` | usa la policy di Corsivo Privacy se disponibile |

Limiti fissi rilevanti: body multipart 27 MB, massimo 25 MB di file e 10 file complessivi per request, massimo configurabile di 10 file per campo e 20 MB per file, 50 campi per form, 10 destinatari, testo 500 caratteri (textarea 5000), token max ~48 KB. I payload non multipart restano limitati a 2 MB. `upload_max_filesize`, `post_max_size` e altri limiti PHP/server possono ridurre questi valori. Il testo suggerito per la privacy policy del sito (`wp_add_privacy_policy_content`) riflette retention e presenza dei file. Requisiti: WordPress 6.6+, PHP 8.1+, estensione OpenSSL, Define Blocks attivo; `ZipArchive` è necessario per accettare DOCX, XLSX, ODT e ODS. Multisito supportato (attivazione, cleanup e uninstall per sito). Distribuito dal canale privato `plugins.corsivo.dev` tramite Corsivo Updater; licenza GPL-2.0-or-later (vedi `LICENSE`).

`CORSIVO_FORMS_PRIVATE_DIR` non è un’opzione amministrativa: se definita, deve contenere una directory assoluta dedicata e fuori dai webroot. Il plugin non sposta automaticamente i file quando cambia il percorso; la configurazione deve quindi essere stabilita prima dell’uso dei campi file.

## Bootstrap, lifecycle, sicurezza e build

Il bootstrap `corsivo-forms.php` carica anche `includes/uploads.php` e registra l’attivazione; Define Blocks resta la dipendenza obbligatoria. `init` registra blocchi e CPT submission, `rest_api_init` espone il submit anonimo e la collection attività riservata a `manage_options`, `plugins_loaded`/`admin_init` verificano l’activity storage e il render di un form file attesta lazy lo storage privato. L’attivazione crea la tabella attività per ogni sito; la disattivazione rimuove i cron ma conserva submission e file. `uninstall.php` cancella dati e storage privato soltanto con l’opt-in. Gli altri ingressi sono render callback, admin-post per pulizia e download file, privacy exporter/eraser e cron; non esistono AJAX o CLI. `admin-app/` compila DataViews e WP Admin UI nel bundle distribuibile, senza dipendenze runtime dal plugin showcase. React 18.3.1 e React DOM 18.3.1 sono dipendenze esclusivamente di sviluppo per risolvere i peer del package; la build li lascia esterni e usa gli handle React/Element registrati da WordPress a runtime. La route pubblica usa token cifrato, same-origin, honeypot, rate limit, fingerprint e idempotenza; download, collection e mutazioni amministrative usano capability e nonce WordPress. Limiti: niente CAPTCHA, invio senza JavaScript o form CPT; storage current-only senza percorsi legacy o migrazioni implicite.

## Inventario completo dei file

Ogni percorso è relativo alla root del plugin. `admin-app/node_modules/` resta locale e non entra nella release; il bundle contiene le dipendenze JavaScript necessarie, mentre non sono presenti `vendor/` o `libs/tcpdf/`.

- `README.md` — contratto operativo corrente di blocchi form, token, submit, storage, privacy, test e inventario.
- `.gitignore` — esclude gli archivi ZIP e le dipendenze npm locali dell’app admin.
- `admin-app/` — sorgenti JavaScript, manifest/lock npm e build distribuibile della tab Attività DataViews.
- `assets/corsivo-forms.css` — stile pubblico di form, input file, descrizioni, honeypot, feedback e stati disabled/error.
- `assets/corsivo-forms.js` — abilita il submit, genera `request_id`, invia `FormData` multipart e gestisce errori/replay UI.
- `changelog.txt` — storia delle release e dei contratti Corsivo Forms.
- `corsivo-forms.php` — header, costanti, require, activation multisite e bootstrap dei moduli.
- `includes/activity-storage.php` — DDL tabella activity, inventory fisico, marker/intent, lock e verifica current-only.
- `includes/activity.php` — claim idempotenti, response envelope, redazione, query/purge e handler clear del registro.
- `includes/blocks.php` — schema Define Blocks, render form/campi, traversal di reusable/template e registrazione asset.
- `includes/editor.php` — categoria blocchi, estensione inspector Define Blocks e CSS della preview editor.
- `includes/fields.php` — registry degli otto field type, schema e render accessibile del campo file, default e validazione server-side.
- `includes/mail.php` — provider Corsivo Mail, merge sicuri, destinatari/Reply-To e fallback `wp_mail()` per recipient.
- `includes/privacy.php` — exporter/eraser, metadati upload, policy text, retention coordinata di submission/file e cron cleanup a batch.
- `includes/settings.php` — option unica, tab amministrative, enqueue della build e collection REST dell’attività.
- `includes/submissions.php` — CPT privato, scrittura compensata di meta/file, indice email e download dal dettaglio admin.
- `includes/submit.php` — route anonima multipart, body/origin guard, fingerprint di parametri/file, replay, token, rate limit, archive/mail e response persistita.
- `includes/token.php` — normalizzazione definizione, evidenza policy, source chain e token AES-256-GCM con revoca.
- `includes/uploads.php` — registry estensioni/MIME, parsing multipart, validazione contenuto, storage privato attestato, metadati, compensazione, download, privacy e cleanup/uninstall multisite.
- `LICENSE` — testo della licenza GPL-2.0-or-later.
- `tests/run.php` — suite TAP WordPress su token, validazione, submit E2E, upload multipart/storage/download, idempotenza, mail, retention e privacy.
- `tests/storage-static.php` — guardia PHP diretta sul blueprint, installazione fresh-only, lock e verifica fisica.
- `tests/admin-dataviews-static.php` — guardia su versioni package, capability, handler di pulizia e completezza degli asset admin.
- `tests/admin-dataviews-wordpress.php` — smoke nel runtime reale su collection REST, header di paginazione e asset accodati.
- `uninstall.php` — cleanup multisite opt-in di cron, submission, file privati attestati, activity, option e tabella posseduta.
- `wpml-config.xml` — configura la traduzione degli attributi testuali dei blocchi form/campi.
