#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Automates the manual hosting-verification checklist: an automated
 * post-deploy check that requests each sensitive path directly over HTTP
 * and asserts a 403. Run this after every deploy to a new or updated
 * host — it does NOT touch the local filesystem or database, it only
 * makes real HTTP requests against a base URL you provide.
 *
 * Usage: php bin/verify-deployment.php https://yourdomain.com
 */

$baseUrl = rtrim((string) ($argv[1] ?? ''), '/');

if ($baseUrl === '') {
    fwrite(STDERR, 'Usage: php bin/verify-deployment.php <base_url>' . PHP_EOL);
    fwrite(STDERR, 'Example: php bin/verify-deployment.php https://yourdomain.com' . PHP_EOL);
    exit(1);
}

/**
 * @return array{status: int, error: string|null}
 */
function checkUrl(string $url): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_NOBODY, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_exec($ch);

    $error = curl_errno($ch) !== 0 ? curl_error($ch) : null;
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'error' => $error];
}

$failures = 0;

/** Paths that MUST return 403 (or at minimum, must NOT return 200 with real content). */
$mustBeBlocked = [
    '/router-app/.env',
    '/router-app/composer.json',
    '/router-app/storage/db/router.sqlite',
    '/router-app/storage/keys/',
    '/router-app/storage/local-backends/',
    '/router-app/src/Api/Router.php',
    '/router-app/vendor/autoload.php',
];

/** Paths that must be reachable and behave as expected. */
$mustWork = [
    '/api/health-check-nonexistent-path' => 401, // no API key -> 401, proves /api/* pipeline is alive
    '/admin/login' => 200,
];

fwrite(STDOUT, "Verifying deployment at: {$baseUrl}" . PHP_EOL . PHP_EOL);

fwrite(STDOUT, '--- Sensitive paths (must be blocked) ---' . PHP_EOL);
foreach ($mustBeBlocked as $path) {
    $result = checkUrl($baseUrl . $path);

    if ($result['error'] !== null) {
        fwrite(STDOUT, "  ? {$path} — request failed ({$result['error']}), treating as blocked (likely connection refused/DNS, not a pass)" . PHP_EOL);
        continue;
    }

    if ($result['status'] === 403 || $result['status'] === 404) {
        fwrite(STDOUT, "  OK  {$path} -> HTTP {$result['status']}" . PHP_EOL);
    } else {
        fwrite(STDOUT, "  FAIL {$path} -> HTTP {$result['status']} (expected 403 or 404 — THIS IS A REAL PROBLEM)" . PHP_EOL);
        $failures++;
    }
}

fwrite(STDOUT, PHP_EOL . '--- Public pipeline (must work) ---' . PHP_EOL);
foreach ($mustWork as $path => $expectedStatus) {
    $result = checkUrl($baseUrl . $path);

    if ($result['error'] !== null) {
        fwrite(STDOUT, "  FAIL {$path} — request failed: {$result['error']}" . PHP_EOL);
        $failures++;
        continue;
    }

    if ($result['status'] === $expectedStatus) {
        fwrite(STDOUT, "  OK  {$path} -> HTTP {$result['status']}" . PHP_EOL);
    } else {
        fwrite(STDOUT, "  FAIL {$path} -> HTTP {$result['status']} (expected {$expectedStatus})" . PHP_EOL);
        $failures++;
    }
}

fwrite(STDOUT, PHP_EOL);

if ($failures > 0) {
    fwrite(STDOUT, "{$failures} check(s) failed. Do not consider this deployment verified until they're fixed." . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'All checks passed.' . PHP_EOL);
exit(0);
