<?php

namespace GlpiPlugin\Fieldconstraints;

use Glpi\Toolbox\Sanitizer;
use Plugin;

/**
 * Single place where GLPI 10 and GLPI 11 differ.
 *
 * The difference that matters here is text: GLPI 10 stores user text
 * HTML-encoded (Sanitizer) and its dropdown helpers print labels as given, while
 * GLPI 11 stores raw text and escapes on output.
 */
final class Compat
{
    public static function isLegacy(): bool
    {
        return version_compare(GLPI_VERSION, '11.0', '<');
    }

    /**
     * Plain text from a value stored by core or another plugin.
     */
    public static function plain(?string $stored): string
    {
        $stored = (string) $stored;

        if ($stored === '' || !self::isLegacy()) {
            return $stored;
        }

        // Idempotent: Sanitizer skips values that are not encoded.
        return (string) Sanitizer::unsanitize($stored);
    }

    /**
     * HTML-escapes plain text.
     */
    public static function escape(string $plain): string
    {
        if (function_exists('htmlescape')) {
            return htmlescape($plain);
        }

        return htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * A label for Dropdown::showFromArray(): GLPI 10 prints it verbatim, GLPI 11
     * escapes it itself.
     */
    public static function dropdownLabel(string $plain): string
    {
        return self::isLegacy() ? self::escape($plain) : $plain;
    }

    /**
     * A string ready for the query builder.
     *
     * GLPI 10's DBmysql::quoteValue() does not escape (data is expected to arrive
     * pre-escaped); GLPI 11's does.
     */
    public static function forDb(string $value): string
    {
        global $DB;

        if (!self::isLegacy() || $value === '') {
            return $value;
        }

        return $DB->escape($value);
    }

    /**
     * Refuses the request: GLPI 11 turns the exception into a 403 page, GLPI 10
     * has its own helper (which exits).
     */
    public static function denyAccess(): void
    {
        if (class_exists(\Glpi\Exception\Http\AccessDeniedHttpException::class)) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }

        \Html::displayRightError();
        exit;
    }

    /**
     * Public URL of the plugin directory.
     *
     * GLPI 10 may serve the plugin from /marketplace; GLPI 11 always routes it
     * through /plugins.
     */
    public static function webDir(): string
    {
        global $CFG_GLPI;

        if (self::isLegacy()) {
            return (string) Plugin::getWebDir('fieldconstraints', true);
        }

        return $CFG_GLPI['root_doc'] . '/plugins/fieldconstraints';
    }
}
