<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Pko\StorefrontCms\Http\Controllers\BrandController;
use Pko\StorefrontCms\Http\Controllers\NewsletterController;
use Pko\StorefrontCms\Http\Controllers\PostController;

Route::middleware('web')->group(function (): void {
    // Newsletter (legacy, inchangé)
    Route::post('/newsletter', [NewsletterController::class, 'subscribe'])->name('newsletter.subscribe');

    // Brand public page (contenu gérable via page-builder)
    Route::get('/marque/{slug}', [BrandController::class, 'show'])->name('brand.view');

    // Redirects 301 des anciennes URLs vers /{postTypeSegment}/{slug}
    Route::get('/article/{slug}', [PostController::class, 'legacyArticleRedirect']);
    Route::get('/pages/{slug}', [PostController::class, 'legacyPageRedirect']);

    /**
     * Catch-all multi-post-type via Route::fallback().
     *
     * Fallback ne s'active que si AUCUNE autre route n'a matché — donc les routes
     * explicites (/collections/{slug}, /produits/{slug}, etc.) ont priorité absolue.
     * On inspecte l'URL : 2 segments = post single, 1 segment = index du type.
     */
    Route::fallback(function (Request $request) {
        $segments = explode('/', trim((string) $request->path(), '/'));
        $controller = app(PostController::class);

        if (count($segments) === 2 && $segments[0] !== '' && $segments[1] !== '') {
            return $controller->show($segments[0], $segments[1]);
        }
        if (count($segments) === 1 && $segments[0] !== '') {
            return $controller->index($segments[0]);
        }

        abort(404);
    });
});
