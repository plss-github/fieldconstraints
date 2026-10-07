<?php

/**
 * Writes of the "Date constraints" tab (Fields container, Formcreator form,
 * native form). Whoever may update the container or form may manage its
 * constraints.
 *
 * CSRF is checked by core before this script runs (inc/includes.php on GLPI 10,
 * the kernel on GLPI 11).
 */

if (!defined('GLPI_ROOT')) {
    // GLPI 10 runs plugin front scripts cold; GLPI 11 has already booted.
    include_once __DIR__ . '/../../../inc/includes.php';
}

use GlpiPlugin\Fieldconstraints\Compat;
use GlpiPlugin\Fieldconstraints\DateConstraint;

Session::checkLoginUser();

$constraint = new DateConstraint();

if (isset($_POST['add'])) {
    $source    = (string) ($_POST['source'] ?? '');
    $parent_id = (int) ($_POST['items_id'] ?? 0);
} elseif ((isset($_POST['update']) || isset($_POST['purge'])) && $constraint->getFromDB((int) ($_POST['id'] ?? 0))) {
    $source    = (string) $constraint->fields['source'];
    $parent_id = (int) $constraint->fields['items_id'];
} else {
    Html::back();
    return;
}

$class  = DateConstraint::getParentClasses()[$source] ?? null;
$parent = $class !== null ? getItemForItemtype($class) : null;
if (!$parent || !$parent->can($parent_id, UPDATE)) {
    Compat::denyAccess();
}

if (isset($_POST['add'])) {
    $constraint->add($_POST);
} elseif (isset($_POST['update'])) {
    $constraint->update(['id' => $constraint->getID(), 'is_active' => (int) ($_POST['is_active'] ?? 0)]);
} else {
    $constraint->delete(['id' => $constraint->getID()], true);
}

Html::back();
