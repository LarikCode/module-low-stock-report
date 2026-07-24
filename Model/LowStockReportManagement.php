<?php
declare(strict_types=1);

namespace Ivanchenko\LowStockReport\Model;

use Ivanchenko\LowStockReport\Api\Data\LowStockItemInterfaceFactory;
use Ivanchenko\LowStockReport\Api\Data\LowStockReportInterface;
use Ivanchenko\LowStockReport\Api\Data\LowStockReportInterfaceFactory;
use Ivanchenko\LowStockReport\Api\LowStockReportManagementInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Api\StockItemCriteriaInterfaceFactory;
use Magento\CatalogInventory\Api\StockItemRepositoryInterface;
use Magento\InventoryCatalogApi\Model\GetProductIdsBySkusInterface;
use Magento\InventoryCatalogApi\Model\GetProductTypesBySkusInterface;
use Magento\InventoryReservationsApi\Model\GetReservationsQuantityBySkuListInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\InventorySalesApi\Model\GetStockItemsDataInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Computes the low-stock report against the FULL catalog using a fixed,
 * small number of bulk single-query MSI providers, replacing an earlier
 * approach (an App Builder action looping the single-SKU
 * get-product-salable-quantity REST endpoint) that only ever covered one
 * page of ~150 products and, when tested looped across the full
 * ~1,891-SKU stock-carrying population, stalled the target instance's
 * PHP-FPM pool after roughly 400-530 requests even at concurrency 10.
 *
 * Every one of GetStockItemsDataInterface, GetReservationsQuantityBySkuList
 * Interface, GetProductIdsBySkusInterface and GetProductTypesBySkusInterface
 * accepts a SKU array and issues exactly ONE query for the whole array --
 * verified by reading each implementation directly, not assumed from the
 * interface signature alone. GetProductSalableQtyInterface and
 * AreProductsSalableInterface were deliberately NOT used here even though
 * both exist: the former is single-SKU only, and the latter loops
 * per-SKU internally despite accepting an array -- neither is a real bulk
 * primitive, just a different call shape around the same N-query cost
 * this module exists to avoid.
 */
class LowStockReportManagement implements LowStockReportManagementInterface
{
    /**
     * Only these product types carry their own stock record under MSI;
     * configurable/grouped/bundle are containers whose stock lives on
     * their child SKUs. Mirrors actions/dashboard/index.js's own
     * STOCK_CARRYING_TYPES constant so both sides of the (now retired)
     * REST-loop bug agree on what "stock-carrying" means.
     */
    private const STOCK_CARRYING_TYPES = ['simple', 'virtual', 'downloadable'];

    /**
     * Keeps every IN() clause and GetStockItemsDataInterface batch well
     * under typical max_allowed_packet / IN()-list limits. At ~1,891
     * stock-carrying SKUs this is 4 chunks; the chunk count scales with
     * catalog size but the total *number* of bulk queries per chunk (4)
     * never does.
     */
    private const SKU_CHUNK_SIZE = 500;

    /**
     * Safety cap on the response payload, independent of how many SKUs
     * are actually below threshold. getTotalScanned() always reflects the
     * true full stock-carrying-catalog count regardless of this cap.
     */
    private const MAX_ITEMS = 50;

    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly GetStockItemsDataInterface $getStockItemsData,
        private readonly GetReservationsQuantityBySkuListInterface $getReservationsQuantityBySkuList,
        private readonly GetProductTypesBySkusInterface $getProductTypesBySkus,
        private readonly GetProductIdsBySkusInterface $getProductIdsBySkus,
        private readonly StockItemCriteriaInterfaceFactory $stockItemCriteriaFactory,
        private readonly StockItemRepositoryInterface $stockItemRepository,
        private readonly StockResolverInterface $stockResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly LowStockReportInterfaceFactory $reportFactory,
        private readonly LowStockItemInterfaceFactory $itemFactory
    ) {
    }

    public function getReport(int $threshold = 10, ?int $storeId = null): LowStockReportInterface
    {
        $store = $storeId !== null
            ? $this->storeRepository->getById($storeId)
            : $this->storeManager->getStore();

        // StoreInterface only exposes getWebsiteId(), not a hydrated
        // Website object -- resolve it separately rather than assuming a
        // getWebsite() method that only the concrete Store model (not the
        // interface) happens to have.
        $websiteCode = $this->storeManager->getWebsite($store->getWebsiteId())->getCode();
        $stockId = (int)$this->stockResolver->execute('website', $websiteCode)->getStockId();

        // Single pass over the catalog: one product-collection query (plus
        // its EAV attribute joins for name/type_id -- a small fixed number
        // of joins, not one query per product), scoped to the resolved
        // store so `name` reflects that store view rather than default/
        // admin scope. This replaces the JS action's single 150-item REST
        // page with the actual full catalog.
        $candidates = $this->getStockCarryingCandidates((int)$store->getId()); // [sku => name]
        $skus = array_keys($candidates);
        $totalScanned = count($skus);

        $qtyBySku = [];
        foreach (array_chunk($skus, self::SKU_CHUNK_SIZE) as $chunk) {
            $qtyBySku += $this->computeQtyForChunk($chunk, $stockId);
        }

        $items = [];
        foreach ($qtyBySku as $sku => $qty) {
            if ($qty < $threshold) {
                $items[] = $this->itemFactory->create([
                    'sku' => $sku,
                    'name' => $candidates[$sku] ?? $sku,
                    'qty' => $qty,
                ]);
            }
        }

        usort($items, fn ($a, $b) => $a->getQty() <=> $b->getQty());
        $items = array_slice($items, 0, self::MAX_ITEMS);

        return $this->reportFactory->create([
            'threshold' => $threshold,
            'totalScanned' => $totalScanned,
            'items' => $items,
        ]);
    }

    /**
     * @return array<string,string> sku => name
     */
    private function getStockCarryingCandidates(int $storeId): array
    {
        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addAttributeToSelect(['name', 'type_id']);
        // No pageSize / currentPage set here on purpose -- this is the one
        // deliberate difference from the old JS action's
        // searchCriteria[pageSize]=150/currentPage=1, which is the actual
        // root cause of the original bug (142 of ~2,040 products scanned).

        $candidates = [];
        foreach ($collection as $product) {
            if (in_array($product->getTypeId(), self::STOCK_CARRYING_TYPES, true)) {
                $candidates[$product->getSku()] = $product->getName();
            }
        }
        return $candidates;
    }

    /**
     * @param string[] $skus
     * @return array<string,float> sku => computed salable qty
     */
    private function computeQtyForChunk(array $skus, int $stockId): array
    {
        // Bulk re-validation against MSI's own product-type source, as a
        // defense-in-depth cross-check against the product collection's
        // type_id column above -- this is the interface MSI's own
        // GetProductSalableQty uses for exactly this purpose, so this
        // chunk-level check uses it too rather than trusting one source.
        $typesBySku = $this->getProductTypesBySkus->execute($skus);
        $skus = array_values(array_filter(
            $skus,
            fn ($sku) => in_array($typesBySku[$sku] ?? null, self::STOCK_CARRYING_TYPES, true)
        ));
        if ($skus === []) {
            return [];
        }

        // Query 1/chunk: quantity + is_salable for every SKU in one call.
        $stockItemsData = $this->getStockItemsData->execute($skus, $stockId) ?? [];

        // Query 2/chunk: reservation deltas, one SUM()...GROUP BY sku query.
        $reservationsBySku = $this->getReservationsQuantityBySkuList->execute($skus, $stockId);

        // Query 3/chunk: sku -> product_id, one query.
        $productIdsBySku = $this->getProductIdsBySkus->execute($skus);

        // Query 4/chunk: min_qty for every product_id in one IN() query,
        // via the legacy (but still-synced-for-default-stock) stock item
        // table -- there is no MSI-native bulk min_qty provider, so this
        // is the one deliberate use of a @deprecated interface in this
        // module, scoped narrowly to a single bulk read.
        $minQtyByProductId = $this->getMinQtyByProductId(array_values($productIdsBySku));

        $result = [];
        foreach ($skus as $sku) {
            $data = $stockItemsData[$sku] ?? null;
            if ($data === null || !(bool)$data[GetStockItemsDataInterface::IS_SALABLE]) {
                $result[$sku] = 0.0;
                continue;
            }

            $productId = $productIdsBySku[$sku] ?? null;
            $minQty = $productId !== null ? ($minQtyByProductId[$productId] ?? 0.0) : 0.0;
            $reservationsQty = $reservationsBySku[$sku] ?? 0.0;

            // Exact replication of Magento\InventorySales\Model
            // \GetProductSalableQty::execute()'s formula.
            $result[$sku] = (float)$data[GetStockItemsDataInterface::QUANTITY] + $reservationsQty - $minQty;
        }
        return $result;
    }

    /**
     * @param int[] $productIds
     * @return array<int,float> product_id => min_qty
     */
    private function getMinQtyByProductId(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $criteria = $this->stockItemCriteriaFactory->create();
        $criteria->setProductsFilter($productIds);
        $stockItemCollection = $this->stockItemRepository->getList($criteria);

        $minQtyByProductId = [];
        foreach ($stockItemCollection->getItems() as $stockItem) {
            $minQtyByProductId[$stockItem->getProductId()] = (float)$stockItem->getMinQty();
        }
        return $minQtyByProductId;
    }
}
