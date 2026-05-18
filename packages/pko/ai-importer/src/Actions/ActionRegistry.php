<?php

declare(strict_types=1);

namespace Pko\AiImporter\Actions;

use Pko\AiImporter\Actions\Types\ChangeCaseAction;
use Pko\AiImporter\Actions\Types\ConcatAction;
use Pko\AiImporter\Actions\Types\ConditionAction;
use Pko\AiImporter\Actions\Types\CopyAction;
use Pko\AiImporter\Actions\Types\DateFormatAction;
use Pko\AiImporter\Actions\Types\FeatureBuildAction;
use Pko\AiImporter\Actions\Types\LlmTransformAction;
use Pko\AiImporter\Actions\Types\MapAction;
use Pko\AiImporter\Actions\Types\MathAction;
use Pko\AiImporter\Actions\Types\MultilineAggregateAction;
use Pko\AiImporter\Actions\Types\ParseCategoryBreadcrumbAction;
use Pko\AiImporter\Actions\Types\ParseFeaturesStringAction;
use Pko\AiImporter\Actions\Types\PrefixAction;
use Pko\AiImporter\Actions\Types\RegexReplaceAction;
use Pko\AiImporter\Actions\Types\ReplaceAction;
use Pko\AiImporter\Actions\Types\RoundAction;
use Pko\AiImporter\Actions\Types\SlugifyAction;
use Pko\AiImporter\Actions\Types\SuffixAction;
use Pko\AiImporter\Actions\Types\TemplateAction;
use Pko\AiImporter\Actions\Types\TrimAction;
use Pko\AiImporter\Actions\Types\TruncateAction;
use Pko\AiImporter\Actions\Types\ValidateEan13Action;

/**
 * Runtime registry mapping a type string to an Action class.
 *
 * Seeded with the 17 built-in actions (Proposition D from SIMPLIFICATION.md).
 * Packages may add their own via `ActionRegistry::register()` in a boot hook.
 */
final class ActionRegistry
{
    /** @var array<string, class-string<Action>> */
    private static array $map = [];

    public static function bootDefaults(): void
    {
        if (self::$map !== []) {
            return;
        }

        foreach ([
            MathAction::class,
            RoundAction::class,
            ChangeCaseAction::class,
            TrimAction::class,
            TruncateAction::class,
            SlugifyAction::class,
            PrefixAction::class,
            SuffixAction::class,
            ReplaceAction::class,
            RegexReplaceAction::class,
            DateFormatAction::class,
            ValidateEan13Action::class,
            ConcatAction::class,
            ConditionAction::class,
            TemplateAction::class,
            CopyAction::class,
            MapAction::class,
            LlmTransformAction::class,
            MultilineAggregateAction::class,
            FeatureBuildAction::class,
            ParseFeaturesStringAction::class,
            ParseCategoryBreadcrumbAction::class,
        ] as $class) {
            self::register($class::type(), $class);
        }

        // Legacy aliases (configs PrestaShop v0) → MathAction multi-operation
        foreach (['multiply', 'divide', 'add', 'subtract'] as $alias) {
            self::register($alias, MathAction::class);
        }
    }

    /**
     * @param  class-string<Action>  $class
     */
    public static function register(string $type, string $class): void
    {
        self::$map[$type] = $class;
    }

    /**
     * @return class-string<Action>
     */
    public static function resolve(string $type): string
    {
        self::bootDefaults();

        if (! isset(self::$map[$type])) {
            throw new \InvalidArgumentException("Unknown action type: {$type}");
        }

        return self::$map[$type];
    }

    /**
     * @return array<string, class-string<Action>>
     */
    public static function all(): array
    {
        self::bootDefaults();

        return self::$map;
    }
}
