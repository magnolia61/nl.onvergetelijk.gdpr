<?php

require_once __DIR__ . '/gdpr.helpers.php';

/**
 * =======================================================================================
 * GDPR PRIVACY-SYNC LOGICA
 * =======================================================================================
 * Houdt de custom voorkeur `Privacy.contactvoorkeuren_1417` en de core-vlaggen
 * `is_opt_out` / `do_not_email` in sync.
 *
 * Keuze voor detectie D.1:
 *   De custom-hook op group 286 is de trigger, maar de definitieve waarde lezen we uit
 *   `civicrm_value_privacy_286`. De hook-parametervorm verschilt per opslagroute, terwijl
 *   de tabel/kolom in de bron-sqltasks juist expliciet en stabiel zijn. Mutaties op core
 *   Contact blijven via APIv4 lopen met OZK-logging en checkPermissions=FALSE.
 * =======================================================================================
 */

/**
 * Bepaal of een hook-objectnaam een contact-mutatie representeert.
 */
function _gdpr_sync_is_contact_object(string $object_name): bool {
    return in_array($object_name, ['Contact', 'Individual', 'Organization', 'Household'], TRUE);
}

/**
 * Lees een waarde uit een hook-object of hook-array.
 *
 * @param mixed  $object_ref Hook-object of array.
 * @param string $field      Gevraagde veldnaam.
 *
 * @return mixed|null
 */
function _gdpr_sync_object_ref_value($object_ref, string $field) {
    if (is_array($object_ref) && array_key_exists($field, $object_ref)) {
        return $object_ref[$field];
    }
    if (is_object($object_ref) && isset($object_ref->{$field})) {
        return $object_ref->{$field};
    }
    return NULL;
}

/**
 * Lees de re-entrancy-status voor een contact.
 */
function _gdpr_sync_is_busy(int $contact_id): bool {
    return !empty(\Civi::$statics['gdpr']['sync_busy'][$contact_id]);
}

/**
 * Zet of wis de re-entrancy-status voor een contact.
 */
function _gdpr_sync_set_busy(int $contact_id, bool $busy): void {
    if ($busy) {
        \Civi::$statics['gdpr']['sync_busy'][$contact_id] = TRUE;
        return;
    }
    unset(\Civi::$statics['gdpr']['sync_busy'][$contact_id]);
}

/**
 * Heeft dit contact een actieve kampregistratie voor dit jaar?
 *
 * DITJAAR.ditjaar_event_start (custom veld 1155) wordt door de nachtsync gevuld zodra
 * er een lopende registratie is en geleegd na afloop van het seizoen. Zolang dit veld
 * gevuld is, is de deelnemer/leiding "onderweg naar kamp" en moeten praktische mails
 * (bevestigingen, betaalinfo, kampinformatie) kunnen blijven aankomen.
 */
function _gdpr_sync_heeft_actieve_registratie(int $contact_id, $extdebug = 'gdpr.sync'): bool {
    $params_contact_ditjaar = [
        'checkPermissions' => FALSE,
        'select'           => ['DITJAAR.ditjaar_event_start'],
        'where'            => [
            ['id', '=', $contact_id],
        ],
        'limit'            => 1,
    ];
    wachthond($extdebug, 7, 'params_contact_ditjaar', $params_contact_ditjaar);
    $result_contact_ditjaar = civicrm_api4('Contact', 'get', $params_contact_ditjaar);
    wachthond($extdebug, 9, 'result_contact_ditjaar', $result_contact_ditjaar);

    return !empty($result_contact_ditjaar->first()['DITJAAR.ditjaar_event_start']);
}

/**
 * Leid de core-vlaggen af uit Privacy.contactvoorkeuren_1417.
 *
 * BELEIDSREGEL (Richard, 11-jul-2026): bij voorkeur 44 mét een actieve kampregistratie
 * voor dit jaar wordt do_not_email UITGESTELD — die vlag zou óók alle praktische
 * kampmail blokkeren (reminders en CiviRules-mail toetsen do_not_email). We sluiten
 * dan alleen de bulk-mailings af (is_opt_out). Zodra het seizoen voorbij is en
 * ditjaar_event_start weer leeg, rondt de dagelijkse reconciliatie (Gdpr.syncbackfill)
 * de 44-behandeling alsnog af: do_not_email aan en de erasure-job verwijdert de
 * contactgegevens.
 *
 * @return array|null NULL betekent: leeg/onbekend, dus ongemoeid laten.
 */
function _gdpr_sync_flags_for_voorkeur(?int $voorkeur, bool $actieve_registratie = FALSE): ?array {
    switch ($voorkeur) {
        case 11:
        case 22:
            return ['is_opt_out' => 0, 'do_not_email' => 0];

        case 33:
            return ['is_opt_out' => 1, 'do_not_email' => 0];

        case 44:
            return $actieve_registratie
                ? ['is_opt_out' => 1]                       // uitstel: praktische mail moet blijven aankomen
                : ['is_opt_out' => 1, 'do_not_email' => 1]; // volledige afsluiting
    }

    return NULL;
}

/**
 * Lees de Contactvoorkeuren-voorkeur (custom veld 1417) via APIv4.
 *
 * Custom velden zijn in APIv4 bereikbaar als 'GROEPNAAM.Veldnaam' (hier
 * 'PRIVACY.Contactvoorkeuren'); dat overleeft kolom-hernoemingen in tegenstelling
 * tot directe SQL op civicrm_value_privacy_286 (vgl. de medisch_check-rename die
 * sqltask 7 brak).
 */
function _gdpr_sync_read_contactvoorkeuren(int $contact_id, $extdebug = 'gdpr.sync'): ?int {
    $params_contact_voorkeur = [
        'checkPermissions' => FALSE,
        'select'           => ['PRIVACY.Contactvoorkeuren'],
        'where'            => [
            ['id', '=', $contact_id],
        ],
        'limit'            => 1,
    ];
    wachthond($extdebug, 7, 'params_contact_voorkeur', $params_contact_voorkeur);
    $result_contact_voorkeur = civicrm_api4('Contact', 'get', $params_contact_voorkeur);
    wachthond($extdebug, 9, 'result_contact_voorkeur', $result_contact_voorkeur);

    $value = $result_contact_voorkeur->first()['PRIVACY.Contactvoorkeuren'] ?? NULL;
    if ($value === NULL || $value === '') {
        return NULL;
    }
    return (int) $value;
}

/**
 * Schrijf de Contactvoorkeuren-voorkeur (custom veld 1417) via APIv4.
 *
 * Bewust via Contact.update en niet via directe SQL: zo vuren de reguliere
 * custom-hooks (incl. onze eigen D.1-sync — idempotent, dus onschadelijk) en
 * blijft delta/logging-gedrag identiek aan een menselijke wijziging.
 */
function _gdpr_sync_write_contactvoorkeuren(int $contact_id, int $voorkeur, $extdebug): void {
    $params_contact_voorkeur_update = [
        'checkPermissions' => FALSE,
        'where'            => [
            ['id', '=', $contact_id],
        ],
        'values'           => [
            'PRIVACY.Contactvoorkeuren' => (string) $voorkeur,
        ],
    ];
    wachthond($extdebug, 7, 'params_contact_voorkeur_update', $params_contact_voorkeur_update);
    $result_contact_voorkeur_update = civicrm_api4('Contact', 'update', $params_contact_voorkeur_update);
    wachthond($extdebug, 9, 'result_contact_voorkeur_update', $result_contact_voorkeur_update);
}

/**
 * Lees de actuele core-vlaggen via APIv4.
 */
function _gdpr_sync_read_contact_flags(int $contact_id, $extdebug): ?array {
    $params = [
        'checkPermissions' => FALSE,
        'select'           => ['id', 'is_opt_out', 'do_not_email'],
        'where'            => [
            ['id', '=', $contact_id],
        ],
        'limit'            => 1,
    ];
    wachthond($extdebug, 7, "APIv4 Contact.get params", $params);
    $result = civicrm_api4('Contact', 'get', $params);
    wachthond($extdebug, 9, "APIv4 Contact.get resultaat", $result);

    $row = $result->first();
    if (empty($row)) {
        return NULL;
    }

    return [
        'is_opt_out'  => (int) !empty($row['is_opt_out']),
        'do_not_email' => (int) !empty($row['do_not_email']),
    ];
}

/**
 * Schrijf gewijzigde core-vlaggen via APIv4.
 */
function _gdpr_sync_update_contact_flags(int $contact_id, array $values, $extdebug): void {
    $params = [
        'checkPermissions' => FALSE,
        'where'            => [
            ['id', '=', $contact_id],
        ],
        'values'           => $values,
    ];
    wachthond($extdebug, 7, "APIv4 Contact.update params", $params);
    $result = civicrm_api4('Contact', 'update', $params);
    wachthond($extdebug, 9, "APIv4 Contact.update resultaat", $result);
}

/**
 * Stuur de bevestigingsmail bij een uitgesteld verwijderverzoek (voorkeur 44 mét
 * actieve kampregistratie): nieuwsbrieven/mailings stoppen per direct, praktische
 * mails over de kampdeelname blijven komen, gegevens worden na het seizoen verwijderd.
 *
 * Template wordt op naam opgezocht (managed entity in gdpr.mgd.php), zodat het
 * template-id per omgeving mag verschillen. In de PHPUnit-omgeving wordt nooit
 * echt verstuurd.
 *
 * @return array{status:string,template_id?:int}
 */
function _gdpr_stuur_uitstel_bevestiging(int $contact_id, $extdebug): array {

    // TEST-SCHAKELAAR: de PHPUnit-tests zetten deze static in setUp zodat er nooit
    // echt gemaild wordt vanuit een testrun. Omgevingsdetectie (CIVICRM_UF) is bij
    // EndToEnd-tests onbetrouwbaar: de site-bootstrap herdefinieert zowel de constante
    // als de environment-variabele naar 'Drupal'.
    if (!empty(\Civi::$statics['gdpr']['mail_onderdrukt'])) {
        wachthond($extdebug, 3, "[SKIP] uitstel-bevestigingsmail onderdrukt (test-schakelaar)", $contact_id);
        return ['status' => 'onderdrukt_test'];
    }

    // Template-id opzoeken op titel (aangemaakt als managed entity).
    $params_template_get = [
        'checkPermissions' => FALSE,
        'select'           => ['id'],
        'where'            => [
            ['msg_title', '=', 'GDPR - Verwijderverzoek tijdens kampseizoen'],
            ['is_active', '=', TRUE],
        ],
        'limit'            => 1,
    ];
    wachthond($extdebug, 7, 'params_template_get', $params_template_get);
    $result_template_get = civicrm_api4('MessageTemplate', 'get', $params_template_get);
    wachthond($extdebug, 9, 'result_template_get', $result_template_get);

    $template_id = (int) ($result_template_get->first()['id'] ?? 0);
    if ($template_id <= 0) {
        wachthond($extdebug, 1, "[FOUT] template 'GDPR - Verwijderverzoek tijdens kampseizoen' niet gevonden", "[MAIL]");
        return ['status' => 'template_niet_gevonden'];
    }

    // Versturen via emailapi (zelfde kanaal als de CiviRules-mails). GEEN
    // location_type_id meesturen (bekende emailapi-valkuil: die overschrijft
    // de adreskeuze voor álle ontvangers).
    $params_email_send = [
        'contact_id'  => $contact_id,
        'template_id' => $template_id,
    ];
    wachthond($extdebug, 7, 'params_email_send', $params_email_send);
    $result_email_send = civicrm_api3('Email', 'send', $params_email_send);
    wachthond($extdebug, 9, 'result_email_send', $result_email_send);

    return ['status' => 'verstuurd', 'template_id' => $template_id];
}

/**
 * D.1 Custom -> core: leid core-vlaggen af uit de custom voorkeur.
 *
 * @param int         $contact_id Contact waarvoor de voorkeur gesynct wordt.
 * @param int|string  $extdebug   Wachthond-kanaal.
 * @param bool        $stuur_mail FALSE = geen bevestigingsmail (bulk/backfill-context:
 *                                de inhaalslag mag nooit onverwacht massa-mailen).
 *
 * @return array{contact_id:int,rows:int,skipped:?string,values:array}
 */
function gdpr_sync_custom_to_core(int $contact_id, $extdebug = 'gdpr.sync', bool $stuur_mail = TRUE): array {
    if (_gdpr_sync_is_busy($contact_id)) {
        return ['contact_id' => $contact_id, 'rows' => 0, 'skipped' => 'busy', 'values' => []];
    }

    $voorkeur = _gdpr_sync_read_contactvoorkeuren($contact_id, $extdebug);

    // Bij voorkeur 44 bepaalt een actieve kampregistratie of we do_not_email uitstellen
    // (zie de beleidsregel bij _gdpr_sync_flags_for_voorkeur). Alleen dáár opvragen —
    // voor 11/22/33 is de registratiestatus niet relevant.
    $actieve_registratie = ($voorkeur === 44)
        ? _gdpr_sync_heeft_actieve_registratie($contact_id, $extdebug)
        : FALSE;

    $flags = _gdpr_sync_flags_for_voorkeur($voorkeur, $actieve_registratie);
    if ($flags === NULL) {
        return ['contact_id' => $contact_id, 'rows' => 0, 'skipped' => 'geen relevante voorkeur', 'values' => []];
    }

    $current = _gdpr_sync_read_contact_flags($contact_id, $extdebug);
    if ($current === NULL) {
        return ['contact_id' => $contact_id, 'rows' => 0, 'skipped' => 'contact niet gevonden', 'values' => []];
    }

    $values = [];
    foreach ($flags as $field => $desired) {
        if ((int) $current[$field] !== (int) $desired) {
            $values[$field] = (int) $desired;
        }
    }

    if (empty($values)) {
        return ['contact_id' => $contact_id, 'rows' => 0, 'skipped' => 'geen wijziging', 'values' => []];
    }

    _gdpr_sync_set_busy($contact_id, TRUE);
    try {
        _gdpr_sync_update_contact_flags($contact_id, $values, $extdebug);
    }
    finally {
        _gdpr_sync_set_busy($contact_id, FALSE);
    }

    // AUDITSPOOR: elke privacy-mutatie krijgt een activity 142, net als bij de
    // bron-sqltasks. We beschrijven de oude en nieuwe vlagwaarden expliciet zodat
    // een jaar later nog te herleiden is wat de sync gedaan heeft en waarom.
    $wijzigingen = [];
    foreach ($values as $field => $nieuw) {
        $wijzigingen[] = "$field: {$current[$field]} -> $nieuw";
    }
    $uitstel_uitleg = ($voorkeur === 44 && $actieve_registratie)
        ? " LET OP: dit contact heeft een actieve kampregistratie voor dit jaar; do_not_email en het"
          . " verwijderen van contactgegevens zijn UITGESTELD tot na het seizoen, zodat praktische"
          . " mails over de kampdeelname blijven aankomen. De dagelijkse reconciliatie rondt dit"
          . " daarna automatisch af."
        : "";
    _gdpr_create_cleanup_activity(
        ['contact_id' => $contact_id],
        'GDPR voorkeur-sync: core privacy-vlaggen bijgewerkt',
        "Contactvoorkeuren (PRIVACY-tab) staat op '$voorkeur'; de core privacy-vlaggen zijn "
        . "daarop aangepast: " . implode(', ', $wijzigingen) . ". "
        . "Mapping: 11/22 = mailings toegestaan, 33 = geen bulk-mailings (is_opt_out), "
        . "44 = verwijderverzoek (is_opt_out + do_not_email)." . $uitstel_uitleg,
        142,
        $extdebug
    );

    // BEVESTIGINGSMAIL bij uitgesteld verwijderverzoek: leg de deelnemer/ouder uit dat
    // nieuwsbrieven en mailings per direct stoppen, maar dat praktische mails over de
    // kampdeelname gewoon blijven komen. Eénmalig: alleen op het moment dat de vlaggen
    // daadwerkelijk wijzigen (een tweede submit belandt hierboven al in 'geen wijziging').
    $mail = NULL;
    if ($stuur_mail && $voorkeur === 44 && $actieve_registratie) {
        $mail = _gdpr_stuur_uitstel_bevestiging($contact_id, $extdebug);
    }

    return ['contact_id' => $contact_id, 'rows' => 1, 'skipped' => NULL, 'values' => $values, 'mail' => $mail];
}

/**
 * Hook-handler voor hook_civicrm_custom: custom voorkeur is bron van waarheid.
 *
 * @param mixed $params Hook-params, bewust niet gebruikt voor de waarde.
 */
function gdpr_sync_custom_hook($op, $group_id, $entity_id, &$params, $extdebug = 'gdpr.sync'): array {
    if ((int) $group_id !== 286 || !in_array($op, ['create', 'edit', 'update'], TRUE)) {
        return ['contact_id' => (int) $entity_id, 'rows' => 0, 'skipped' => 'niet relevant', 'values' => []];
    }

    wachthond($extdebug, 3, "[HOOK] Privacy.contactvoorkeuren gewijzigd", [
        'op'         => $op,
        'group_id'   => $group_id,
        'contact_id' => $entity_id,
    ]);

    return gdpr_sync_custom_to_core((int) $entity_id, $extdebug);
}

/**
 * D.2 voorbereiding: bewaar de oude is_opt_out bij Contact edit.
 */
function gdpr_sync_remember_contact_before_core_update($op, $object_name, $object_id, &$params, $extdebug = 'gdpr.sync'): void {
    if ($op !== 'edit' || !_gdpr_sync_is_contact_object((string) $object_name) || (int) $object_id <= 0) {
        return;
    }

    // PERFORMANCE-GUARD: deze pre-hook vuurt bij ELKE contact-edit (ook bulk-syncs die
    // duizenden contacten aanraken). Alleen als de mutatie is_opt_out zelf bevat kán de
    // vlag wijzigen; in alle andere gevallen slaan we de APIv4-leescall volledig over.
    if (!is_array($params) || !array_key_exists('is_opt_out', $params)) {
        return;
    }

    $contact_id = (int) $object_id;
    if (_gdpr_sync_is_busy($contact_id)) {
        return;
    }

    $current = _gdpr_sync_read_contact_flags($contact_id, $extdebug);
    if ($current === NULL) {
        return;
    }

    \Civi::$statics['gdpr']['previous_is_opt_out'][$contact_id] = (int) $current['is_opt_out'];
}

/**
 * D.2 Core -> custom: native CiviMail unsubscribe zet custom voorkeur op 33.
 *
 * @return array{contact_id:int,rows:int,skipped:?string,values:array}
 */
function gdpr_sync_core_to_custom_from_contact_post($op, $object_name, $object_id, &$object_ref, $extdebug = 'gdpr.sync'): array {
    if ($op !== 'edit' || !_gdpr_sync_is_contact_object((string) $object_name) || (int) $object_id <= 0) {
        return ['contact_id' => (int) $object_id, 'rows' => 0, 'skipped' => 'niet relevant', 'values' => []];
    }

    $contact_id = (int) $object_id;
    if (_gdpr_sync_is_busy($contact_id)) {
        return ['contact_id' => $contact_id, 'rows' => 0, 'skipped' => 'busy', 'values' => []];
    }

    $old_opt_out = \Civi::$statics['gdpr']['previous_is_opt_out'][$contact_id] ?? NULL;
    unset(\Civi::$statics['gdpr']['previous_is_opt_out'][$contact_id]);
    if ($old_opt_out === NULL || (int) $old_opt_out !== 0) {
        return ['contact_id' => $contact_id, 'rows' => 0, 'skipped' => 'geen 0->1 overgang', 'values' => []];
    }

    $new_opt_out = _gdpr_sync_object_ref_value($object_ref, 'is_opt_out');
    if ($new_opt_out === NULL) {
        $current = _gdpr_sync_read_contact_flags($contact_id, $extdebug);
        $new_opt_out = $current === NULL ? NULL : $current['is_opt_out'];
    }

    if ((int) $new_opt_out !== 1) {
        return ['contact_id' => $contact_id, 'rows' => 0, 'skipped' => 'geen 0->1 overgang', 'values' => []];
    }

    $voorkeur = _gdpr_sync_read_contactvoorkeuren($contact_id, $extdebug);
    if (in_array((int) $voorkeur, [33, 44], TRUE)) {
        return ['contact_id' => $contact_id, 'rows' => 0, 'skipped' => 'voorkeur al sterk genoeg', 'values' => []];
    }

    _gdpr_sync_write_contactvoorkeuren($contact_id, 33, $extdebug);

    // AUDITSPOOR: leg vast dat een native opt-out (bv. CiviMail-unsubscribe of een
    // vinkje op het contactscherm) is doorvertaald naar de PRIVACY-tab. De eventuele
    // core-vlag-correctie hieronder legt zichzelf apart vast via gdpr_sync_custom_to_core.
    _gdpr_create_cleanup_activity(
        ['contact_id' => $contact_id],
        'GDPR opt-out overgenomen als Contactvoorkeuren 33',
        "Dit contact heeft zich via een CiviCRM opt-out afgemeld voor bulk-mailings "
        . "(is_opt_out 0 -> 1, bv. een unsubscribe-link of het privacy-vinkje). "
        . "De voorkeur op de PRIVACY-tab was nog niet 33/44 en is bijgewerkt naar "
        . "33 (Geen mailings), zodat beide administraties hetzelfde zeggen.",
        142,
        $extdebug
    );

    $sync = gdpr_sync_custom_to_core($contact_id, $extdebug);

    return [
        'contact_id' => $contact_id,
        'rows'       => 1,
        'skipped'    => NULL,
        'values'     => ['contactvoorkeuren_1417' => 33, 'core_sync' => $sync],
    ];
}

/**
 * D.4 Backfill: corrigeer bestaande contacten met voorkeur 33/44 en afwijkende core-vlaggen.
 *
 * @param bool        $dry_run  TRUE = alleen tellen, niets muteren.
 * @param int|string  $extdebug Wachthond-kanaal.
 *
 * @return array{dry_run:bool,totaal_rijen:int,stappen:array}
 */
function gdpr_sync_backfill(bool $dry_run = FALSE, $extdebug = 'gdpr.syncbackfill'): array {

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GDPR SB.0 PRIVACY-SYNC BACKFILL", $dry_run ? "[DRY-RUN]" : "[RUN]");
    wachthond($extdebug, 2, "########################################################################");

    $select_sql = "SELECT C.id AS contact_id, C.display_name, C.is_opt_out, C.do_not_email,
       G.contactvoorkeuren_1417 AS contactvoorkeuren
FROM civicrm_contact C
INNER JOIN civicrm_value_privacy_286 G ON G.entity_id = C.id
WHERE G.contactvoorkeuren_1417 IN (33,44)
  AND (
    (G.contactvoorkeuren_1417 = 33 AND (COALESCE(C.is_opt_out,0) != 1 OR COALESCE(C.do_not_email,0) != 0))
    OR
    (G.contactvoorkeuren_1417 = 44 AND (COALESCE(C.is_opt_out,0) != 1 OR COALESCE(C.do_not_email,0) != 1))
  )";

    $label = 'SB.1 privacy voorkeur 33/44 terugschrijven naar core-vlaggen';
    if ($dry_run) {
        $count_sql = _gdpr_count_sql_from_select($select_sql);
        wachthond($extdebug, 3, "[DRY-RUN] tel kandidaten: $label", $count_sql);
        $rows = (int) CRM_Core_DAO::singleValueQuery($count_sql);
        $stappen = [['label' => $label, 'dry_run' => TRUE, 'rows' => $rows]];
    }
    else {
        wachthond($extdebug, 3, "[RUN] selecteer kandidaten: $label", $select_sql);
        // fetchAll() i.p.v. storeValues(): generieke executeQuery-DAO kent geen fields()
        // voor de SELECT-aliassen, waardoor storeValues() een lege $row zou opleveren.
        $rijen = CRM_Core_DAO::executeQuery($select_sql)->fetchAll();
        $rows  = 0;
        foreach ($rijen as $row) {
            wachthond($extdebug, 3, "[RUN] backfill kandidaat", $row);
            // stuur_mail=FALSE: de reconciliatie corrigeert stilletjes; alleen een échte
            // voorkeur-wijziging via de hook mag de bevestigingsmail triggeren.
            $result = gdpr_sync_custom_to_core((int) $row['contact_id'], $extdebug, FALSE);
            if (!empty($result['rows'])) {
                $rows++;
            }
        }
        $stappen = [['label' => $label, 'dry_run' => FALSE, 'rows' => $rows]];
    }

    $totaal_rijen = array_sum(array_column($stappen, 'rows'));
    $samenvatting = [
        'dry_run'      => $dry_run,
        'totaal_rijen' => $totaal_rijen,
        'stappen'      => $stappen,
    ];

    wachthond($extdebug, 1, "### GDPR SB.0 PRIVACY-SYNC BACKFILL KLAAR", $samenvatting);
    return $samenvatting;
}
