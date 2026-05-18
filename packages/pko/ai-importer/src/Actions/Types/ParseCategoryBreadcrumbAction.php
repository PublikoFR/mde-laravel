<?php

declare(strict_types=1);

namespace Pko\AiImporter\Actions\Types;

use Illuminate\Support\Str;
use Pko\AiImporter\Actions\Action;
use Pko\AiImporter\Actions\ExecutionContext;

/**
 * Parses an LLM-emitted category breadcrumb string into a CSV of Lunar
 * `Collection` handles, ready to be consumed by `LunarProductWriter::collections`.
 *
 * Input formats accepted (configurable separators):
 *
 *   - `"Accueil>Motorisation>Moteur filaire"`               (single path)
 *   - `"Accueil>Motorisation,Accueil>Domotique"`            (multiple paths)
 *
 * Strategy: by default we keep ONLY the leaf segment of each path (the most
 * specific category) and slugify it. The breadcrumb root (`"Accueil"`,
 * `"Home"`...) is therefore dropped automatically — only meaningful when it
 * appears alone, which we then keep.
 *
 * Set `mode: "all"` to keep every segment of every path (useful when each
 * level corresponds to a distinct Collection in Lunar).
 *
 * Output: comma-separated handles, e.g. `"moteur-filaire,domotique"`. The
 * writer already accepts a CSV of handles via `resolveCollectionIds()`.
 */
final class ParseCategoryBreadcrumbAction extends Action
{
    public function __construct(
        public readonly string $path_separator = ',',
        public readonly string $segment_separator = '>',
        public readonly string $mode = 'leaf', // leaf|all
        public readonly bool $slugify = true,
        public readonly string $output_separator = ',',
    ) {}

    public static function type(): string
    {
        return 'parse_category_breadcrumb';
    }

    public function execute(mixed $value, ExecutionContext $ctx): mixed
    {
        $input = (string) $value;
        if (trim($input) === '') {
            return '';
        }

        $handles = [];

        foreach (explode($this->path_separator, $input) as $path) {
            $segments = array_values(array_filter(
                array_map('trim', explode($this->segment_separator, $path)),
                static fn ($s) => $s !== '',
            ));

            if ($segments === []) {
                continue;
            }

            $kept = $this->mode === 'all' ? $segments : [end($segments)];
            foreach ($kept as $seg) {
                $handles[] = $this->slugify ? Str::slug($seg) : $seg;
            }
        }

        $handles = array_values(array_unique(array_filter($handles, static fn ($h) => $h !== '')));

        return implode($this->output_separator, $handles);
    }
}
