<?php

use App\Http\Controllers\MapController;
use App\Http\Controllers\SeoController;
use App\Http\Middleware\ApplySeoHeaders;
use App\Http\Middleware\CanonicalizeRequest;
use Illuminate\Support\Facades\Route;

Route::middleware([CanonicalizeRequest::class])->group(function (): void {
    Route::get('/robots.txt', [SeoController::class, 'robots'])->name('seo.robots');
    Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('seo.sitemap');

    Route::get('/', MapController::class)
        ->middleware([ApplySeoHeaders::class])
        ->name('map.index');
});
