<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Canonical URL
    |--------------------------------------------------------------------------
    |
    | This URL is the single source of truth for canonical links and sitemap
    | entries. In production, point this to your public primary domain.
    |
    */
    'canonical_url' => env('SEO_CANONICAL_URL', env('APP_URL', 'http://localhost')),

    /*
    |--------------------------------------------------------------------------
    | Canonical Enforcement
    |--------------------------------------------------------------------------
    |
    | Keep these enabled in production to avoid duplicate-index URLs across
    | hostnames/protocols.
    |
    */
    'enforce_canonical_host' => env('SEO_ENFORCE_CANONICAL_HOST', env('APP_ENV', 'production') === 'production'),
    'force_https' => env('SEO_FORCE_HTTPS', env('APP_ENV', 'production') === 'production'),

    /*
    |--------------------------------------------------------------------------
    | SEO Headers
    |--------------------------------------------------------------------------
    */
    'content_language' => env('SEO_CONTENT_LANGUAGE', 'de-AT'),
    'html_cache_seconds' => (int) env('SEO_HTML_CACHE_SECONDS', 300),

    'robots' => [
        'index' => env('SEO_ROBOTS_INDEX', 'index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1'),
        'noindex' => env('SEO_ROBOTS_NOINDEX', 'noindex, nofollow, noarchive'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sitemap / Robots Cache
    |--------------------------------------------------------------------------
    */
    'sitemap_cache_seconds' => (int) env('SEO_SITEMAP_CACHE_SECONDS', 900),
    'robots_cache_seconds' => (int) env('SEO_ROBOTS_CACHE_SECONDS', 3600),
];

