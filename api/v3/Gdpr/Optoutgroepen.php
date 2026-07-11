<?php

require_once dirname(__DIR__, 3) . '/gdpr.logic.optout.php';

/**
 * =======================================================================================
 * APIv3 actie: Gdpr.optoutgroepen
 * =======================================================================================
 * Draait sqltask 11 in extensievorm: opt-out-contacten worden uit publieke
 * nieuwsbriefgroepen gezet via GroupContact.update.
 *
 * Parameters:
 *   dry_run  1 = alleen kandidaten tellen, niets muteren (default 0 = echt draaien).
 * =======================================================================================
 */

/**
 * Spec van de parameters.
 */
function _civicrm_api3_gdpr_optoutgroepen_spec(&$spec) {
    $spec['dry_run'] = [
        'title'        => 'Dry run',
        'description'  => '1 = alleen berekenen/tellen, niets uit groepen verwijderen',
        'type'         => CRM_Utils_Type::T_BOOLEAN,
        'api.default'  => 0,
    ];
}

/**
 * Gdpr.optoutgroepen — draait de opt-out-groepenmigratie en geeft een samenvatting terug.
 */
function civicrm_api3_gdpr_optoutgroepen($params) {

    $extdebug = 'gdpr.api';
    $dry_run  = !empty($params['dry_run']);

    wachthond($extdebug, 1, "### GDPR API optoutgroepen", "dry_run=" . ($dry_run ? '1' : '0'));

    $samenvatting = gdpr_optout_groepen_run($dry_run, $extdebug);

    wachthond($extdebug, 1, "### GDPR API optoutgroepen KLAAR", $samenvatting);

    return civicrm_api3_create_success([$samenvatting], $params, 'Gdpr', 'optoutgroepen');
}
