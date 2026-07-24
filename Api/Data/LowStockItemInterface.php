<?php
declare(strict_types=1);

namespace Ivanchenko\LowStockReport\Api\Data;

/**
 * One stock-carrying SKU whose computed salable quantity is below the
 * requested threshold. Read-only by design (no setters): this is a report
 * row produced entirely inside Model\LowStockReportManagement, never a
 * hydration target for webapi input, so there's no reason to expose
 * mutators the way an ExtensibleDataInterface entity would.
 *
 * @api
 */
interface LowStockItemInterface
{
    /**
     * @return string
     */
    public function getSku(): string;

    /**
     * @return string
     */
    public function getName(): string;

    /**
     * Salable quantity, matching Magento\InventorySales\Model
     * \GetProductSalableQty's own formula exactly:
     * is_salable ? (quantity + reservations - min_qty) : 0.
     *
     * @return float
     */
    public function getQty(): float;
}
