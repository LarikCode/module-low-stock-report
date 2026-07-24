<?php
declare(strict_types=1);

namespace Ivanchenko\LowStockReport\Model\Data;

use Ivanchenko\LowStockReport\Api\Data\LowStockItemInterface;
use Ivanchenko\LowStockReport\Api\Data\LowStockReportInterface;

class LowStockReport implements LowStockReportInterface
{
    /**
     * @param LowStockItemInterface[] $items
     */
    public function __construct(
        private readonly int $threshold,
        private readonly int $totalScanned,
        private readonly array $items
    ) {
    }

    public function getThreshold(): int
    {
        return $this->threshold;
    }

    public function getTotalScanned(): int
    {
        return $this->totalScanned;
    }

    public function getItems(): array
    {
        return $this->items;
    }
}
