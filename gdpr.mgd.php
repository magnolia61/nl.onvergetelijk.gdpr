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
                'name'          => 'Onvergetelijk - GDPR Sync Reconciliatie',
                'description'   => 'Dagelijkse AVG-reconciliatie: corrigeert core is_opt_out/do_not_email voor contacten met Privacy.contactvoorkeuren 33/44 (incl. het na het kampseizoen alsnog afronden van uitgestelde verwijderverzoeken zodra de actieve registratie is verlopen). Stuurt zelf nooit mail. Eerste activering pas na dry-runvergelijking.',
                'run_frequency' => 'Daily',
                'api_entity'    => 'Gdpr',
                'api_action'    => 'syncbackfill',
                'parameters'    => "dry_run=0",
                'is_active'     => FALSE,
            ],
        ],
    ],
    [
        // Bevestigingsmail bij een verwijderverzoek TIJDENS het kampseizoen (voorkeur 44
        // mét actieve registratie): bulk stopt direct, praktische kampmail blijft, de
        // gegevens worden na het seizoen verwijderd. CONCEPT-tekst — door Richard te
        // redigeren en door de cssinliner/huisstijl-workflow te halen vóór activering
        // van de sync-hooks in productie.
        'name'    => 'MsgTpl_Gdpr_Uitstel_Verwijderverzoek',
        'entity'  => 'MessageTemplate',
        'update'  => 'unmodified',
        'cleanup' => 'unused',
        'params'  => [
            'version' => 4,
            'values'  => [
                'msg_title'    => 'GDPR - Verwijderverzoek tijdens kampseizoen',
                'msg_subject'  => 'Je afmelding is verwerkt',
                'msg_html'     => '<p>Hallo {contact.first_name},</p>'
                    . '<p>We hebben je verzoek ontvangen om je gegevens te laten verwijderen en je '
                    . 'af te melden voor onze mailings. Bij deze bevestigen we dat je vanaf nu geen '
                    . 'nieuwsbrieven en mailings meer van ons ontvangt.</p>'
                    . '<p>Omdat er dit jaar nog een kampdeelname op jouw naam geregistreerd staat, '
                    . 'blijven we je wél de praktische e-mails rond het kamp sturen — zoals de '
                    . 'bevestiging, betaalinformatie en de kampinformatie. Die heb je nodig om goed '
                    . 'voorbereid op kamp te kunnen.</p>'
                    . '<p>Na afloop van het kampseizoen verwijderen we je contactgegevens definitief. '
                    . 'Daar hoef je verder niets voor te doen.</p>'
                    . '<p>Heb je vragen, of wil je tóch dat we alles per direct verwijderen? '
                    . 'Mail ons gerust op info@onvergetelijk.nl.</p>'
                    . '<div class="ozk-groet">Hartelijke groet,<br/>Stichting Onvergetelijke Zomerkampen'
                    . '<br/><div class="site-logo">{site.smarty_logo}</div></div>',
                'msg_text'     => '',
                'is_active'    => TRUE,
                'is_reserved'  => FALSE,
                'is_default'   => FALSE,
            ],
        ],
    ],
];
