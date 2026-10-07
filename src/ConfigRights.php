<?php

namespace GlpiPlugin\Fieldconstraints;

use Session;

/**
 * Rules are managed with the core `config` right, which only carries READ and
 * UPDATE: CREATE, DELETE and PURGE are mapped onto UPDATE.
 */
trait ConfigRights
{
    public static function canView(): bool
    {
        return (bool) Session::haveRight('config', READ);
    }

    public static function canCreate(): bool
    {
        return (bool) Session::haveRight('config', UPDATE);
    }

    public static function canUpdate(): bool
    {
        return (bool) Session::haveRight('config', UPDATE);
    }

    public static function canDelete(): bool
    {
        return (bool) Session::haveRight('config', UPDATE);
    }

    public static function canPurge(): bool
    {
        return (bool) Session::haveRight('config', UPDATE);
    }
}
