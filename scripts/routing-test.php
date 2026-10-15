<?php
declare(strict_types=1);
/**
 * Test der Routenplaner-Antworten (ohne Netz): openrouteservice- und BRouter-GeoJSON wie von den
 * Diensten dokumentiert, Luftlinie, Anbieterwahl.   php scripts/routing-test.php
 */
require dirname(__DIR__) . '/public/config.php';
require dirname(__DIR__) . '/public/lib/routing.php';
$fails = 0;
$check = function (bool $ok, string $msg) use (&$fails) { echo ($ok ? '  ok   ' : '  FEHL ') . $msg . "\n"; if (!$ok) $fails++; };

$ors = '{"type":"FeatureCollection","bbox":[10.0412,48.3201,10.0601,48.3311],"features":[{"bbox":[10.0412,48.3201,10.0601,48.3311],"type":"Feature",'
     . '"properties":{"segments":[{"distance":2345.6,"duration":512.3,"steps":[]}],"summary":{"distance":2345.6,"duration":512.3},"way_points":[0,3]},'
     . '"geometry":{"coordinates":[[10.0412,48.3201],[10.05,48.325],[10.0555,48.3288],[10.0601,48.3311]],"type":"LineString"}}],'
     . '"metadata":{"attribution":"openrouteservice.org | OpenStreetMap contributors","service":"routing"}}';
$r = routing_parse_ors($ors);
$check(count($r['line']) === 4 && $r['line'][0] === [10.0412, 48.3201] && $r['m'] === 2346, 'openrouteservice-Antwort: Linie und Länge');

$brouter = '{"type":"FeatureCollection","features":[{"type":"Feature","properties":{"creator":"BRouter-1.7.5","name":"brouter_trekking_0",'
     . '"messages":[["Longitude","Latitude","Elevation"]],"track-length":"1873","filtered ascend":"4","plain-ascend":"2","total-time":"412","total-energy":"12345","cost":"2210"},'
     . '"geometry":{"type":"LineString","coordinates":[[10.041200,48.320100,482.25],[10.048000,48.323000,480.5],[10.060100,48.331100,478.0]]}}]}';
$r = routing_parse_brouter($brouter);
$check(count($r['line']) === 3 && $r['line'][2] === [10.0601, 48.3311] && $r['m'] === 1873, 'BRouter-Antwort: Linie (ohne Höhe) und Länge');

try { routing_parse_brouter('Fehler: from-position not mapped in existing datafile'); $check(false, 'BRouter-Fehlertext erkannt'); }
catch (RuntimeException $e) { $check(str_contains($e->getMessage(), 'keine Route'), 'BRouter-Fehlertext erkannt'); }

$d = geo_dist([10.0, 48.3], [10.0, 48.31]);
$check($d > 1100 && $d < 1125, 'Luftlinie 0,01° Breite ≈ 1,11 km (' . round($d) . ' m)');

putenv('ROUTING_PROVIDER=straight');
$r = route_between([10.0, 48.3], [10.01, 48.3]);
$check(count($r['line']) === 2 && $r['m'] > 700, 'Anbieter straight (Tests)');
putenv('ROUTING_PROVIDER='); putenv('ORS_API_KEY=');
$check(routing_provider() === 'brouter', 'ohne Schlüssel: BRouter');
putenv('ORS_API_KEY=abc');
$check(routing_provider() === 'ors', 'mit ORS_API_KEY: openrouteservice');

echo $fails ? "\n$fails fehlgeschlagen\n" : "\nAlles grün.\n";
exit($fails ? 1 : 0);
