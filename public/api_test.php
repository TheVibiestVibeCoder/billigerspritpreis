<?php

// ============================================================
// E-Control API Tester
// Usage: php api_test.php
// ============================================================

$results = [];

function test(string $label, callable $fn): void {
    global $results;
    $start = microtime(true);
    try {
        $result = $fn();
        $ms = round((microtime(true) - $start) * 1000);
        $results[] = ['label' => $label, 'status' => 'ok', 'ms' => $ms, 'data' => $result];
        echo "[OK]   {$label} ({$ms}ms)\n";
        if ($result) echo "       " . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
    } catch (\Throwable $e) {
        $ms = round((microtime(true) - $start) * 1000);
        $results[] = ['label' => $label, 'status' => 'fail', 'ms' => $ms, 'error' => $e->getMessage()];
        echo "[FAIL] {$label} ({$ms}ms): " . $e->getMessage() . "\n";
    }
    echo "\n";
}

function get(string $url, array $params = []): array {
    if ($params) $url .= '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) throw new \Exception("cURL error: $err");
    if ($status !== 200) throw new \Exception("HTTP $status: " . substr($body, 0, 200));

    $json = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) throw new \Exception("Invalid JSON");
    return ['status' => $status, 'data' => $json];
}

echo "=================================================\n";
echo " E-Control API Tester\n";
echo " " . date('Y-m-d H:i:s') . "\n";
echo "=================================================\n\n";

// ── 1. Ping beiden Base URLs ──────────────────────────────

test("Ping: /sprit/1.0/ping", function() {
    $r = get('https://api.e-control.at/sprit/1.0/ping');
    return ['response' => $r['data']];
});

test("Ping: /api/ping", function() {
    $r = get('https://api.e-control.at/api/ping');
    return ['response' => $r['data']];
});

// ── 2. Regions ────────────────────────────────────────────

test("Regions: /sprit/1.0/regions", function() {
    $r = get('https://api.e-control.at/sprit/1.0/regions');
    $count = count($r['data']);
    $first = $r['data'][0] ?? null;
    return ['count' => $count, 'first' => $first];
});

test("Regions: /api/regions", function() {
    $r = get('https://api.e-control.at/api/regions');
    $count = count($r['data']);
    $first = $r['data'][0] ?? null;
    return ['count' => $count, 'first' => $first];
});

// ── 3. by-address: beide Base URLs, DIE ──────────────────

test("by-address /sprit/1.0 DIE Wien - prices?", function() {
    $r = get('https://api.e-control.at/sprit/1.0/search/gas-stations/by-address', [
        'latitude' => 48.2082,
        'longitude' => 16.3738,
        'fuelType' => 'DIE',
        'includeClosed' => 'false',
    ]);
    $stations = $r['data'];
    $withPrices = array_filter($stations, fn($s) => !empty($s['prices']));
    $first = $stations[0] ?? null;
    return [
        'total' => count($stations),
        'with_prices' => count($withPrices),
        'first_prices' => $first['prices'] ?? [],
        'first_position' => $first['position'] ?? null,
    ];
});

test("by-address /api DIE Wien - prices?", function() {
    $r = get('https://api.e-control.at/api/search/gas-stations/by-address', [
        'latitude' => 48.2082,
        'longitude' => 16.3738,
        'fuelType' => 'DIE',
        'includeClosed' => 'false',
    ]);
    $stations = $r['data'];
    $withPrices = array_filter($stations, fn($s) => !empty($s['prices']));
    $first = $stations[0] ?? null;
    return [
        'total' => count($stations),
        'with_prices' => count($withPrices),
        'first_prices' => $first['prices'] ?? [],
        'first_position' => $first['position'] ?? null,
    ];
});

// ── 4. by-address includeClosed=true ─────────────────────

test("by-address /sprit/1.0 DIE includeClosed=true", function() {
    $r = get('https://api.e-control.at/sprit/1.0/search/gas-stations/by-address', [
        'latitude' => 48.2082,
        'longitude' => 16.3738,
        'fuelType' => 'DIE',
        'includeClosed' => 'true',
    ]);
    $stations = $r['data'];
    $withPrices = array_filter($stations, fn($s) => !empty($s['prices']));
    return [
        'total' => count($stations),
        'with_prices' => count($withPrices),
        'positions' => array_column($stations, 'position'),
    ];
});

// ── 5. by-region: beide Base URLs ────────────────────────

test("by-region /sprit/1.0 code=3 BL DIE (NÖ)", function() {
    $r = get('https://api.e-control.at/sprit/1.0/search/gas-stations/by-region', [
        'code' => '3',
        'type' => 'BL',
        'fuelType' => 'DIE',
        'includeClosed' => 'false',
    ]);
    $stations = $r['data'];
    $withPrices = array_filter($stations, fn($s) => !empty($s['prices']));
    $first = current($withPrices) ?: null;
    return [
        'total' => count($stations),
        'with_prices' => count($withPrices),
        'sample_price' => $first['prices'][0] ?? null,
    ];
});

test("by-region /api code=3 BL DIE (NÖ)", function() {
    $r = get('https://api.e-control.at/api/search/gas-stations/by-region', [
        'code' => '3',
        'type' => 'BL',
        'fuelType' => 'DIE',
        'includeClosed' => 'false',
    ]);
    $stations = $r['data'];
    $withPrices = array_filter($stations, fn($s) => !empty($s['prices']));
    $first = current($withPrices) ?: null;
    return [
        'total' => count($stations),
        'with_prices' => count($withPrices),
        'sample_price' => $first['prices'][0] ?? null,
    ];
});

// ── 6. by-region alle 9 Bundesländer ─────────────────────

test("by-region alle BL codes 1-9 /sprit/1.0", function() {
    $total = 0;
    $withPrices = 0;
    $sample = null;
    for ($code = 1; $code <= 9; $code++) {
        try {
            $r = get('https://api.e-control.at/sprit/1.0/search/gas-stations/by-region', [
                'code' => (string)$code,
                'type' => 'BL',
                'fuelType' => 'DIE',
                'includeClosed' => 'false',
            ]);
            $stations = $r['data'];
            $total += count($stations);
            $wp = array_filter($stations, fn($s) => !empty($s['prices']));
            $withPrices += count($wp);
            if (!$sample && $first = current($wp)) {
                $sample = ['name' => $first['name'], 'price' => $first['prices'][0] ?? null];
            }
        } catch (\Throwable $e) {
            echo "       BL {$code} failed: " . $e->getMessage() . "\n";
        }
    }
    return ['total_stations' => $total, 'with_prices' => $withPrices, 'sample' => $sample];
});

// ── 7. Regions endpoint für codes ────────────────────────

test("GET /regions - what codes exist?", function() {
    $r = get('https://api.e-control.at/sprit/1.0/regions');
    return array_map(fn($reg) => ['code' => $reg['code'], 'name' => $reg['name'], 'type' => $reg['type']], array_slice($r['data'], 0, 5));
});

echo "=================================================\n";
echo " Done!\n";
echo "=================================================\n";