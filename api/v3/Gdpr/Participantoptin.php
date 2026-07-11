<?php

/**
 * =======================================================================================
 * APIv3 actie: Gdpr.participantoptin
 * =======================================================================================
 * Consent-override bij kampdeelname: zet do_not_email uit (maar is_opt_out aan) en
 * haalt e-mail-on-holds weg voor deelnemers/leiding met een registratie in het lopende
 * kampwindow, zodat praktische kampmail aankomt terwijl bulk dicht blijft.
 * Gemigreerde en gecorrigeerde tegenhanger van sqltasks 97/108.
 *
 * Parameters:
 *   dry_run  1 = alleen kandidaten tellen, niets muteren (default 0).
 * =======================================================================================
 */

/**
 * Spec van de parameters.
 */
function _civicrm_api3_gdpr_participantoptin_spec(&$spec) {
    $spec['dry_run'] = [
        'title'        => 'Dry run',
        'description'  => '1 = alleen berekenen/tellen, niets muteren',
        'type'         => CRM_Utils_Type::T_BOOLEAN,
        'api.default'  => 0,
    ];
}

/**
 * Gdpr.participantoptin — draait de consent-override en geeft een samenvatting terug.
 */
function civicrm_api3_gdpr_participantoptin($params) {

    $extdebug = 'gdpr.api';
    $dry_run  = !empty($params['dry_run']);

    wachthond($extdebug, 1, "### GDPR API participantoptin", "dry_run=" . ($dry_run ? '1' : '0'));

    $samenvatting = gdpr_participantoptin_run($dry_run, $extdebug);

    wachthond($extdebug, 1, "### GDPR API participantoptin KLAAR", $samenvatting);

    return civicrm_api3_create_success([$samenvatting], $params, 'Gdpr', 'participantoptin');
}
