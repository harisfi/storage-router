<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Pagination;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    public function testOffsetAndRanges(): void
    {
        $p = new Pagination(57, 3, 25); // pages: 1(1-25) 2(26-50) 3(51-57)
        $this->assertSame(3, $p->current());
        $this->assertSame(3, $p->totalPages());
        $this->assertSame(25, $p->perPage());
        $this->assertSame(50, $p->offset());
        $this->assertTrue($p->hasPrev());
        $this->assertFalse($p->hasNext());
        $this->assertSame(2, $p->prev());
        $this->assertNull($p->next());
        $this->assertSame(51, $p->from());
        $this->assertSame(57, $p->to());
    }

    public function testClampsPageAboveRangeToLastPage(): void
    {
        $p = new Pagination(10, 99, 25);
        $this->assertSame(1, $p->current());
        $this->assertSame(1, $p->totalPages());
    }

    public function testClampsPageBelowOne(): void
    {
        $p = new Pagination(100, 0, 25);
        $this->assertSame(1, $p->current());
    }

    public function testEmptyTotalIsSingleEmptyPage(): void
    {
        $p = new Pagination(0, 1, 25);
        $this->assertSame(1, $p->totalPages());
        $this->assertSame(0, $p->from());
        $this->assertSame(0, $p->to());
        $this->assertFalse($p->hasNext());
        $this->assertFalse($p->hasPrev());
    }

    public function testNormalizePerPageAllowsOnlyKnownSizes(): void
    {
        foreach (Pagination::PER_PAGE_OPTIONS as $opt) {
            $this->assertSame($opt, Pagination::normalizePerPage($opt));
        }
    }

    public function testNormalizePerPageFallsBackToDefault(): void
    {
        $this->assertSame(25, Pagination::normalizePerPage(0));
        $this->assertSame(25, Pagination::normalizePerPage(7));
        $this->assertSame(25, Pagination::normalizePerPage(300));
        $this->assertSame(25, Pagination::normalizePerPage(-1));
    }

    public function testPageNumbersFlatUnderSevenPages(): void
    {
        $p = new Pagination(250, 3, 50); // 5 pages
        $this->assertSame([1, 2, 3, 4, 5], $p->pageNumbers());
    }

    public function testPageNumbersWithEllipsis(): void
    {
        $p = new Pagination(1000, 5, 25); // 40 pages, current 5
        $this->assertSame([1, '…', 4, 5, 6, '…', 40], $p->pageNumbers());
    }

    public function testPageNumbersAtTheEnd(): void
    {
        $p = new Pagination(1000, 40, 25); // 40 pages, current 40
        $this->assertSame([1, '…', 39, 40], $p->pageNumbers());
    }
}