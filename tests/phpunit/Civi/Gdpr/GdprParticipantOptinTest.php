<?php

namespace Civi\Gdpr;

use Civi\Test\EndToEndInterface;
use Civi\Test\TransactionalInterface;

/**
 * End-to-end test voor de participant-optin (consent-override bij kampdeelname).
 *
 * @group e2e
 *
 * Bewijst de beleidscorrectie t.o.v. bron-sqltask 108: do_not_email gaat uit zodat
 * praktische kampmail aankomt, maar is_opt_out gaat AAN zodat bulk dicht blijft.
 */
class GdprParticipantOptinTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface, TransactionalInterface {

  public function setUp(): void {
    parent::setUp();

    if (!function_exists('gdpr_participantoptin_run')) {
      require_once __DIR__ . '/../../../../gdpr.helpers.php';
      require_once __DIR__ . '/../../../../gdpr.logic.optin.php';
    }
    if (!function_exists('gdpr_participantoptin_run')) {
      $this->markTestSkipped('gdpr_participantoptin_run() niet beschikbaar.');
    }

    // Nooit echt mailen vanuit een testrun (de is_opt_out-flip kan via de live
    // synchooks doorwerken; alle mail-paden blijven onderdrukt).
    \Civi::$statics['gdpr']['mail_onderdrukt'] = TRUE;
  }

  public function tearDown(): void {
    unset(\Civi::$statics['gdpr']['mail_onderdrukt']);
    parent::tearDown();
  }

  /**
   * Maak een kamp-event binnen het optin-window (start over 1 maand, type 11 = KK1).
   *
   * BEWUST via directe SQL: Event.create via de API triggert de volledige OZK-hookstack
   * (event-cascade naar deelnemers/groepen) die op kale test-fixtures een constraint
   * violation gaf en daarmee de test-transactie corrumpeerde. De optin-SELECT heeft
   * alleen de event-rij zelf nodig.
   */
  private function maakKampEvent(): int {
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO `civicrm_event` (title, event_type_id, start_date, is_active, is_template)
       VALUES (%1, 11, %2, 1, 0)",
      [
        1 => ['GdprTest Kamp ' . uniqid(), 'String'],
        2 => [date('Y-m-d 10:00:00', strtotime('+1 month')), 'String'],
      ]
    );

    return (int) \CRM_Core_DAO::singleValueQuery("SELECT LAST_INSERT_ID()");
  }

  /**
   * Maak een testcontact met privacy-rij, vlaggen en registratie op het event.
   */
  private function maakDeelnemer(int $eventId, int $doNotEmail, int $isOptOut): int {
    $result_contact_create = civicrm_api4('Contact', 'create', [
      'checkPermissions' => FALSE,
      'values'           => [
        'contact_type' => 'Individual',
        'first_name'   => 'GdprTest',
        'last_name'    => 'Optin_' . uniqid(),
        'do_not_email' => $doNotEmail,
        'is_opt_out'   => $isOptOut,
      ],
    ]);
    $cid = (int) $result_contact_create->first()['id'];

    // Privacy-rij (de bron-selects INNER JOINen op civicrm_value_privacy_286).
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO `civicrm_value_privacy_286` (entity_id) VALUES (%1)
       ON DUPLICATE KEY UPDATE entity_id = entity_id",
      [1 => [$cid, 'Integer']]
    );

    // Registratie via directe SQL (zelfde reden als maakKampEvent: de zware
    // OZK-participant-hookstack is hier niet het testonderwerp).
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO `civicrm_participant` (contact_id, event_id, status_id, role_id, register_date)
       VALUES (%1, %2, 1, 1, NOW())",
      [
        1 => [$cid, 'Integer'],
        2 => [$eventId, 'Integer'],
      ]
    );

    return $cid;
  }

  /**
   * Lees de core-vlaggen terug.
   */
  private function leesFlags(int $cid): array {
    $result_contact_get = civicrm_api4('Contact', 'get', [
      'checkPermissions' => FALSE,
      'select'           => ['do_not_email', 'is_opt_out'],
      'where'            => [['id', '=', $cid]],
      'limit'            => 1,
    ]);
    $row = $result_contact_get->first();

    return [
      'do_not_email' => (int) !empty($row['do_not_email']),
      'is_opt_out'   => (int) !empty($row['is_opt_out']),
    ];
  }

  /**
   * Lees on_hold van een e-mailrij.
   */
  private function leesOnHold(int $emailId): int {
    return (int) \CRM_Core_DAO::singleValueQuery(
      "SELECT on_hold FROM civicrm_email WHERE id = %1",
      [1 => [$emailId, 'Integer']]
    );
  }

  /**
   * Tel optin-activities (type 142) voor een contact.
   */
  private function telActivity(int $cid, string $subjectPrefix): int {
    return (int) \CRM_Core_DAO::singleValueQuery(
      "SELECT COUNT(DISTINCT A.id)
       FROM civicrm_activity A
       INNER JOIN civicrm_activity_contact AC ON AC.activity_id = A.id
       WHERE A.activity_type_id = 142 AND A.subject LIKE %2 AND AC.contact_id = %1",
      [1 => [$cid, 'Integer'], 2 => [$subjectPrefix . '%', 'String']]
    );
  }

  // ########################################################################
  // ### SCENARIO: DO_NOT_EMAIL-OVERRIDE MET BEHOUD VAN BULK-BESCHERMING
  // ########################################################################

  /**
   * Deelnemer met do_not_email=1: na de run is do_not_email 0 maar is_opt_out 1.
   */
  public function testDoNotEmailUitMaarOptOutAan() {
    $eventId = $this->maakKampEvent();
    $cid     = $this->maakDeelnemer($eventId, 1, 0);

    // Dry-run telt de kandidaat maar muteert niets.
    $dry = gdpr_participantoptin_run(TRUE, 'gdpr.test');
    $this->assertGreaterThanOrEqual(1, $dry['totaal_rijen'], 'Dry-run moet onze kandidaat tellen.');
    $this->assertSame(1, $this->leesFlags($cid)['do_not_email'], 'Dry-run mag niets muteren.');

    gdpr_participantoptin_run(FALSE, 'gdpr.test');
    $flags = $this->leesFlags($cid);

    $this->assertSame(0, $flags['do_not_email'],
      'do_not_email moet uit zodat praktische kampmail aankomt.');
    $this->assertSame(1, $flags['is_opt_out'],
      'is_opt_out moet AAN blijven/gaan: bulk-mailings blijven dicht (correctie op sqltask 108).');
    $this->assertSame(1, $this->telActivity($cid, 'GDPR Optin vanwege deelname kamp dit jaar (praktische mail)'),
      'De override moet een activity 142 vastleggen.');
  }

  // ########################################################################
  // ### SCENARIO: E-MAIL ON-HOLD VRIJGEVEN (MAAR OPT-OUT-HOLD=2 NIET)
  // ########################################################################

  /**
   * on_hold=1 gaat naar 0; on_hold=2 (opt-out-hold uit de remove-request-flow) blijft.
   */
  public function testOnHoldVrijgegevenMaarOptOutHoldBlijft() {
    $eventId = $this->maakKampEvent();
    $cid     = $this->maakDeelnemer($eventId, 0, 0);

    $result_email_bounce = civicrm_api4('Email', 'create', [
      'checkPermissions' => FALSE,
      'values'           => [
        'contact_id'       => $cid,
        'email'            => 'gdpr-optin-' . uniqid() . '@example.invalid',
        'location_type_id' => 1,
        'is_primary'       => 1,
        'on_hold'          => 1,
      ],
    ]);
    $bounceEmailId = (int) $result_email_bounce->first()['id'];

    $result_email_hold2 = civicrm_api4('Email', 'create', [
      'checkPermissions' => FALSE,
      'values'           => [
        'contact_id'       => $cid,
        'email'            => 'gdpr-optin2-' . uniqid() . '@example.invalid',
        'location_type_id' => 10,
        'on_hold'          => 2,
      ],
    ]);
    $hold2EmailId = (int) $result_email_hold2->first()['id'];

    gdpr_participantoptin_run(FALSE, 'gdpr.test');

    $this->assertSame(0, $this->leesOnHold($bounceEmailId),
      'on_hold=1 moet vrijgegeven worden voor kampmail.');
    $this->assertSame(2, $this->leesOnHold($hold2EmailId),
      'on_hold=2 (bewuste opt-out-hold) moet blijven staan.');
    $this->assertSame(1, $this->telActivity($cid, 'GDPR Optin vanwege deelname kamp dit jaar:'),
      'De on-hold-vrijgave moet een activity 142 vastleggen.');
  }

  // ########################################################################
  // ### SCENARIO: BUITEN HET KAMPWINDOW GEBEURT NIETS
  // ########################################################################

  /**
   * Zonder registratie in het window blijven de vlaggen ongemoeid.
   */
  public function testZonderDeelnameGeenOverride() {
    // Contact mét privacy-rij en do_not_email=1, maar zónder registratie.
    $result_contact_create = civicrm_api4('Contact', 'create', [
      'checkPermissions' => FALSE,
      'values'           => [
        'contact_type' => 'Individual',
        'first_name'   => 'GdprTest',
        'last_name'    => 'OptinGeenKamp_' . uniqid(),
        'do_not_email' => 1,
        'is_opt_out'   => 0,
      ],
    ]);
    $cid = (int) $result_contact_create->first()['id'];
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO `civicrm_value_privacy_286` (entity_id) VALUES (%1)
       ON DUPLICATE KEY UPDATE entity_id = entity_id",
      [1 => [$cid, 'Integer']]
    );

    gdpr_participantoptin_run(FALSE, 'gdpr.test');
    $flags = $this->leesFlags($cid);

    $this->assertSame(1, $flags['do_not_email'],
      'Zonder kampdeelname mag do_not_email niet aangeraakt worden.');
    $this->assertSame(0, $flags['is_opt_out'],
      'Zonder kampdeelname mag is_opt_out niet aangeraakt worden.');
  }
}
