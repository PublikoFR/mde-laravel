<?php

declare(strict_types=1);

namespace Pko\AiImporter\Actions\Types;

use Illuminate\Support\Str;
use Pko\AiImporter\Actions\Action;
use Pko\AiImporter\Actions\ExecutionContext;

/**
 * Parses a human-readable features string (typically emitted by an LLM) into
 * the hash shape expected by `LunarProductWriter::features` :
 *
 *   `{family_handle: [value_handle, ...]}`
 *
 * Input format (default, configurable):
 *
 *   `"Feature 1:Value 1,Value 2|Feature 2:Value A|Feature 3:Value X,Value Y"`
 *
 * Output:
 *
 *   `["feature-1" => ["value-1", "value-2"], "feature-2" => ["value-a"], ...]`
 *
 * Both family and value tokens are slugified (kebab-case) by default to match
 * the `handle` columns in `pko_feature_families` / `pko_feature_values`. Set
 * `slugify: false` if the LLM is already producing canonical handles.
 *
 * Empty / malformed pairs (no `:` separator, no value list) are silently
 * dropped — robustness over strictness given LLM variability.
 */
final class ParseFeaturesStringAction extends Action
{
    public function __construct(
        public readonly string $family_separator = '|',
        public readonly string $kv_separator = ':',
        public readonly string $value_separator = ',',
        public readonly bool $slugify = true,
    ) {}

    public static function type(): string
    {
        return 'parse_features_string';
    }

    public function execute(mixed $value, ExecutionContext $ctx): mixed
    {
        $input = (string) $value;
        if (trim($input) === '') {
            return [];
        }

        $out = [];

        foreach (explode($this->family_separator, $input) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || ! str_contains($pair, $this->kv_separator)) {
                continue;
            }

            [$familyRaw, $valuesRaw] = explode($this->kv_separator, $pair, 2);
            $familyHandle = $this->normalize($familyRaw);
            if ($familyHandle === '') {
                continue;
            }

            $valueHandles = [];
            foreach (explode($this->value_separator, $valuesRaw) as $v) {
                $h = $this->normalize($v);
                if ($h !== '') {
                    $valueHandles[] = $h;
                }
            }

            if ($valueHandles === []) {
                continue;
            }

            $out[$familyHandle] = array_values(array_unique(
                array_merge($out[$familyHandle] ?? [], $valueHandles)
            ));
        }

        return $out;
    }

    private function normalize(string $token): string
    {
        $t = trim($token);
        if ($t === '') {
            return '';
        }

        return $this->slugify ? Str::slug($t) : $t;
    }
}
