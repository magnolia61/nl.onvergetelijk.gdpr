<?php

namespace Civi\Gdpr;

use Civi\Test\EndToEndInterface;
use Civi\Test\TransactionalInterface;

/**
 * End-to-end test voor de bidirectionele GDPR privacy-sync.
 *
 * @group e2e
 */
class GdprPrivacySyncTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface, TransactionalInterface {

  public function setUp(): void {
    parent::setUp();

    // De extensie draait op productie (nog) uitgeschakeld; laad de helper/logic direct.
    if (!function_exists('gdpr_sync_custom_to_core')) {
      require_once __DIR__ . '/../../../../gdpr.helpers.php';
      require_once __DIR__ . '/../../../../gdpr.logic.privacy.php';
    }
    if (!function_exists('gdpr_sync_custom_to_core')) {
      $this->markTestSkipped('gdpr_sync_custom_to_core() niet beschikbaar.');
    }

    // Nooit echt mailen vanuit een testrun (zie _gdpr_stuur_uitstel_bevestiging).
    \Civi::$statics['gdpr']['mail_onderdrukt'] = TRUE;
  }

  public function tearDown(): void {
    unset(\Civi::$statics['gdpr']['mail_onderdrukt']);
    parent::tearDown();
  }

  /**
   * Maak een testcontact met expliciete core-vlaggen.
   */
  private function maakContact(int $isOptOut = 0, int $doNotEmail = 0): int {
    $result_contact_create = civicrm_api4('Contact', 'create', [
      'checkPermissions' => FALSE,
      'values'           => [
        'contact_type' => 'Individual',
        'first_name'   => 'GdprTest',
        'last_name'    => 'PrivacySync_' . uniqid(),
        'is_opt_out'   => $isOptOut,
        'do_not_email' => $doNotEmail,
      ],
    ]);

    return (int) $result_contact_create->first()['id'];
  }

  /**
   * Zet Privacy.contactvoorkeuren_1417 voor een contact.
   */
  private function zetPrivacyVoorkeur(int $cid, int $voorkeur): void {
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO `civicrm_value_privacy_286` (entity_id, contactvoorkeuren_1417)
       VALUES (%1, %2)
       ON DUPLICATE KEY UPDATE contactvoorkeuren_1417 = VALUES(contactvoorkeuren_1417)",
      [
        1 => [$cid, 'Integer'],
        2 => [$voorkeur, 'String'],
      ]
    );
  }

  /**
   * Lees Privacy.contactvoorkeuren_1417.
   */
  private function leesPrivacyVoorkeur(int $cid): ?int {
    $value = \CRM_Core_DAO::singleValueQuery(
      "SELECT contactvoorkeuren_1417
       FROM `civicrm_value_privacy_286`
       WHERE entity_id = %1",
      [1 => [$cid, 'Integer']]
    );

    return $value === NULL || $value === '' ? NULL : (int) $value;
  }

  /**
   * Lees core-vlaggen terug.
   *
   * @return array{is_opt_out:int,do_not_email:int}
   */
  private function leesCoreFlags(int $cid): array {
    $result_contact_get = civicrm_api4('Contact', 'get', [
      'checkPermissions' => FALSE,
      'select'           => ['is_opt_out', 'do_not_email'],
      'where'            => [
        ['id', '=', $cid],
      ],
      'limit'            => 1,
    ]);
    $row = $result_contact_get->first();

    return [
      'is_opt_out'  => (int) !empty($row['is_opt_out']),
      'do_not_email' => (int) !empty($row['do_not_email']),
    ];
  }

  /**
   * Tel GDPR-cleanup-activities (type 142) voor een contact met een subject-prefix.
   */
  private function telActivity(int $contactId, string $subjectPrefix): int {
    // COUNT(DISTINCT ...): het contact is source én target van dezelfde activity
    // (2 activity_contact-rijen), we willen unieke activities tellen.
    return (int) \CRM_Core_DAO::singleValueQuery(
      "SELECT COUNT(DISTINCT A.id)
       FROM civicrm_activity A
       INNER JOIN civicrm_activity_contact AC ON AC.activity_id = A.id
       WHERE A.activity_type_id = 142
         AND A.subject LIKE %2
         AND AC.contact_id = %1",
      [
        1 => [$contactId, 'Integer'],
        2 => [$subjectPrefix . '%', 'String'],
      ]
    );
  }

  // ########################################################################
  // ### SCENARIO: CUSTOM -> CORE
  // ########################################################################

  /**
   * Voorkeur 44 zet core is_opt_out=1 en do_not_email=1, mét auditspoor-activity.
   */
  public function testVoorkeur44ZetCoreFlags() {
    $cid = $this->maakContact(0, 0);
    $this->zetPrivacyVoorkeur($cid, 44);

    $result = gdpr_sync_custom_to_core($cid, 'gdpr.test');
    $flags  = $this->leesCoreFlags($cid);

    $this->assertSame(1, $result['rows'], 'Eerste sync moet core-vlaggen wijzigen.');
    $this->assertSame(1, $flags['is_opt_out'], 'Voorkeur 44 moet is_opt_out=1 zetten.');
    $this->assertSame(1, $flags['do_not_email'], 'Voorkeur 44 moet do_not_email=1 zetten.');
    $this->assertSame(1, $this->telActivity($cid, 'GDPR voorkeur-sync'),
      'Elke sync-mutatie moet een activity 142 vastleggen.');
  }

  /**
   * BELEIDSREGEL: voorkeur 44 mét actieve kampregistratie stelt do_not_email uit
   * (alleen is_opt_out), en triggert de bevestigingsmail (in testomgeving: skip-status).
   */
  public function testVoorkeur44MetActieveRegistratieSteltDoNotEmailUit() {
    $cid = $this->maakContact(0, 0);
    $this->zetPrivacyVoorkeur($cid, 44);
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO `civicrm_value_ditjaar_199` (entity_id, ditjaar_event_start_1155)
       VALUES (%1, NOW())
       ON DUPLICATE KEY UPDATE ditjaar_event_start_1155 = NOW()",
      [1 => [$cid, 'Integer']]
    );

    $result = gdpr_sync_custom_to_core($cid, 'gdpr.test');
    $flags  = $this->leesCoreFlags($cid);

    $this->assertSame(1, $flags['is_opt_out'], 'Bulk moet direct dicht (is_opt_out=1).');
    $this->assertSame(0, $flags['do_not_email'],
      'do_not_email moet UITGESTELD worden zolang er een actieve registratie is.');
    $this->assertSame('onderdrukt_test', $result['mail']['status'] ?? NULL,
      'De bevestigingsmail moet getriggerd worden (en in de testomgeving worden overgeslagen).');
    $this->assertSame(1, $this->telActivity($cid, 'GDPR voorkeur-sync'),
      'Ook het uitstel-scenario moet een activity 142 vastleggen.');

    // Backfill-context mag de mail nooit triggeren: eerst vlag terugdraaien zodat er
    // opnieuw iets te syncen valt, dan syncen met stuur_mail=FALSE.
    civicrm_api4('Contact', 'update', [
      'checkPermissions' => FALSE,
      'where'            => [['id', '=', $cid]],
      'values'           => ['is_opt_out' => 0],
    ]);
    $stil = gdpr_sync_custom_to_core($cid, 'gdpr.test', FALSE);
    $this->assertSame(1, $stil['rows'], 'Reconciliatie moet de vlag opnieuw zetten.');
    $this->assertNull($stil['mail'], 'Reconciliatie (stuur_mail=FALSE) mag geen mail triggeren.');
  }

  /**
   * Voorkeur 33 zet core is_opt_out=1 en do_not_email=0.
   */
  public function testVoorkeur33ZetOptoutMaarGeenDoNotEmail() {
    $cid = $this->maakContact(0, 1);
    $this->zetPrivacyVoorkeur($cid, 33);

    gdpr_sync_custom_to_core($cid, 'gdpr.test');
    $flags = $this->leesCoreFlags($cid);

    $this->assertSame(1, $flags['is_opt_out'], 'Voorkeur 33 moet is_opt_out=1 zetten.');
    $this->assertSame(0, $flags['do_not_email'], 'Voorkeur 33 moet do_not_email=0 zetten.');
  }

  // ########################################################################
  // ### SCENARIO: CORE -> CUSTOM
  // ########################################################################

  /**
   * Native opt-out 0->1 vult voorkeur 33, tenzij er al 33/44 staat.
   */
  public function testCoreOptoutNaarCustomVoorkeur33() {
    $cid = $this->maakContact(0, 0);
    $this->zetPrivacyVoorkeur($cid, 11);

    $params = ['is_opt_out' => 1];
    gdpr_sync_remember_contact_before_core_update('edit', 'Individual', $cid, $params, 'gdpr.test');

    civicrm_api4('Contact', 'update', [
      'checkPermissions' => FALSE,
      'where'            => [
        ['id', '=', $cid],
      ],
      'values'           => [
        'is_opt_out' => 1,
      ],
    ]);

    $objectRef = ['id' => $cid, 'is_opt_out' => 1];
    $result    = gdpr_sync_core_to_custom_from_contact_post('edit', 'Individual', $cid, $objectRef, 'gdpr.test');
    $flags     = $this->leesCoreFlags($cid);

    $this->assertSame(1, $result['rows'], 'Core 0->1 moet een custom voorkeur schrijven.');
    $this->assertSame(33, $this->leesPrivacyVoorkeur($cid), 'Core opt-out moet voorkeur 33 zetten.');
    $this->assertSame(1, $flags['is_opt_out'], 'Core opt-out moet is_opt_out=1 houden.');
    $this->assertSame(0, $flags['do_not_email'], 'Voorkeur 33 moet do_not_email=0 afdwingen.');
    $this->assertSame(1, $this->telActivity($cid, 'GDPR opt-out overgenomen'),
      'De voorkeur-overname moet een activity 142 vastleggen.');
  }

  /**
   * Een tweede custom->core sync is idempotent: geen extra mutatie én geen extra activity.
   */
  public function testCustomSyncIsIdempotent() {
    $cid = $this->maakContact(0, 0);
    $this->zetPrivacyVoorkeur($cid, 44);

    $eerste = gdpr_sync_custom_to_core($cid, 'gdpr.test');
    $tweede = gdpr_sync_custom_to_core($cid, 'gdpr.test');

    $this->assertSame(1, $eerste['rows'], 'Eerste sync moet core-vlaggen wijzigen.');
    $this->assertSame(0, $tweede['rows'], 'Tweede sync mag niets extra muteren.');
    $this->assertFalse(_gdpr_sync_is_busy($cid), 'Re-entrancy guard moet na sync vrijgegeven zijn.');
    $this->assertSame(1, $this->telActivity($cid, 'GDPR voorkeur-sync'),
      'Een idempotente tweede sync mag géén extra activity aanmaken.');
  }
}
