<?php
/**
 * BadgeLabel Service
 *
 * Resolves the translated badge label for the current (or specified) store view
 * by reading the store-scoped option value from the EAV option value table.
 */
declare(strict_types=1);

namespace Custom\Badge\Model;

use Custom\Badge\Setup\Patch\Data\AddBadgeAttribute;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

class BadgeLabel
{
    /**
     * In-memory cache: storeId → optionId → label
     *
     * @var array<int, array<int, string>>
     */
    private array $cache = [];

    public function __construct(
        private readonly ResourceConnection    $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly EavConfig             $eavConfig,
    ) {}

    /**
     * Return the translated badge label for the given product.
     *
     * @param Product  $product
     * @param int|null $storeId  Pass null to use the current store.
     * @return string  Empty string when no badge is assigned.
     */
    public function getLabel(Product $product, ?int $storeId = null): string
    {
        $optionId = (int) $product->getData(AddBadgeAttribute::ATTRIBUTE_CODE);

        if (!$optionId) {
            return '';
        }

        $storeId ??= (int) $this->storeManager->getStore()->getId();

        return $this->fetchLabel($optionId, $storeId);
    }

    /**
     * Fetch the most specific label for an option/store combination.
     *
     * Priority: store-specific label → admin/default (store 0).
     */
    private function fetchLabel(int $optionId, int $storeId): string
    {
        if (isset($this->cache[$storeId][$optionId])) {
            return $this->cache[$storeId][$optionId];
        }

        $connection = $this->resourceConnection->getConnection();
        $table      = $this->resourceConnection->getTableName('eav_attribute_option_value');

        // Fetch both the admin row (store 0) and the requested store row in one query.
        $select = $connection->select()
            ->from($table, ['store_id', 'value'])
            ->where('option_id = ?', $optionId)
            ->where('store_id IN (?)', [0, $storeId])
            ->order('store_id DESC'); // store-specific row comes first

        $rows  = $connection->fetchAll($select);
        $label = '';

        foreach ($rows as $row) {
            // The first row with the highest store_id wins (store-specific takes precedence).
            if ($label === '' || (int) $row['store_id'] === $storeId) {
                $label = (string) $row['value'];
                if ((int) $row['store_id'] === $storeId) {
                    break; // Exact match found; no need to keep iterating.
                }
            }
        }

        $this->cache[$storeId][$optionId] = $label;

        return $label;
    }
}

