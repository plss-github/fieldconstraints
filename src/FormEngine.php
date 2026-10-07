<?php

namespace GlpiPlugin\Fieldconstraints;

use Plugin;

/**
 * The form engine of this GLPI: Formcreator on GLPI 10, native forms on GLPI 11
 * (Formcreator 3 on GLPI 11 only migrates forms to the native engine).
 *
 * Questions are identified by `question:<id>`; the id is the engine's own.
 */
final class FormEngine
{
    public const FORMCREATOR = 'formcreator';
    public const NATIVE      = 'native';

    private const NATIVE_DATETIME = 'Glpi\\Form\\QuestionType\\QuestionTypeDateTime';

    public static function current(): ?string
    {
        global $DB;

        if (!Compat::isLegacy()) {
            return $DB->tableExists('glpi_forms_forms') ? self::NATIVE : null;
        }

        if (Plugin::isPluginActive('formcreator') && $DB->tableExists('glpi_plugin_formcreator_forms')) {
            return self::FORMCREATOR;
        }

        return null;
    }

    public static function label(?string $engine): string
    {
        return match ($engine) {
            self::FORMCREATOR => 'Formcreator',
            self::NATIVE      => __('GLPI forms', 'fieldconstraints'),
            default           => __('No form engine available', 'fieldconstraints'),
        };
    }

    /**
     * @return array<int, string> form id => name
     */
    public static function getForms(?string $engine = null): array
    {
        global $DB;

        $engine ??= self::current();
        $table   = match ($engine) {
            self::FORMCREATOR => 'glpi_plugin_formcreator_forms',
            self::NATIVE      => 'glpi_forms_forms',
            default           => null,
        };
        if ($table === null) {
            return [];
        }

        $forms = [];
        foreach ($DB->request(['FROM' => $table, 'WHERE' => ['is_deleted' => 0], 'ORDER' => 'name']) as $row) {
            $forms[(int) $row['id']] = Compat::plain($row['name']);
        }

        return $forms;
    }

    public static function getFormName(string $engine, int $forms_id): string
    {
        return self::getForms($engine)[$forms_id] ?? sprintf(__('Form #%d (removed)', 'fieldconstraints'), $forms_id);
    }

    /**
     * Date and date+time questions of a form.
     *
     * @return array<string, string> `question:<id>` => label
     */
    public static function getDateQuestions(string $engine, int $forms_id): array
    {
        global $DB;

        $questions = [];

        if ($engine === self::FORMCREATOR) {
            $iterator = $DB->request([
                'SELECT'     => ['q.id', 'q.name'],
                'FROM'       => 'glpi_plugin_formcreator_questions AS q',
                'INNER JOIN' => [
                    'glpi_plugin_formcreator_sections AS s' => [
                        'ON' => ['q' => 'plugin_formcreator_sections_id', 's' => 'id'],
                    ],
                ],
                'WHERE'      => [
                    's.plugin_formcreator_forms_id' => $forms_id,
                    'q.fieldtype'                   => ['date', 'datetime'],
                ],
                'ORDER'      => ['s.order', 'q.row', 'q.col'],
            ]);
            foreach ($iterator as $row) {
                $questions['question:' . $row['id']] = Compat::plain($row['name']);
            }
        } elseif ($engine === self::NATIVE) {
            $iterator = $DB->request([
                'SELECT'     => ['q.id', 'q.name', 'q.extra_data'],
                'FROM'       => 'glpi_forms_questions AS q',
                'INNER JOIN' => [
                    'glpi_forms_sections AS s' => [
                        'ON' => ['q' => 'forms_sections_id', 's' => 'id'],
                    ],
                ],
                'WHERE'      => [
                    's.forms_forms_id' => $forms_id,
                    'q.type'           => self::NATIVE_DATETIME,
                ],
                'ORDER'      => ['s.rank', 'q.vertical_rank'],
            ]);
            foreach ($iterator as $row) {
                // A time-only question has no date to compare.
                $extra = json_decode((string) $row['extra_data'], true);
                if (is_array($extra) && array_key_exists('is_date_enabled', $extra) && !$extra['is_date_enabled']) {
                    continue;
                }
                $questions['question:' . $row['id']] = (string) $row['name'];
            }
        }

        return $questions;
    }

    /**
     * Name of the input holding a question's answer in the end-user form.
     */
    public static function inputName(string $engine, int $question_id): string
    {
        return $engine === self::NATIVE ? 'answers_' . $question_id : 'formcreator_field_' . $question_id;
    }
}
