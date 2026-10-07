<?php

/**
 * Required labels and date rules of an item page (Ticket, Change, Problem).
 */

if (!defined('GLPI_ROOT')) {
    $AJAX_INCLUDE = 1;
    include_once __DIR__ . '/../../../inc/includes.php';
    Html::header_nocache();
}
header('Content-Type: application/json; charset=UTF-8');

use GlpiPlugin\Fieldconstraints\FormHints;
use GlpiPlugin\Fieldconstraints\ActionBlock;

Session::checkLoginUser();

$itemtype = (string) ($_GET['itemtype'] ?? '');
$id       = (int) ($_GET['id'] ?? 0);

$config = ['marks' => [], 'dates' => []];
if (in_array($itemtype, ActionBlock::ITEMTYPES, true)) {
    $item = new $itemtype();
    if ($id > 0 ? $item->can($id, READ) : $item->canCreate()) {
        if ($id <= 0) {
            $item->getEmpty();
        }
        $config = FormHints::itemConfig($item);
    }
}

echo FormHints::json($config);
