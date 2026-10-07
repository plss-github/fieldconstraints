<?php

/**
 * Form of an action rule block.
 *
 * CSRF is checked by core before this script runs (inc/includes.php on GLPI 10,
 * the kernel on GLPI 11).
 */

if (!defined('GLPI_ROOT')) {
    // GLPI 10 runs plugin front scripts cold; GLPI 11 has already booted.
    include_once __DIR__ . '/../../../inc/includes.php';
}

use GlpiPlugin\Fieldconstraints\ActionBlock;

Session::checkRight('config', READ);

$rule = new ActionBlock();

if (isset($_POST['add'])) {
    $rule->check(-1, CREATE, $_POST);
    if ($rule->add($_POST)) {
        Html::redirect($rule->getLinkURL());
    }
    Html::back();
} elseif (isset($_POST['update'])) {
    $rule->check((int) $_POST['id'], UPDATE);
    $rule->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $rule->check((int) $_POST['id'], PURGE);
    $rule->delete($_POST, true);
    $rule->redirectToList();
}

Html::header(
    ActionBlock::getTypeName(Session::getPluralNumber()),
    '',
    'config',
    ActionBlock::class
);

$rule->display(['id' => (int) ($_GET['id'] ?? -1)]);

Html::footer();
