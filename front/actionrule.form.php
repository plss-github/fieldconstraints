<?php

/**
 * Form of an item rule (rule of an action block for one item).
 *
 * CSRF is checked by core before this script runs (inc/includes.php on GLPI 10,
 * the kernel on GLPI 11).
 */

if (!defined('GLPI_ROOT')) {
    // GLPI 10 runs plugin front scripts cold; GLPI 11 has already booted.
    include_once __DIR__ . '/../../../inc/includes.php';
}

use GlpiPlugin\Fieldconstraints\ActionBlock;
use GlpiPlugin\Fieldconstraints\ActionRule;

Session::checkRight('config', READ);

$rule = new ActionRule();

if (isset($_POST['add'])) {
    $rule->check(-1, CREATE, $_POST);
    if ($id = $rule->add($_POST)) {
        Html::redirect(ActionRule::getFormURLWithID($id));
    }
    Html::back();
} elseif (isset($_POST['update'])) {
    $rule->check((int) $_POST['id'], UPDATE);
    $rule->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $rule->check((int) $_POST['id'], PURGE);
    $block_id = (int) ($rule->fields[ActionRule::$items_id] ?? 0);
    $rule->delete($_POST, true);
    Html::redirect(ActionBlock::getFormURLWithID($block_id));
}

Html::header(
    ActionBlock::getTypeName(Session::getPluralNumber()),
    '',
    'config',
    ActionBlock::class
);

$id = (int) ($_GET['id'] ?? -1);
if ($id > 0) {
    $rule->check($id, READ);
}
// display(): standard page title and tabs; the options reach showForm() through the tab URL.
$rule->display([
    'id'                  => $id,
    ActionRule::$items_id => (int) ($_GET[ActionRule::$items_id] ?? 0),
]);

Html::footer();
