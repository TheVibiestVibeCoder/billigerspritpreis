<?php

declare(strict_types=1);

/**
 * Spritmap End-to-End Diagnostics
 *
 * Usage:
 * - Browser: http://127.0.0.1:8000/test.php
 * - JSON:    http://127.0.0.1:8000/test.php?format=json
 *
 * Remove or protect this file in production environments.
 */

$isCli = PHP_SAPI === 'cli';
$jsonMode = $isCli || (isset($_GET['format']) && $_GET['format'] === 'json') || isset($_GET['json']);

$context = [];
$steps = [];
$startedAt = microtime(true);

/**
 * @param array<string, mixed> $context
 * @param array<int, array<string, mixed>> $steps
 * @param callable(array<string, mixed>):mixed $callback
 */
function runStep(string $name, callable $callback, array &$context, array &$steps): void
{
    $started = microtime(true);
    $step = [
        'name' => $name,
        'status' => 'fail',
        'message' => '',
        'duration_ms' => 0,
        'details' => null,
    ];

    try {
        $result = $callback($context);

        if (is_array($result)) {
            $status = strtolower((string) ($result['status'] ?? ($result['ok'] ?? false ? 'ok' : 'fail')));

            if (! in_array($status, ['ok', 'warn', 'fail'], true)) {
                $status = 'fail';
            }

            $step['status'] = $status;
            $step['message'] = (string) ($result['message'] ?? '');
            $step['details'] = $result['details'] ?? null;
        } elseif ($result === true) {
            $step['status'] = 'ok';
            $step['message'] = 'OK';
        } elseif ($result === false) {
            $step['status'] = 'fail';
            $step['message'] = 'Failed';
        } else {
            $step['status'] = 'ok';
            $step['message'] = 'OK';
            $step['details'] = $result;
        }
    } catch (Throwable $e) {
        $step['status'] = 'fail';
        $step['message'] = $e->getMessage();
        $step['details'] = [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
    }

    $step['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
    $steps[] = $step;
}

/**
 * @param mixed $payload
 * @return array<int, mixed>
 */
function extractListPayload(mixed $payload): array
{
    if (! is_array($payload)) {
        return [];
    }

    if (array_is_list($payload)) {
        return $payload;
    }

    $candidateKeys = ['gasStations', 'stations', 'result', 'items', 'regions', 'bundeslaender'];

    foreach ($candidateKeys as $key) {
        $candidate = $payload[$key] ?? null;
        if (is_array($candidate)) {
            return $candidate;
        }
    }

    return [];
}

/**
 * @param string $input
 */
function normalizeSnippet(string $input): string
{
    $singleLine = preg_replace('/\s+/', ' ', $input);

    if ($singleLine === null) {
        return '';
    }

    return mb_substr(trim($singleLine), 0, 260);
}

runStep('PHP Basics', function (): array {
    $requiredExtensions = ['curl', 'json', 'mbstring', 'openssl', 'pdo'];
    $missing = [];

    foreach ($requiredExtensions as $extension) {
        if (! extension_loaded($extension)) {
            $missing[] = $extension;
        }
    }

    $status = empty($missing) ? 'ok' : 'fail';
    $message = empty($missing)
        ? 'PHP and required extensions are available.'
        : 'Missing extensions: '.implode(', ', $missing);

    return [
        'status' => $status,
        'message' => $message,
        'details' => [
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
        ],
    ];
}, $context, $steps);

runStep('Load Laravel', function (array &$ctx): array {
    $basePath = dirname(__DIR__);
    $autoload = $basePath.'/vendor/autoload.php';
    $bootstrap = $basePath.'/bootstrap/app.php';

    if (! file_exists($autoload) || ! file_exists($bootstrap)) {
        return [
            'status' => 'fail',
            'message' => 'Laravel files not found. Run this script from a Laravel public/ directory.',
            'details' => [
                'autoload_exists' => file_exists($autoload),
                'bootstrap_exists' => file_exists($bootstrap),
            ],
        ];
    }

    require_once $autoload;
    $app = require $bootstrap;

    $ctx['app'] = $app;

    return [
        'status' => 'ok',
        'message' => 'Laravel application loaded.',
    ];
}, $context, $steps);

runStep('Bootstrap Kernels', function (array &$ctx): array {
    /** @var \Illuminate\Contracts\Foundation\Application $app */
    $app = $ctx['app'];

    /** @var \Illuminate\Contracts\Console\Kernel $consoleKernel */
    $consoleKernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $consoleKernel->bootstrap();

    /** @var \Illuminate\Contracts\Http\Kernel $httpKernel */
    $httpKernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);

    $ctx['consoleKernel'] = $consoleKernel;
    $ctx['httpKernel'] = $httpKernel;

    return [
        'status' => 'ok',
        'message' => 'Console + HTTP kernel bootstrapped.',
    ];
}, $context, $steps);

runStep('Config Check', function (array &$ctx): array {
    /** @var \Illuminate\Contracts\Foundation\Application $app */
    $app = $ctx['app'];
    /** @var \Illuminate\Contracts\Config\Repository $config */
    $config = $app->make('config');

    $ctx['config'] = $config;

    $baseUrl = (string) $config->get('services.econtrol.base_url', '');
    $fallbackBaseUrl = (string) $config->get('services.econtrol.fallback_base_url', '');
    $cacheTtl = (int) $config->get('services.econtrol.cache_ttl', 0);
    $dbDefault = (string) $config->get('database.default', '');

    $status = 'ok';
    $messages = [];

    if ($baseUrl === '') {
        $status = 'fail';
        $messages[] = 'ECONTROL_API_BASE missing';
    } elseif (! str_contains($baseUrl, 'api.e-control.at')) {
        $status = 'warn';
        $messages[] = 'ECONTROL_API_BASE differs from default';
    } else {
        $messages[] = 'ECONTROL_API_BASE looks good';
    }

    if ($cacheTtl <= 0) {
        $status = 'warn';
        $messages[] = 'ECONTROL_CACHE_TTL should be > 0';
    }

    return [
        'status' => $status,
        'message' => implode(' | ', $messages),
        'details' => [
            'app_env' => (string) $config->get('app.env'),
            'app_url' => (string) $config->get('app.url'),
            'econtrol_base_url' => $baseUrl,
            'econtrol_fallback_base_url' => $fallbackBaseUrl,
            'econtrol_cache_ttl' => $cacheTtl,
            'database_default' => $dbDefault,
            'cache_store' => (string) $config->get('cache.default'),
        ],
    ];
}, $context, $steps);

runStep('Cache Read/Write', function (): array {
    $key = 'spritmap:test:'.bin2hex(random_bytes(4));
    $value = ['time' => date(DATE_ATOM), 'ok' => true];

    \Illuminate\Support\Facades\Cache::put($key, $value, now()->addMinutes(5));
    $read = \Illuminate\Support\Facades\Cache::get($key);
    \Illuminate\Support\Facades\Cache::forget($key);

    $ok = is_array($read) && (($read['ok'] ?? false) === true);

    return [
        'status' => $ok ? 'ok' : 'fail',
        'message' => $ok ? 'Cache is writable and readable.' : 'Cache roundtrip failed.',
        'details' => [
            'cache_store' => config('cache.default'),
        ],
    ];
}, $context, $steps);

runStep('Database + gas_stations', function (): array {
    \Illuminate\Support\Facades\DB::connection()->getPdo();
    $hasTable = \Illuminate\Support\Facades\Schema::hasTable('gas_stations');
    $count = $hasTable ? \App\Models\GasStation::query()->count() : null;

    return [
        'status' => $hasTable ? 'ok' : 'fail',
        'message' => $hasTable ? 'Database reachable, gas_stations table exists.' : 'gas_stations table missing. Run migrations.',
        'details' => [
            'connection' => config('database.default'),
            'table_exists' => $hasTable,
            'rows_in_gas_stations' => $count,
        ],
    ];
}, $context, $steps);

runStep('Direct E-Control: regions', function (): array {
    $base = rtrim((string) config('services.econtrol.base_url', 'https://api.e-control.at/api'), '/');

    try {
        $response = \Illuminate\Support\Facades\Http::acceptJson()
            ->timeout((int) config('services.econtrol.timeout', 15))
            ->retry(1, 200)
            ->get($base.'/regions');
    } catch (Throwable $e) {
        return [
            'status' => 'fail',
            'message' => 'Connection failed: '.$e->getMessage(),
        ];
    }

    $payload = $response->json();
    $list = extractListPayload($payload);
    $count = count($list);
    $ok = $response->successful();
    $status = $ok ? ($count > 0 ? 'ok' : 'warn') : 'fail';

    return [
        'status' => $status,
        'message' => $ok
            ? 'HTTP '.$response->status().' with '.$count.' region entries.'
            : 'HTTP '.$response->status().' from E-Control regions endpoint.',
        'details' => [
            'status_code' => $response->status(),
            'entry_count' => $count,
            'body_snippet' => normalizeSnippet((string) $response->body()),
        ],
    ];
}, $context, $steps);

runStep('Direct E-Control: by-address (Vienna, DIE)', function (): array {
    $base = rtrim((string) config('services.econtrol.base_url', 'https://api.e-control.at/api'), '/');

    try {
        $response = \Illuminate\Support\Facades\Http::acceptJson()
            ->timeout((int) config('services.econtrol.timeout', 15))
            ->retry(1, 200)
            ->get($base.'/search/gas-stations/by-address', [
                'latitude' => 48.2082,
                'longitude' => 16.3738,
                'fuelType' => 'DIE',
                'includeClosed' => 'false',
            ]);
    } catch (Throwable $e) {
        return [
            'status' => 'fail',
            'message' => 'Connection failed: '.$e->getMessage(),
        ];
    }

    $payload = $response->json();
    $list = extractListPayload($payload);
    $count = count($list);
    $ok = $response->successful();
    $status = $ok ? ($count > 0 ? 'ok' : 'warn') : 'fail';

    return [
        'status' => $status,
        'message' => $ok
            ? 'HTTP '.$response->status().' with '.$count.' stations.'
            : 'HTTP '.$response->status().' from by-address endpoint.',
        'details' => [
            'status_code' => $response->status(),
            'station_count' => $count,
            'body_snippet' => normalizeSnippet((string) $response->body()),
        ],
    ];
}, $context, $steps);

runStep('Service Flow: EControlService DIE', function (array &$ctx): array {
    /** @var \Illuminate\Contracts\Foundation\Application $app */
    $app = $ctx['app'];
    /** @var \App\Services\EControlService $service */
    $service = $app->make(\App\Services\EControlService::class);

    $bounds = [
        'south' => 46.2,
        'west' => 9.3,
        'north' => 49.2,
        'east' => 17.3,
    ];

    $stations = $service->getStationsForBounds($bounds, 'DIE', false);
    $count = $stations->count();
    $first = $stations->first();

    return [
        'status' => $count > 0 ? 'ok' : 'warn',
        'message' => 'Service returned '.$count.' DIE stations.',
        'details' => [
            'count' => $count,
            'first_station' => $first ? [
                'id' => $first['id'] ?? null,
                'name' => $first['name'] ?? null,
                'price' => $first['price'] ?? null,
                'city' => $first['city'] ?? null,
            ] : null,
        ],
    ];
}, $context, $steps);

runStep('Service Flow: EControlService SUP', function (array &$ctx): array {
    /** @var \Illuminate\Contracts\Foundation\Application $app */
    $app = $ctx['app'];
    /** @var \App\Services\EControlService $service */
    $service = $app->make(\App\Services\EControlService::class);

    $bounds = [
        'south' => 46.2,
        'west' => 9.3,
        'north' => 49.2,
        'east' => 17.3,
    ];

    $stations = $service->getStationsForBounds($bounds, 'SUP', false);
    $count = $stations->count();

    return [
        'status' => $count > 0 ? 'ok' : 'warn',
        'message' => 'Service returned '.$count.' SUP stations.',
    ];
}, $context, $steps);

runStep('Internal API Route: /api/stations', function (array &$ctx): array {
    /** @var \Illuminate\Contracts\Http\Kernel $httpKernel */
    $httpKernel = $ctx['httpKernel'];

    $request = \Illuminate\Http\Request::create(
        '/api/stations?fuel=DIE&bounds=46.2,9.3,49.2,17.3&includeClosed=0',
        'GET'
    );
    $request->headers->set('Accept', 'application/json');
    $request->headers->set('X-Requested-With', 'XMLHttpRequest');

    $response = $httpKernel->handle($request);
    $statusCode = $response->getStatusCode();
    $body = (string) $response->getContent();
    $httpKernel->terminate($request, $response);

    if ($statusCode !== 200) {
        return [
            'status' => 'fail',
            'message' => 'API returned HTTP '.$statusCode,
            'details' => ['body_snippet' => normalizeSnippet($body)],
        ];
    }

    $payload = json_decode($body, true);
    if (! is_array($payload)) {
        return [
            'status' => 'fail',
            'message' => 'API response is not valid JSON.',
            'details' => ['body_snippet' => normalizeSnippet($body)],
        ];
    }

    $stations = $payload['stations'] ?? null;
    if (! is_array($stations)) {
        return [
            'status' => 'fail',
            'message' => 'API JSON does not contain an array "stations".',
            'details' => ['payload_keys' => array_keys($payload)],
        ];
    }

    $count = count($stations);

    return [
        'status' => $count > 0 ? 'ok' : 'warn',
        'message' => 'Internal API responded with '.$count.' stations.',
        'details' => [
            'fuel' => $payload['fuel'] ?? null,
            'count' => $payload['count'] ?? null,
            'generated_at' => $payload['meta']['generated_at'] ?? null,
        ],
    ];
}, $context, $steps);

runStep('Frontend Binding Check', function (array &$ctx): array {
    /** @var \Illuminate\Contracts\Http\Kernel $httpKernel */
    $httpKernel = $ctx['httpKernel'];

    $request = \Illuminate\Http\Request::create('/', 'GET');
    $response = $httpKernel->handle($request);
    $statusCode = $response->getStatusCode();
    $body = (string) $response->getContent();
    $httpKernel->terminate($request, $response);

    $hasMapDiv = str_contains($body, 'id="map"');
    $hasScript = str_contains($body, '/js/spritmap.js');
    $jsFileExists = file_exists(__DIR__.'/js/spritmap.js');

    $status = ($statusCode === 200 && $hasMapDiv && $hasScript && $jsFileExists) ? 'ok' : 'fail';

    return [
        'status' => $status,
        'message' => $status === 'ok'
            ? 'Map page contains map container and JS binding.'
            : 'Map page/JS binding incomplete.',
        'details' => [
            'root_status' => $statusCode,
            'has_map_div' => $hasMapDiv,
            'has_script_reference' => $hasScript,
            'public_js_exists' => $jsFileExists,
        ],
    ];
}, $context, $steps);

$summary = [
    'ok' => count(array_filter($steps, fn (array $step): bool => $step['status'] === 'ok')),
    'warn' => count(array_filter($steps, fn (array $step): bool => $step['status'] === 'warn')),
    'fail' => count(array_filter($steps, fn (array $step): bool => $step['status'] === 'fail')),
    'total' => count($steps),
    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
    'timestamp' => date(DATE_ATOM),
];

if ($jsonMode) {
    if (! $isCli) {
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode(
        ['summary' => $summary, 'steps' => $steps],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );

    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Spritmap Testreport</title>
    <style>
        :root {
            --ok: #166534;
            --ok-bg: #dcfce7;
            --warn: #92400e;
            --warn-bg: #ffedd5;
            --fail: #991b1b;
            --fail-bg: #fee2e2;
            --ink: #0f172a;
            --muted: #475569;
            --line: #e2e8f0;
            --card: #ffffff;
            --bg: #f8fafc;
        }
        html, body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font-family: Segoe UI, Tahoma, Geneva, Verdana, sans-serif;
        }
        .wrap {
            max-width: 1080px;
            margin: 24px auto 56px;
            padding: 0 16px;
        }
        .head {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 18px 20px;
            margin-bottom: 16px;
        }
        h1 {
            margin: 0 0 6px;
            font-size: 1.25rem;
        }
        .meta {
            color: var(--muted);
            font-size: 0.92rem;
        }
        .summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-top: 14px;
        }
        .pill {
            border-radius: 10px;
            padding: 8px 10px;
            font-weight: 600;
            font-size: 0.9rem;
            border: 1px solid transparent;
        }
        .pill.ok {
            color: var(--ok);
            background: var(--ok-bg);
            border-color: #86efac;
        }
        .pill.warn {
            color: var(--warn);
            background: var(--warn-bg);
            border-color: #fdba74;
        }
        .pill.fail {
            color: var(--fail);
            background: var(--fail-bg);
            border-color: #fca5a5;
        }
        .pill.info {
            color: #1d4ed8;
            background: #dbeafe;
            border-color: #93c5fd;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 14px;
            overflow: hidden;
        }
        th, td {
            text-align: left;
            vertical-align: top;
            padding: 10px 12px;
            border-bottom: 1px solid var(--line);
            font-size: 0.92rem;
        }
        th {
            background: #f1f5f9;
            font-size: 0.82rem;
            letter-spacing: .02em;
            text-transform: uppercase;
            color: #334155;
        }
        tr:last-child td {
            border-bottom: 0;
        }
        .badge {
            display: inline-block;
            font-size: 0.75rem;
            font-weight: 700;
            border-radius: 999px;
            padding: 3px 8px;
            border: 1px solid transparent;
            text-transform: uppercase;
        }
        .badge.ok {
            color: var(--ok);
            background: var(--ok-bg);
            border-color: #86efac;
        }
        .badge.warn {
            color: var(--warn);
            background: var(--warn-bg);
            border-color: #fdba74;
        }
        .badge.fail {
            color: var(--fail);
            background: var(--fail-bg);
            border-color: #fca5a5;
        }
        pre {
            margin: 0;
            white-space: pre-wrap;
            word-break: break-word;
            font-size: 0.8rem;
            color: #334155;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px;
            max-height: 280px;
            overflow: auto;
        }
        .foot {
            margin-top: 12px;
            color: var(--muted);
            font-size: 0.82rem;
        }
        a {
            color: #1d4ed8;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
        @media (max-width: 860px) {
            .summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
    </style>
</head>
<body>
<main class="wrap">
    <section class="head">
        <h1>Spritmap Testreport</h1>
        <div class="meta">
            Zeit: <?= htmlspecialchars((string) $summary['timestamp'], ENT_QUOTES, 'UTF-8') ?> |
            Dauer: <?= (int) $summary['duration_ms'] ?> ms |
            JSON: <a href="?format=json">?format=json</a>
        </div>

        <div class="summary">
            <div class="pill ok">OK: <?= (int) $summary['ok'] ?></div>
            <div class="pill warn">WARN: <?= (int) $summary['warn'] ?></div>
            <div class="pill fail">FAIL: <?= (int) $summary['fail'] ?></div>
            <div class="pill info">TOTAL: <?= (int) $summary['total'] ?></div>
        </div>
    </section>

    <table>
        <thead>
        <tr>
            <th style="width: 52px;">#</th>
            <th style="width: 120px;">Status</th>
            <th style="width: 290px;">Step</th>
            <th>Message / Details</th>
            <th style="width: 90px;">Time</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($steps as $index => $step): ?>
            <tr>
                <td><?= (int) ($index + 1) ?></td>
                <td><span class="badge <?= htmlspecialchars((string) $step['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $step['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                <td><?= htmlspecialchars((string) $step['name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <div><?= htmlspecialchars((string) $step['message'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if (! is_null($step['details'])): ?>
                        <pre><?= htmlspecialchars((string) json_encode($step['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></pre>
                    <?php endif; ?>
                </td>
                <td><?= (int) $step['duration_ms'] ?> ms</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="foot">
        Hinweis: Diese Datei ist fuer lokale Diagnose gedacht und sollte in Produktion entfernt oder geschuetzt werden.
    </div>
</main>
</body>
</html>
