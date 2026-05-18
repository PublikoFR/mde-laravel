# pko/lunar-ai-importer — pipeline import Excel

### 7.quinquies.1 Contexte

Portage du module PrestaShop **Publiko AI Importer** (23 560 lignes de code, 43 actions, parsing Excel multi-feuilles, LLM, staging) vers un package Laravel `packages/pko/ai-importer/` intégré Lunar + Filament. Branche de travail : `ai-importer`. Plan détaillé : `docs/ai-importer-migration-plan.md`.

### 7.quinquies.2 Architecture

- Package **Filament Plugin** autonome `Pko\AiImporter\` sous `packages/pko/ai-importer/`
- 5 tables `pko_ai_importer_*` (configs, llm_configs, jobs, staging, logs)
- Pipeline d'actions **polymorphe** — 17 classes (après simplification Proposition D, fusion `multiply/divide/add/subtract` → `math`, `uppercase/lowercase/capitalize` → `change_case`, `map/category_map` → `map` multi-value, `prefix` supprimé au profit de `concat → truncate → change_case`)
- Workflow découpé en 2 Laravel Jobs queue (`ParseFileToStagingJob`, `ImportStagingToLunarJob`), replacent les 3 cron PS (`cron.php`, `cron-prepare.php`, `cron-import.php`)
- Preview & import via **Filament Resources** natives (pas de DataTables server-side custom) — réutilise les patterns perf TreeManager (`#[Computed]`, pagination SQL, cache Redis progress)

### 7.quinquies.3 Décisions tranchées

| Question | Choix | Raison |
|---|---|---|
| Simplification actions | 43 → 17 (Proposition D de `SIMPLIFICATION.md`) | Les actions PS étaient redondantes. Moins de code, UI cohérente |
| Stockage clé API LLM | Cast Eloquent `encrypted` | Le module PS stockait en clair → faille corrigée |
| Orchestration cron | Laravel Queues + `Bus::batch()` | Aligné stack, resume natif, pas de cron custom |
| Rollback | Backup ciblé tables Lunar concernées (JSON gzippé) plutôt que `spatie/laravel-backup` | Plus léger, plus rapide sur gros imports |
| Édition config JSON v1 | Textarea JSON brut + validation | Phase 5 livrera l'éditeur drag-n-drop Livewire/Alpine |
| UUID job | `HasUuids` Laravel + `uniqueIds()` | Remplace la génération manuelle PS `job_TIMESTAMP_random` |
| Virtual scroll preview | Non — Filament Table pagination SQL | 50 lignes/page suffit, même sur 10 000+ staging rows |
| Progress real-time | Cache Redis + Livewire polling 2s | Pas de Reverb en phase 1 (ajout possible phase 6) |

### 7.quinquies.4 Dépendance externe

- `phpoffice/phpspreadsheet: ^2.0 || ^3.0` — déclaré dans le composer du package. Phase 3 gérera l'itération streaming pour éviter le full-load en mémoire.

### 7.quinquies.5 Intégration avec le reste du projet

- **`mde/catalog-features`** : l'importeur appelle `Features::syncByHandles($product, [...])` à la fin de chaque row importée pour mapper les caractéristiques filtrables
- **Prix Lunar** : toujours écrits en **cents entiers** via `Price::updateOrCreate([...])`, jamais en float
- **`attribute_data`** : toujours assemblé comme collection de `Lunar\FieldTypes\*` (TranslatedText, Number…), jamais de strings bruts
- **Stock** : écrit sur `ProductVariant::stock` (int), pas de table dédiée — diffère du modèle PS `ps_stock_available`
- **Images** : Spatie MediaLibrary (`$product->addMediaFromUrl(...)`) — déjà utilisé par Lunar

### 7.quinquies.6 Navigation

Nouveau groupe Filament **« Imports »** (entre *Expédition* et *Configuration*) avec 3 Resources :

- `ImportJobResource` — liste des imports, création (upload + config + options), détail (preview staging + logs)
- `ImporterConfigResource` — CRUD configs de mapping par fournisseur
- `LlmConfigResource` — CRUD clés API LLM (Claude, OpenAI)

### 7.quinquies.7 Phase 1 — Foundation (commit 4a66a3e)

- Squelette package complet, 5 migrations appliquées, 5 modèles Eloquent, 6 enums, contrats, 16 classes d'action, LLM Manager + providers, 2 Jobs queue (squelettes), 3 Filament Resources, enregistrement `composer.json` + `bootstrap/providers.php` + `AppServiceProvider`.

### 7.quinquies.8 Phases 2-6 livrées (ce commit)

#### Phase 3 — Parsing multi-feuilles

- **`Services/SpreadsheetParser`** : lecteur PhpSpreadsheet (XLSX/XLS/CSV). Itération générateur sur la feuille primaire, pré-indexation paresseuse des feuilles secondaires par `join_key`. Chaque ligne est exposée en double clé : par nom d'en-tête ET par lettre de colonne (`A`, `B`, `M`...), pour rester rétro-compatible avec les configs PS qui pointent en lettres.
- **`Services/ProgressCache`** (Redis) : clé `ai-importer:job:{uuid}:progress` contenant `{processed, total, percentage, updated_at}`, TTL 15 min. Alimenté par les jobs toutes les `checkpoint_every` lignes, lu par Livewire polling (évite un SELECT DB toutes les 2 s).
- **`Jobs/ParseFileToStagingJob`** (réel, plus un stub) : lit le fichier, construit l'`ExecutionContext` par ligne (row + secondary sheets indexed), fait tourner `ActionPipeline` pour chaque colonne du mapping, bulk-insert `StagingRecord` avec `status=pending`. Resume natif via `last_processed_row`. Transition statut `pending → parsing → parsed/error` + écriture dans `ImportLog`.
- **Dépendance ajoutée** : `phpoffice/phpspreadsheet: ^3.0` au composer racine (PhpSpreadsheet 3.x — API stable, PHP 8.2+).

#### Phase 4 — Écriture Lunar + backup/rollback

- **`Services/LunarProductWriter`** : écriture d'un `StagingRecord` vers `Product` + `ProductVariant` + `Price` + `Collection` + `Brand` + custom Features. Résolution par SKU (`reference`). Cache par instance pour brand et collection lookups. Contrat de clés staging documenté in-code — voir la docblock de la classe pour la liste exhaustive (17 clés reconnues).
- **`attribute_data`** assemblé comme Collection de `Lunar\FieldTypes\TranslatedText` pour `name`, `description`, `description_short`, `meta_title`, `meta_description`, `meta_keywords` + `Text` pour `url_key`. Préserve les traductions existantes sur update (merge par langue).
- **Prix** : `Price::updateOrCreate` keyé sur `(priceable_type, priceable_id, currency_id, customer_group_id=null, min_quantity=1)`, valeur en cents entiers. Support `compare_price_cents`.
- **Features catalogue** : `Pko\CatalogFeatures\Facades\Features::syncByHandles()` via `class_exists` guard (le writer reste utilisable même si catalog-features est désinstallé).
- **`Services/LunarBackupManager`** : snapshot avant import. Collecte les SKUs présents en staging → récupère les `ProductVariant` correspondants + leurs produits + prix + pivot `lunar_collection_product`. Stockage `storage/app/ai-importer/backups/job_{uuid}_{datetime}.json.gz` (gzip level 6, typiquement 70-80% compression). Format JSON brut, lisible à l'œil. `restore()` replay en transaction.
- **`Jobs/ImportStagingToLunarJob`** (réel) : boucle `chunkById(200)` sur staging (statuts `pending|validated|warning`), appelle le writer par row, gère les 3 politiques d'erreur :
  - `ignore` → log + continue
  - `stop` → retourne false depuis `chunkById`, transition `import_status=error`
  - `rollback` → `LunarBackupManager::restore()` + `import_status=rolled_back`
- Snapshot créé UNE fois au premier run. Sur resume, le backup_path existant est réutilisé.

#### Phase 5 — UI Filament (RelationManagers + Actions)

- **`StagingRecordsRelationManager`** : tableau preview avec colonnes `row_number / SKU / nom / prix formaté / statut badgé / erreur`. Filtre par statut, search sur JSON (`where data like %"reference":"%Q%"`). Edit modale avec textarea JSON + validation `json`. Bulk actions : valider / ignorer / supprimer.
- **`ImportLogsRelationManager`** : tableau read-only, polling 5s, tri `id desc`, filtre par niveau, badge coloré. Fournit la vue temps-réel pendant le parse/import.
- **Header actions sur `ViewImportJob`** :
  - `Resume parse` — visible si `Paused | Error`
  - `Launch import Lunar` — visible si `Parsed` et `import_status ∈ {Pending, Scheduled}`
  - `Rollback` — visible si `Imported` avec `backup_path` et `!rollback_completed`
  - `Cancel` — visible si `Pending | Parsing | Paused`
- L'éditeur visuel drag-n-drop de config (remplace le textarea JSON actuel) reste hors scope — tracked comme phase 5+ dédiée.

#### Phase 6 — CLI migration depuis PS

- **`php artisan ai-importer:import-ps-config {file} [--name=] [--supplier=] [--replace]`** : lit un JSON Publiko AI Importer et crée un `ImporterConfig`. Compatibilité v0 : si une colonne a `action:{}` (objet unique legacy) au lieu de `actions:[]`, la commande le lift automatiquement. Affiche un résumé : colonnes mappées / actions totales / feuilles.

#### Phase 2 — Tests

- **`tests/Unit/AiImporter/Actions/ActionTypesTest`** (17 tests) : chaque type d'action (math, change_case, truncate, concat, template, map simple et multi-value, validate_ean13, slugify, replace/regex_replace, copy, trim, date_format, multiline_aggregate concat et count).
- **`tests/Unit/AiImporter/Services/ActionPipelineTest`** (5 tests) : chaînage ordonné, défaut sur valeur null, `condition` true/false, lève sur type inconnu.
- **`tests/Feature/AiImporter/ParseFileToStagingJobTest`** : CSV fake → pipeline → staging (fixture CSV minimale via `Storage::fake`).
- **`tests/Feature/AiImporter/LunarProductWriterTest`** (3 tests) : création produit + variant + prix, update sur 2e appel, erreur sur `reference` manquante.

Couverture totale : **26 nouveaux tests verts** (61 total sur le projet).

### 7.quinquies.9 Contrat du writer (clés staging reconnues)

Les clés suivantes, si présentes dans `StagingRecord::data`, déclenchent une écriture Lunar :

| Clé | Cible Lunar | Notes |
|---|---|---|
| `reference` **(requis)** | `ProductVariant::sku` | Résolveur : existe ? update. Sinon : create. |
| `name` **(requis en création)** | `attribute_data.name` (TranslatedText) | Stocké dans la langue par défaut |
| `description` | `attribute_data.description` (TranslatedText) | |
| `description_short` | `attribute_data.description_short` | |
| `meta_title` / `meta_description` / `meta_keywords` | `attribute_data.*` (TranslatedText) | |
| `url_key` | `attribute_data.url` (Text) | |
| `ean` | `ProductVariant::ean` | |
| `stock` | `ProductVariant::stock` (int) | |
| `price_cents` | `Price::price` (cents) | UpdateOrCreate keyé sur variant+currency+tier |
| `compare_price_cents` | `Price::compare_price` (cents) | Optionnel |
| `weight_value` | `ProductVariant::weight_value` (kg) | Unité forcée à `kg` |
| `length_value` / `width_value` / `height_value` | `ProductVariant::{axis}_value` (cm) | Unité forcée à `cm` |
| `brand_name` | `Product::brand_id` | `Brand::firstOrCreate(['name' => ...])` |
| `collections` | `Product::collections()` | Array ou CSV, int (ID) ou string (handle). `syncWithoutDetaching` |
| `features` | pivot `pko_feature_value_product` | Hash `{family_handle => [value_handle, ...]}`, delegated à `catalog-features` |
| `images` | Spatie MediaLibrary | Array ou CSV d'URLs distantes. Idempotent via `custom_properties.source_url`, première URL `primary=true`. |
| `videos` | `pko/product-videos` | Array ou CSV d'URLs YouTube/Vimeo/Dailymotion/MP4. Idempotent par URL. |
| `product_type_handle` | ProductType | Lookup par handle, fallback sur premier trouvé |
| `tax_class_handle` | TaxClass | Lookup par handle, fallback sur premier trouvé |
| `compare_price_cents` | `Price::compare_price` | Prix barré |

Les clés inconnues du writer sont **ignorées silencieusement** — le config author peut donc émettre des keys arbitraires pour d'autres consommateurs downstream.

#### Aliases legacy PrestaShop FAB-DIS (auto-normalisés par `LunarProductWriter::normalizeLegacyKeys`)

Pour importer un JSON Publiko AI Importer (PrestaShop) tel quel, sans renommer les clés du mapping, le writer accepte ces alias et les remappe vers la clé canonique ci-dessus. **La clé canonique gagne toujours** quand les deux sont présentes — l'alias est ignoré.

| Alias PS | Clé canonique | Conversion |
|---|---|---|
| `ean13` | `ean` | — |
| `quantity` | `stock` | cast int |
| `manufacturer` | `brand_name` | — |
| `link_rewrite` | `url_key` | — |
| `width` | `width_value` | cast float |
| `height` | `height_value` | cast float |
| `depth` | `length_value` | cast float (axes : depth PS = length Lunar) |
| `weight` | `weight_value` | cast float |
| `image` | `images` | passe array ou CSV inchangé |
| `category` | `collections` | passe array ou CSV inchangé |
| `price_tex` | `price_cents` | **×100 puis `(int) round()`** (euros → cents) |

### 7.quinquies.10 Actions disponibles (19 + 4 alias legacy)

| Type | Rôle |
|---|---|
| `math` | Opération arithmétique (`operation: multiply|divide|add|subtract`) |
| `round` | Arrondi à N décimales |
| `change_case` | upper / lower / capitalize |
| `trim` | both / left / right |
| `truncate` | Coupe à N caractères avec suffix optionnel |
| `slugify` | URL-safe (kebab-case) |
| `prefix` | Préfixe une string littérale (avec separator optionnel) |
| `suffix` | Suffixe une string littérale |
| `replace` | Search/replace littéral |
| `regex_replace` | Search/replace via pattern PCRE |
| `date_format` | Conversion entre patterns de date |
| `validate_ean13` | Valide checksum EAN13 (sinon vide) |
| `concat` | Concatène plusieurs colonnes de la row primaire |
| `template` | Interpole `{placeholders}` depuis sources nommées |
| `copy` | Recopie une autre colonne (déjà mappée ou brute) |
| `map` | Lookup `{from => to}`, support multi-value avec séparateur |
| `llm_transform` | Appel LLM (Claude/OpenAI) avec prompt + sources |
| `multiline_aggregate` | Agrège plusieurs lignes d'une sheet many (concat/count/json_array) |
| `feature_build` | Construit le hash `features` depuis N colonnes source |
| `parse_features_string` | Parse `"F1:V1,V2|F2:V3"` (sortie LLM) → `{f1:[v1,v2]}` slugifié |
| `parse_category_breadcrumb` | Parse `"A>B>C,D>E"` → CSV de handles, mode `leaf` (défaut) ou `all` |
| `condition` | Branching `if/else` avec branches/rules/else_actions. Support `field: "sheet:col"` ou `"col_value"`, opérateurs `=`/`!=`/`>`/`<`/`contains`/`empty`/`in`. Premier match wins. Pas de récursion (nested condition silencieusement skip). |

**Alias legacy** (configs PrestaShop v0) : `multiply`, `divide`, `add`, `subtract` routent automatiquement vers `MathAction` avec l'opération correspondante. Permet d'importer un JSON PS sans modifier les actions.

### 7.quinquies.11 Diagnostic des imports LLM

Quand un `llm_transform` (ou n'importe quelle source) émet un handle de `Collection` ou de `FeatureFamily`/`FeatureValue` qui n'existe pas en DB, le writer **ne plante pas** : le record est écrit normalement, et un `ImportLog` warning est créé avec le détail des handles ignorés. Visible dans l'onglet **Logs** de chaque `ImportJob` (RelationManager temps-réel).

Exemple de message :

```
[SKU-12345] handle(s) introuvable(s) — 2 collection(s), 3 feature(s) ignoré(s).
context: {
  collections: ["categorie-inexistante", "autre-cat"],
  features: ["family:application", "value:matiere.unobtanium"]
}
```

Stratégie : afficher la liste des handles autorisés au LLM via `llm_global_context` (cf JSON Publiko AI Importer). Pour les rares hallucinations restantes, l'admin diagnostique via les logs et choisit : créer la catégorie/feature manquante, ou affiner le prompt.

### 7.quinquies.12 Limitations connues

- ProductType : le writer utilise le premier `ProductType` trouvé (ordre `id asc`) si `product_type_handle` absent. Pour forcer, ajouter la clé au staging.
- TaxClass : idem, premier trouvé (fallback de `tax_class_handle`).
- True streaming XLSX : `PhpSpreadsheet::load()` charge tout en RAM. Au-delà de ~100k lignes, basculer sur un `IReadFilter` chunked — l'API parser reste stable.
- Éditeur config visual : reste en textarea JSON (phase 5+).
- Appels LLM réels non testés automatiquement (nécessitent une clé API valide, hors CI).

---

