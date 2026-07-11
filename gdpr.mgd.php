<?php

/**
 * Managed entities: dagelijkse Scheduled Jobs voor de GDPR-migratie.
 *
 * Verschijnt in Beheer -> Systeeminstellingen -> Scheduled Jobs. Wordt automatisch
 * aangemaakt bij het inschakelen van de extensie (mgd-php@1.0.0 mixin).
 *
 * BELANGRIJK - is_active staat bewust overal op FALSE:
 *   Zolang de losse database-taken nog actief zijn, mogen deze jobs NIET meelopen
 *   (dubbele opschoning / dubbele verwijdermails). De cutover is: eerst de bron-sqltasks
 *   uitzetten, dan pas de bijbehorende job activeren.
 *
 * Er is bewust één job per gemigreerde taakgroep:
 *   - cleanup: bestaande dataminimalisatie (sqltask 7),
 *   - removerequest: voorkeur 44 erasure (sqltasks 106/112/113/114/163),
 *   - optoutgroepen: publieke nieuwsbriefgroepen (sqltask 11),
 *   - syncbackfill: eenmalige privacy/core-correctie (niet automatisch activeren).
 *
 * 'update' => 'unmodified' zorgt dat een handmatige activering (is_active) door een admin
 * NIET teruggezet wordt bij een volgende cache-flush.
 */
return [
    [
        'name'    => 'Cron_Gdpr_Cleanup',
        'entity'  => 'Job',
        'update'  => 'unmodified',
        'cleanup' => 'always',
        'params'  => [
            'version'       => 4,
            'values'        => [
                'name'          => 'Onvergetelijk - GDPR Cleanup',
                'description'   => 'AVG-dataminimalisatie: wist medische/gedrags-/contactgegevens van oud-deelnemers na afloop van de bewaartermijn (eigen extensie nl.onvergetelijk.gdpr). Migratie van sqltask 7 - activeer pas NADAT sqltask 7 is uitgezet.',
                'run_frequency' => 'Daily',
                'api_entity'    => 'Gdpr',
                'api_action'    => 'cleanup',
                'parameters'    => "dry_run=0",
                'is_active'     => FALSE,
            ],
        ],
    ],
    [
        'name'    => 'Cron_Gdpr_RemoveRequest',
        'entity'  => 'Job',
        'update'  => 'unmodified',
        'cleanup' => 'always',
        'params'  => [
            'version'       => 4,
            'values'        => [
                'name'          => 'Onvergetelijk - GDPR Remove Request',
                'description'   => 'AVG erasure: verwijdert e-mail/telefoon/adres voor contacten met Privacy.contactvoorkeuren 44 en zet gerelateerde e-mail on-hold. Migratie van sqltasks 106/112/113/114/163 - activeer pas NADAT de bron-sqltasks zijn uitgezet.',
                'run_frequency' => 'Daily',
                'api_entity'    => 'Gdpr',
                'api_action'    => 'removerequest',
                'parameters'    => "dry_run=0",
                'is_active'     => FALSE,
            ],
        ],
    ],
    [
        'name'    => 'Cron_Gdpr_OptoutGroepen',
        'entity'  => 'Job',
        'update'  => 'unmodified',
        'cleanup' => 'always',
        'params'  => [
            'version'       => 4,
            'values'        => [
                'name'          => 'Onvergetelijk - GDPR Optout Groepen',
                'description'   => 'AVG opt-out: zet opt-out-contacten op Removed in publieke nieuwsbriefgroepen. Migratie van sqltask 11 - activeer pas NADAT de bron-sqltask is uitgezet.',
                'run_frequency' => 'Daily',
                'api_entity'    => 'Gdpr',
                'api_action'    => 'optoutgroepen',
                'parameters'    => "dry_run=0",
                'is_active'     => FALSE,
            ],
        ],
    ],
    [
        'name'    => 'Cron_Gdpr_SyncBackfill',
        'entity'  => 'Job',
        'update'  => 'unmodified',
        'cleanup' => 'always',
        'params'  => [
            'version'       => 4,
            'values'        => [
                'name'          => 'Onvergetelijk - GDPR Sync Backfill',
                'description'   => 'Eenmalige AVG backfill: corrigeert core is_opt_out/do_not_email voor bestaande contacten met Privacy.contactvoorkeuren 33/44. Alleen handmatig activeren na dry-runvergelijking.',
                'run_frequency' => 'Daily',
                'api_entity'    => 'Gdpr',
                'api_action'    => 'syncbackfill',
                'parameters'    => "dry_run=0",
                'is_active'     => FALSE,
            ],
        ],
    ],
];
