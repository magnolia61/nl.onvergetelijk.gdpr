# nl.onvergetelijk.gdpr

AVG/GDPR-extensie voor Stichting Onvergetelijke Zomerkampen. Bundelt de privacy-logica
die voorheen in losse database-taken (sqltasks) leefde in één versiebeheerde, geteste
extensie:

- **Dataminimalisatie** (`Gdpr.cleanup`): wist medische gegevens, gedragsnotities en
  historische contactgegevens van oud-deelnemers na afloop van de bewaartermijn
  (migratie van sqltask 7).
- **Recht op vergetelheid** (`Gdpr.removerequest`): verwijdert e-mail/telefoon/adres
  voor contacten met Contactvoorkeuren = "Verwijder contactgegevens" en zet gedeelde
  e-mailadressen bij gerelateerde contacten on-hold, met een audit-activity per mutatie
  (migratie van sqltasks 106/112/113/114/163).
- **Opt-out-hygiëne** (`Gdpr.optoutgroepen`): haalt contacten met opt-out uit publieke
  nieuwsbriefgroepen (migratie van sqltask 11).
- **Bidirectionele privacy-sync** (hooks): houdt het custom veld
  `PRIVACY.Contactvoorkeuren` en de core-vlaggen `is_opt_out`/`do_not_email` in sync,
  in beide richtingen, met re-entrancy-bewaking. Eenmalige inhaalslag via
  `Gdpr.syncbackfill`.

Alle API-acties ondersteunen `dry_run=1` (alleen tellen, niets muteren). De managed
Scheduled Jobs staan standaard op `is_active=FALSE`; activeren hoort pas ná het
uitzetten van de bijbehorende bron-sqltask (zie het cutover-plan).

Tests: `CIVICRM_UF="UnitTests" phpunit9 --configuration=phpunit.xml.dist`
(EndToEnd + Transactional; draait veilig tegen een live database en rolt terug).

This is an [extension for CiviCRM](https://docs.civicrm.org/sysadmin/en/latest/customize/extensions/), licensed under [AGPL-3.0](LICENSE.txt).

## Requirements

- PHP v8.x
- CiviCRM 6.x
- nl.onvergetelijk.base (wachthond-logging)
