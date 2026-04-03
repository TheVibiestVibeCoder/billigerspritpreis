<?php

namespace App\Http\Middleware;

use App\Services\SeoService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanonicalizeRequest
{
    public function __construct(
        private readonly SeoService $seoService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        $targetUrl = $this->buildRedirectTarget($request);

        if (is_string($targetUrl)) {
            return redirect()->to($targetUrl, 301);
        }

        return $next($request);
    }

    private function buildRedirectTarget(Request $request): ?string
    {
        $canonicalParts = parse_url($this->seoService->canonicalBaseUrl()) ?: [];
        $enforceCanonicalHost = (bool) config('seo.enforce_canonical_host', false);
        $forceHttps = (bool) config('seo.force_https', false);

        $targetHost = $enforceCanonicalHost
            ? ($canonicalParts['host'] ?? $request->getHost())
            : $request->getHost();
        $targetPort = $enforceCanonicalHost
            ? ($canonicalParts['port'] ?? null)
            : null;
        $targetScheme = $forceHttps
            ? 'https'
            : ($enforceCanonicalHost
                ? ($canonicalParts['scheme'] ?? $request->getScheme())
                : $request->getScheme());

        $currentPath = $request->getPathInfo();
        $normalizedPath = '/'.ltrim($currentPath, '/');
        if ($normalizedPath !== '/') {
            $normalizedPath = rtrim($normalizedPath, '/');
        }

        $currentHost = strtolower($request->getHost());
        $currentScheme = strtolower($request->getScheme());
        $normalizedHost = strtolower((string) $targetHost);
        $normalizedScheme = strtolower((string) $targetScheme);

        $needsHostRedirect = $enforceCanonicalHost && $currentHost !== $normalizedHost;
        $needsSchemeRedirect = $normalizedScheme !== $currentScheme;
        $needsPathRedirect = $currentPath !== $normalizedPath;

        if (! $needsHostRedirect && ! $needsSchemeRedirect && ! $needsPathRedirect) {
            return null;
        }

        $hostWithPort = $normalizedHost;
        if (is_numeric($targetPort)) {
            $port = (int) $targetPort;
            $isDefaultPort = ($normalizedScheme === 'https' && $port === 443)
                || ($normalizedScheme === 'http' && $port === 80);

            if (! $isDefaultPort) {
                $hostWithPort .= ':'.$port;
            }
        }

        $targetUrl = $normalizedScheme.'://'.$hostWithPort.$normalizedPath;
        $query = $request->getQueryString();
        if (is_string($query) && $query !== '') {
            $targetUrl .= '?'.$query;
        }

        return $targetUrl;
    }
}

