<?php

namespace Civi\Gdpr;

use Civi\Test\EndToEndInterface;
use Civi\Test\TransactionalInterface;

/**
 * End-to-end test voor de AVG-opschoonlogica van nl.onvergetelijk.gdpr.
 *
 * @group e2e
 *
 * Kern van de test: de bewaartermijn-regel voor MEDISCHE aandachtspunten (stap 3.1).
 * De regel luidt: medische gegevens worden geanonimiseerd zodra het laatste kampjaar
 * van de deelnemer (curriculum.laatste_keer_847) meer dan 2 jaar geleden is
 * (< EXTRACT(YEAR FROM DATE_SUB(CURDATE(), INTERVAL 2 YEAR))).
 *
 * We bewijzen beide kanten van de regel:
 *   - een OUD record (laatste kampjaar ver in het verleden) MOET gewist worden;
 *   - een RECENT record (dit jaar nog op kamp) MOET behouden blijven.
 *
 * De test draait binnen een transactie (TransactionalInterface) en rolt volledig terug,
 * dus er wordt niets blijvend op de productie-database gewijzigd.
 */
class GdprCleanupTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface, TransactionalInterface {

  /** @var int contact-id met een OUD laatste kampjaar (moet gewist worden) */
  private $cidOud;

  /** @var int contact-id met een RECENT laatste kampjaar (moet behouden blijven) */
  private $cidRecent;

  public function setUp(): void {
    parent::setUp();

    // De extensie draait op productie (nog) uitgeschakeld; laad de helper direct zodat
    // de test zelfstandig werkt zonder de extensie te hoeven inschakelen.
    if (!function_exists('gdpr_cleanup_aandachtspunten')) {
      require_once __DIR__ . '/../../../../gdpr.helpers.php';
    }
    if (!function_exists('gdpr_cleanup_aandachtspunten')) {
      $this->markTestSkipped('gdpr_cleanup_aandachtspunten() niet beschikbaar.');
    }
  }

  /**
   * Maak een contact met een curriculum-jaar en gevulde medische gegevens.
   *
   * @param int $laatsteKeerJaar Jaartal voor curriculum.laatste_keer_847.
   *
   * @return int Het aangemaakte contact-id.
   */
  private function maakDeelnemerMetMedisch(int $laatsteKeerJaar): int {

    // 0.1 Maak een individu aan.
    $result_contact_create = civicrm_api4('Contact', 'create', [
      'checkPermissions' => FALSE,
      'values'           => [
        'contact_type' => 'Individual',
        'first_name'   => 'GdprTest',
        'last_name'    => 'Deelnemer_' . $laatsteKeerJaar . '_' . uniqid(),
      ],
    ]);
    $cid = (int) $result_contact_create->first()['id'];

    // 0.2 Zet het laatste kampjaar in curriculum_103 (bepaalt de bewaartermijn).
    //     CiviCRM/hooks kunnen bij het aanmaken van het contact al een lege custom-rij
    //     hebben gemaakt; daarom ON DUPLICATE KEY UPDATE (entity_id is UNIQUE).
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO `civicrm_value_curriculum_103` (entity_id, laatste_keer_847) VALUES (%1, %2)
       ON DUPLICATE KEY UPDATE laatste_keer_847 = VALUES(laatste_keer_847)",
      [1 => [$cid, 'Integer'], 2 => [$laatsteKeerJaar, 'Integer']]
    );

    // 0.3 Vul medische gegevens in medisch_148 (deze moeten conditioneel gewist worden).
    //     medisch_check_1837 is exact het veld dat sqltask 7 liet crashen (rename).
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO `civicrm_value_medisch_148`
              (entity_id, medisch_issues_1832, medisch_notities_1836, medisch_check_1837)
       VALUES (%1, %2, %3, %4)
       ON DUPLICATE KEY UPDATE medisch_issues_1832   = VALUES(medisch_issues_1832),
                               medisch_notities_1836 = VALUES(medisch_notities_1836),
                               medisch_check_1837     = VALUES(medisch_check_1837)",
      [
        1 => [$cid, 'Integer'],
        2 => ['Testallergie', 'String'],
        3 => ['Testnotitie medisch', 'String'],
        4 => [1, 'Integer'],
      ]
    );

    return $cid;
  }

  /**
   * Lees de medische kernvelden van een contact terug.
   *
   * @return array{issues:?string,check:?string}
   */
  private function leesMedisch(int $cid): array {
    $dao = \CRM_Core_DAO::executeQuery(
      "SELECT medisch_issues_1832 AS issues, medisch_check_1837 AS chk
       FROM `civicrm_value_medisch_148` WHERE entity_id = %1",
      [1 => [$cid, 'Integer']]
    );
    $dao->fetch();
    return ['issues' => $dao->issues, 'check' => $dao->chk];
  }

  // ########################################################################
  // ### SCENARIO: MEDISCHE AANDACHTSPUNTEN-BEWAARTERMIJN (stap 3.1)
  // ########################################################################

  /**
   * Oude medische gegevens worden gewist, recente blijven staan.
   */
  public function testMedischeGegevensWordenNaBewaartermijnGewist() {

    $huidigJaar = (int) date('Y');

    // OUD: 6 jaar geleden voor het laatst op kamp -> ruim voorbij de 2-jaars-drempel.
    $this->cidOud = $this->maakDeelnemerMetMedisch($huidigJaar - 6);
    // RECENT: dit jaar nog op kamp -> moet behouden blijven.
    $this->cidRecent = $this->maakDeelnemerMetMedisch($huidigJaar);

    // Sanity: beide records zijn vooraf gevuld.
    $this->assertSame('Testallergie', $this->leesMedisch($this->cidOud)['issues'],
      'Voorwaarde: oud record moet vooraf gevuld zijn.');
    $this->assertSame('Testallergie', $this->leesMedisch($this->cidRecent)['issues'],
      'Voorwaarde: recent record moet vooraf gevuld zijn.');

    // Draai de echte opschoning van de aandachtspunten (geen dry-run).
    $stappen = gdpr_cleanup_aandachtspunten(FALSE, 'gdpr.test');
    $this->assertNotEmpty($stappen, 'Opschoning moet stap-resultaten teruggeven.');

    // OUD record: medische velden zijn nu NULL (geanonimiseerd).
    $oud = $this->leesMedisch($this->cidOud);
    $this->assertNull($oud['issues'], 'Oud medisch_issues moet gewist zijn (NULL).');
    $this->assertNull($oud['check'], 'Oud medisch_check moet gewist zijn (NULL).');

    // RECENT record: medische velden zijn onaangetast.
    $recent = $this->leesMedisch($this->cidRecent);
    $this->assertSame('Testallergie', $recent['issues'],
      'Recent medisch_issues moet behouden blijven.');
    $this->assertSame('1', $recent['check'],
      'Recent medisch_check moet behouden blijven.');
  }

  /**
   * Dry-run wist niets, maar telt wel de kandidaten.
   */
  public function testDryRunWistNiets() {

    $huidigJaar   = (int) date('Y');
    $this->cidOud = $this->maakDeelnemerMetMedisch($huidigJaar - 6);

    $stappen = gdpr_cleanup_aandachtspunten(TRUE, 'gdpr.test');

    // Na een dry-run staat het oude record er nog steeds gevuld bij.
    $oud = $this->leesMedisch($this->cidOud);
    $this->assertSame('Testallergie', $oud['issues'],
      'Dry-run mag niets wissen.');

    // Stap 3.1 moet minstens 1 kandidaat gerapporteerd hebben (ons oude record).
    $stap31 = NULL;
    foreach ($stappen as $s) {
      if (strpos($s['label'], '3.1') === 0) {
        $stap31 = $s;
        break;
      }
    }
    $this->assertNotNull($stap31, 'Stap 3.1 moet in het resultaat voorkomen.');
    $this->assertTrue($stap31['dry_run'], 'Stap 3.1 moet als dry-run gemarkeerd zijn.');
    $this->assertGreaterThanOrEqual(1, $stap31['rows'],
      'Dry-run moet minstens 1 kandidaat tellen.');
  }
}
