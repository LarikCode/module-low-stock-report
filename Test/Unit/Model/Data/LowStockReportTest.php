<?php
declare(strict_types=1);

namespace Ivanchenko\LowStockReport\Test\Unit\Model\Data;

use Ivanchenko\LowStockReport\Model\Data\LowStockItem;
use Ivanchenko\LowStockReport\Model\Data\LowStockReport;
use PHPUnit\Framework\TestCase;

class LowStockReportTest extends TestCase
{
    public function testGettersReturnConstructorValues(): void
    {
        $items = [new LowStockItem('24-MB04', 'Strive Shoulder Pack', 7.0)];
        $report = new LowStockReport(10, 1891, $items);

        $this->assertSame(10, $report->getThreshold());
        $this->assertSame(1891, $report->getTotalScanned());
        $this->assertSame($items, $report->getItems());
    }
}
