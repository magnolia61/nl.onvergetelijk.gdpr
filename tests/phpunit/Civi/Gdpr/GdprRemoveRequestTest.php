<?php

namespace Civi\Gdpr;

use Civi\Test\EndToEndInterface;
use Civi\Test\TransactionalInterface;

/**
 * End-to-end test voor de GDPR remove-request-logica (voorkeur 44).
 *
 * @group e2e
 */
class GdprRemoveRequestTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface, TransactionalInterface {

  public function setUp(): void {
    parent::setUp();

    // De extensie draait op productie (nog) uitgeschakeld; laad de helper/logic direct.
    if (!function_exists('gdpr_removerequest_run')) {
      require_once __DIR__ . '/../../../../gdpr.helpers.php';
      require_once __DIR__ . '/../../../../gdpr.logic.removerequest.php';
    }
    if (!function_exists('gdpr_removerequest_run')) {
      $this->markTestSkipped('gdpr_removerequest_run() niet beschikbaar.');
    }
  }

  /**
   * Maak een uniek testcontact.
   */
  private function maakContact(string $suffix): int {
    $result_contact_create = civicrm_api4('Contact', 'create', [
      'checkPermissions' => FALSE,
      'values'           => [
        'contact_type' => 'Individual',
        'first_name'   => 'GdprTest',
        'last_name'    => $suffix . '_' . uniqid(),
        'is_opt_out'   => 0,
        'do_not_email' => 0,
      ],
    ]);

    return (int) $result_contact_create->first()['id'];
  }

  /**
   * Zet Privacy.contactvoorkeuren_1417 voor een contact.
   */
  private function zetPrivacyVoorkeur(int $cid, int $voorkeur): void {
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO `civicrm_value_privacy_286`
              (entity_id, contactvoorkeuren_1417, datum_update_gdpr_1418, opmerkingen_gdpr_1419)
       VALUES (%1, %2, NOW(), %3)
       ON DUPLICATE KEY UPDATE contactvoorkeuren_1417 = VALUES(contactvoorkeuren_1417),
                               datum_update_gdpr_1418 = VALUES(datum_update_gdpr_1418),
                               opmerkingen_gdpr_1419  = VALUES(opmerkingen_gdpr_1419)",
      [
        1 => [$cid, 'Integer'],
        2 => [$voorkeur, 'String'],
        3 => ['GDPR phpunit remove request', 'String'],
      ]
    );
  }

  /**
   * Zorg dat ditjaar_event_start_1155 NULL is voor guards in related/adres-SQL.
   */
  private function zetDitjaarNull(int $cid): void {
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO `civicrm_value_ditjaar_199` (entity_id, ditjaar_event_start_1155)
       VALUES (%1, NULL)
       ON DUPLICATE KEY UPDATE ditjaar_event_start_1155 = NULL",
      [1 => [$cid, 'Integer']]
    );
  }

  /**
   * Maak een e-mailadres aan.
   */
  private function maakEmail(int $cid, string $email, int $locationType = 1, int $onHold = 0): int {
    $result_email_create = civicrm_api4('Email', 'create', [
      'checkPermissions' => FALSE,
      'values'           => [
        'contact_id'       => $cid,
        'email'            => $email,
        'location_type_id' => $locationType,
        'is_primary'       => 1,
        'on_hold'          => $onHold,
      ],
    ]);

    return (int) $result_email_create->first()['id'];
  }

  /**
   * Maak een telefoonnummer aan.
   */
  private function maakTelefoon(int $cid, string $phone, int $locationType = 1): int {
    $result_phone_create = civicrm_api4('Phone', 'create', [
      'checkPermissions' => FALSE,
      'values'           => [
        'contact_id'       => $cid,
        'phone'            => $phone,
        'location_type_id' => $locationType,
        'is_primary'       => 1,
      ],
    ]);

    return (int) $result_phone_create->first()['id'];
  }

  /**
   * Controleer of een e-mailrij nog bestaat.
   */
  private function emailBestaat(int $emailId): bool {
    $result_email_get = civicrm_api4('Email', 'get', [
      'checkPermissions' => FALSE,
      'select'           => ['id'],
      'where'            => [
        ['id', '=', $emailId],
      ],
      'limit'            => 1,
    ]);

    return !empty($result_email_get->first());
  }

  /**
   * Controleer of een telefoonrij nog bestaat.
   */
  private function telefoonBestaat(int $phoneId): bool {
    $result_phone_get = civicrm_api4('Phone', 'get', [
      'checkPermissions' => FALSE,
      'select'           => ['id'],
      'where'            => [
        ['id', '=', $phoneId],
      ],
      'limit'            => 1,
    ]);

    return !empty($result_phone_get->first());
  }

  /**
   * Lees de on_hold-status van een e-mailadres.
   */
  private function leesEmailOnHold(int $emailId): int {
    $result_email_get = civicrm_api4('Email', 'get', [
      'checkPermissions' => FALSE,
      'select'           => ['on_hold'],
      'where'            => [
        ['id', '=', $emailId],
      ],
      'limit'            => 1,
    ]);

    return (int) $result_email_get->first()['on_hold'];
  }

  /**
   * Tel cleanup-activities voor een contact en subject.
   */
  private function telActivity(int $contactId, string $subject, ?string $location = NULL): int {
    $whereLocation = '';
    $params        = [
      1 => [$contactId, 'Integer'],
      2 => [$subject, 'String'],
    ];

    if ($location !== NULL) {
      $whereLocation = " AND A.location = %3";
      $params[3]     = [$location, 'String'];
    }

    return (int) \CRM_Core_DAO::singleValueQuery(
      "SELECT COUNT(*)
       FROM civicrm_activity A
       INNER JOIN civicrm_activity_contact AC ON AC.activity_id = A.id
       WHERE A.activity_type_id = 142
         AND A.subject = %2
         AND AC.contact_id = %1
         $whereLocation",
      $params
    );
  }

  // ########################################################################
  // ### SCENARIO: REMOVE-REQUEST E-MAIL
  // ########################################################################

  /**
   * Dry-run laat de e-mail staan; echte run verwijdert e-mail en maakt activity 142.
   */
  public function testEmailDirectDryRunEnRun() {
    $cid   = $this->maakContact('RemoveEmail');
    $email = 'gdpr-remove-' . uniqid() . '@example.invalid';
    $this->zetPrivacyVoorkeur($cid, 44);
    $this->zetDitjaarNull($cid);
    $emailId = $this->maakEmail($cid, $email, 1);

    $dryRun = gdpr_removerequest_run(TRUE, 'gdpr.test');
    $this->assertTrue($this->emailBestaat($emailId), 'Dry-run mag het e-mailadres niet verwijderen.');
    $this->assertTrue($dryRun['dry_run'], 'Samenvatting moet dry-run melden.');

    $run = gdpr_removerequest_run(FALSE, 'gdpr.test');
    $this->assertFalse($this->emailBestaat($emailId), 'Echte run moet het e-mailadres verwijderen.');
    $this->assertGreaterThanOrEqual(1, $run['totaal_rijen'], 'Run moet minstens onze kandidaat muteren.');
    $this->assertGreaterThanOrEqual(1,
      $this->telActivity($cid, 'GDPR emailadres verwijderd ivm verzoek (1)', $email),
      'E-maildelete moet een activity 142 met het verwijderde adres in location maken.');
  }

  /**
   * Hetzelfde e-mailadres bij een gerelateerd contact wordt on-hold gezet.
   */
  public function testRelatedEmailWordtOnHoldGezet() {
    $gdprCid    = $this->maakContact('RemoveEmailRequester');
    $relatedCid = $this->maakContact('RemoveEmailRelated');
    $email      = 'gdpr-related-' . uniqid() . '@example.invalid';

    $this->zetPrivacyVoorkeur($gdprCid, 44);
    $this->zetPrivacyVoorkeur($relatedCid, 11);
    $this->zetDitjaarNull($gdprCid);
    $this->zetDitjaarNull($relatedCid);

    $gdprEmailId    = $this->maakEmail($gdprCid, $email, 1);
    $relatedEmailId = $this->maakEmail($relatedCid, $email, 1, 0);

    gdpr_removerequest_run(FALSE, 'gdpr.test');

    $this->assertFalse($this->emailBestaat($gdprEmailId),
      'Directe e-mail van de verzoeker moet verwijderd zijn.');
    $this->assertSame(2, $this->leesEmailOnHold($relatedEmailId),
      'Gerelateerde e-mail moet on-hold=2 krijgen.');
  }

  /**
   * Telefoonnummer van een voorkeur-44-contact wordt via APIv4 verwijderd.
   */
  public function testTelefoonDirectWordtVerwijderd() {
    $cid   = $this->maakContact('RemovePhone');
    $phone = '06123' . random_int(10000, 99999);
    $this->zetPrivacyVoorkeur($cid, 44);
    $this->zetDitjaarNull($cid);
    $phoneId = $this->maakTelefoon($cid, $phone, 1);

    gdpr_removerequest_run(FALSE, 'gdpr.test');

    $this->assertFalse($this->telefoonBestaat($phoneId),
      'Telefoonnummer van voorkeur-44-contact moet verwijderd zijn.');
    $this->assertGreaterThanOrEqual(1,
      $this->telActivity($cid, 'GDPR telefoon verwijderd ivm verzoek', $phone),
      'Telefoondelete moet een activity 142 maken.');
  }
}
