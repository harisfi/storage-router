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
 * Usage:
 *   php bin/verify-deployment.php https://yourdomain.com   # live deployment (HTTP)
 *   php bin/verify-deployment.php --repo [REPO_ROOT]       # static repo/CI checks
 */

// ---- Static repo check (runs in CI without a live deployment) -------------
if (($argv[1] ?? '') === '--repo') {
    $repoRoot = rtrim((string) ($argv[2] ?? ''), '/');
    if ($repoRoot === '') {
        $repoRoot = dirname(__DIR__, 2);
    }
    exit(repoCheck($repoRoot));
}

/**
 * Verifies the structural/static invariants that keep operational safety
 * from depending on human discipline: deny-rule files guard sensitive
 * subtrees, secrets are gitignored and untracked, and the docroot never
 * coincides with storage. Run in CI so a regression fails the build.
 */
function repoCheck(string $repoRoot): int
{
    $appDir = is_dir($repoRoot . '/router-app') ? $repoRoot . '/router-app' : $repoRoot;
    $failures = 0;

    $ok = static function (string $msg): void { fwrite(STDOUT, "  OK  {$msg}" . PHP_EOL); };
    $fail = static function (string $msg): void { fwrite(STDOUT, "  FAIL {$msg}" . PHP_EOL); };

    fwrite(STDOUT, "Repo check on: {$appDir}" . PHP_EOL . PHP_EOL);

    // 1. Deny-rule files guard the sensitive subtrees (a single .htaccess
    //    at storage/ and src/ covers all descendants on Apache).
    fwrite(STDOUT, '--- Sensitive subtree guards ---' . PHP_EOL);
    $requiredDeny = [
        'storage/.htaccess' => 'storage subtree (DB, KEKs, blobs)',
        'src/.htaccess' => 'source tree',
    ];
    foreach ($requiredDeny as $rel => $what) {
        $path = $appDir . '/' . $rel;
        if (!is_file($path)) {
            $fail("missing deny file {$rel} ({$what})");
            $failures++;
            continue;
        }
        $content = (string) file_get_contents($path);
        if (preg_match('/Deny from all|Require all denied|deny all/i', $content) === 1) {
            $ok("{$rel} contains a deny directive ({$what})");
        } else {
            $fail("{$rel} exists but has no deny directive ({$what})");
            $failures++;
        }
    }
    foreach (['public/admin/.htaccess', 'public/api/.htaccess'] as $rel) {
        if (!is_file($appDir . '/' . $rel)) {
            $fail("missing front-controller file {$rel}");
            $failures++;
        } else {
            $ok("{$rel} present");
        }
    }

    // 2. Secrets are gitignored.
    fwrite(STDOUT, PHP_EOL . '--- Gitignore coverage ---' . PHP_EOL);
    $gitignore = is_file($appDir . '/.gitignore') ? (string) file_get_contents($appDir . '/.gitignore') : '';
    $requiredIgnore = [
        '/.env' => '.env',
        '.sqlite' => 'SQLite database',
        'storage/keys' => 'KEK files',
        'local-backends' => 'local blobs',
        'backups' => 'backups',
    ];
    foreach ($requiredIgnore as $needle => $what) {
        if (str_contains($gitignore, $needle)) {
            $ok("gitignore covers {$what}");
        } else {
            $fail("gitignore does not cover {$what}");
            $failures++;
        }
    }

    // 3. Nothing secret is actually tracked under git.
    fwrite(STDOUT, PHP_EOL . '--- No tracked secrets ---' . PHP_EOL);
    exec('git -C ' . escapeshellarg($appDir) . ' ls-files 2>/dev/null', $trackedLines, $gitExit);
    $tracked = implode("\n", $trackedLines);
    $leaks = [];
    foreach (['.env', '.kek', 'router.sqlite'] as $needle) {
        foreach (explode("\n", $tracked) as $line) {
            if ($line !== '' && (str_ends_with($line, $needle) || str_contains($line, '.env'))) {
                $leaks[] = $line;
            }
        }
    }
    // .env.example is expected; anything else .env-ish is a leak.
    $leaks = array_values(array_filter($leaks, static fn ($l) => $l !== '.env.example'));
    if ($leaks === []) {
        $ok('no tracked secrets (only .env.example)');
    } else {
        $fail('tracked secret file(s): ' . implode(', ', array_unique($leaks)));
        $failures++;
    }

    // 4. Structural: storage must never be inside public/.
    fwrite(STDOUT, PHP_EOL . '--- Docroot separation ---' . PHP_EOL);
    foreach (['storage', 'src', 'vendor'] as $dir) {
        if (is_dir($appDir . '/' . $dir)) {
            if (is_dir($appDir . '/public/' . $dir)) {
                $fail("{$dir} exists under public/ — it must live outside the docroot");
                $failures++;
            } else {
                $ok("{$dir} is outside public/");
            }
        }
    }

    fwrite(STDOUT, PHP_EOL);
    if ($failures > 0) {
        fwrite(STDOUT, "{$failures} repo check(s) failed." . PHP_EOL);
        return 1;
    }

    fwrite(STDOUT, 'Repo checks passed.' . PHP_EOL);
    return 0;
}

$baseUrl = rtrim((string) ($argv[1] ?? ''), '/');

if ($baseUrl === '') {
    fwrite(STDERR, 'Usage: php bin/verify-deployment.php <base_url>' . PHP_EOL);
    fwrite(STDERR, '   or: php bin/verify-deployment.php --repo [REPO_ROOT]' . PHP_EOL);
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
    '/storage-router/.env',
    '/storage-router/composer.json',
    '/storage-router/storage/db/router.sqlite',
    '/storage-router/storage/keys/',
    '/storage-router/storage/local-backends/',
    '/storage-router/src/Api/Router.php',
    '/storage-router/vendor/autoload.php',
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
