<?php

declare(strict_types=1);

namespace App\Providers;

use App\Filament\Extensions\DisableBrokenChartsExtension;
use App\Filament\Extensions\HideLunarMediaExtension;
use App\Filament\Pages\StripeConfig;
use App\Filament\Pages\TreeManager;
use App\Filament\Resources\PkoAttributeGroupResource;
use App\Filament\Resources\PkoCollectionGroupResource;
use App\Filament\Resources\PkoProductOptionResource;
use App\Filament\Resources\PkoProductResource;
use App\Filament\Resources\PkoProductTypeResource;
use App\Generators\PkoProductUrlGenerator;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Panel;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Lunar\Admin\Filament\Pages\Dashboard;
use Lunar\Admin\Filament\Resources\AttributeGroupResource;
use Lunar\Admin\Filament\Resources\BrandResource;
use Lunar\Admin\Filament\Resources\CollectionGroupResource;
use Lunar\Admin\Filament\Resources\CollectionResource;
use Lunar\Admin\Filament\Resources\CustomerResource;
use Lunar\Admin\Filament\Resources\OrderResource\Pages\ManageOrder;
use Lunar\Admin\Filament\Resources\ProductOptionResource;
use Lunar\Admin\Filament\Resources\ProductResource;
use Lunar\Admin\Filament\Resources\ProductTypeResource;
use Lunar\Admin\Filament\Resources\TaxClassResource;
use Lunar\Admin\Filament\Resources\TaxRateResource;
use Lunar\Admin\Filament\Resources\TaxZoneResource;
use Lunar\Admin\LunarPanelManager;
use Lunar\Admin\Support\Facades\LunarPanel;
use Lunar\Models\ProductVariant;
use Lunar\Shipping\ShippingPlugin;
use Pko\AdminNav\Filament\AdminNavPlugin;
use Pko\AdminNav\Filament\Resources\PkoTaxClassResource;
use Pko\AdminNav\Filament\Resources\PkoTaxRateResource;
use Pko\AdminNav\Filament\Resources\PkoTaxZoneResource;
use Pko\AiImporter\Filament\AiImporterPlugin;
use Pko\CatalogFeatures\Filament\CatalogFeaturesPlugin;
use Pko\CatalogFeatures\Filament\Extensions\ProductFeaturesExtension;
use Pko\Loyalty\Filament\Extensions\CustomerLoyaltyExtension;
use Pko\Loyalty\Filament\LoyaltyPlugin;
use Pko\Pennylane\Filament\Extensions\OrderInvoiceActionsExtension;
use Pko\Pennylane\Filament\PennylanePlugin;
use Pko\ProductDocuments\ProductDocumentsPlugin;
use Pko\Secrets\Facades\Secrets;
use Pko\ShippingCommon\Filament\SwapLunarShippingResourcesPlugin;
use Pko\ShippingCommon\Filament\TransportersPlugin;
use Pko\StorefrontCms\Filament\Extensions\BrandContentExtension;
use Pko\StorefrontCms\Filament\MediaManagerShimPlugin;
use Pko\StorefrontCms\Filament\Pages\StorefrontSettings;
use Pko\StorefrontCms\Filament\StorefrontCmsPlugin;
use Pko\StoreLocator\Filament\StoreLocatorPlugin;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->swapLunarResources();
        $this->registerSecretModules();

        LunarPanel::panel(function (Panel $panel): Panel {
            return $panel
                ->spa(false)
                ->path('admin')
                ->brandName(brand_name())
                ->viteTheme('resources/css/filament/admin/theme.css')
                ->pages([
                    StripeConfig::class,
                    TreeManager::class,
                    StorefrontSettings::class,
                ])
                ->discoverClusters(
                    in: base_path('packages/pko/shipping-common/src/Filament/Clusters'),
                    for: 'Pko\\ShippingCommon\\Filament\\Clusters',
                )
                ->discoverClusters(
                    in: base_path('packages/pko/lunar-admin-nav/src/Filament/Clusters'),
                    for: 'Pko\\AdminNav\\Filament\\Clusters',
                )
                ->plugin(FilamentShieldPlugin::make())
                ->plugin(ShippingPlugin::make())
                ->plugin(SwapLunarShippingResourcesPlugin::make())
                ->plugin(TransportersPlugin::make())
                ->plugin(CatalogFeaturesPlugin::make())
                ->plugin(ProductDocumentsPlugin::make())
                ->plugin(AiImporterPlugin::make())
                ->plugin(LoyaltyPlugin::make())
                ->plugin(StorefrontCmsPlugin::make())
                ->plugin(StoreLocatorPlugin::make())
                ->plugin(MediaManagerShimPlugin::make())
                ->plugin(PennylanePlugin::make())
                ->plugin(AdminNavPlugin::make());
        })->register();

        LunarPanel::extensions([
            PkoProductResource::class => [
                ProductFeaturesExtension::class,
                HideLunarMediaExtension::class,
            ],
            CollectionResource::class => [
                HideLunarMediaExtension::class,
            ],
            BrandResource::class => [
                HideLunarMediaExtension::class,
                BrandContentExtension::class,
            ],
            CustomerResource::class => [
                CustomerLoyaltyExtension::class,
            ],
            ManageOrder::class => [
                OrderInvoiceActionsExtension::class,
            ],
            Dashboard::class => [
                DisableBrokenChartsExtension::class,
            ],
        ]);
    }

    public function boot(): void
    {
        // Regenerate product URL slug when variants change — MPN is only
        // available after variant creation so Lunar's native post-create hook
        // runs too early. See App\Generators\PkoProductUrlGenerator::regenerate.
        ProductVariant::saved(function (ProductVariant $variant): void {
            if ($variant->product) {
                app(PkoProductUrlGenerator::class)->regenerate($variant->product);
            }
        });

        Blade::anonymousComponentPath(
            resource_path('views/filament/resources/pko-product/partials'),
            'pko-product'
        );
    }

    /**
     * Declare secret keys for each module so they can be toggled between .env and DB
     * storage from the admin UI. Registered at register() so the helper secret() is
     * usable during bootstrap of other providers.
     */
    private function registerSecretModules(): void
    {
        Secrets::register(
            'stripe',
            keys: [
                'public_key' => 'STRIPE_KEY',
                'secret' => 'STRIPE_SECRET',
                'webhook_lunar' => 'STRIPE_WEBHOOK_SECRET_LUNAR',
            ],
            defaultSource: 'env',
            label: 'Stripe',
            configMap: [
                'public_key' => 'services.stripe.public_key',
                'secret' => 'services.stripe.key',
                'webhook_lunar' => 'services.stripe.webhooks.lunar',
            ],
        );

        Secrets::register(
            'chronopost',
            keys: [
                'account' => 'CHRONOPOST_ACCOUNT',
                'password' => 'CHRONOPOST_PASSWORD',
                'sub_account' => 'CHRONOPOST_SUB_ACCOUNT',
            ],
            defaultSource: 'env',
            label: 'Chronopost',
            configMap: [
                'account' => 'chronopost.credentials.account',
                'password' => 'chronopost.credentials.password',
                'sub_account' => 'chronopost.credentials.sub_account',
            ],
        );

        Secrets::register(
            'colissimo',
            keys: [
                'contract_number' => 'COLISSIMO_CONTRACT',
                'password' => 'COLISSIMO_PASSWORD',
            ],
            defaultSource: 'env',
            label: 'Colissimo',
            configMap: [
                'contract_number' => 'colissimo.credentials.contract_number',
                'password' => 'colissimo.credentials.password',
            ],
        );

        Secrets::register(
            'laposte',
            keys: [
                'api_key' => 'LAPOSTE_API_KEY',
            ],
            defaultSource: 'env',
            label: 'La Poste — API Suivi',
        );

        Secrets::register(
            'pennylane',
            keys: [
                'api_token' => 'PENNYLANE_API_TOKEN',
                'invoice_template_id' => 'PENNYLANE_INVOICE_TEMPLATE_ID',
            ],
            defaultSource: 'env',
            label: 'Pennylane',
            configMap: [
                'api_token' => 'pennylane.api_token',
                'invoice_template_id' => 'pennylane.customer_invoice_template_id',
            ],
        );
    }

    /**
     * Swap 4 Lunar resources with our subclasses that override navigation placement.
     * Must run BEFORE LunarPanel::panel()->register() reads the static $resources array.
     */
    private function swapLunarResources(): void
    {
        $swaps = [
            ProductResource::class => PkoProductResource::class,
            ProductTypeResource::class => PkoProductTypeResource::class,
            ProductOptionResource::class => PkoProductOptionResource::class,
            AttributeGroupResource::class => PkoAttributeGroupResource::class,
            CollectionGroupResource::class => PkoCollectionGroupResource::class,
            TaxZoneResource::class => PkoTaxZoneResource::class,
            TaxClassResource::class => PkoTaxClassResource::class,
            TaxRateResource::class => PkoTaxRateResource::class,
        ];

        $prop = (new \ReflectionClass(LunarPanelManager::class))->getProperty('resources');

        /** @var array<int, class-string> $resources */
        $resources = $prop->getValue();

        foreach ($swaps as $original => $replacement) {
            $idx = array_search($original, $resources, true);
            if ($idx !== false) {
                $resources[$idx] = $replacement;
            }
        }

        $prop->setValue(null, $resources);
    }
}
