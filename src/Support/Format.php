<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Small formatting helpers for byte sizes and ratios, shared by admin views.
 */
final class Format
{
    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

    /** Human-readable labels for audit_log action codes. */
    private const ACTION_LABELS = [
        'admin.login' => 'Admin login',
        'admin.login_failed' => 'Admin login failed',
        'admin.logout' => 'Admin logout',
        'app.create' => 'App created',
        'app.key_rotated' => 'App API key rotated',
        'app.status_change' => 'App status changed',
        'app.assignments_updated' => 'Assignments updated',
        'storage.create' => 'Storage backend created',
        'storage.delete' => 'Storage backend removed',
        'storage.status_change' => 'Backend status changed',
        'storage.quota_refresh' => 'Quota refresh failed',
        'file.migrate' => 'File migration failed',
        'upload.rejected' => 'Upload rejected',
        'rate_limit.exceeded' => 'Rate limit exceeded',
    ];

    /** Formats a byte count using the closest binary unit, e.g. 211 KB, 10 GB. */
    public static function humanBytes(int $bytes): string
    {
        if ($bytes < 0) {
            return '0 B';
        }

        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $value = (float) $bytes;
        $i = 0;

        while ($value >= 1024.0 && $i < count(self::UNITS) - 1) {
            $value /= 1024.0;
            $i++;
        }

        $decimals = 2;
        if ($value >= 100) {
            $decimals = 0;
        } elseif ($value >= 10) {
            $decimals = 1;
        }

        $formatted = number_format($value, $decimals, '.', '');
        if ($decimals > 0) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted . ' ' . self::UNITS[$i];
    }

    /** Percentage of $used out of $total, clamped to 0-100. 0 when total <= 0. */
    public static function percent(int $used, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        $pct = (int) round((float) $used * 100 / (float) $total);

        return max(0, min(100, $pct));
    }

    /** Human-readable label for an audit_log action code, e.g. "upload.rejected" → "Upload rejected". */
    public static function actionLabel(string $action): string
    {
        return self::ACTION_LABELS[$action] ?? ucwords(str_replace('_', ' ', $action), ' ');
    }

    /** Human-readable label for an audit_log actor, e.g. ("app", "My App") → "App · My App". */
    public static function actorLabel(string $actorType, string $actorName): string
    {
        $type = match ($actorType) {
            'app' => 'App',
            'admin' => 'Admin',
            default => ucfirst($actorType),
        };

        return $type . ' · ' . $actorName;
    }

    /** Human-readable label for an app or backend status, e.g. "active" → "Active". */
    public static function statusLabel(string $status): string
    {
        return ucfirst($status);
    }
}