<?php

require_once dirname(__DIR__, 3) . '/gdpr.logic.removerequest.php';

/**
 * =======================================================================================
 * APIv3 actie: Gdpr.removerequest
 * =======================================================================================
 * Draait de remove-request-logica voor contacten met Privacy.contactvoorkeuren = 44.
 * Migreert de sqltasks 106/112/113/114/163 naar de extensie.
 *
 * Parameters:
 *   dry_run  1 = alleen kandidaten tellen, niets muteren (default 0 = echt draaien).
 * =======================================================================================
 */

/**
 * Spec van de parameters.
 */
function _civicrm_api3_gdpr_removerequest_spec(&$spec) {
    $spec['dry_run'] = [
        'title'        => 'Dry run',
        'description'  => '1 = alleen berekenen/tellen, niets verwijderen of on-hold zetten',
        'type'         => CRM_Utils_Type::T_BOOLEAN,
        'api.default'  => 0,
    ];
}

/**
 * Gdpr.removerequest — draait de remove-request-migratie en geeft een samenvatting terug.
 */
function civicrm_api3_gdpr_removerequest($params) {

    $extdebug = 'gdpr.api';
    $dry_run  = !empty($params['dry_run']);

    wachthond($extdebug, 1, "### GDPR API removerequest", "dry_run=" . ($dry_run ? '1' : '0'));

    $samenvatting = gdpr_removerequest_run($dry_run, $extdebug);

    wachthond($extdebug, 1, "### GDPR API removerequest KLAAR", $samenvatting);

    return civicrm_api3_create_success([$samenvatting], $params, 'Gdpr', 'removerequest');
}
