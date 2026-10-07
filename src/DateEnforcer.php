<?php

namespace GlpiPlugin\Fieldconstraints;

use CommonDBTM;
use Session;
use Throwable;

/**
 * Server side of the date constraints.
 *
 * Where the dates are written decides the hook:
 *  - Fields: the class Fields generates per container and itemtype
 *    (PluginFields<Itemtype><container>), whatever screen the block is on;
 *  - Formcreator: PluginFormcreatorFormAnswer, holding `formcreator_field_<id>`;
 *  - native forms: Glpi\Form\AnswersSet, holding the answers as JSON.
 */
final class DateEnforcer
{
    public const FORMCREATOR_ANSWER = 'PluginFormcreatorFormAnswer';
    public const NATIVE_ANSWERS     = 'Glpi\\Form\\AnswersSet';

    /**
     * Fields classes of the containers holding constraints: they need their
     * own hooks. Called at plugin init, hence defensive.
     *
     * @return string[]
     */
    public static function watchedFieldsClasses(): array
    {
        global $DB;

        try {
            if (!FieldCatalog::isFieldsPluginAvailable() || !$DB->tableExists(DateConstraint::getTable())) {
                return [];
            }

            $iterator = $DB->request([
                'SELECT'     => ['c.name', 'c.itemtypes'],
                'DISTINCT'   => true,
                'FROM'       => DateConstraint::getTable() . ' AS d',
                'INNER JOIN' => [
                    'glpi_plugin_fields_containers AS c' => ['ON' => ['d' => 'items_id', 'c' => 'id']],
                ],
                'WHERE'      => ['d.source' => DateConstraint::SOURCE_FIELDS, 'd.is_active' => 1],
            ]);

            $classes = [];
            foreach ($iterator as $row) {
                $itemtypes = json_decode(Compat::plain($row['itemtypes']), true);
                foreach (is_array($itemtypes) ? $itemtypes : [] as $itemtype) {
                    $classes[] = FieldCatalog::fieldsClassname((string) $itemtype, (string) $row['name']);
                }
            }

            return array_values(array_unique($classes));
        } catch (Throwable $e) {
            // Database not ready (install/update in progress).
            return [];
        }
    }

    /**
     * Values of a Fields container being written.
     *
     * Refused dates are dropped from the input rather than the whole write
     * refused: Fields ignores the result of add()/update() and goes on using the
     * object's input (history, files), which breaks on `false`.
     */
    public static function preFieldsValues(CommonDBTM $values): void
    {
        if (!is_array($values->input)) {
            return;
        }

        $container_id = (int) ($values->input['plugin_fields_containers_id'] ?? $values->fields['plugin_fields_containers_id'] ?? 0);
        $constraints  = DateConstraint::getForParent(DateConstraint::SOURCE_FIELDS, $container_id);
        if ($constraints === []) {
            return;
        }

        $is_new  = $values->isNewItem();
        $columns = [];
        $read    = function (string $key) use ($values, $is_new, &$columns): array {
            $column = DateConstraint::inputName(DateConstraint::SOURCE_FIELDS, $key);
            if ($column === null) {
                return [null, null];
            }
            $columns[$key] = $column;
            $old = $is_new ? null : ($values->fields[$column] ?? null);
            $new = array_key_exists($column, $values->input) ? $values->input[$column] : $old;

            return [self::str($new), self::str($old)];
        };

        $fields   = DateConstraint::getDateFields(DateConstraint::SOURCE_FIELDS, $container_id);
        $problems = [];
        $refused  = [];
        foreach (DateConstraint::applicable($constraints, $read) as $constraint) {
            $problem = DateConstraint::evaluate($constraint, $read, $fields);
            if ($problem === null) {
                continue;
            }
            $problems[] = $problem;
            foreach ([(string) $constraint['field'], (string) $constraint['ref_field']] as $key) {
                if ($key !== '' && isset($columns[$key]) && array_key_exists($columns[$key], $values->input)) {
                    [$new, $old] = $read($key);
                    if ($new !== $old) {
                        $refused[] = $columns[$key];
                    }
                }
            }
        }

        if ($problems !== []) {
            foreach (array_unique($refused) as $column) {
                unset($values->input[$column]);
            }
            self::report($problems);
        }
    }

    public static function preFormcreatorAnswer(CommonDBTM $answer): void
    {
        if (!is_array($answer->input)) {
            return;
        }

        $problems = self::checkForm(
            FormEngine::FORMCREATOR,
            (int) ($answer->input['plugin_formcreator_forms_id'] ?? 0),
            static fn (int $question_id): ?string => self::str($answer->input['formcreator_field_' . $question_id] ?? null)
        );

        if ($problems !== []) {
            $answer->input = false;
            self::report($problems);
        }
    }

    public static function preNativeAnswers(CommonDBTM $answers_set): void
    {
        if (!is_array($answers_set->input)) {
            return;
        }

        $raw     = [];
        $answers = json_decode((string) ($answers_set->input['answers'] ?? ''), true);
        foreach (is_array($answers) ? $answers : [] as $answer) {
            if (is_array($answer) && isset($answer['question_id'])) {
                $raw[(int) $answer['question_id']] = $answer['raw_answer'] ?? null;
            }
        }

        $problems = self::checkForm(
            FormEngine::NATIVE,
            (int) ($answers_set->input['forms_forms_id'] ?? 0),
            static fn (int $question_id): ?string => self::str($raw[$question_id] ?? null)
        );

        if ($problems !== []) {
            $answers_set->input = false;
            // The form renderer escapes and joins these messages itself.
            foreach ($problems as $problem) {
                Session::addMessageAfterRedirect(Compat::escape($problem), false, ERROR);
            }
        }
    }

    /**
     * @param callable(int): ?string $answer
     *
     * @return string[]
     */
    private static function checkForm(string $engine, int $forms_id, callable $answer): array
    {
        $constraints = DateConstraint::getForParent($engine, $forms_id);
        if ($constraints === []) {
            return [];
        }

        // A new answer: nothing was stored before, every value is "changed".
        $read = static function (string $key) use ($answer): array {
            $value = str_starts_with($key, 'question:') ? $answer((int) substr($key, strlen('question:'))) : null;

            return [$value, null];
        };

        $fields   = DateConstraint::getDateFields($engine, $forms_id);
        $problems = [];
        foreach (DateConstraint::applicable($constraints, $read) as $constraint) {
            $problem = DateConstraint::evaluate($constraint, $read, $fields);
            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    private static function str(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param string[] $problems
     */
    private static function report(array $problems): void
    {
        Session::addMessageAfterRedirect(
            implode('<br>', array_map([Compat::class, 'escape'], array_values(array_unique($problems)))),
            false,
            ERROR
        );
    }
}
