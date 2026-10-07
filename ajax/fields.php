<?php

/**
 * Item rule form: list of fields available for the chosen item and the block's
 * action, with the picker configuration.
 */

if (!defined('GLPI_ROOT')) {
    $AJAX_INCLUDE = 1;
    include_once __DIR__ . '/../../../inc/includes.php';
    header('Content-Type: text/html; charset=UTF-8');
    Html::header_nocache();
}

use GlpiPlugin\Fieldconstraints\ActionBlock;
use GlpiPlugin\Fieldconstraints\ActionRule;

Session::checkRight('config', UPDATE);

$itemtype = (string) ($_POST['itemtype'] ?? $_GET['itemtype'] ?? '');
$block    = new ActionBlock();
if (!$block->getFromDB((int) ($_POST['block'] ?? $_GET['block'] ?? 0))) {
    return;
}
$status = (int) $block->fields['status'];
if (!in_array($itemtype, ActionBlock::getItemtypesFor($status), true)) {
    return;
}

// Keep what the rule already requires: whatever no longer applies is shown apart.
$selected = [];
$rule     = new ActionRule();
$id       = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
if ($id > 0 && $rule->getFromDB($id)) {
    $selected = ActionRule::decodeRequiredFields($rule->fields['required_fields']);
}

ActionRule::showFieldsDropdown($itemtype, $status, $selected);
