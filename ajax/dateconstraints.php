<?php

/**
 * Date constraints for the date pickers of a page.
 *
 *  - source=fields: every active Fields constraint (inputs absent from the page
 *    are simply not found);
 *  - source=formcreator|native&id=<form>: the constraints of that form.
 *
 * Only there so the pickers already refuse what the server would.
 */

if (!defined('GLPI_ROOT')) {
    $AJAX_INCLUDE = 1;
    include_once __DIR__ . '/../../../inc/includes.php';
    Html::header_nocache();
}
header('Content-Type: application/json; charset=UTF-8');

use GlpiPlugin\Fieldconstraints\DateConstraint;
use GlpiPlugin\Fieldconstraints\FormEngine;
use GlpiPlugin\Fieldconstraints\FormHints;

Session::checkLoginUser();

$source = (string) ($_GET['source'] ?? '');
$id     = (int) ($_GET['id'] ?? 0);

$config = in_array($source, [DateConstraint::SOURCE_FIELDS, FormEngine::FORMCREATOR, FormEngine::NATIVE], true)
    ? DateConstraint::clientConfig($source, $id)
    : [];

echo FormHints::json(['constraints' => $config]);
