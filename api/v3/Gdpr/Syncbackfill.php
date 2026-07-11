<?php

require_once dirname(__DIR__, 3) . '/gdpr.logic.privacy.php';

/**
 * =======================================================================================
 * APIv3 actie: Gdpr.syncbackfill
 * =======================================================================================
 * Eenmalige backfill voor bestaande contacten met Privacy.contactvoorkeuren 33/44 waarvan
 * de core-vlaggen is_opt_out/do_not_email nog afwijken.
 *
 * Parameters:
 *   dry_run  1 = alleen kandidaten tellen, niets muteren (default 0 = echt corrigeren).
 * =======================================================================================
 */

/**
 * Spec van de parameters.
 */
function _civicrm_api3_gdpr_syncbackfill_spec(&$spec) {
    $spec['dry_run'] = [
        'title'        => 'Dry run',
        'description'  => '1 = alleen berekenen/tellen, niets corrigeren',
        'type'         => CRM_Utils_Type::T_BOOLEAN,
        'api.default'  => 0,
    ];
}

/**
 * Gdpr.syncbackfill — corrigeert bestaande privacy/core-afwijkingen.
 */
function civicrm_api3_gdpr_syncbackfill($params) {

    $extdebug = 'gdpr.api';
    $dry_run  = !empty($params['dry_run']);

    wachthond($extdebug, 1, "### GDPR API syncbackfill", "dry_run=" . ($dry_run ? '1' : '0'));

    $samenvatting = gdpr_sync_backfill($dry_run, $extdebug);

    wachthond($extdebug, 1, "### GDPR API syncbackfill KLAAR", $samenvatting);

    return civicrm_api3_create_success([$samenvatting], $params, 'Gdpr', 'syncbackfill');
}
