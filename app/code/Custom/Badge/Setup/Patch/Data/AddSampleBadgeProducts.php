<?php
/**
 * Data Patch: Seed demo products for Custom_Badge module
 *
 * Creates three simple products — each assigned a different badge option —
 * so the module behaviour is immediately visible on the standard Magento
 * frontend and testable via GraphQL without any manual admin work.
 *
 * Products created:
 *   BADGE-NEW-001      "Heritage Canvas Tote"   → New
 *   BADGE-BEST-001     "Classic Running Shoe"   → Best Seller
 *   BADGE-SALE-001     "Slim Chino Trousers"    → Sale
 *
 * Depends on AddBadgeAttribute so the 'badge' attribute and its options
 * exist before we try to assign them.
 */
declare(strict_types=1);

namespace Custom\Badge\Setup\Patch\Data;

use Custom\Badge\Setup\Patch\Data\AddBadgeAttribute;
use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\Store;

class AddSampleBadgeProducts implements DataPatchInterface
{
    /**
     * Demo products to seed: [sku => [name, price, badge_option_key]]
     * badge_option_key must match the keys in AddBadgeAttribute::BADGE_OPTIONS.
     */
    private const DEMO_PRODUCTS = [
        'BADGE-NEW-001'  => ['Heritage Canvas Tote',  29.99, 'new'],
        'BADGE-BEST-001' => ['Classic Running Shoe',  89.99, 'best_seller'],
        'BADGE-SALE-001' => ['Slim Chino Trousers',   49.99, 'sale'],
    ];

    /**
     * Store-specific name translations: [sku => [store_code => name]]
     */
    private const NAME_TRANSLATIONS = [
        'BADGE-NEW-001' => [
            'en' => 'Heritage Canvas Tote',
            'fr' => 'Cabas en Toile Héritage',
            'de' => 'Heritage Canvas Tragetasche',
        ],
        'BADGE-BEST-001' => [
            'en' => 'Classic Running Shoe',
            'fr' => 'Chaussure de Running Classique',
            'de' => 'Klassischer Laufschuh',
        ],
        'BADGE-SALE-001' => [
            'en' => 'Slim Chino Trousers',
            'fr' => 'Pantalon Chino Ajusté',
            'de' => 'Slim Chino Hose',
        ],
    ];

    public function __construct(
        private readonly ModuleDataSetupInterface    $moduleDataSetup,
        private readonly ProductInterfaceFactory     $productFactory,
        private readonly ProductRepositoryInterface  $productRepository,
        private readonly StockRegistryInterface      $stockRegistry,
        private readonly ResourceConnection          $resourceConnection,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $optionMap = $this->buildOptionMap();

        foreach (self::DEMO_PRODUCTS as $sku => [$name, $price, $badgeKey]) {
            // If product already exists, ensure store-scoped translations are up to date.
            try {
                $existing = $this->productRepository->get($sku);
                $this->saveStoreTranslations((int) $existing->getId(), $sku);
                continue;
            } catch (\Magento\Framework\Exception\NoSuchEntityException) {
                // Not found — safe to create.
            }

            $optionId = $optionMap[$badgeKey] ?? null;

            /** @var \Magento\Catalog\Model\Product $product */
            $product = $this->productFactory->create();
            $product->setSku($sku)
                ->setName($name)
                ->setPrice($price)
                ->setTypeId(Type::TYPE_SIMPLE)
                ->setAttributeSetId($product->getDefaultAttributeSetId())
                ->setStatus(Status::STATUS_ENABLED)
                ->setVisibility(Visibility::VISIBILITY_BOTH)
                ->setWeight(0.5)
                ->setStoreId(Store::DEFAULT_STORE_ID);

            if ($optionId !== null) {
                $product->setData(AddBadgeAttribute::ATTRIBUTE_CODE, $optionId);
            }

            $savedProduct = $this->productRepository->save($product);

            // Set stock as in-stock with qty 100.
            $stockItem = $this->stockRegistry->getStockItem($savedProduct->getId());
            $stockItem->setIsInStock(true)
                ->setQty(100)
                ->setManageStock(true);
            $this->stockRegistry->updateStockItemBySku($sku, $stockItem);

            $this->saveStoreTranslations((int) $savedProduct->getId(), $sku);
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    /**
     * Save localized product names per store view (e.g. en, fr, de).
     */
    private function saveStoreTranslations(int $entityId, string $sku): void
    {
        if (!isset(self::NAME_TRANSLATIONS[$sku])) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $storeTable = $this->resourceConnection->getTableName('store');
        $varcharTable = $this->resourceConnection->getTableName('catalog_product_entity_varchar');
        $attributeTable = $this->resourceConnection->getTableName('eav_attribute');

        $attrId = (int) $connection->fetchOne(
            $connection->select()
                ->from($attributeTable, ['attribute_id'])
                ->where('attribute_code = ?', 'name')
                ->where('entity_type_id = ?', 4)
        );

        if (!$attrId) {
            return;
        }

        foreach (self::NAME_TRANSLATIONS[$sku] as $storeCode => $translatedName) {
            $storeId = (int) $connection->fetchOne(
                $connection->select()->from($storeTable, ['store_id'])->where('code = ?', $storeCode)
            );
            if (!$storeId) {
                continue;
            }

            $exists = $connection->fetchOne(
                $connection->select()
                    ->from($varcharTable, ['value_id'])
                    ->where('entity_id = ?', $entityId)
                    ->where('attribute_id = ?', $attrId)
                    ->where('store_id = ?', $storeId)
            );

            if ($exists) {
                $connection->update(
                    $varcharTable,
                    ['value' => $translatedName],
                    ['value_id = ?' => (int) $exists]
                );
            } else {
                $connection->insert($varcharTable, [
                    'attribute_id' => $attrId,
                    'store_id'     => $storeId,
                    'entity_id'    => $entityId,
                    'value'        => $translatedName,
                ]);
            }
        }
    }

    /**
     * Build a map of badge_option_key → option_id by reading the EAV tables.
     *
     * Reads the admin (store_id = 0) option labels for the 'badge' attribute
     * and matches them against the BADGE_OPTIONS keys from AddBadgeAttribute.
     *
     * @return array<string, int>  e.g. ['new' => 1, 'best_seller' => 2, 'sale' => 3]
     */
    private function buildOptionMap(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $connection = $this->resourceConnection->getConnection();
        $attributeTable = $this->resourceConnection->getTableName('eav_attribute');
        $entityTypeTable = $this->resourceConnection->getTableName('eav_entity_type');
        $attrId = (int) $connection->fetchOne(
            $connection->select()
                ->from(['a' => $attributeTable], ['attribute_id'])
                ->join(
                    ['e' => $entityTypeTable],
                    'e.entity_type_id = a.entity_type_id',
                    []
                )
                ->where('a.attribute_code = ?', AddBadgeAttribute::ATTRIBUTE_CODE)
                ->where('e.entity_type_code = ?', 'catalog_product')
        );

        $optionTable      = $this->resourceConnection->getTableName('eav_attribute_option');
        $optionValueTable = $this->resourceConnection->getTableName('eav_attribute_option_value');

        // Fetch all options for this attribute with their admin labels.
        $select = $connection->select()
            ->from(['o' => $optionTable], ['option_id', 'sort_order'])
            ->joinLeft(
                ['v' => $optionValueTable],
                'o.option_id = v.option_id AND v.store_id = 0',
                ['label' => 'v.value']
            )
            ->where('o.attribute_id = ?', $attrId)
            ->order('o.sort_order ASC');

        $rows = $connection->fetchAll($select);

        // Map admin label → option_id, then convert to badge_key → option_id.
        $adminLabelToId = [];
        foreach ($rows as $row) {
            $adminLabelToId[strtolower((string) $row['label'])] = (int) $row['option_id'];
        }

        // Canonical admin labels from AddBadgeAttribute::BADGE_OPTIONS (store_id = 0).
        $keyToAdminLabel = [
            'new'         => 'new',
            'best_seller' => 'best seller',
            'sale'        => 'sale',
        ];

        $map = [];
        foreach ($keyToAdminLabel as $key => $adminLabel) {
            if (isset($adminLabelToId[$adminLabel])) {
                $map[$key] = $adminLabelToId[$adminLabel];
            }
        }

        return $map;
    }

    /**
     * {@inheritdoc}
     */
    public static function getDependencies(): array
    {
        return [AddBadgeAttribute::class];
    }

    /**
     * {@inheritdoc}
     */
    public function getAliases(): array
    {
        return [];
    }
}

