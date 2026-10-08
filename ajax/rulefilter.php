<?php

/**
 * Filter editor ("Filters" tab of an item rule): operators of a criterion and
 * value widget of a criterion + operator.
 */

if (!defined('GLPI_ROOT')) {
    $AJAX_INCLUDE = 1;
    include_once __DIR__ . '/../../../inc/includes.php';
    header('Content-Type: text/html; charset=UTF-8');
    Html::header_nocache();
}

use GlpiPlugin\Fieldconstraints\RuleFilter;

Session::checkRight('config', UPDATE);

$criterion = (string) ($_POST['criterion'] ?? $_GET['criterion'] ?? '');
$operator  = (string) ($_POST['operator'] ?? $_GET['operator'] ?? RuleFilter::EQUALS);
if (!isset(RuleFilter::getCriteria()[$criterion])) {
    return;
}

if (($_POST['part'] ?? $_GET['part'] ?? '') === 'operator') {
    RuleFilter::showOperatorDropdown($criterion, $operator, (int) ($_POST['rand'] ?? $_GET['rand'] ?? 0), true);
} else {
    RuleFilter::showValuesDropdown($criterion, $operator, []);
}
