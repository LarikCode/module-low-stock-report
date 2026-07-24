<?php
declare(strict_types=1);

namespace Ivanchenko\LowStockReport\Api;

use Ivanchenko\LowStockReport\Api\Data\LowStockReportInterface;

/**
 * @api
 */
interface LowStockReportManagementInterface
{
    /**
     * Returns stock-carrying SKUs whose real-time salable quantity is
     * below $threshold, across the FULL catalog -- not a single REST
     * page -- computed via a fixed, small number of bulk SQL queries
     * rather than one REST/DB round trip per SKU.
     *
     * $storeId scopes the report to a specific store's website/stock
     * (needed on any multi-website/multi-stock installation -- a single
     * store's default stock is not necessarily every website's stock).
     * When omitted, Magento's own default store is used. One call
     * reports against one store/stock; a merchant with multiple
     * websites mapped to different Stocks needs one call per storeId.
     *
     * @param int $threshold defaults to 10, matching the dashboard action's
     *        own DEFAULT_LOW_STOCK_THRESHOLD.
     * @param int|null $storeId defaults to the default store when null.
     * @return \Ivanchenko\LowStockReport\Api\Data\LowStockReportInterface
     */
    public function getReport(int $threshold = 10, ?int $storeId = null): LowStockReportInterface;
}
