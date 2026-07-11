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
 * Leid de core-vlaggen af uit Privacy.contactvoorkeuren_1417.
 *
 * @return array|null NULL betekent: leeg/onbekend, dus ongemoeid laten.
 */
function _gdpr_sync_flags_for_voorkeur(?int $voorkeur): ?array {
    switch ($voorkeur) {
        case 11:
        case 22:
            return ['is_opt_out' => 0, 'do_not_email' => 0];

        case 33:
            return ['is_opt_out' => 1, 'do_not_email' => 0];

        case 44:
            return ['is_opt_out' => 1, 'do_not_email' => 1];
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
 * D.1 Custom -> core: leid core-vlaggen af uit de custom voorkeur.
 *
 * @return array{contact_id:int,rows:int,skipped:?string,values:array}
 */
function gdpr_sync_custom_to_core(int $contact_id, $extdebug = 'gdpr.sync'): array {
    if (_gdpr_sync_is_busy($contact_id)) {
        return ['contact_id' => $contact_id, 'rows' => 0, 'skipped' => 'busy', 'values' => []];
    }

    $voorkeur = _gdpr_sync_read_contactvoorkeuren($contact_id, $extdebug);
    $flags    = _gdpr_sync_flags_for_voorkeur($voorkeur);
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

    return ['contact_id' => $contact_id, 'rows' => 1, 'skipped' => NULL, 'values' => $values];
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
            $result = gdpr_sync_custom_to_core((int) $row['contact_id'], $extdebug);
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
