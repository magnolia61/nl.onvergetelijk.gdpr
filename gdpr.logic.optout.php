<?php

require_once __DIR__ . '/gdpr.helpers.php';

/**
 * =======================================================================================
 * GDPR OPTOUT-GROEPEN LOGICA
 * =======================================================================================
 * Migreert sqltask 11: contacten met core opt-out mogen niet actief in publieke
 * nieuwsbriefgroepen blijven staan. De GroupContact wordt via APIv4 op Removed gezet
 * en activity type 142 legt vast uit welke groep het contact is verwijderd.
 * =======================================================================================
 */

/**
 * APIv4 GroupContact.update voor een opt-out-contact in een publieke nieuwsbriefgroep.
 */
function _gdpr_optout_groepen_groupcontact_removed(array $row, $extdebug): bool {
    $params = [
        'checkPermissions' => FALSE,
        'where'            => [
            ['contact_id', '=', (int) $row['contact_id']],
            ['group_id', '=', (int) $row['group_id']],
        ],
        'values'           => [
            'status' => 'Removed',
        ],
    ];
    wachthond($extdebug, 7, "APIv4 GroupContact.update params", $params);
    $result = civicrm_api4('GroupContact', 'update', $params);
    wachthond($extdebug, 9, "APIv4 GroupContact.update resultaat", $result);
    return TRUE;
}

/**
 * =======================================================================================
 * GDPR OG.0  OPTOUT-GROEPEN HOOFDMOTOR
 * =======================================================================================
 *
 * @param bool        $dry_run  TRUE = alleen tellen, niets muteren.
 * @param int|string  $extdebug Wachthond-kanaal.
 *
 * @return array{dry_run:bool,totaal_rijen:int,stappen:array}
 */
function gdpr_optout_groepen_run(bool $dry_run = FALSE, $extdebug = 'gdpr.optoutgroepen'): array {

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GDPR OG.0 OPTOUT-GROEPEN MOTOR", $dry_run ? "[DRY-RUN]" : "[RUN]");
    wachthond($extdebug, 2, "########################################################################");

    // OG.1 Contacten met opt-out verwijderen uit publieke nieuwsbriefgroepen (task 11).
    $optout_groepen_sql = "SELECT C.id AS contact_id, C.display_name, GR.id AS group_id, GR.title AS group_title, E.email
FROM civicrm_contact C
LEFT JOIN civicrm_group_contact GC ON GC.contact_id = C.id
LEFT JOIN civicrm_group GR ON GR.id = GC.group_id
INNER JOIN civicrm_email E ON E.contact_id = C.id
WHERE C.is_opt_out = 1
  AND (C.is_deleted IS NULL OR C.is_deleted = 0)
  AND GC.status = 'Added'
  AND GR.group_type LIKE '%,2,%'
  AND GR.visibility = 'Public Pages'
  AND E.is_primary = 1";

    $stappen   = [];
    $stappen[] = _gdpr_exec_removerequest($dry_run,
        'OG.1 opt-out-contact uit publieke nieuwsbriefgroep verwijderen',
        $optout_groepen_sql,
        '_gdpr_optout_groepen_groupcontact_removed',
        'GDPR Optout uit {group_title}',
        142,
        $extdebug);

    $totaal_rijen = array_sum(array_column($stappen, 'rows'));
    $samenvatting = [
        'dry_run'      => $dry_run,
        'totaal_rijen' => $totaal_rijen,
        'stappen'      => $stappen,
    ];

    wachthond($extdebug, 1, "### GDPR OG.0 OPTOUT-GROEPEN MOTOR KLAAR", $samenvatting);
    return $samenvatting;
}
