<?php
declare(strict_types=1);

namespace Ivanchenko\LowStockReport\Api\Data;

/**
 * @api
 */
interface LowStockReportInterface
{
    /**
     * @return int
     */
    public function getThreshold(): int;

    /**
     * Count of stock-carrying SKUs (simple/virtual/downloadable) actually
     * evaluated for the resolved store/stock -- i.e. the full stock-carrying
     * catalog, not just however many low-stock rows made the cut below.
     * Configurable/grouped/bundle parents are intentionally excluded here,
     * same as the JS action this replaces: their own row never carries
     * stock, only their children's do.
     *
     * @return int
     */
    public function getTotalScanned(): int;

    /**
     * @return \Ivanchenko\LowStockReport\Api\Data\LowStockItemInterface[]
     */
    public function getItems(): array;
}
