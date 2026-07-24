# Ivanchenko_LowStockReport

Source: https://github.com/LarikCode/module-low-stock-report

A Magento 2 module adding a single bulk REST endpoint (`GET /rest/V1/low-stock-report`) that reports real-time MSI salable quantity across the **entire** stock-carrying catalog, computed via a small fixed number of bulk SQL queries rather than one round trip per SKU.

## Install

```
composer config repositories.low-stock-report vcs https://github.com/LarikCode/module-low-stock-report
composer require ivanchenko/module-low-stock-report:dev-main
bin/magento module:enable Ivanchenko_LowStockReport
bin/magento setup:upgrade
bin/magento cache:flush
```

## Granting an existing Integration access

This module's `acl.xml` resource (`Ivanchenko_LowStockReport::report`) only becomes checkable *after* the module is installed and `setup:upgrade` has run — it does not exist in Magento's ACL tree before that. Nesting it under `Magento_Catalog::catalog_inventory` groups it logically under Stores > Inventory for any **new** role/integration created afterward, but it does **not** retroactively grant it to an Integration that was authorized before this module existed. For an existing Integration, either re-open it in Admin (System > Integrations), check the newly-visible "Low Stock Report" permission under Sales > Operations > Inventory (wherever your ACL tree places it), and re-save/re-authorize — or insert the grant directly:

```sql
INSERT INTO authorization_rule (resource_id, role_id, permission)
SELECT 'Ivanchenko_LowStockReport::report', <role_id>, 'allow'
WHERE NOT EXISTS (
    SELECT 1 FROM authorization_rule
    WHERE role_id = <role_id> AND resource_id = 'Ivanchenko_LowStockReport::report'
);
```

No cache flush is needed after the insert — Magento's rule checks read `authorization_rule` directly per request, not through a cache layer.

## What it demonstrates

- **Bulk MSI data providers composed directly**, instead of looping the single-SKU `GetProductSalableQtyInterface` (or the array-accepting but internally-looping `AreProductsSalableInterface`) — `GetStockItemsDataInterface`, `GetReservationsQuantityBySkuListInterface`, `GetProductTypesBySkusInterface`, and `GetProductIdsBySkusInterface` each do the whole SKU array in one query.
- **Exact replication of Magento's own salable-qty formula** (`Magento\InventorySales\Model\GetProductSalableQty`): `is_salable ? (quantity + reservations - min_qty) : 0`.
- **Multi-store/multi-stock aware**: an optional `storeId` parameter resolves that store's website and MSI stock correctly, rather than silently assuming a single default store/stock.
- A complete, from-scratch `webapi.xml`/`Api`+`Model`/`acl.xml` service-contract module — no core files touched or overridden.

## Architecture notes and design decisions

### The problem this replaces

This module exists because a REST-client-side "low stock" report (originally implemented as a Node.js Adobe I/O Runtime action calling Magento's public REST API) had two compounding problems:

1. It paged through `/rest/V1/products` a single page at a time (`pageSize=150`, `currentPage=1`, no loop, no sort) and only ever evaluated whatever products happened to fall on that one unsorted page — on a ~2,040-product catalog (1,891 stock-carrying SKUs), that's roughly 7% coverage, not a real low-stock check.
2. Looping `/rest/V1/inventory/get-product-salable-quantity/{sku}/{stockId}` once per SKU to fix problem #1 properly was tested against the full ~1,891-SKU population and reliably stalled the target instance's PHP-FPM pool after roughly 400-530 requests, even at a modest concurrency of 10 — each call to that single-SKU endpoint costs ~3-4 internal DB queries, so looping it for the whole catalog means several thousand round trips.

Magento's GraphQL `products` query was evaluated as an alternative and ruled out separately: on the target instance, both `only_x_left_in_stock` and `quantity` returned `null` for every product regardless of real stock level — gated behind an unset "Only X Left" merchant display threshold, not a genuine salable-quantity source.

### Why bulk data providers instead of a bulk salable-qty API

There is no single MSI interface that takes an array of SKUs and returns exact salable quantities in one call. `AreProductsSalableInterface` and `AreProductsSalableForRequestedQtyInterface` both accept SKU arrays, but their own implementations loop the single-SKU logic internally — verified by reading the actual `Magento\InventorySales\Model\*` implementation classes, not assumed from the interface signature. What Magento's own core code does instead (e.g. its `CachePool::warmup()` mechanism used before quote/checkout salability checks) is pre-warm a handful of genuinely bulk, single-query data sources and then compose the same per-SKU formula in-memory against already-fetched data. This module does that composition directly and explicitly:

1. `GetStockItemsDataInterface::execute($skus, $stockId)` — quantity + is_salable for the whole SKU array, one query.
2. `GetReservationsQuantityBySkuListInterface::execute($skus, $stockId)` — `SUM(qty) ... GROUP BY sku`, one query, always real-time (unlike the indexer-dependent `quantity` component above).
3. `GetProductTypesBySkusInterface::execute($skus)` — bulk type validation, one query.
4. `GetProductIdsBySkusInterface::execute($skus)` + `StockItemRepositoryInterface::getList()` with a `product_id IN (...)` criteria — bulk `min_qty` (out-of-stock threshold), two queries. This is the one deliberate use of a `@deprecated` interface in this module: there is no MSI-native bulk `min_qty` provider, and the legacy `cataloginventory_stock_item` table stays correctly synced for the default stock, so it remains a valid bulk source for this one value.

SKUs are chunked at 500 to stay well under typical `IN()`/packet-size limits. For the ~1,891-SKU catalog this is 4 chunks × 4 queries + 1 catalog-enumeration query ≈ 17 total SQL queries for the entire report, versus roughly 1,891 REST calls (several thousand underlying DB queries) in the approach this replaces.

### Multi-store handling

An optional `storeId` query parameter resolves the specific store's website and MSI stock via `StockResolverInterface::execute('website', $store->getWebsite()->getCode())`, rather than resolving the process-wide default website unconditionally. The product collection is also scoped to that store (`setStoreId()`) so store-scoped attributes like `name` reflect the correct store view. One call reports against one store/stock; a merchant with multiple websites mapped to different Stocks needs one call per `storeId` for full coverage.

## Known limitations

- `min_qty` bulk lookup uses the `@deprecated` `StockItemRepositoryInterface`/`StockItemCriteriaInterface` — correct for the default stock under MSI, but there is currently no MSI-native bulk replacement if that ever changes.
- `MAX_ITEMS = 50` caps the response payload; `total_scanned` always reflects the true full stock-carrying-catalog count regardless of the cap.
- One call reports one store/stock (see Multi-store handling above) — no built-in cross-stock aggregation in a single call.

## Running the tests

```
vendor/bin/phpunit
```

Plain `PHPUnit\Framework\TestCase` unit tests, every constructor dependency mocked — no Magento integration-test bootstrap required. Covers: exact formula replication, `is_salable=false` forcing qty to 0, strict threshold exclusion, ascending sort, the 50-item cap vs. true scanned count, configurable/grouped/bundle exclusion, SKU chunking at 500, and store/stock resolution (both the default-store and explicit-`storeId` paths).
