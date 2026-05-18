<?php

declare(strict_types=1);

namespace Pko\AiImporter\Actions\Types;

use Pko\AiImporter\Actions\Action;
use Pko\AiImporter\Actions\ExecutionContext;

/**
 * Branching action — evaluates a list of branches against the current row and
 * runs the actions of the first matching branch. Falls back to `else_actions`
 * if no branch matches.
 *
 * Config shape (matches the PrestaShop Publiko AI Importer JSON):
 *
 * ```json
 * {
 *   "type": "condition",
 *   "branches": [
 *     {
 *       "logic": "AND",
 *       "rules": [
 *         {"field": "B01_COMMERCE:AB", "operator": "=", "value": "GTK"},
 *         {"field": "B01_COMMERCE:M",  "operator": ">", "value": "0"}
 *       ],
 *       "actions": [
 *         {"type": "math", "operation": "multiply", "value": 1.2}
 *       ]
 *     }
 *   ],
 *   "else_actions": [
 *     {"type": "math", "operation": "multiply", "value": 1.0}
 *   ]
 * }
 * ```
 *
 * Semantics:
 *   - Branches evaluated in order. First match wins (no fallthrough).
 *   - `logic`: "AND" (default) or "OR".
 *   - `field` accepts `"col"` (read primary row), `"sheet:col"` (read a secondary
 *     sheet's first joined row), or the special `"col_value"` to refer to the
 *     current pipeline value (string at this point).
 *   - Supported operators: `=`/`==`, `!=`/`<>`, `>`, `>=`, `<`, `<=`,
 *     `contains`, `not_contains`, `empty`, `not_empty`, `in`, `not_in`.
 *   - `value` is a scalar for most operators ; an array (or CSV string) for
 *     `in` / `not_in`.
 *
 * Actions within a branch run via a fresh nested `ActionPipeline`-equivalent
 * loop (no recursion into `condition` for branch.actions to keep the contract
 * simple — chain a second `condition` action at the column level if needed).
 */
final class ConditionAction extends Action
{
    /**
     * @param  array<int, array<string, mixed>>  $branches
     * @param  array<int, array<string, mixed>>  $else_actions
     */
    public function __construct(
        public readonly array $branches = [],
        public readonly array $else_actions = [],
    ) {}

    public static function type(): string
    {
        return 'condition';
    }

    public function execute(mixed $value, ExecutionContext $ctx): mixed
    {
        foreach ($this->branches as $branch) {
            if (! is_array($branch)) {
                continue;
            }

            $rules = (array) ($branch['rules'] ?? []);
            $logic = strtoupper((string) ($branch['logic'] ?? 'AND'));

            if ($this->evaluateRules($rules, $logic, $value, $ctx)) {
                return $this->runActions((array) ($branch['actions'] ?? []), $value, $ctx);
            }
        }

        return $this->runActions($this->else_actions, $value, $ctx);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     */
    private function evaluateRules(array $rules, string $logic, mixed $current, ExecutionContext $ctx): bool
    {
        if ($rules === []) {
            return false;
        }

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $matched = $this->compare(
                $this->resolveField((string) ($rule['field'] ?? ''), $current, $ctx),
                (string) ($rule['operator'] ?? '='),
                $rule['value'] ?? null,
            );

            if ($logic === 'OR' && $matched) {
                return true;
            }
            if ($logic !== 'OR' && ! $matched) {
                return false;
            }
        }

        return $logic !== 'OR';
    }

    private function resolveField(string $field, mixed $current, ExecutionContext $ctx): mixed
    {
        if ($field === '' || $field === 'col_value') {
            return $current;
        }
        if (! str_contains($field, ':')) {
            return $ctx->row[$field] ?? null;
        }
        [$sheet, $col] = explode(':', $field, 2);
        $rows = $ctx->sheets[$sheet] ?? [];
        $firstRow = is_array($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : [];

        return $firstRow[$col] ?? null;
    }

    private function compare(mixed $left, string $op, mixed $right): bool
    {
        if ($op === 'in' || $op === 'not_in') {
            $haystack = is_array($right)
                ? $right
                : array_map('trim', explode(',', (string) $right));
            $found = in_array((string) $left, array_map('strval', $haystack), true);

            return $op === 'in' ? $found : ! $found;
        }

        return match ($op) {
            '=', '==' => (string) $left === (string) $right,
            '!=', '<>' => (string) $left !== (string) $right,
            '>' => is_numeric($left) && is_numeric($right) && (float) $left > (float) $right,
            '>=' => is_numeric($left) && is_numeric($right) && (float) $left >= (float) $right,
            '<' => is_numeric($left) && is_numeric($right) && (float) $left < (float) $right,
            '<=' => is_numeric($left) && is_numeric($right) && (float) $left <= (float) $right,
            'contains' => is_string($left) && str_contains($left, (string) $right),
            'not_contains' => ! is_string($left) || ! str_contains($left, (string) $right),
            'empty' => $left === null || $left === '',
            'not_empty' => $left !== null && $left !== '',
            default => false,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $actions
     */
    private function runActions(array $actions, mixed $value, ExecutionContext $ctx): mixed
    {
        foreach ($actions as $actionConfig) {
            if (! is_array($actionConfig) || ! isset($actionConfig['type'])) {
                continue;
            }
            // Defensive : avoid infinite recursion if someone nests `condition`.
            if ($actionConfig['type'] === self::type()) {
                continue;
            }
            $action = Action::make($actionConfig);
            $value = $action->execute($value, $ctx);
        }

        return $value;
    }
}
