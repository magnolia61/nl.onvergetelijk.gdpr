<?php

/**
 * =======================================================================================
 * GDPR opschoon-helpers
 * =======================================================================================
 * Bevat de daadwerkelijke AVG-dataminimalisatie-logica die 1-op-1 gemigreerd is uit
 * de losse database-taak `sqltask 7` ("GDPR opschonen data (oa. aandachtspunten)").
 *
 * De SQL is opzettelijk VERBATIM overgenomen uit sqltask 7 zodat het gedrag exact
 * gelijk blijft aan de bestaande, in productie gevalideerde opschoning. Elke instructie
 * is verpakt in een genummerde stap met:
 *   - functionele + technische toelichting (welke gegevens, welke bewaartermijn, waarom),
 *   - wachthond-logging (severity 1 sectiekop / 2 scheidingslijn / 3 tussenstap),
 *   - dry-run-ondersteuning (telt kandidaten via COUNT i.p.v. te muteren),
 *   - het aantal daadwerkelijk gemuteerde rijen (affectedRows) bij een echte run.
 *
 * De bewaartermijnen (retentiecriteria) uit sqltask 7:
 *   - Contactgegevens oud1/oud2 (location_type 11/12): wissen zodra deelnemer > 21 jaar
 *     (werving.nextkamp_rondjaren_1578), en/of leiding is geworden (curriculum.keren_leid_1438).
 *   - Activiteiten: > 51 maanden (m.u.v. beschermde types), communicatie-activiteiten
 *     (telefoon/e-mail/sms/reminder) > 27 maanden, VOG-activiteiten > 7 jaar,
 *     en toestemmings-/voorwaarden-activiteiten (types 110-113) altijd.
 *   - Aandachtspunten (medisch/gedrag/interne notities): wissen zodra het laatste kampjaar
 *     (curriculum.laatste_keer_847) meer dan 2 jaar geleden is.
 *   - Deelname-info per participant (gedrag/interne notities/dieet): wissen zodra het
 *     bijbehorende event > 7 jaar geleden startte (alleen echte kamp-event-types).
 * =======================================================================================
 */

/**
 * Voer één opschoonstap uit met logging, dry-run-ondersteuning en row-count.
 *
 * @param bool        $dry_run    TRUE = niets muteren, alleen kandidaten tellen.
 * @param string      $label      Menselijke omschrijving van de stap (voor de log/rapport).
 * @param string      $mutate_sql De DELETE/UPDATE die bij een echte run draait.
 * @param string      $count_sql  Een SELECT COUNT(...) die het aantal kandidaten telt (dry-run).
 * @param int|string  $extdebug   Wachthond-kanaal.
 *
 * @return array{label:string,dry_run:bool,rows:int}
 */
function _gdpr_exec_step(bool $dry_run, string $label, string $mutate_sql, string $count_sql, $extdebug): array {

    // In dry-run tellen we alleen de kandidaten; bij UPDATE is dit een bovengrens
    // (MySQL telt bij een echte UPDATE alleen rijen die daadwerkelijk wijzigen).
    if ($dry_run) {
        wachthond($extdebug, 3, "[DRY-RUN] tel kandidaten: $label", $count_sql);
        $rows = (int) CRM_Core_DAO::singleValueQuery($count_sql);
        wachthond($extdebug, 3, "[DRY-RUN] kandidaten voor '$label'", $rows);
        return ['label' => $label, 'dry_run' => TRUE, 'rows' => $rows];
    }

    // Echte run: voer de mutatie uit en lees het aantal geraakte rijen af.
    wachthond($extdebug, 3, "[RUN] muteer: $label", $mutate_sql);
    $dao  = CRM_Core_DAO::executeQuery($mutate_sql);
    $rows = (int) $dao->affectedRows();
    wachthond($extdebug, 3, "[RUN] gemuteerde rijen voor '$label'", $rows);
    return ['label' => $label, 'dry_run' => FALSE, 'rows' => $rows];
}

/**
 * Maak van een kandidaten-SELECT een COUNT-query zonder de bron-SQL inhoudelijk te wijzigen.
 *
 * @param string $select_sql De bron-SELECT die kandidaten oplevert.
 *
 * @return string COUNT-wrapper voor dry-run.
 */
function _gdpr_count_sql_from_select(string $select_sql): string {
    $select_sql = rtrim(trim($select_sql), ';');
    return "SELECT COUNT(*) FROM (\n$select_sql\n) gdpr_candidates";
}

/**
 * Vervang {tokens} in een activity-template (subject of details) door waarden uit de
 * kandidaat-rij. Zelfde token-mechanisme als de CreateActivity-actie van de bron-sqltasks.
 *
 * @param string $template Template met {kolomnaam}-tokens.
 * @param array  $row      Kandidaat-rij.
 *
 * @return string Tekst met ingevulde waarden.
 */
function _gdpr_render_activity_template(string $template, array $row): string {
    $tekst = $template;
    foreach ($row as $key => $value) {
        if (is_scalar($value) || $value === NULL) {
            $tekst = str_replace('{' . $key . '}', (string) $value, $tekst);
        }
    }
    return $tekst;
}

/**
 * Bepaal de activity-location voor audit en related-lookups.
 *
 * E-mail-remove-request task 163 zoekt bewust terug op civicrm_activity.location; daarom
 * bewaren we het verwijderde e-mailadres als location. Voor telefoon/adres is dit alleen
 * audit-informatie en verandert het de selectie niet.
 *
 * @param array $row Kandidaat-rij.
 *
 * @return string|null
 */
function _gdpr_activity_location_from_row(array $row): ?string {
    foreach (['gdpr_email', 'related_email', 'activity_email', 'gdpr_phone', 'gdpr_adres'] as $field) {
        if (!empty($row[$field])) {
            return (string) $row[$field];
        }
    }
    return NULL;
}

/**
 * Maak de GDPR-cleanup activity die de bron-sqltasks als auditspoor achterlieten.
 *
 * Elke privacy-mutatie krijgt een activity type 142 (Cleanup data) met subject ÉN
 * details, net als de CreateActivity-acties van de bron-sqltasks. Type 142 staat in
 * de beschermde-types-lijst van de dataminimalisatie (stap 2.1), dus het auditspoor
 * wordt zelf nooit opgeruimd.
 *
 * @param array       $row              Kandidaat-rij.
 * @param string      $subject_template Subject-template met {tokens} uit de rij.
 * @param string      $details_template Details-template met {tokens} uit de rij ('' = geen details).
 * @param int         $activity_type_id Activity type (142 = Cleanup data).
 * @param int|string  $extdebug         Wachthond-kanaal.
 */
function _gdpr_create_cleanup_activity(array $row, string $subject_template, string $details_template, int $activity_type_id, $extdebug): void {
    $target_contact_id = (int) ($row['contact_id'] ?? $row['gdpr_contact_id'] ?? 0);
    if ($target_contact_id <= 0) {
        wachthond($extdebug, 3, "[SKIP] geen target_contact_id voor cleanup-activity", $row);
        return;
    }

    $subject = _gdpr_render_activity_template($subject_template, $row);
    $values  = [
        'activity_type_id'   => $activity_type_id,
        'subject'            => $subject,
        'activity_date_time' => date('Y-m-d H:i:s'),
        'status_id'          => 2,
        'source_record_id'   => (int) ($row['gdpr_contact_id'] ?? $target_contact_id),
        'source_contact_id'  => (int) ($row['gdpr_contact_id'] ?? $target_contact_id),
        'target_contact_id'  => [$target_contact_id],
    ];

    if ($details_template !== '') {
        $values['details'] = _gdpr_render_activity_template($details_template, $row);
    }

    $location = _gdpr_activity_location_from_row($row);
    if ($location !== NULL) {
        $values['location'] = $location;
    }

    $params = [
        'checkPermissions' => FALSE,
        'values'           => $values,
    ];
    wachthond($extdebug, 7, "APIv4 Activity.create params", $params);
    $result = civicrm_api4('Activity', 'create', $params);
    wachthond($extdebug, 9, "APIv4 Activity.create resultaat", $result);
}

/**
 * Voer één remove-request-stap uit: kandidaten selecteren, per rij APIv4-mutatie doen,
 * en per geslaagde mutatie een type-142 activity aanmaken.
 *
 * @param bool        $dry_run              TRUE = niets muteren, alleen kandidaten tellen.
 * @param string      $label                Menselijke omschrijving van de stap.
 * @param string      $select_sql           SELECT die kandidaat-rijen oplevert.
 * @param callable    $mutate_row           Mutatie-callback: function(array $row, $extdebug): bool.
 * @param string      $activity_subject_tpl Subject-template met {tokens} uit de rij.
 * @param string      $activity_details_tpl Details-template met {tokens} uit de rij (bron-sqltask-tekst).
 * @param int         $activity_type_id     Activity type (142 = Cleanup data).
 * @param int|string  $extdebug             Wachthond-kanaal.
 *
 * @return array{label:string,dry_run:bool,rows:int}
 */
function _gdpr_exec_removerequest(
    bool $dry_run,
    string $label,
    string $select_sql,
    callable $mutate_row,
    string $activity_subject_tpl,
    string $activity_details_tpl,
    int $activity_type_id,
    $extdebug
): array {

    $select_sql = rtrim(trim($select_sql), ';');

    if ($dry_run) {
        $count_sql = _gdpr_count_sql_from_select($select_sql);
        wachthond($extdebug, 3, "[DRY-RUN] tel kandidaten: $label", $count_sql);
        $rows = (int) CRM_Core_DAO::singleValueQuery($count_sql);
        wachthond($extdebug, 3, "[DRY-RUN] kandidaten voor '$label'", $rows);
        return ['label' => $label, 'dry_run' => TRUE, 'rows' => $rows];
    }

    wachthond($extdebug, 3, "[RUN] selecteer kandidaten: $label", $select_sql);
    // LET OP: CRM_Core_DAO::storeValues() werkt NIET op een generieke executeQuery-DAO
    // (die kent geen fields()-definitie voor de ad-hoc SELECT-aliassen, dus $row bleef leeg
    // en er werd met id=0 gemuteerd = niets). fetchAll() geeft elke geselecteerde kolom als
    // associatieve array terug — dat is wat de mutate-callbacks en de activity-tokens nodig hebben.
    $rijen = CRM_Core_DAO::executeQuery($select_sql)->fetchAll();
    $rows  = 0;

    foreach ($rijen as $row) {

        wachthond($extdebug, 3, "[RUN] verwerk kandidaat voor '$label'", $row);
        $mutated = (bool) $mutate_row($row, $extdebug);
        if (!$mutated) {
            wachthond($extdebug, 3, "[RUN] kandidaat overgeslagen voor '$label'", $row);
            continue;
        }

        _gdpr_create_cleanup_activity($row, $activity_subject_tpl, $activity_details_tpl, $activity_type_id, $extdebug);
        $rows++;
    }

    wachthond($extdebug, 3, "[RUN] gemuteerde rijen voor '$label'", $rows);
    return ['label' => $label, 'dry_run' => FALSE, 'rows' => $rows];
}

/**
 * =======================================================================================
 * GDPR 1.0  CLEANUP CONTACTGEGEVENS (e-mail/telefoon oud1 & oud2)
 * =======================================================================================
 * Wanneer een oud-deelnemer 21+ wordt (en/of leiding is geworden) hoeven de historische
 * oud1/oud2-contactgegevens (location_type 11 en 12) niet meer bewaard te worden; de
 * actuele gegevens staan inmiddels in de reguliere locatietypes.
 */
function gdpr_cleanup_contactgegevens(bool $dry_run, $extdebug): array {

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GDPR 1.0 CLEANUP CONTACTGEGEVENS oud1/oud2", "[START]");
    wachthond($extdebug, 2, "########################################################################");

    $stappen = [];

    // 1.1 E-mail oud1/oud2 wissen nadat deelnemer leiding is geworden (21+).
    $stappen[] = _gdpr_exec_step($dry_run,
        '1.1 e-mail oud1/oud2 (leiding geworden, 21+)',
        "DELETE      e
         FROM       `civicrm_email`                      AS e
         INNER JOIN `civicrm_value_curriculum_103`       AS c
         INNER JOIN `civicrm_value_werving_270`          AS l
                 ON  e.contact_id = c.entity_id
         WHERE       l.nextkamp_rondjaren_1578  > 21
         AND         c.keren_leid_1438 > 1
         AND        (e.location_type_id = 11 OR e.location_type_id = 12)",
        "SELECT COUNT(*)
         FROM       `civicrm_email`                      AS e
         INNER JOIN `civicrm_value_curriculum_103`       AS c
         INNER JOIN `civicrm_value_werving_270`          AS l
                 ON  e.contact_id = c.entity_id
         WHERE       l.nextkamp_rondjaren_1578  > 21
         AND         c.keren_leid_1438 > 1
         AND        (e.location_type_id = 11 OR e.location_type_id = 12)",
        $extdebug);

    // 1.2 Telefoon oud1/oud2 wissen voor deelnemer ouder dan 21.
    $stappen[] = _gdpr_exec_step($dry_run,
        '1.2 telefoon oud1/oud2 (deelnemer > 21)',
        "DELETE      p
         FROM       `civicrm_phone`                      AS p
         INNER JOIN `civicrm_value_werving_270`          AS l
                     ON p.contact_id = l.entity_id
         WHERE       l.nextkamp_rondjaren_1578 > 21
         AND         (p.location_type_id = 11 OR p.location_type_id = 12)",
        "SELECT COUNT(*)
         FROM       `civicrm_phone`                      AS p
         INNER JOIN `civicrm_value_werving_270`          AS l
                     ON p.contact_id = l.entity_id
         WHERE       l.nextkamp_rondjaren_1578 > 21
         AND         (p.location_type_id = 11 OR p.location_type_id = 12)",
        $extdebug);

    // 1.3 E-mail oud1/oud2 wissen voor deelnemer ouder dan 21.
    $stappen[] = _gdpr_exec_step($dry_run,
        '1.3 e-mail oud1/oud2 (deelnemer > 21)',
        "DELETE      e
         FROM       `civicrm_email`                      AS e
         INNER JOIN `civicrm_value_werving_270`          AS l
                     ON e.contact_id = l.entity_id
         WHERE       l.nextkamp_rondjaren_1578 > 21
         AND         (e.location_type_id = 11 OR e.location_type_id = 12)",
        "SELECT COUNT(*)
         FROM       `civicrm_email`                      AS e
         INNER JOIN `civicrm_value_werving_270`          AS l
                     ON e.contact_id = l.entity_id
         WHERE       l.nextkamp_rondjaren_1578 > 21
         AND         (e.location_type_id = 11 OR e.location_type_id = 12)",
        $extdebug);

    // 1.4 Telefoon oud1/oud2 wissen nadat deelnemer leiding is geworden (21+).
    $stappen[] = _gdpr_exec_step($dry_run,
        '1.4 telefoon oud1/oud2 (leiding geworden, 21+)',
        "DELETE      p
         FROM       `civicrm_phone`                      AS p
         INNER JOIN `civicrm_value_curriculum_103`       AS c
         INNER JOIN `civicrm_value_werving_270`          AS l
                 ON p.contact_id = c.entity_id
         WHERE       l.nextkamp_rondjaren_1578  >= 21
         AND         c.keren_leid_1438 >= 1
         AND        (p.location_type_id = 11 OR p.location_type_id = 12)",
        "SELECT COUNT(*)
         FROM       `civicrm_phone`                      AS p
         INNER JOIN `civicrm_value_curriculum_103`       AS c
         INNER JOIN `civicrm_value_werving_270`          AS l
                 ON p.contact_id = c.entity_id
         WHERE       l.nextkamp_rondjaren_1578  >= 21
         AND         c.keren_leid_1438 >= 1
         AND        (p.location_type_id = 11 OR p.location_type_id = 12)",
        $extdebug);

    return $stappen;
}

/**
 * =======================================================================================
 * GDPR 2.0  CLEANUP ACTIVITEITEN
 * =======================================================================================
 * Activiteiten (logregels) worden na hun bewaartermijn verwijderd. Een set activity-types
 * is beschermd (event-registratie, GDPR-keuzes, cleanup-markers). Communicatie-activiteiten
 * hebben een kortere termijn; VOG-activiteiten een langere. Toestemmings-/voorwaarden-
 * activiteiten worden altijd opgeruimd (die worden telkens opnieuw vastgelegd).
 */
function gdpr_cleanup_activiteiten(bool $dry_run, $extdebug): array {

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GDPR 2.0 CLEANUP ACTIVITEITEN", "[START]");
    wachthond($extdebug, 2, "########################################################################");

    $stappen = [];

    // 2.1 Alles ouder dan 51 maanden, behalve beschermde types
    //     (5 Event Registration, 142 Cleanup data, 110 Comm Pref, 111 Terms Event,
    //      112 Terms Contrib, 113 Terms SLA, 114 GDPR Forget me).
    $stappen[] = _gdpr_exec_step($dry_run,
        '2.1 activiteiten > 51 maanden (m.u.v. beschermde types)',
        "DELETE a, ac
         FROM       `civicrm_activity_contact` AS ac
         INNER JOIN `civicrm_activity`         AS a
                ON ac.activity_id = a.id
         WHERE  a.activity_date_time < DATE_SUB(CURDATE(),INTERVAL 51 MONTH)
         AND    a.activity_type_id NOT IN (5,142,110,111,112,113,114)",
        "SELECT COUNT(DISTINCT a.id)
         FROM       `civicrm_activity_contact` AS ac
         INNER JOIN `civicrm_activity`         AS a
                ON ac.activity_id = a.id
         WHERE  a.activity_date_time < DATE_SUB(CURDATE(),INTERVAL 51 MONTH)
         AND    a.activity_type_id NOT IN (5,142,110,111,112,113,114)",
        $extdebug);

    // 2.2 Communicatie-activiteiten ouder dan 27 maanden
    //     (2 Phone, 3 Email, 4 Uitgaande SMS, 40 Scheduled reminder, 57 SMS delivery).
    $stappen[] = _gdpr_exec_step($dry_run,
        '2.2 communicatie-activiteiten > 27 maanden',
        "DELETE a, ac
         FROM       `civicrm_activity_contact` AS ac
         INNER JOIN `civicrm_activity`         AS a
                ON ac.activity_id = a.id
         WHERE  a.activity_date_time < DATE_SUB(CURDATE(),INTERVAL 27 MONTH)
         AND    a.activity_type_id IN (2,3,4,40,57)",
        "SELECT COUNT(DISTINCT a.id)
         FROM       `civicrm_activity_contact` AS ac
         INNER JOIN `civicrm_activity`         AS a
                ON ac.activity_id = a.id
         WHERE  a.activity_date_time < DATE_SUB(CURDATE(),INTERVAL 27 MONTH)
         AND    a.activity_type_id IN (2,3,4,40,57)",
        $extdebug);

    // 2.3 VOG-activiteiten (118 verzoek, 119 aanvraag, 120 ontvangst) ouder dan 7 jaar,
    //     alleen de target-record (record_type_id = 2).
    $stappen[] = _gdpr_exec_step($dry_run,
        '2.3 VOG-activiteiten > 7 jaar',
        "DELETE a, ac
         FROM       `civicrm_activity_contact` AS ac
         INNER JOIN `civicrm_activity`         AS a
                ON ac.activity_id = a.id
         WHERE  a.activity_type_id IN (118,119,120)
         AND    ac.record_type_id = 2
         AND    a.activity_date_time < DATE_SUB(NOW(), INTERVAL 7 YEAR)",
        "SELECT COUNT(DISTINCT a.id)
         FROM       `civicrm_activity_contact` AS ac
         INNER JOIN `civicrm_activity`         AS a
                ON ac.activity_id = a.id
         WHERE  a.activity_type_id IN (118,119,120)
         AND    ac.record_type_id = 2
         AND    a.activity_date_time < DATE_SUB(NOW(), INTERVAL 7 YEAR)",
        $extdebug);

    // 2.4 Alle toestemmings-/voorwaarden-activiteiten (110 Comm Pref, 111 Terms Event,
    //     112 Terms Contrib, 113 Terms SLA) — worden telkens opnieuw vastgelegd.
    $stappen[] = _gdpr_exec_step($dry_run,
        '2.4 toestemmings-/voorwaarden-activiteiten (types 110-113)',
        "DELETE a, ac
         FROM       `civicrm_activity_contact` AS ac
         INNER JOIN `civicrm_activity`         AS a
                ON ac.activity_id = a.id
         WHERE  a.activity_type_id IN (110,111,112,113)",
        "SELECT COUNT(DISTINCT a.id)
         FROM       `civicrm_activity_contact` AS ac
         INNER JOIN `civicrm_activity`         AS a
                ON ac.activity_id = a.id
         WHERE  a.activity_type_id IN (110,111,112,113)",
        $extdebug);

    return $stappen;
}

/**
 * =======================================================================================
 * GDPR 3.0  CLEANUP AANDACHTSPUNTEN (medisch / gedrag / interne notities)
 * =======================================================================================
 * De gevoeligste stap: medische gegevens, gedrag-shortlist en interne notities worden
 * geanonimiseerd/verwijderd zodra het laatste kampjaar van de deelnemer
 * (curriculum.laatste_keer_847, een JAARTAL) meer dan 2 jaar geleden is.
 *
 * LET OP: dit is exact de stap die brak toen kolom `medisch_doublecheck_1837` op 3-jul-2026
 * hernoemd werd naar `medisch_check_1837` (veldhernoeming medisch_doublecheck -> medisch_check).
 */
function gdpr_cleanup_aandachtspunten(bool $dry_run, $extdebug): array {

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GDPR 3.0 CLEANUP AANDACHTSPUNTEN medisch/gedrag/notities", "[START]");
    wachthond($extdebug, 2, "########################################################################");

    $stappen = [];

    // 3.1 Medisch tabje leegmaken (velden op NULL). Bewaartermijn: laatste kampjaar > 2 jaar geleden.
    //     Kolom `medisch_check_1837` (voorheen medisch_doublecheck_1837) is de reden dat sqltask 7 faalde.
    $stappen[] = _gdpr_exec_step($dry_run,
        '3.1 medisch tabje wissen (laatste kampjaar > 2 jaar geleden)',
        "UPDATE     `civicrm_value_medisch_148`    AS m
         INNER JOIN `civicrm_value_curriculum_103` AS c ON m.entity_id = c.entity_id
         SET     m.medisch_issues_1832         = NULL,
                 m.medisch_medicatie_1834      = NULL,
                 m.medisch_toelichting_1833    = NULL,
                 m.medisch_notities_1836       = NULL,
                 m.medisch_check_1837          = NULL,
                 m.zorgverzekering_naam_1885   = NULL,
                 m.zorgverzekering_nummer_1886 = NULL,
                 m.gevaccineerd_1330           = NULL,
                 m.getest_1337                 = NULL,
                 m.genezen_1338                = NULL
         WHERE   c.laatste_keer_847 IS NOT NULL
         AND     c.laatste_keer_847 < EXTRACT(YEAR FROM(DATE_SUB(CURDATE(),INTERVAL 2 YEAR)))",
        "SELECT COUNT(*)
         FROM       `civicrm_value_medisch_148`    AS m
         INNER JOIN `civicrm_value_curriculum_103` AS c ON m.entity_id = c.entity_id
         WHERE   c.laatste_keer_847 IS NOT NULL
         AND     c.laatste_keer_847 < EXTRACT(YEAR FROM(DATE_SUB(CURDATE(),INTERVAL 2 YEAR)))",
        $extdebug);

    // 3.2 Gedrag-tabje verwijderen (records met een shortlist), zelfde bewaartermijn.
    $stappen[] = _gdpr_exec_step($dry_run,
        '3.2 gedrag tabje wissen (laatste kampjaar > 2 jaar geleden)',
        "DELETE  g
         FROM       `civicrm_value_gedrag_322`      AS g
         INNER JOIN `civicrm_value_curriculum_103`  AS c ON g.entity_id = c.entity_id
         WHERE   g.gedrag_shortlist_1985 IS NOT NULL
         AND     c.laatste_keer_847      IS NOT NULL
         AND     c.laatste_keer_847 < EXTRACT(YEAR FROM(DATE_SUB(CURDATE(),INTERVAL 2 YEAR)))",
        "SELECT COUNT(*)
         FROM       `civicrm_value_gedrag_322`      AS g
         INNER JOIN `civicrm_value_curriculum_103`  AS c ON g.entity_id = c.entity_id
         WHERE   g.gedrag_shortlist_1985 IS NOT NULL
         AND     c.laatste_keer_847      IS NOT NULL
         AND     c.laatste_keer_847 < EXTRACT(YEAR FROM(DATE_SUB(CURDATE(),INTERVAL 2 YEAR)))",
        $extdebug);

    // 3.3 Interne notities gedrag/medisch/dieet op contactniveau wissen, zelfde bewaartermijn.
    $stappen[] = _gdpr_exec_step($dry_run,
        '3.3 interne notities gedrag/medisch/dieet wissen (laatste kampjaar > 2 jaar geleden)',
        "UPDATE     `civicrm_value_part_intern_241` AS i
         INNER JOIN `civicrm_value_curriculum_103`  AS c
         ON     i.entity_id = c.entity_id
         SET    i.internenotities_gedrag_1194  = NULL,
                i.internenotities_medisch_1196 = NULL,
                i.internenotities_dieet_1195   = NULL
         WHERE  i.internenotities_gedrag_1194 IS NOT NULL
         AND    c.laatste_keer_847            IS NOT NULL
         AND    c.laatste_keer_847 < EXTRACT(YEAR FROM(DATE_SUB(CURDATE(),INTERVAL 2 YEAR)))",
        "SELECT COUNT(*)
         FROM       `civicrm_value_part_intern_241` AS i
         INNER JOIN `civicrm_value_curriculum_103`  AS c
         ON     i.entity_id = c.entity_id
         WHERE  i.internenotities_gedrag_1194 IS NOT NULL
         AND    c.laatste_keer_847            IS NOT NULL
         AND    c.laatste_keer_847 < EXTRACT(YEAR FROM(DATE_SUB(CURDATE(),INTERVAL 2 YEAR)))",
        $extdebug);

    return $stappen;
}

/**
 * =======================================================================================
 * GDPR 4.0  CLEANUP DEELNAME-INFO PER PARTICIPANT (> 7 jaar)
 * =======================================================================================
 * Per-participant-tabjes (gedrag, interne gedragsnotities, deelname-intern, leiding-intern,
 * participant-intern) worden verwijderd zodra het bijbehorende event meer dan 7 jaar geleden
 * startte. Alleen echte kamp-event-types (1,11,12,13,14,21,22,23,24,33).
 */
function gdpr_cleanup_partinfo(bool $dry_run, $extdebug): array {

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GDPR 4.0 CLEANUP DEELNAME-INFO PER PARTICIPANT (> 7 jaar)", "[START]");
    wachthond($extdebug, 2, "########################################################################");

    // De vijf per-participant custom-tabellen met hun alias, in dezelfde volgorde als sqltask 7.
    $tabellen = [
        'PG' => 'civicrm_value_part_gedrag_358',        // gedrag per deelname
        'GI' => 'civicrm_value_part_gedrag_intern_359', // interne gedragsnotities per deelname
        'DI' => 'civicrm_value_part_deel_int_271',      // deelname-interne info
        'LI' => 'civicrm_value_part_leid_int_300',      // leiding-interne info
        'PI' => 'civicrm_value_part_intern_241',        // participant-interne info
    ];

    $event_types = '1,11,12,13,14,21,22,23,24,33';
    $stappen     = [];
    $i           = 0;

    foreach ($tabellen as $alias => $tabel) {
        $i++;
        // Bouw het join-blok dynamisch; identiek voor elke tabel op de alias/tabel na.
        $from = "FROM       civicrm_contact                              AS CC
                 INNER JOIN `civicrm_participant`                  AS PP ON CC.id = PP.contact_id
                 INNER JOIN `$tabel`                               AS $alias ON PP.id = $alias.entity_id
                 INNER JOIN `civicrm_event`                        AS EV ON EV.id = PP.event_id
                 WHERE EV.event_type_id   IN ($event_types)
                 AND   EV.start_date < date_sub(now(), interval 7 year)";

        $stappen[] = _gdpr_exec_step($dry_run,
            "4.$i deelname-info $tabel (> 7 jaar)",
            "DELETE $alias $from",
            "SELECT COUNT(DISTINCT $alias.id) $from",
            $extdebug);
    }

    return $stappen;
}

/**
 * =======================================================================================
 * GDPR HOOFDMOTOR: draai de volledige opschoning
 * =======================================================================================
 * Roept alle vier de opschoongroepen aan en levert een platte samenvatting terug.
 * Deze functie wordt aangeroepen door de APIv3-actie Gdpr.cleanup (zie api/v3/Gdpr/Cleanup.php),
 * die op zijn beurt door de dagelijkse Scheduled Job (gdpr.mgd.php) wordt getriggerd.
 *
 * @param bool        $dry_run  TRUE = alleen tellen, niets muteren.
 * @param int|string  $extdebug Wachthond-kanaal.
 *
 * @return array{dry_run:bool,totaal_rijen:int,stappen:array}
 */
function gdpr_cleanup_run(bool $dry_run = FALSE, $extdebug = 'gdpr.cleanup'): array {

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GDPR CLEANUP MOTOR", $dry_run ? "[DRY-RUN]" : "[RUN]");
    wachthond($extdebug, 2, "########################################################################");

    // Voer de vier opschoongroepen uit in dezelfde volgorde als sqltask 7.
    $stappen = array_merge(
        gdpr_cleanup_contactgegevens($dry_run, $extdebug),
        gdpr_cleanup_activiteiten($dry_run, $extdebug),
        gdpr_cleanup_aandachtspunten($dry_run, $extdebug),
        gdpr_cleanup_partinfo($dry_run, $extdebug),
    );

    // Tel het totaal aantal geraakte (of, bij dry-run, kandidaat-) rijen.
    $totaal_rijen = array_sum(array_column($stappen, 'rows'));

    $samenvatting = [
        'dry_run'      => $dry_run,
        'totaal_rijen' => $totaal_rijen,
        'stappen'      => $stappen,
    ];
    wachthond($extdebug, 1, "### GDPR CLEANUP MOTOR KLAAR", $samenvatting);

    return $samenvatting;
}
