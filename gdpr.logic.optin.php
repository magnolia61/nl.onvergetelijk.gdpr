<?php

require_once __DIR__ . '/gdpr.helpers.php';

/**
 * =======================================================================================
 * GDPR PARTICIPANT-OPTIN LOGICA (consent-override bij kampdeelname)
 * =======================================================================================
 * Migreert de sqltasks 97 (on_hold) en 108 (do_not_email), mét beleidscorrectie.
 *
 * FUNCTIONEEL: wie zich inschrijft voor een kamp, moet de praktische mails rond die
 * deelname (bevestiging, betaalinformatie, kampinformatie) kunnen ontvangen — óók als
 * eerder do_not_email was gezet of het e-mailadres on-hold stond. Inschrijven geldt
 * als akkoord voor operationele kampmail (besluit Richard, 11-jul-2026).
 *
 * BELEIDSCORRECTIE t.o.v. bron-sqltask 108: de bron zette naast do_not_email óók
 * is_opt_out op 0, waarmee de bulk-afmelding van de deelnemer werd vernietigd (en wat
 * een nachtelijke ping-pong zou geven met de sync-reconciliatie, die is_opt_out weer
 * aanzet bij voorkeur 33/44). Hier: do_not_email -> 0 maar is_opt_out -> 1, zodat
 * praktische mail aankomt (reminders/CiviRules toetsen do_not_email) terwijl
 * bulk-mailings dicht blijven (CiviMail toetst is_opt_out).
 *
 * De is_opt_out 0->1-overgang triggert bewust de D.2-synchook: de PRIVACY-tab wordt
 * dan bijgewerkt naar voorkeur 33, zodat beide administraties hetzelfde zeggen.
 * =======================================================================================
 */

/**
 * PO.1: APIv4 Contact.update — do_not_email uit, is_opt_out aan (bulk blijft dicht).
 */
function _gdpr_participantoptin_contact_update(array $row, $extdebug): bool {
    $params = [
        'checkPermissions' => FALSE,
        'where'            => [
            ['id', '=', (int) $row['contact_id']],
        ],
        'values'           => [
            'do_not_email' => FALSE,
            'is_opt_out'   => TRUE,
        ],
    ];
    wachthond($extdebug, 7, "APIv4 Contact.update params", $params);
    $result = civicrm_api4('Contact', 'update', $params);
    wachthond($extdebug, 9, "APIv4 Contact.update resultaat", $result);
    return TRUE;
}

/**
 * PO.2: APIv4 Email.update — on_hold uit voor het kampmail-adres.
 */
function _gdpr_participantoptin_email_unhold(array $row, $extdebug): bool {
    $params = [
        'checkPermissions' => FALSE,
        'where'            => [
            ['id', '=', (int) $row['gdpr_email_id']],
            ['contact_id', '=', (int) $row['contact_id']],
        ],
        'values'           => [
            'on_hold' => 0,
        ],
    ];
    wachthond($extdebug, 7, "APIv4 Email.update params", $params);
    $result = civicrm_api4('Email', 'update', $params);
    wachthond($extdebug, 9, "APIv4 Email.update resultaat", $result);
    return TRUE;
}

/**
 * =======================================================================================
 * GDPR PO.0  PARTICIPANT-OPTIN HOOFDMOTOR
 * =======================================================================================
 * Doelgroep (bron-semantiek 97/108, verbatim): deelnemers/leiding met een registratie
 * (status 1,2,5,6,15,16) voor een kamp-event (types 1,11,12,13,14,21,22,23,24,33)
 * dat startte/start tussen 3 maanden terug en 9 maanden vooruit.
 *
 * SELECT DISTINCT is toegevoegd t.o.v. de bron: meerdere registraties in het window
 * zouden anders dubbele mutaties/activities per contact geven.
 *
 * @param bool        $dry_run  TRUE = alleen tellen, niets muteren.
 * @param int|string  $extdebug Wachthond-kanaal.
 *
 * @return array{dry_run:bool,totaal_rijen:int,stappen:array}
 */
function gdpr_participantoptin_run(bool $dry_run = FALSE, $extdebug = 'gdpr.participantoptin'): array {

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GDPR PO.0 PARTICIPANT-OPTIN MOTOR", $dry_run ? "[DRY-RUN]" : "[RUN]");
    wachthond($extdebug, 2, "########################################################################");

    $event_types    = '1,11,12,13,14,21,22,23,24,33';
    $part_statussen = '1,2,5,6,15,16';
    $window         = "EV.start_date > date_sub(now(), interval 3 month)
  AND EV.start_date < date_add(now(), interval 9 month)";

    // PO.1 Contact-vlaggen (bron: task 108, temp_gdpr_ditjaar_donotemail).
    $donotemail_sql = "SELECT DISTINCT C.id AS contact_id, C.display_name, C.do_not_email, C.is_opt_out,
       G.contactvoorkeuren_1417 AS gdpr_voorkeuren
FROM `civicrm_contact` C
INNER JOIN `civicrm_participant` P ON P.contact_id = C.id
INNER JOIN `civicrm_event` EV ON EV.id = P.event_id
INNER JOIN `civicrm_value_privacy_286` G ON G.entity_id = C.id
WHERE EV.event_type_id IN ($event_types)
  AND P.status_id IN ($part_statussen)
  AND $window
  AND C.do_not_email = 1";

    // PO.2 E-mail on-hold (bron: task 97, temp_gdpr_ditjaar_emailonhold; alleen
    // on_hold=1 — on_hold=2 is een bewuste opt-out-hold uit de remove-request-flow
    // en blijft dus staan).
    $onhold_sql = "SELECT DISTINCT C.id AS contact_id, C.display_name, E.location_type_id, E.is_primary,
       E.on_hold, E.id AS gdpr_email_id, E.email AS gdpr_email
FROM `civicrm_contact` C
INNER JOIN `civicrm_participant` P ON C.id = P.contact_id
INNER JOIN `civicrm_event` EV ON EV.id = P.event_id
INNER JOIN `civicrm_email` E ON E.contact_id = C.id
INNER JOIN `civicrm_value_privacy_286` G ON G.entity_id = C.id
WHERE EV.event_type_id IN ($event_types)
  AND P.status_id IN ($part_statussen)
  AND E.location_type_id IN (1,10,11)
  AND $window
  AND E.on_hold = 1";

    $stappen   = [];
    $stappen[] = _gdpr_exec_removerequest($dry_run,
        'PO.1 do_not_email uit bij kampdeelname (is_opt_out blijft aan)',
        $donotemail_sql,
        '_gdpr_participantoptin_contact_update',
        'GDPR Optin vanwege deelname kamp dit jaar (praktische mail)',
        "Omdat {display_name} dit jaar meegaat op kamp is do_not_email uitgezet, zodat de praktische "
        . "mails rond de kampdeelname (bevestiging, betaalinformatie, kampinformatie) aankomen. "
        . "is_opt_out is/blijft AANgezet: bulk-mailings blijven dicht, de eerdere afmelding wordt "
        . "dus gerespecteerd (aanscherping t.o.v. oude sqltask 108, die ook is_opt_out wiste).",
        142,
        $extdebug);

    $stappen[] = _gdpr_exec_removerequest($dry_run,
        'PO.2 e-mail on-hold uit bij kampdeelname',
        $onhold_sql,
        '_gdpr_participantoptin_email_unhold',
        'GDPR Optin vanwege deelname kamp dit jaar: {gdpr_email}',
        "Omdat {display_name} dit jaar meegaat op kamp is de on-hold van {gdpr_email} "
        . "(email_id {gdpr_email_id}, locationtype {location_type_id}) uitgezet, zodat de praktische "
        . "kampmails kunnen aankomen. Let op: stond het adres on-hold door een bounce, dan kan het "
        . "opnieuw bouncen — controleer het adres bij herhaling.",
        142,
        $extdebug);

    $totaal_rijen = array_sum(array_column($stappen, 'rows'));
    $samenvatting = [
        'dry_run'      => $dry_run,
        'totaal_rijen' => $totaal_rijen,
        'stappen'      => $stappen,
    ];

    wachthond($extdebug, 1, "### GDPR PO.0 PARTICIPANT-OPTIN MOTOR KLAAR", $samenvatting);
    return $samenvatting;
}
