<?php

namespace App\Http\Middleware;

use App\Services\SeoService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplySeoHeaders
{
    public function __construct(
        private readonly SeoService $seoService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->isIndexableHtmlResponse($request, $response)) {
            return $response;
        }

        $canonicalUrl = $this->seoService->canonicalUrlForRequest($request);
        $response->headers->set('Link', sprintf('<%s>; rel="canonical"', $canonicalUrl));
        $response->headers->set('X-Robots-Tag', (string) config('seo.robots.index'));
        $response->headers->set('Content-Language', (string) config('seo.content_language', 'de-AT'));

        if (! $response->headers->has('Cache-Control')) {
            $maxAge = max(60, (int) config('seo.html_cache_seconds', 300));
            $staleRevalidate = $maxAge * 3;
            $response->headers->set(
                'Cache-Control',
                sprintf('public, max-age=%d, stale-while-revalidate=%d', $maxAge, $staleRevalidate),
            );
        }

        $lastModified = $this->seoService->latestContentUpdate();
        if ($lastModified) {
            $response->setLastModified($lastModified);
            $response->setEtag('"'.sha1($canonicalUrl.'|'.$lastModified->getTimestamp()).'"');
            $response->isNotModified($request);
        }

        return $response;
    }

    private function isIndexableHtmlResponse(Request $request, Response $response): bool
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return false;
        }

        if ($response->getStatusCode() >= 300) {
            return false;
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type', ''));
        if ($contentType !== '' && ! str_contains($contentType, 'text/html')) {
            return false;
        }

        return ! $request->is('api/*')
            && ! $request->is('robots.txt')
            && ! $request->is('sitemap.xml');
    }
}

