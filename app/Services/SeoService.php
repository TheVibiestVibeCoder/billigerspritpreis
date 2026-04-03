<?php

namespace App\Services;

use App\Models\GasStation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class SeoService
{
    public function canonicalBaseUrl(): string
    {
        $configured = trim((string) config('seo.canonical_url', config('app.url', 'http://localhost')));

        if ($configured === '') {
            return 'http://localhost';
        }

        return rtrim($configured, '/');
    }

    public function canonicalUrlForRequest(Request $request): string
    {
        return $this->canonicalUrlForPath($request->getPathInfo());
    }

    public function canonicalUrlForPath(string $path): string
    {
        $normalizedPath = '/'.ltrim($path, '/');

        if ($normalizedPath !== '/') {
            $normalizedPath = rtrim($normalizedPath, '/');
        }

        return $normalizedPath === '/'
            ? $this->canonicalBaseUrl().'/'
            : $this->canonicalBaseUrl().$normalizedPath;
    }

    public function latestContentUpdate(): ?Carbon
    {
        $cachedIso = Cache::remember('seo:latest-content-update:v1', now()->addMinutes(5), function (): ?string {
            try {
                if (! Schema::hasTable((new GasStation())->getTable())) {
                    return null;
                }

                $latestLastUpdated = GasStation::query()
                    ->whereNotNull('last_updated')
                    ->max('last_updated');
                $latestUpdatedAt = GasStation::query()->max('updated_at');
                $latest = $latestLastUpdated ?: $latestUpdatedAt;

                if (! $latest) {
                    return null;
                }

                return Carbon::parse($latest)->utc()->toAtomString();
            } catch (\Throwable $exception) {
                return null;
            }
        });

        if (! is_string($cachedIso) || $cachedIso === '') {
            return null;
        }

        return Carbon::parse($cachedIso);
    }

    public function robotsTxt(): string
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /api/',
            'Disallow: /up',
            'Disallow: /test.php',
            'Disallow: /api_test.php',
            'Disallow: /storage/',
            'Disallow: /vendor/',
            'Disallow: /*?*',
            'Sitemap: '.$this->canonicalUrlForPath('/sitemap.xml'),
        ];

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    public function sitemapXml(): string
    {
        $homeUrl = $this->canonicalUrlForPath('/');
        $lastModified = $this->latestContentUpdate()?->toAtomString();

        $xml = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
            '  <url>',
            '    <loc>'.$this->xmlEscape($homeUrl).'</loc>',
        ];

        if (is_string($lastModified) && $lastModified !== '') {
            $xml[] = '    <lastmod>'.$this->xmlEscape($lastModified).'</lastmod>';
        }

        $xml[] = '    <changefreq>always</changefreq>';
        $xml[] = '    <priority>1.0</priority>';
        $xml[] = '  </url>';
        $xml[] = '</urlset>';

        return implode(PHP_EOL, $xml).PHP_EOL;
    }

    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

