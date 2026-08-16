<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Orders an app's candidate backends by selection preference:
 * least-used-space by default, with priority as a tie-breaker/manual
 * override mechanism rather than the primary sort key.
 *
 * Operates on the joined rows from AppStorageAccessRepository::listForApp()
 * (which already carries quota_used_bytes/quota_total_bytes/priority via
 * its JOIN against storage_backends) — no extra DB queries needed here.
 */
final class BackendSelector
{
    /**
     * @param array<int, array<string, mixed>> $candidates rows from AppStorageAccessRepository::listForApp()
     * @return array<int, array<string, mixed>> the same rows, reordered by preference
     */
    public function order(array $candidates): array
    {
        $ordered = $candidates;

        usort($ordered, function (array $a, array $b): int {
            $ratioCompare = $this->usageRatio($a) <=> $this->usageRatio($b);
            if ($ratioCompare !== 0) {
                return $ratioCompare;
            }

            return ((int) $a['priority']) <=> ((int) $b['priority']);
        });

        return $ordered;
    }

    /**
     * A backend with an unknown or zero quota_total_bytes — an uncapped
     * local backend (capacity_cap_bytes=0 means "no cap set"), or a Drive
     * backend whose quota hasn't been refreshed yet since being connected
     * — is treated as 0% used. This is the safest default absent better
     * information, though it does mean an un-refreshed Drive backend may
     * be favored by selection until an admin triggers a quota refresh.
     *
     * @param array<string, mixed> $candidate
     */
    private function usageRatio(array $candidate): float
    {
        $total = (int) ($candidate['quota_total_bytes'] ?? 0);

        if ($total <= 0) {
            return 0.0;
        }

        $used = (int) ($candidate['quota_used_bytes'] ?? 0);

        return $used / $total;
    }
}
