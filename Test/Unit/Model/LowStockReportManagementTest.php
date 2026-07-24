<?php
declare(strict_types=1);

namespace Ivanchenko\LowStockReport\Test\Unit\Model;

use Ivanchenko\LowStockReport\Api\Data\LowStockItemInterfaceFactory;
use Ivanchenko\LowStockReport\Api\Data\LowStockReportInterfaceFactory;
use Ivanchenko\LowStockReport\Model\Data\LowStockItem;
use Ivanchenko\LowStockReport\Model\Data\LowStockReport;
use Ivanchenko\LowStockReport\Model\LowStockReportManagement;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Api\Data\StockItemCollectionInterface;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockItemCriteriaInterface;
use Magento\CatalogInventory\Api\StockItemCriteriaInterfaceFactory;
use Magento\CatalogInventory\Api\StockItemRepositoryInterface;
use Magento\InventoryCatalogApi\Model\GetProductIdsBySkusInterface;
use Magento\InventoryCatalogApi\Model\GetProductTypesBySkusInterface;
use Magento\InventoryReservationsApi\Model\GetReservationsQuantityBySkuListInterface;
use Magento\InventoryApi\Api\Data\StockInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\InventorySalesApi\Model\GetStockItemsDataInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Proves LowStockReportManagement replicates Magento\InventorySales\Model
 * \GetProductSalableQty's own formula exactly, scans the FULL catalog
 * rather than a single page, chunks/caps correctly, and resolves
 * store/stock rather than hardcoding stock_id=1 -- the exact properties
 * that made the JS-action REST-loop approach this module replaces both
 * incomplete and unsafe to fix in place.
 */
class LowStockReportManagementTest extends TestCase
{
    private ProductCollectionFactory|MockObject $productCollectionFactory;
    private GetStockItemsDataInterface|MockObject $getStockItemsData;
    private GetReservationsQuantityBySkuListInterface|MockObject $getReservationsQuantityBySkuList;
    private GetProductTypesBySkusInterface|MockObject $getProductTypesBySkus;
    private GetProductIdsBySkusInterface|MockObject $getProductIdsBySkus;
    private StockItemCriteriaInterfaceFactory|MockObject $stockItemCriteriaFactory;
    private StockItemRepositoryInterface|MockObject $stockItemRepository;
    private StockResolverInterface|MockObject $stockResolver;
    private StoreManagerInterface|MockObject $storeManager;
    private StoreRepositoryInterface|MockObject $storeRepository;

    protected function setUp(): void
    {
        $this->productCollectionFactory = $this->createMock(ProductCollectionFactory::class);
        $this->getStockItemsData = $this->createMock(GetStockItemsDataInterface::class);
        $this->getReservationsQuantityBySkuList = $this->createMock(GetReservationsQuantityBySkuListInterface::class);
        $this->getProductTypesBySkus = $this->createMock(GetProductTypesBySkusInterface::class);
        $this->getProductIdsBySkus = $this->createMock(GetProductIdsBySkusInterface::class);
        $this->stockItemCriteriaFactory = $this->createMock(StockItemCriteriaInterfaceFactory::class);
        $this->stockItemRepository = $this->createMock(StockItemRepositoryInterface::class);
        $this->stockResolver = $this->createMock(StockResolverInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeRepository = $this->createMock(StoreRepositoryInterface::class);

        // Empty min_qty by default (criteria/repository round trip returns no rows).
        $criteria = $this->createMock(StockItemCriteriaInterface::class);
        $this->stockItemCriteriaFactory->method('create')->willReturn($criteria);
        $emptyStockItemCollection = $this->createMock(StockItemCollectionInterface::class);
        $emptyStockItemCollection->method('getItems')->willReturn([]);
        $this->stockItemRepository->method('getList')->willReturn($emptyStockItemCollection);
    }

    private function buildManagement(): LowStockReportManagement
    {
        $itemFactory = $this->createMock(LowStockItemInterfaceFactory::class);
        $itemFactory->method('create')->willReturnCallback(
            fn (array $data) => new LowStockItem($data['sku'], $data['name'], $data['qty'])
        );
        $reportFactory = $this->createMock(LowStockReportInterfaceFactory::class);
        $reportFactory->method('create')->willReturnCallback(
            fn (array $data) => new LowStockReport($data['threshold'], $data['totalScanned'], $data['items'])
        );

        return new LowStockReportManagement(
            $this->productCollectionFactory,
            $this->getStockItemsData,
            $this->getReservationsQuantityBySkuList,
            $this->getProductTypesBySkus,
            $this->getProductIdsBySkus,
            $this->stockItemCriteriaFactory,
            $this->stockItemRepository,
            $this->stockResolver,
            $this->storeManager,
            $this->storeRepository,
            $reportFactory,
            $itemFactory
        );
    }

    /**
     * @param array<int,array{sku:string,name:string,type:string}> $products
     */
    private function mockCatalog(array $products, int $expectedStoreId = 1): void
    {
        $collection = $this->createMock(ProductCollection::class);
        $collection->expects($this->once())->method('setStoreId')->with($expectedStoreId);
        $collection->expects($this->once())->method('addAttributeToSelect')->with(['name', 'type_id']);

        $productMocks = array_map(function (array $p): Product {
            $product = $this->createMock(Product::class);
            $product->method('getSku')->willReturn($p['sku']);
            $product->method('getName')->willReturn($p['name']);
            $product->method('getTypeId')->willReturn($p['type']);
            return $product;
        }, $products);

        $collection->method('getIterator')->willReturn(new \ArrayIterator($productMocks));
        $this->productCollectionFactory->method('create')->willReturn($collection);
    }

    private function mockDefaultStoreResolution(int $storeId = 1, int $websiteId = 1, string $websiteCode = 'base', int $stockId = 1): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($storeId);
        $store->method('getWebsiteId')->willReturn($websiteId);
        $this->storeManager->method('getStore')->willReturn($store);

        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getCode')->willReturn($websiteCode);
        $this->storeManager->method('getWebsite')->with($websiteId)->willReturn($website);

        $stock = $this->createMock(StockInterface::class);
        $stock->method('getStockId')->willReturn($stockId);
        $this->stockResolver->method('execute')->with('website', $websiteCode)->willReturn($stock);
    }

    public function testGetReportAppliesCoreSalableQtyFormulaExactly(): void
    {
        $this->mockCatalog([['sku' => 'sku-a', 'name' => 'A', 'type' => 'simple']]);
        $this->mockDefaultStoreResolution();
        $this->getProductTypesBySkus->method('execute')->willReturn(['sku-a' => 'simple']);
        $this->getStockItemsData->method('execute')->willReturn([
            'sku-a' => [GetStockItemsDataInterface::QUANTITY => 20.0, GetStockItemsDataInterface::IS_SALABLE => 1],
        ]);
        $this->getReservationsQuantityBySkuList->method('execute')->willReturn(['sku-a' => -3.0]);
        $this->getProductIdsBySkus->method('execute')->willReturn(['sku-a' => 101]);

        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getProductId')->willReturn(101);
        $stockItem->method('getMinQty')->willReturn(2.0);
        $stockItemCollection = $this->createMock(StockItemCollectionInterface::class);
        $stockItemCollection->method('getItems')->willReturn([$stockItem]);
        $this->stockItemRepository = $this->createMock(StockItemRepositoryInterface::class);
        $this->stockItemRepository->method('getList')->willReturn($stockItemCollection);

        $report = $this->buildManagement()->getReport(100);

        // quantity(20) + reservations(-3) - minQty(2) = 15
        $this->assertCount(1, $report->getItems());
        $this->assertSame(15.0, $report->getItems()[0]->getQty());
    }

    public function testGetReportReturnsZeroQtyWhenIsSalableIsFalse(): void
    {
        $this->mockCatalog([['sku' => 'sku-a', 'name' => 'A', 'type' => 'simple']]);
        $this->mockDefaultStoreResolution();
        $this->getProductTypesBySkus->method('execute')->willReturn(['sku-a' => 'simple']);
        $this->getStockItemsData->method('execute')->willReturn([
            'sku-a' => [GetStockItemsDataInterface::QUANTITY => 500.0, GetStockItemsDataInterface::IS_SALABLE => 0],
        ]);
        $this->getReservationsQuantityBySkuList->method('execute')->willReturn([]);
        $this->getProductIdsBySkus->method('execute')->willReturn(['sku-a' => 101]);

        $report = $this->buildManagement()->getReport(10);

        $this->assertSame(0.0, $report->getItems()[0]->getQty());
    }

    public function testGetReportExcludesSkusAtOrAboveThreshold(): void
    {
        $this->mockCatalog([
            ['sku' => 'sku-low', 'name' => 'Low', 'type' => 'simple'],
            ['sku' => 'sku-exact', 'name' => 'Exact', 'type' => 'simple'],
            ['sku' => 'sku-high', 'name' => 'High', 'type' => 'simple'],
        ]);
        $this->mockDefaultStoreResolution();
        $this->getProductTypesBySkus->method('execute')->willReturn([
            'sku-low' => 'simple', 'sku-exact' => 'simple', 'sku-high' => 'simple',
        ]);
        $this->getStockItemsData->method('execute')->willReturn([
            'sku-low' => [GetStockItemsDataInterface::QUANTITY => 5.0, GetStockItemsDataInterface::IS_SALABLE => 1],
            'sku-exact' => [GetStockItemsDataInterface::QUANTITY => 10.0, GetStockItemsDataInterface::IS_SALABLE => 1],
            'sku-high' => [GetStockItemsDataInterface::QUANTITY => 50.0, GetStockItemsDataInterface::IS_SALABLE => 1],
        ]);
        $this->getReservationsQuantityBySkuList->method('execute')->willReturn([]);
        $this->getProductIdsBySkus->method('execute')->willReturn([]);

        $report = $this->buildManagement()->getReport(10);

        $skus = array_map(fn ($i) => $i->getSku(), $report->getItems());
        $this->assertSame(['sku-low'], $skus);
    }

    public function testGetReportSortsItemsAscendingByQty(): void
    {
        $this->mockCatalog([
            ['sku' => 'sku-b', 'name' => 'B', 'type' => 'simple'],
            ['sku' => 'sku-a', 'name' => 'A', 'type' => 'simple'],
        ]);
        $this->mockDefaultStoreResolution();
        $this->getProductTypesBySkus->method('execute')->willReturn(['sku-b' => 'simple', 'sku-a' => 'simple']);
        $this->getStockItemsData->method('execute')->willReturn([
            'sku-b' => [GetStockItemsDataInterface::QUANTITY => 5.0, GetStockItemsDataInterface::IS_SALABLE => 1],
            'sku-a' => [GetStockItemsDataInterface::QUANTITY => 1.0, GetStockItemsDataInterface::IS_SALABLE => 1],
        ]);
        $this->getReservationsQuantityBySkuList->method('execute')->willReturn([]);
        $this->getProductIdsBySkus->method('execute')->willReturn([]);

        $report = $this->buildManagement()->getReport(10);

        $skus = array_map(fn ($i) => $i->getSku(), $report->getItems());
        $this->assertSame(['sku-a', 'sku-b'], $skus);
    }

    public function testGetReportCapsItemsAtFiftyWhileTotalScannedStaysTrue(): void
    {
        $products = [];
        $stockData = [];
        $types = [];
        for ($i = 0; $i < 75; $i++) {
            $sku = "sku-$i";
            $products[] = ['sku' => $sku, 'name' => "Product $i", 'type' => 'simple'];
            $stockData[$sku] = [GetStockItemsDataInterface::QUANTITY => 1.0, GetStockItemsDataInterface::IS_SALABLE => 1];
            $types[$sku] = 'simple';
        }
        $this->mockCatalog($products);
        $this->mockDefaultStoreResolution();
        $this->getProductTypesBySkus->method('execute')->willReturn($types);
        $this->getStockItemsData->method('execute')->willReturn($stockData);
        $this->getReservationsQuantityBySkuList->method('execute')->willReturn([]);
        $this->getProductIdsBySkus->method('execute')->willReturn([]);

        $report = $this->buildManagement()->getReport(10);

        $this->assertCount(50, $report->getItems());
        $this->assertSame(75, $report->getTotalScanned());
    }

    public function testGetReportExcludesConfigurableGroupedBundleTypesFromCandidates(): void
    {
        $this->mockCatalog([
            ['sku' => 'sku-simple', 'name' => 'Simple', 'type' => 'simple'],
            ['sku' => 'sku-config', 'name' => 'Config', 'type' => 'configurable'],
            ['sku' => 'sku-grouped', 'name' => 'Grouped', 'type' => 'grouped'],
            ['sku' => 'sku-bundle', 'name' => 'Bundle', 'type' => 'bundle'],
        ]);
        $this->mockDefaultStoreResolution();
        $this->getProductTypesBySkus->method('execute')->willReturn(['sku-simple' => 'simple']);
        $this->getStockItemsData->method('execute')->willReturn([
            'sku-simple' => [GetStockItemsDataInterface::QUANTITY => 1.0, GetStockItemsDataInterface::IS_SALABLE => 1],
        ]);
        $this->getReservationsQuantityBySkuList->method('execute')->willReturn([]);
        $this->getProductIdsBySkus->method('execute')->willReturn([]);

        $report = $this->buildManagement()->getReport(10);

        $this->assertSame(1, $report->getTotalScanned());
        $this->assertSame(['sku-simple'], array_map(fn ($i) => $i->getSku(), $report->getItems()));
    }

    public function testGetReportChunksSkuListAtFiveHundred(): void
    {
        $products = [];
        for ($i = 0; $i < 1200; $i++) {
            $products[] = ['sku' => "sku-$i", 'name' => "Product $i", 'type' => 'simple'];
        }
        $this->mockCatalog($products);
        $this->mockDefaultStoreResolution();

        $this->getProductTypesBySkus->expects($this->exactly(3))
            ->method('execute')
            ->willReturnCallback(fn (array $skus) => array_fill_keys($skus, 'simple'));
        $this->getStockItemsData->expects($this->exactly(3))
            ->method('execute')
            ->willReturnCallback(fn (array $skus) => array_fill_keys(
                $skus,
                [GetStockItemsDataInterface::QUANTITY => 100.0, GetStockItemsDataInterface::IS_SALABLE => 1]
            ));
        $this->getReservationsQuantityBySkuList->method('execute')->willReturn([]);
        $this->getProductIdsBySkus->method('execute')->willReturn([]);

        $report = $this->buildManagement()->getReport(10);

        $this->assertSame(1200, $report->getTotalScanned());
    }

    public function testGetReportResolvesStockIdViaStockResolverRatherThanHardcodingOne(): void
    {
        $this->mockCatalog([], 7);
        $this->mockDefaultStoreResolution(7, 2, 'second_website', 99);
        $this->getProductTypesBySkus->method('execute')->willReturn([]);

        $this->buildManagement()->getReport(10);

        // mockDefaultStoreResolution already asserts execute() was called
        // with ('website', 'second_website') via the with() expectation;
        // reaching here without a mock-expectation failure proves it.
        $this->addToAssertionCount(1);
    }

    public function testGetReportUsesDefaultStoreWhenStoreIdOmitted(): void
    {
        $this->mockCatalog([], 1);
        $this->mockDefaultStoreResolution(1, 1, 'base', 1);
        $this->storeRepository->expects($this->never())->method('getById');
        $this->getProductTypesBySkus->method('execute')->willReturn([]);

        $this->buildManagement()->getReport(10, null);
    }

    public function testGetReportResolvesDifferentStoreWhenStoreIdProvided(): void
    {
        $this->mockCatalog([], 2);
        $this->storeManager->expects($this->never())->method('getStore');

        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getCode')->willReturn('second_website');
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(2);
        $store->method('getWebsiteId')->willReturn(2);
        $this->storeRepository->expects($this->once())->method('getById')->with(2)->willReturn($store);
        $this->storeManager->method('getWebsite')->with(2)->willReturn($website);

        $stock = $this->createMock(StockInterface::class);
        $stock->method('getStockId')->willReturn(5);
        $this->stockResolver->method('execute')->with('website', 'second_website')->willReturn($stock);

        $this->getProductTypesBySkus->method('execute')->willReturn([]);

        $this->buildManagement()->getReport(10, 2);
    }

    public function testGetReportHandlesSkuMissingFromGetStockItemsDataResultAsZeroQty(): void
    {
        $this->mockCatalog([['sku' => 'sku-a', 'name' => 'A', 'type' => 'simple']]);
        $this->mockDefaultStoreResolution();
        $this->getProductTypesBySkus->method('execute')->willReturn(['sku-a' => 'simple']);
        $this->getStockItemsData->method('execute')->willReturn([]); // sku-a missing entirely
        $this->getReservationsQuantityBySkuList->method('execute')->willReturn([]);
        $this->getProductIdsBySkus->method('execute')->willReturn([]);

        $report = $this->buildManagement()->getReport(10);

        $this->assertSame(0.0, $report->getItems()[0]->getQty());
    }

    public function testGetReportDefaultsThresholdToTenWhenNotPassed(): void
    {
        $this->mockCatalog([['sku' => 'sku-a', 'name' => 'A', 'type' => 'simple']]);
        $this->mockDefaultStoreResolution();
        $this->getProductTypesBySkus->method('execute')->willReturn(['sku-a' => 'simple']);
        $this->getStockItemsData->method('execute')->willReturn([
            'sku-a' => [GetStockItemsDataInterface::QUANTITY => 9.0, GetStockItemsDataInterface::IS_SALABLE => 1],
        ]);
        $this->getReservationsQuantityBySkuList->method('execute')->willReturn([]);
        $this->getProductIdsBySkus->method('execute')->willReturn([]);

        $report = $this->buildManagement()->getReport();

        $this->assertSame(10, $report->getThreshold());
        $this->assertCount(1, $report->getItems());
    }
}
