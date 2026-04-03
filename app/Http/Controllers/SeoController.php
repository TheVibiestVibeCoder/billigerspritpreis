<?php

namespace App\Http\Controllers;

use App\Services\SeoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class SeoController extends Controller
{
    public function robots(SeoService $seoService): Response
    {
        $maxAge = max(300, (int) config('seo.robots_cache_seconds', 3600));
        $robots = Cache::remember('seo:robots.txt:v1', now()->addSeconds($maxAge), function () use ($seoService): string {
            return $seoService->robotsTxt();
        });

        return response($robots, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => sprintf('public, max-age=%d', $maxAge),
            'X-Robots-Tag' => (string) config('seo.robots.noindex'),
        ]);
    }

    public function sitemap(Request $request, SeoService $seoService): Response
    {
        $maxAge = max(300, (int) config('seo.sitemap_cache_seconds', 900));
        $xml = Cache::remember('seo:sitemap.xml:v1', now()->addSeconds($maxAge), function () use ($seoService): string {
            return $seoService->sitemapXml();
        });

        $response = response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => sprintf(
                'public, max-age=%d, stale-while-revalidate=%d',
                $maxAge,
                $maxAge * 2,
            ),
            'X-Robots-Tag' => (string) config('seo.robots.noindex'),
        ]);

        $lastModified = $seoService->latestContentUpdate();
        if ($lastModified) {
            $response->setLastModified($lastModified);
        }

        $response->setEtag('"'.sha1($xml).'"');
        $response->isNotModified($request);

        return $response;
    }
}

