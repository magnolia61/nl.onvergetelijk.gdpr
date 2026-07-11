<?php

/**
 * =======================================================================================
 * APIv3 actie: Gdpr.cleanup
 * =======================================================================================
 * Draait de AVG-dataminimalisatie (opschoning van medische/gedrags-/contactgegevens van
 * oud-deelnemers na afloop van de bewaartermijn). Dit is de gemigreerde tegenhanger van
 * de losse database-taak `sqltask 7`.
 *
 * De feitelijke logica staat in gdpr.helpers.php (gdpr_cleanup_run). Deze wrapper maakt
 * dat de opschoning vanuit een CiviCRM Scheduled Job (zie gdpr.mgd.php) of handmatig via
 * `cv api3 Gdpr.cleanup dry_run=1` gedraaid kan worden.
 *
 * Parameters:
 *   dry_run  1 = alleen kandidaten tellen, niets muteren (default 0 = echt opschonen).
 * =======================================================================================
 */

/**
 * Spec van de parameters.
 */
function _civicrm_api3_gdpr_cleanup_spec(&$spec) {
    $spec['dry_run'] = [
        'title'        => 'Dry run',
        'description'  => '1 = alleen berekenen/tellen, niets verwijderen of anonimiseren',
        'type'         => CRM_Utils_Type::T_BOOLEAN,
        'api.default'  => 0,
    ];
}

/**
 * Gdpr.cleanup — draait de opschoning en geeft een samenvatting per stap terug.
 */
function civicrm_api3_gdpr_cleanup($params) {

    $extdebug = 'gdpr.api';
    $dry_run  = !empty($params['dry_run']);

    wachthond($extdebug, 1, "### GDPR API cleanup", "dry_run=" . ($dry_run ? '1' : '0'));

    // Roep de hoofdmotor aan (alle vier de opschoongroepen).
    $samenvatting = gdpr_cleanup_run($dry_run, $extdebug);

    wachthond($extdebug, 1, "### GDPR API cleanup KLAAR", $samenvatting);

    return civicrm_api3_create_success([$samenvatting], $params, 'Gdpr', 'cleanup');
}
