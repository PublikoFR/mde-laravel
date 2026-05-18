<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('pko_post_types')
            ->where('handle', 'article')
            ->where('url_segment', 'article')
            ->update(['url_segment' => 'actualites']);
    }

    public function down(): void
    {
        DB::table('pko_post_types')
            ->where('handle', 'article')
            ->where('url_segment', 'actualites')
            ->update(['url_segment' => 'article']);
    }
};
