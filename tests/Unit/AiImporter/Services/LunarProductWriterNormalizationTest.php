<?php

declare(strict_types=1);

namespace Tests\Unit\AiImporter\Services;

use PHPUnit\Framework\TestCase;
use Pko\AiImporter\Services\LunarProductWriter;

class LunarProductWriterNormalizationTest extends TestCase
{
    public function test_renames_simple_aliases(): void
    {
        $data = [
            'reference' => 'X',
            'ean13' => '3017620422003',
            'quantity' => 5,
            'manufacturer' => 'Somfy',
            'link_rewrite' => 'mon-produit',
        ];

        $out = LunarProductWriter::normalizeLegacyKeys($data);

        $this->assertSame('3017620422003', $out['ean']);
        $this->assertSame(5, $out['stock']);
        $this->assertSame('Somfy', $out['brand_name']);
        $this->assertSame('mon-produit', $out['url_key']);
        $this->assertArrayNotHasKey('ean13', $out);
        $this->assertArrayNotHasKey('quantity', $out);
        $this->assertArrayNotHasKey('manufacturer', $out);
        $this->assertArrayNotHasKey('link_rewrite', $out);
    }

    public function test_renames_dimension_axes(): void
    {
        $data = ['width' => 10.0, 'height' => 20.5, 'depth' => 30.0, 'weight' => 1.250];

        $out = LunarProductWriter::normalizeLegacyKeys($data);

        $this->assertSame(10.0, $out['width_value']);
        $this->assertSame(20.5, $out['height_value']);
        $this->assertSame(30.0, $out['length_value']);  // depth → length
        $this->assertSame(1.250, $out['weight_value']);
        $this->assertArrayNotHasKey('depth', $out);
    }

    public function test_converts_price_tex_euros_to_price_cents(): void
    {
        $out = LunarProductWriter::normalizeLegacyKeys(['price_tex' => 199.00]);
        $this->assertSame(19900, $out['price_cents']);
        $this->assertArrayNotHasKey('price_tex', $out);

        $out2 = LunarProductWriter::normalizeLegacyKeys(['price_tex' => '49.99']);
        $this->assertSame(4999, $out2['price_cents']);

        $out3 = LunarProductWriter::normalizeLegacyKeys(['price_tex' => 0.10]);
        $this->assertSame(10, $out3['price_cents']);
    }

    public function test_canonical_key_wins_over_legacy(): void
    {
        $data = [
            'price_cents' => 5000,
            'price_tex' => 99.0,
            'stock' => 10,
            'quantity' => 999,
            'ean' => 'CANONICAL',
            'ean13' => 'LEGACY',
        ];

        $out = LunarProductWriter::normalizeLegacyKeys($data);

        $this->assertSame(5000, $out['price_cents']);
        $this->assertSame(10, $out['stock']);
        $this->assertSame('CANONICAL', $out['ean']);
    }

    public function test_renames_image_and_category(): void
    {
        $out = LunarProductWriter::normalizeLegacyKeys([
            'image' => 'https://example.com/a.jpg,https://example.com/b.jpg',
            'category' => 'moteurs,domotique',
        ]);

        $this->assertSame('https://example.com/a.jpg,https://example.com/b.jpg', $out['images']);
        $this->assertSame('moteurs,domotique', $out['collections']);
    }

    public function test_no_legacy_keys_is_passthrough(): void
    {
        $data = ['reference' => 'X', 'name' => 'Y', 'price_cents' => 1000];

        $this->assertSame($data, LunarProductWriter::normalizeLegacyKeys($data));
    }

    public function test_unknown_keys_are_preserved(): void
    {
        $data = ['reference' => 'X', 'custom_downstream_key' => 'kept'];

        $out = LunarProductWriter::normalizeLegacyKeys($data);

        $this->assertSame('kept', $out['custom_downstream_key']);
    }
}
