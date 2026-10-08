<?php

/**
 * Field Constraints - blocks status changes of ITIL objects (solve, close, put on
 * hold...) until the fields required by an administrator-defined rule are filled.
 *
 * A single codebase runs on GLPI 10.0 and 11.0; every divergence between the two
 * goes through GlpiPlugin\Fieldconstraints\Compat.
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Fieldconstraints\ActionBlock;
use GlpiPlugin\Fieldconstraints\ActionRule;
use GlpiPlugin\Fieldconstraints\Compat;
use GlpiPlugin\Fieldconstraints\DateConstraint;
use GlpiPlugin\Fieldconstraints\DateEnforcer;
use GlpiPlugin\Fieldconstraints\Enforcer;
use GlpiPlugin\Fieldconstraints\FormHints;
use GlpiPlugin\Fieldconstraints\RuleFilter;

define('PLUGIN_FIELDCONSTRAINTS_VERSION', '1.1.0');

// Plugin::checkGlpiVersion() treats `max` as exclusive: every 10.0.x and 11.0.x.
define('PLUGIN_FIELDCONSTRAINTS_MIN_GLPI', '10.0.0');
define('PLUGIN_FIELDCONSTRAINTS_MAX_GLPI', '11.1.0');

function plugin_version_fieldconstraints(): array
{
    return [
        // Translated like core's plugins do: GLPI re-reads this, with the plugin's
        // catalog loaded, whenever it lists plugins.
        'name'         => __('Field Constraints', 'fieldconstraints'),
        'version'      => PLUGIN_FIELDCONSTRAINTS_VERSION,
        'author'       => 'Ampris',
        'license'      => 'GPLv3+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_FIELDCONSTRAINTS_MIN_GLPI,
                'max' => PLUGIN_FIELDCONSTRAINTS_MAX_GLPI,
            ],
            'php'  => ['min' => '8.0'],
        ],
    ];
}

function plugin_fieldconstraints_check_prerequisites(): bool
{
    return true;
}

function plugin_init_fieldconstraints(): void
{
    global $PLUGIN_HOOKS;

    // Required by GLPI 10 for the plugin's own POST forms; ignored by GLPI 11.
    $PLUGIN_HOOKS['csrf_compliant']['fieldconstraints'] = true;

    if (!Plugin::isPluginActive('fieldconstraints')) {
        return;
    }

    Plugin::registerClass(ActionBlock::class);
    Plugin::registerClass(ActionRule::class);
    Plugin::registerClass(RuleFilter::class);

    // "Date constraints" tab on Fields containers and on forms. Guarded: a
    // throw here silently deactivates the plugin, and the database may not be
    // ready yet (install/update).
    try {
        $parents = array_values(DateConstraint::getParentClasses());
    } catch (Throwable $e) {
        $parents = [];
    }
    if ($parents !== []) {
        Plugin::registerClass(DateConstraint::class, ['addtabon' => $parents]);
    }

    // Enforcement hooks live outside any session check: a status change made
    // through the API, a massive action or the auto-close cron must obey the
    // rules as well.
    //
    // Plugin::doHook() keys on the exact class name, so every concrete class is
    // listed -- TicketTask rather than CommonITILTask.
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['fieldconstraints'] = [
        'Ticket'  => [Enforcer::class, 'preItilUpdate'],
        'Change'  => [Enforcer::class, 'preItilUpdate'],
        'Problem' => [Enforcer::class, 'preItilUpdate'],
    ];

    $PLUGIN_HOOKS[Hooks::PRE_ITEM_ADD]['fieldconstraints'] = [
        'ITILSolution' => [Enforcer::class, 'preSolutionAdd'],
        'ITILFollowup' => [Enforcer::class, 'preTimelineAdd'],
        'TicketTask'   => [Enforcer::class, 'preTimelineAdd'],
        'ChangeTask'   => [Enforcer::class, 'preTimelineAdd'],
        'ProblemTask'  => [Enforcer::class, 'preTimelineAdd'],

        // Date constraints of forms.
        DateEnforcer::FORMCREATOR_ANSWER => [DateEnforcer::class, 'preFormcreatorAnswer'],
        DateEnforcer::NATIVE_ANSWERS     => [DateEnforcer::class, 'preNativeAnswers'],
    ];

    // Fields values are written through the class Fields generates per
    // container and itemtype, not through the item: those classes get the
    // date-constraint hooks.
    foreach (DateEnforcer::watchedFieldsClasses() as $class) {
        $PLUGIN_HOOKS[Hooks::PRE_ITEM_ADD]['fieldconstraints'][$class]    = [DateEnforcer::class, 'preFieldsValues'];
        $PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['fieldconstraints'][$class] = [DateEnforcer::class, 'preFieldsValues'];
    }

    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['fieldconstraints'] = [
        'ITILSolution' => [Enforcer::class, 'solutionAdded'],
    ];

    if (Session::getLoginUserID()) {
        // Notice above the solution button and red `*` on required labels.
        $PLUGIN_HOOKS[Hooks::POST_ITEM_FORM]['fieldconstraints'] = [FormHints::class, 'postItemForm'];

        // GLPI 10 resolves the path from the plugin root (and checks the file
        // exists, so it must stay a bare path); GLPI 11 serves public/ at the
        // plugin URL. The browser cache key GLPI adds is the plugin version, so on
        // GLPI 11 the file's mtime is added too: a changed script is never served
        // stale. On GLPI 10, bump the version on every release.
        $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['fieldconstraints'][] = Compat::isLegacy()
            ? 'public/fieldconstraints.js'
            : 'fieldconstraints.js?m=' . (int) @filemtime(__DIR__ . '/public/fieldconstraints.js');
    }

    // GLPI builds the menu once per session ($_SESSION['glpimenu']): after an
    // update, a session opened before it would keep the old entries (stale
    // links, no breadcrumb for the new pages) until the user logs in again.
    if (Session::getLoginUserID() && ($_SESSION['plugin_fieldconstraints_menu'] ?? null) !== PLUGIN_FIELDCONSTRAINTS_VERSION) {
        unset($_SESSION['glpimenu']);
        $_SESSION['plugin_fieldconstraints_menu'] = PLUGIN_FIELDCONSTRAINTS_VERSION;
    }

    if (Session::getLoginUserID() && Session::haveRight('config', READ)) {
        $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['fieldconstraints'] = 'front/actionblock.php';
        $PLUGIN_HOOKS[Hooks::MENU_TOADD]['fieldconstraints']  = ['config' => ActionBlock::class];
    }
}
