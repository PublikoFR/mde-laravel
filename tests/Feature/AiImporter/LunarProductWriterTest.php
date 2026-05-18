<?php

declare(strict_types=1);

namespace Tests\Feature\AiImporter;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lunar\Models\Currency;
use Lunar\Models\Language;
use Lunar\Models\Price;
use Lunar\Models\ProductType;
use Lunar\Models\ProductVariant;
use Lunar\Models\TaxClass;
use Pko\AiImporter\Enums\StagingStatus;
use Pko\AiImporter\Models\ImportJob;
use Pko\AiImporter\Models\StagingRecord;
use Pko\AiImporter\Services\LunarProductWriter;
use Tests\TestCase;

class LunarProductWriterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ProductType::firstOrCreate(['name' => 'Test Type']);
        TaxClass::firstOrCreate(['name' => 'Standard'], ['default' => true]);
        Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'exchange_rate' => 1.0, 'default' => true, 'enabled' => true]);
        Language::firstOrCreate(['code' => 'fr'], ['name' => 'Français', 'default' => true]);
    }

    public function test_creates_product_and_variant_with_price(): void
    {
        $job = ImportJob::create([
            'input_file_path' => 'n/a',
            'status' => 'pending',
            'import_status' => 'pending',
            'error_policy' => 'ignore',
        ]);

        $record = StagingRecord::create([
            'import_job_id' => $job->id,
            'row_number' => 2,
            'data' => [
                'reference' => 'SKU-100',
                'name' => 'Moteur Somfy RS100',
                'description' => 'Moteur tubulaire 40Nm',
                'price_cents' => 19900,
                'stock' => 12,
                'weight_value' => 2.5,
            ],
            'status' => StagingStatus::Pending,
        ]);

        (new LunarProductWriter)->write($record);

        $record->refresh();
        $this->assertSame(StagingStatus::Created, $record->status);

        $variant = ProductVariant::where('sku', 'SKU-100')->firstOrFail();
        $this->assertSame(12, $variant->stock);
        $this->assertEquals(2.5, $variant->weight_value);

        $price = Price::where('priceable_id', $variant->id)->where('priceable_type', ProductVariant::class)->first();
        $this->assertNotNull($price);
    }

    public function test_second_call_updates_instead_of_creates(): void
    {
        $job = ImportJob::create([
            'input_file_path' => 'n/a',
            'status' => 'pending',
            'import_status' => 'pending',
            'error_policy' => 'ignore',
        ]);

        $mkRecord = fn (int $stock) => StagingRecord::create([
            'import_job_id' => $job->id,
            'row_number' => $stock,
            'data' => [
                'reference' => 'SKU-200',
                'name' => 'Stock test',
                'price_cents' => 1000,
                'stock' => $stock,
            ],
            'status' => StagingStatus::Pending,
        ]);

        (new LunarProductWriter)->write($mkRecord(10));
        $r2 = $mkRecord(42);
        (new LunarProductWriter)->write($r2);

        $r2->refresh();
        $this->assertSame(StagingStatus::Updated, $r2->status);
        $this->assertSame(42, ProductVariant::where('sku', 'SKU-200')->value('stock'));
    }

    public function test_missing_reference_marks_error(): void
    {
        $job = ImportJob::create([
            'input_file_path' => 'n/a', 'status' => 'pending', 'import_status' => 'pending', 'error_policy' => 'ignore',
        ]);
        $record = StagingRecord::create([
            'import_job_id' => $job->id,
            'row_number' => 1,
            'data' => ['name' => 'no sku'],
            'status' => StagingStatus::Pending,
        ]);

        (new LunarProductWriter)->write($record);

        $record->refresh();
        $this->assertSame(StagingStatus::Error, $record->status);
        $this->assertStringContainsString('reference', (string) $record->error_message);
    }

    public function test_accepts_prestashop_legacy_keys(): void
    {
        $job = ImportJob::create([
            'input_file_path' => 'n/a', 'status' => 'pending', 'import_status' => 'pending', 'error_policy' => 'ignore',
        ]);

        $record = StagingRecord::create([
            'import_job_id' => $job->id,
            'row_number' => 2,
            'data' => [
                'reference' => 'SOM-LEGACY-1',
                'name' => 'Produit legacy PS',
                'price_tex' => 199.00,     // → price_cents = 19900
                'quantity' => 7,            // → stock
                'ean13' => '3017620422003', // → ean
                'manufacturer' => 'Somfy',  // → brand_name
                'link_rewrite' => 'produit-legacy-ps', // → url_key
                'depth' => 12.5,            // → length_value
                'width' => 3.2,             // → width_value
                'height' => 4.8,            // → height_value
                'weight' => 0.450,          // → weight_value
            ],
            'status' => StagingStatus::Pending,
        ]);

        (new LunarProductWriter)->write($record);

        $record->refresh();
        $this->assertSame(StagingStatus::Created, $record->status);

        $variant = ProductVariant::where('sku', 'SOM-LEGACY-1')->firstOrFail();
        $this->assertSame(7, $variant->stock);
        $this->assertSame('3017620422003', $variant->ean);
        $this->assertEquals(0.450, $variant->weight_value);
        $this->assertEquals(12.5, $variant->length_value);
        $this->assertEquals(3.2, $variant->width_value);
        $this->assertEquals(4.8, $variant->height_value);

        $price = Price::where('priceable_id', $variant->id)->first();
        $this->assertNotNull($price);
        $this->assertSame(19900, (int) $price->price->value);
    }

    public function test_canonical_key_wins_over_legacy(): void
    {
        $job = ImportJob::create([
            'input_file_path' => 'n/a', 'status' => 'pending', 'import_status' => 'pending', 'error_policy' => 'ignore',
        ]);

        $record = StagingRecord::create([
            'import_job_id' => $job->id,
            'row_number' => 3,
            'data' => [
                'reference' => 'SOM-CANON-1',
                'name' => 'Test priority',
                'price_cents' => 5000,  // canonical wins
                'price_tex' => 99.0,    // legacy ignored
                'stock' => 10,           // canonical wins
                'quantity' => 999,        // legacy ignored
            ],
            'status' => StagingStatus::Pending,
        ]);

        (new LunarProductWriter)->write($record);

        $variant = ProductVariant::where('sku', 'SOM-CANON-1')->firstOrFail();
        $this->assertSame(10, $variant->stock);

        $price = Price::where('priceable_id', $variant->id)->first();
        $this->assertSame(5000, (int) $price->price->value);
    }
}
