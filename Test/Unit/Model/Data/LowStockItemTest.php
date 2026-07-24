<?php
declare(strict_types=1);

namespace Ivanchenko\LowStockReport\Test\Unit\Model\Data;

use Ivanchenko\LowStockReport\Model\Data\LowStockItem;
use PHPUnit\Framework\TestCase;

class LowStockItemTest extends TestCase
{
    public function testGettersReturnConstructorValues(): void
    {
        $item = new LowStockItem('24-MB04', 'Strive Shoulder Pack', 7.0);

        $this->assertSame('24-MB04', $item->getSku());
        $this->assertSame('Strive Shoulder Pack', $item->getName());
        $this->assertSame(7.0, $item->getQty());
    }
}
