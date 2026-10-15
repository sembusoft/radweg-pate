<?php
declare(strict_types=1);
/**
 * Fahrrad-Routing für die Zufahrt (Startpunkt des Paten ↔ Tour). Nur Server-seitig.
 *
 * Anbieter (ROUTING_PROVIDER in der .env):
 *   ors      openrouteservice, Profil cycling-regular – braucht ORS_API_KEY (kostenlos: openrouteservice.org)
 *   brouter  BRouter (brouter.de, Profil trekking) – ohne Schlüssel, faire Nutzung
 *   straight gerade Linie – nur für Tests
 * Ohne Angabe: ors, wenn ORS_API_KEY gesetzt ist, sonst brouter.
 * Koordinaten immer [lon, lat]. Rückgabe: ['line' => [[lon,lat],…], 'm' => Meter].
 */
function routing_provider(): string {
    $p = strtolower(rp_env('ROUTING_PROVIDER'));
    if (in_array($p, ['ors', 'brouter', 'straight'], true)) return $p;
    return rp_env('ORS_API_KEY') !== '' ? 'ors' : 'brouter';
}

/** Luftlinie in Metern (Haversine). */
function geo_dist(array $a, array $b): float {
    $r = 6371000; $la1 = deg2rad($a[1]); $la2 = deg2rad($b[1]);
    $d = sin(($la2 - $la1) / 2) ** 2 + cos($la1) * cos($la2) * sin(deg2rad($b[0] - $a[0]) / 2) ** 2;
    return 2 * $r * asin(min(1, sqrt($d)));
}

/** HTTP-Aufruf (curl bevorzugt). */
function routing_http(string $url, ?string $body = null, array $headers = []): string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_USERAGENT => 'Radwegpate (radweg-pate.de)']);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $out = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
        curl_close($ch);
        if ($out === false) throw new RuntimeException('Routenplaner nicht erreichbar: ' . $err);
        if ($code >= 400) throw new RuntimeException('Routenplaner antwortet mit HTTP ' . $code . ': ' . mb_substr((string)$out, 0, 200));
        return (string)$out;
    }
    $ctx = stream_context_create(['http' => ['method' => $body === null ? 'GET' : 'POST', 'timeout' => 25, 'ignore_errors' => true,
        'header' => implode("\r\n", array_merge($headers, ['User-Agent: Radwegpate (radweg-pate.de)'])), 'content' => $body ?? '']]);
    $out = @file_get_contents($url, false, $ctx);
    if ($out === false) throw new RuntimeException('Routenplaner nicht erreichbar.');
    return $out;
}

/** openrouteservice-Antwort (GeoJSON) auswerten. */
function routing_parse_ors(string $json): array {
    $d = json_decode($json, true);
    $f = $d['features'][0] ?? null;
    if (!is_array($f) || !is_array($f['geometry']['coordinates'] ?? null)) throw new RuntimeException('openrouteservice: keine Route gefunden.');
    $line = array_map(fn($c) => [round((float)$c[0], 6), round((float)$c[1], 6)], $f['geometry']['coordinates']);
    return ['line' => $line, 'm' => (int)round((float)($f['properties']['summary']['distance'] ?? 0))];
}
/** BRouter-Antwort (GeoJSON) auswerten. */
function routing_parse_brouter(string $json): array {
    $d = json_decode($json, true);
    $f = $d['features'][0] ?? null;
    if (!is_array($f) || !is_array($f['geometry']['coordinates'] ?? null)) throw new RuntimeException('BRouter: keine Route gefunden (' . mb_substr(trim($json), 0, 120) . ')');
    $line = array_map(fn($c) => [round((float)$c[0], 6), round((float)$c[1], 6)], $f['geometry']['coordinates']);
    return ['line' => $line, 'm' => (int)round((float)($f['properties']['track-length'] ?? 0))];
}

/** Radroute von $from nach $to ([lon,lat]). */
function route_between(array $from, array $to): array {
    if (geo_dist($from, $to) < 30) return ['line' => [$from, $to], 'm' => (int)round(geo_dist($from, $to))];
    $p = routing_provider();
    if ($p === 'straight') return ['line' => [$from, $to], 'm' => (int)round(geo_dist($from, $to))];
    if ($p === 'ors') {
        $key = rp_env('ORS_API_KEY');
        if ($key === '') throw new RuntimeException('ORS_API_KEY fehlt in der .env.');
        $url = rtrim(rp_env('ORS_URL', 'https://api.openrouteservice.org'), '/') . '/v2/directions/cycling-regular/geojson';
        return routing_parse_ors(routing_http($url, json_encode(['coordinates' => [$from, $to]]),
            ['Authorization: ' . $key, 'Content-Type: application/json', 'Accept: application/geo+json']));
    }
    $url = rtrim(rp_env('BROUTER_URL', 'https://brouter.de/brouter'), '/') . '?' . http_build_query([
        'lonlats' => $from[0] . ',' . $from[1] . '|' . $to[0] . ',' . $to[1],
        'profile' => rp_env('BROUTER_PROFILE', 'trekking'), 'alternativeidx' => 0, 'format' => 'geojson']);
    return routing_parse_brouter(routing_http($url));
}
