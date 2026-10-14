<?php
// Einzige Stelle für die Versionsnummer der App.
// sw.js trägt dieselbe Nummer in CACHE_NAME ('radwegpate-<Version>');
// scripts/check.mjs bricht in der CI ab, wenn beide auseinanderlaufen.
declare(strict_types=1);
const APP_VERSION = '3.4.0';
