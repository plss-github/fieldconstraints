<?php

/**
 * Filters of an item rule (its "Filters" tab posts here).
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
use GlpiPlugin\Fieldconstraints\RuleFilter;

Session::checkRight('config', READ);

$filter = new RuleFilter();

if (isset($_POST['add'])) {
    $filter->check(-1, CREATE, $_POST);
    $filter->add($_POST);
    Html::back();
} elseif (isset($_POST['update'])) {
    $filter->check((int) $_POST['id'], UPDATE);
    $filter->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $filter->check((int) $_POST['id'], PURGE);
    $rule_id = (int) ($filter->fields[RuleFilter::$items_id] ?? 0);
    $filter->delete($_POST, true);
    // Back to the rule's "Filters" tab, also when deleted from the filter's own page.
    Html::redirect(ActionRule::getFormURLWithID($rule_id) . '&forcetab=' . urlencode(RuleFilter::class . '$1'));
}

Html::header(
    ActionBlock::getTypeName(Session::getPluralNumber()),
    '',
    'config',
    ActionBlock::class
);

$id = (int) ($_GET['id'] ?? -1);
if ($id <= 0) {
    // Filters are added from the rule's "Filters" tab only.
    Html::redirect(ActionBlock::getSearchURL());
}
$filter->check($id, READ);
$filter->display(['id' => $id]);

Html::footer();
