<?php

require_once __DIR__ . '/gdpr.helpers.php';

/**
 * =======================================================================================
 * GDPR REMOVE-REQUEST LOGICA (voorkeur 44: verwijder contactgegevens)
 * =======================================================================================
 * Migreert de sqltasks 106/112/113/114/163. In tegenstelling tot de dataminimalisatie
 * loopt deze groep per kandidaat-rij via APIv4, zodat CiviCRM-hooks op Email/Phone/Address
 * blijven vuren. Na elke geslaagde mutatie wordt activity type 142 vastgelegd.
 * =======================================================================================
 */

/**
 * APIv4 Email.delete voor een direct remove-request e-mailadres.
 */
function _gdpr_removerequest_email_delete(array $row, $extdebug): bool {
    $params = [
        'checkPermissions' => FALSE,
        'where'            => [
            ['id', '=', (int) $row['gdpr_email_id']],
            ['contact_id', '=', (int) $row['contact_id']],
        ],
    ];
    wachthond($extdebug, 7, "APIv4 Email.delete params", $params);
    $result = civicrm_api4('Email', 'delete', $params);
    wachthond($extdebug, 9, "APIv4 Email.delete resultaat", $result);
    return TRUE;
}

/**
 * APIv4 Email.update voor hetzelfde e-mailadres bij gerelateerde contacten.
 */
function _gdpr_removerequest_email_on_hold(array $row, $extdebug): bool {
    $params = [
        'checkPermissions' => FALSE,
        'where'            => [
            ['id', '=', (int) $row['related_email_id']],
            ['contact_id', '=', (int) $row['contact_id']],
        ],
        'values'           => [
            'on_hold' => 2,
        ],
    ];
    wachthond($extdebug, 7, "APIv4 Email.update params", $params);
    $result = civicrm_api4('Email', 'update', $params);
    wachthond($extdebug, 9, "APIv4 Email.update resultaat", $result);
    return TRUE;
}

/**
 * APIv4 Phone.delete voor een direct remove-request telefoonnummer.
 */
function _gdpr_removerequest_phone_direct_delete(array $row, $extdebug): bool {
    $params = [
        'checkPermissions' => FALSE,
        'where'            => [
            ['id', '=', (int) $row['gdpr_phone_id']],
            ['contact_id', '=', (int) $row['contact_id']],
        ],
    ];
    wachthond($extdebug, 7, "APIv4 Phone.delete params", $params);
    $result = civicrm_api4('Phone', 'delete', $params);
    wachthond($extdebug, 9, "APIv4 Phone.delete resultaat", $result);
    return TRUE;
}

/**
 * APIv4 Phone.delete voor hetzelfde telefoonnummer bij een gerelateerd contact.
 */
function _gdpr_removerequest_phone_related_delete(array $row, $extdebug): bool {
    $params = [
        'checkPermissions' => FALSE,
        'where'            => [
            ['id', '=', (int) $row['phone_id']],
            ['contact_id', '=', (int) $row['contact_id']],
        ],
    ];
    wachthond($extdebug, 7, "APIv4 Phone.delete params", $params);
    $result = civicrm_api4('Phone', 'delete', $params);
    wachthond($extdebug, 9, "APIv4 Phone.delete resultaat", $result);
    return TRUE;
}

/**
 * APIv4 Address.delete voor een direct remove-request adres.
 */
function _gdpr_removerequest_address_delete(array $row, $extdebug): bool {
    $params = [
        'checkPermissions' => FALSE,
        'where'            => [
            ['id', '=', (int) $row['gdpr_adres_id']],
            ['contact_id', '=', (int) $row['contact_id']],
        ],
    ];
    wachthond($extdebug, 7, "APIv4 Address.delete params", $params);
    $result = civicrm_api4('Address', 'delete', $params);
    wachthond($extdebug, 9, "APIv4 Address.delete resultaat", $result);
    return TRUE;
}

/**
 * =======================================================================================
 * GDPR RR.0  REMOVE-REQUEST HOOFDMOTOR
 * =======================================================================================
 * Draait alle remove-request-subtaken voor contactvoorkeur 44. E-mail-related draait na
 * de directe e-maildelete omdat task 163 terugzoekt op de achtergelaten activity. Telefoon-
 * related wordt juist vóór de directe telefoondelete geëvalueerd, omdat de bron-view over
 * de live direct-telefoonrijen loopt en we geen tijdelijke tabellen materialiseren.
 *
 * @param bool        $dry_run  TRUE = alleen tellen, niets muteren.
 * @param int|string  $extdebug Wachthond-kanaal.
 *
 * @return array{dry_run:bool,totaal_rijen:int,stappen:array}
 */
function gdpr_removerequest_run(bool $dry_run = FALSE, $extdebug = 'gdpr.removerequest'): array {

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GDPR RR.0 REMOVE-REQUEST MOTOR", $dry_run ? "[DRY-RUN]" : "[RUN]");
    wachthond($extdebug, 2, "########################################################################");

    // RR.1 E-mail direct verwijderen (task 106, temp_gdpr_removerequest_email_direct).
    // BELEIDSREGEL (Richard, 11-jul-2026, aanscherping t.o.v. bron-sqltask 106): een
    // verzoeker MET actieve kampregistratie voor dit jaar wordt overgeslagen — de
    // praktische kampmail moet kunnen blijven aankomen. LEFT JOIN: een contact zónder
    // DITJAAR-rij heeft geen registratie en wordt dus wél verwerkt. Na het seizoen
    // (ditjaar_event_start weer leeg) pakt de nachtelijke run het verzoek alsnog op.
    $email_direct_sql = "SELECT C.id AS contact_id, C.display_name AS gdpr_contact_name,
       E.id AS gdpr_email_id, E.email AS gdpr_email, E.location_type_id, E.is_primary, E.on_hold,
       G.contactvoorkeuren_1417 AS contactvoorkeuren,
       G.datum_update_gdpr_1418 AS gdpr_datum, G.opmerkingen_gdpr_1419 AS gdpr_opmerkingen
FROM `civicrm_contact` C
INNER JOIN `civicrm_email` E ON E.contact_id = C.id
INNER JOIN `civicrm_value_privacy_286` G ON G.entity_id = C.id
LEFT JOIN `civicrm_value_ditjaar_199` DJ ON DJ.entity_id = C.id
WHERE G.contactvoorkeuren_1417 = 44
  AND DJ.ditjaar_event_start_1155 IS NULL";

    // RR.2 Direct-view uit task 163: activity-gedreven lookup op eerder verwijderde e-mail.
    $email_activity_direct_sql = "SELECT C.id contact_id, C.display_name AS gdpr_contact_name, E.id AS gdpr_email_id,
       A.location AS activity_email, E.email AS gdpr_email, E.location_type_id, E.is_primary, E.on_hold,
       G.contactvoorkeuren_1417, G.datum_update_gdpr_1418 AS gdpr_datum, G.opmerkingen_gdpr_1419 AS gdpr_opmerkingen
FROM `civicrm_contact` C
LEFT JOIN `civicrm_email` E ON E.contact_id = C.id
INNER JOIN `civicrm_value_privacy_286` G ON G.entity_id = C.id
INNER JOIN `civicrm_activity` A ON A.source_record_id = C.id
WHERE G.contactvoorkeuren_1417 = 44
  AND A.subject LIKE '%GDPR emailadres verwijderd%'
  AND A.location NOT LIKE '%placeholder%'";

    // RR.2 Related-view uit task 163: zet hetzelfde e-mailadres bij anderen on-hold.
    $email_related_sql = "SELECT V.contact_id AS gdpr_contact_id, V.gdpr_contact_name, V.gdpr_datum,
       E.contact_id AS contact_id, C.display_name AS related_display_name,
       E.id AS related_email_id, E.email AS related_email, E.location_type_id AS related_location_type_id,
       E.is_primary, E.on_hold
FROM (
$email_activity_direct_sql
) V
INNER JOIN `civicrm_email` E ON E.email = V.activity_email
INNER JOIN `civicrm_contact` C ON C.id = E.contact_id
LEFT JOIN `civicrm_value_ditjaar_199` D ON D.entity_id = E.contact_id
WHERE E.on_hold = 0
  AND D.ditjaar_event_start_1155 IS NULL
  AND V.contact_id != E.contact_id";

    // RR.3 Directe telefoon-view (task 112, temp_gdpr_removerequest_phone_direct).
    // Zelfde beleidsregel als RR.1: verzoeker met actieve kampregistratie overslaan.
    $phone_direct_sql = "SELECT C.id AS contact_id, C.display_name AS gdpr_contact_name, T.id AS gdpr_phone_id, T.phone AS gdpr_phone,
       T.location_type_id AS phone_location, T.is_primary, G.contactvoorkeuren_1417,
       G.datum_update_gdpr_1418 AS gdpr_datum, G.opmerkingen_gdpr_1419 AS gdpr_opmerkingen
FROM `civicrm_contact` C
INNER JOIN `civicrm_phone` T ON T.contact_id = C.id
INNER JOIN `civicrm_value_privacy_286` G ON G.entity_id = C.id
LEFT JOIN `civicrm_value_ditjaar_199` DJ ON DJ.entity_id = C.id
WHERE G.contactvoorkeuren_1417 = 44
  AND DJ.ditjaar_event_start_1155 IS NULL";

    // RR.3 Related telefoon-view (task 113, temp_gdpr_removerequest_phone_related).
    $phone_related_sql = "SELECT V.contact_id AS gdpr_contact_id, V.gdpr_contact_name, V.gdpr_datum, C.id AS contact_id,
       C.display_name AS related_contact_name, T.id AS phone_id, T.phone AS gdpr_phone,
       V.phone_location AS location_verzoeker, T.location_type_id AS location_related, T.is_primary,
       G.contactvoorkeuren_1417, D.ditjaar_event_start_1155
FROM (
$phone_direct_sql
) V
INNER JOIN `civicrm_phone` T ON T.phone = V.gdpr_phone
INNER JOIN `civicrm_contact` C ON C.id = T.contact_id
INNER JOIN `civicrm_value_privacy_286` G ON G.entity_id = C.id
INNER JOIN `civicrm_value_ditjaar_199` D ON D.entity_id = T.contact_id
WHERE G.contactvoorkeuren_1417 != '44'
  AND D.ditjaar_event_start_1155 IS NULL
  AND V.phone_location IN (1)
  AND T.location_type_id IN (11,12)";

    // RR.4 Adres direct verwijderen (task 114, temp_gdpr_removerequest_adres_direct).
    // Had als enige direct-stap de registratie-guard al in de bron. LET OP: de bron
    // gebruikt een INNER JOIN op DITJAAR — een contact zónder DITJAAR-rij wordt daardoor
    // óók overgeslagen (conservatiever dan RR.1/RR.3). Bewust zo gelaten (bron-pariteit).
    $adres_direct_sql = "SELECT C.id AS contact_id, C.display_name, A.id AS gdpr_adres_id, A.street_address AS gdpr_adres,
       A.city AS gdpr_city, A.location_type_id AS adres_locatie, G.contactvoorkeuren_1417,
       G.datum_update_gdpr_1418 AS gdpr_datum, G.opmerkingen_gdpr_1419 AS gdpr_opmerkingen
FROM `civicrm_contact` C
INNER JOIN `civicrm_address` A ON A.contact_id = C.id
INNER JOIN `civicrm_value_privacy_286` G ON G.entity_id = C.id
INNER JOIN `civicrm_value_ditjaar_199` D ON D.entity_id = C.id
WHERE G.contactvoorkeuren_1417 = 44
  AND D.ditjaar_event_start_1155 IS NULL";

    // Activity-details: VERBATIM overgenomen uit de CreateActivity-acties van de
    // bron-sqltasks, zodat het auditspoor identiek blijft aan wat de organisatie kent.
    $stappen   = [];
    $stappen[] = _gdpr_exec_removerequest($dry_run,
        'RR.1 e-mail direct verwijderen (voorkeur 44)',
        $email_direct_sql,
        '_gdpr_removerequest_email_delete',
        'GDPR emailadres verwijderd ivm verzoek ({location_type_id})',
        "{gdpr_contact_name} ({contact_id}) heeft op {gdpr_datum} een verwijderverzoek gedaan voor het emailadres: {gdpr_email} (location type: {location_type_id}). "
        . "Indien dit emailadres ook door andere contacten in gebruik was hebben we dit emailadres bij hen 'on hold' gezet en bij {gdpr_contact_name} verwijderd.",
        142,
        $extdebug);

    $stappen[] = _gdpr_exec_removerequest($dry_run,
        'RR.2 gerelateerde e-mail on-hold zetten (voorkeur 44)',
        $email_related_sql,
        '_gdpr_removerequest_email_on_hold',
        'GDPR emailadres on-hold ivm verzoek {gdpr_contact_name}',
        "{gdpr_contact_name} ({gdpr_contact_id}) heeft op {gdpr_datum} een verwijderverzoek gedaan voor het emailadres: {related_email}.\n\n"
        . "Omdat {related_display_name} (contact_id: {contact_id}) hetzelfde emailadres heeft (met location_type: {related_location_type_id}) hebben we dit emailadres voorlopig 'on hold' gezet. "
        . "Het emailadres bij {gdpr_contact_name} is verwijderd.",
        142,
        $extdebug);

    $stappen[] = _gdpr_exec_removerequest($dry_run,
        'RR.3 gerelateerde telefoon verwijderen (voorkeur 44)',
        $phone_related_sql,
        '_gdpr_removerequest_phone_related_delete',
        'GDPR telefoon verwijderd ivm verzoek {gdpr_contact_name}',
        "{gdpr_contact_name} ({gdpr_contact_id}) heeft op {gdpr_datum} een verwijderverzoek gedaan voor het telefoonnummer: {gdpr_phone} (location_type: {location_verzoeker})\n\n"
        . "Omdat {related_contact_name} hetzelfde telefoonnummer secundair heeft (location_type: {location_related}) verwijderen we het nummer ook voor {related_contact_name}.",
        142,
        $extdebug);

    $stappen[] = _gdpr_exec_removerequest($dry_run,
        'RR.3 telefoon direct verwijderen (voorkeur 44)',
        $phone_direct_sql,
        '_gdpr_removerequest_phone_direct_delete',
        'GDPR telefoon verwijderd ivm verzoek',
        "{gdpr_contact_name} ({contact_id}) heeft op {gdpr_datum} een verwijderverzoek gedaan voor het telefoonnummer: {gdpr_phone} (location_type: {phone_location}). "
        . "Indien dit telefoonnummer ook door andere contacten in gebruik was hebben we dit telefoonnummer bij hen ook verwijderd tenzij het was bij iemand die dit jaar meegaat of het location_type: home was.",
        142,
        $extdebug);

    $stappen[] = _gdpr_exec_removerequest($dry_run,
        'RR.4 adres direct verwijderen (voorkeur 44)',
        $adres_direct_sql,
        '_gdpr_removerequest_address_delete',
        'GDPR adres verwijderd ivm verzoek (id: {gdpr_adres_id})',
        "{display_name} ({contact_id}) heeft op {gdpr_datum} een verwijderverzoek gedaan voor het adres: {gdpr_adres} {gdpr_city} (location type: {adres_locatie}).",
        142,
        $extdebug);

    $totaal_rijen = array_sum(array_column($stappen, 'rows'));
    $samenvatting = [
        'dry_run'      => $dry_run,
        'totaal_rijen' => $totaal_rijen,
        'stappen'      => $stappen,
    ];

    wachthond($extdebug, 1, "### GDPR RR.0 REMOVE-REQUEST MOTOR KLAAR", $samenvatting);
    return $samenvatting;
}
