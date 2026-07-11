<?php

require_once 'gdpr.civix.php';

// AVG/GDPR opschoon-helpers (dataminimalisatie, gemigreerd uit sqltask 7).
require_once 'gdpr.helpers.php';
require_once 'gdpr.logic.removerequest.php';
require_once 'gdpr.logic.optout.php';
require_once 'gdpr.logic.privacy.php';

use CRM_Gdpr_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function gdpr_civicrm_config(&$config): void {
  _gdpr_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function gdpr_civicrm_install(): void {
  _gdpr_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function gdpr_civicrm_enable(): void {
  _gdpr_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_custom().
 *
 * Privacy.contactvoorkeuren_1417 is de bron van waarheid voor de afgeleide core-vlaggen.
 */
function gdpr_civicrm_custom($op, $groupID, $entityID, &$params): void {
  gdpr_sync_custom_hook($op, $groupID, $entityID, $params, 'gdpr.sync');
}

/**
 * Implements hook_civicrm_pre().
 *
 * Bewaart de oude opt-out-status zodat hook_civicrm_post een echte 0->1 overgang kan zien.
 */
function gdpr_civicrm_pre($op, $objectName, $id, &$params): void {
  gdpr_sync_remember_contact_before_core_update($op, $objectName, $id, $params, 'gdpr.sync');
}

/**
 * Implements hook_civicrm_post().
 *
 * Native CiviMail unsubscribe (core is_opt_out 0->1) vult de custom voorkeur aan met 33,
 * tenzij de voorkeur al 33 of 44 is.
 */
function gdpr_civicrm_post($op, $objectName, $objectId, &$objectRef): void {
  gdpr_sync_core_to_custom_from_contact_post($op, $objectName, $objectId, $objectRef, 'gdpr.sync');
}
