<?php
declare(strict_types=1);

namespace Ivanchenko\LowStockReport\Model\Data;

use Ivanchenko\LowStockReport\Api\Data\LowStockItemInterface;

/**
 * Plain immutable DTO rather than an Magento\Framework\Api\AbstractSimpleObject:
 * this type is exclusively an *output* the management class constructs
 * directly, never something the webapi framework needs to hydrate from
 * client input, so there's no reason for the extra setData()/extension
 * attribute machinery AbstractSimpleObject exists for.
 */
class LowStockItem implements LowStockItemInterface
{
    public function __construct(
        private readonly string $sku,
        private readonly string $name,
        private readonly float $qty
    ) {
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getQty(): float
    {
        return $this->qty;
    }
}
