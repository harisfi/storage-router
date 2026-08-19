<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Immutable pagination state for admin list views.
 *
 * Derives offset/total-pages/prev/next/page-numbers from a total, the
 * requested page, and a per-page size. The per-page size is normalized to
 * the allowed set, so an out-of-range value never reaches the repository.
 */
final class Pagination
{
    public const PER_PAGE_OPTIONS = [5, 10, 25, 50, 100];
    public const DEFAULT_PER_PAGE = 25;

    private int $total;
    private int $perPage;
    private int $page;
    private int $totalPages;

    public function __construct(int $total, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE)
    {
        $this->total = max(0, $total);
        $this->perPage = self::normalizePerPage($perPage);

        $this->totalPages = $this->perPage > 0 ? (int) ceil($this->total / $this->perPage) : 1;
        $this->totalPages = max(1, $this->totalPages);

        $this->page = max(1, min($page, $this->totalPages));
    }

    /** Rounds any requested size to the nearest allowed value; falls back to the default. */
    public static function normalizePerPage(int $perPage): int
    {
        return in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::DEFAULT_PER_PAGE;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function current(): int
    {
        return $this->page;
    }

    public function totalPages(): int
    {
        return $this->totalPages;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function hasPrev(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->totalPages;
    }

    public function prev(): ?int
    {
        return $this->hasPrev() ? $this->page - 1 : null;
    }

    public function next(): ?int
    {
        return $this->hasNext() ? $this->page + 1 : null;
    }

    public function from(): int
    {
        return $this->total === 0 ? 0 : $this->offset() + 1;
    }

    public function to(): int
    {
        return min($this->total, $this->page * $this->perPage);
    }

    /**
     * Page numbers to render, using the string '…' as an ellipsis gap
     * marker for ranges wider than 7 pages, e.g. [1, 2, 3, '…', 9, 10].
     *
     * @return array<int, int|string>
     */
    public function pageNumbers(): array
    {
        $total = $this->totalPages;
        $current = $this->page;

        if ($total <= 7) {
            return range(1, $total);
        }

        $pages = [1];
        $start = max(2, $current - 1);
        $end = min($total - 1, $current + 1);

        if ($start > 2) {
            $pages[] = '…';
        }
        for ($i = $start; $i <= $end; $i++) {
            $pages[] = $i;
        }
        if ($end < $total - 1) {
            $pages[] = '…';
        }
        $pages[] = $total;

        return $pages;
    }
}