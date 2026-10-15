<?php
/**
 * Data Patch: Add 'badge' product attribute
 *
 * Creates a store-scoped dropdown EAV attribute with three options:
 * New, Best Seller, Sale. Each option label is stored per store view so
 * Magento's standard translation/label mechanism can be used to return
 * the correct text for EN / FR / DE.
 */
declare(strict_types=1);

namespace Custom\Badge\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Eav\Model\Entity\Attribute\Source\TableFactory;
use Magento\Catalog\Model\ResourceModel\Eav\AttributeFactory as CatalogAttributeFactory;

class AddBadgeAttribute implements DataPatchInterface
{
    /**
     * Attribute code used throughout the module.
     */
    public const ATTRIBUTE_CODE = 'badge';

    /**
     * Badge options: [option_key => [store_id => label]]
     * store_id 0 = admin / default (EN)
     */
    private const BADGE_OPTIONS = [
        'new'         => [
            0  => 'New',        // admin / EN default
            'en' => 'New',
            'fr' => 'Nouveau',
            'de' => 'Neu',
        ],
        'best_seller' => [
            0  => 'Best Seller',
            'en' => 'Best Seller',
            'fr' => 'Meilleure Vente',
            'de' => 'Bestseller',
        ],
        'sale'        => [
            0  => 'Sale',
            'en' => 'Sale',
            'fr' => 'Soldes',
            'de' => 'Angebot',
        ],
    ];

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory          $eavSetupFactory,
        private readonly StoreManagerInterface    $storeManager,
        private readonly CatalogAttributeFactory  $catalogAttributeFactory,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        // 1. Create the attribute (no options yet — added manually below so we
        //    can attach per-store labels).
        $eavSetup->addAttribute(
            Product::ENTITY,
            self::ATTRIBUTE_CODE,
            [
                'type'                    => 'int',
                'label'                   => 'Badge',
                'input'                   => 'select',
                'source'                  => \Magento\Eav\Model\Entity\Attribute\Source\Table::class,
                'required'                => false,
                'visible'                 => true,
                'user_defined'            => true,
                'searchable'              => false,
                'filterable'              => false,
                'comparable'              => false,
                'visible_on_front'        => true,
                'used_in_product_listing' => true,
                'unique'                  => false,
                'global'                  => ScopedAttributeInterface::SCOPE_STORE,
                'apply_to'               => '',
                'note'                    => 'Select a badge to display on the product (New, Best Seller, Sale)',
                'option'                  => ['values' => []],
            ]
        );

        // 2. Build a store-code → store-id map.
        $storeCodeToId = [];
        foreach ($this->storeManager->getStores(true) as $store) {
            $storeCodeToId[$store->getCode()] = (int) $store->getId();
        }

        // 3. Insert option rows with per-store labels.
        $this->insertOptions($storeCodeToId);

        $defaultAttributeSetId = $eavSetup->getDefaultAttributeSetId(Product::ENTITY);
        $defaultAttributeGroupId = $eavSetup->getDefaultAttributeGroupId(
            Product::ENTITY,
            $defaultAttributeSetId
        );
        $eavSetup->addAttributeToSet(
            Product::ENTITY,
            $defaultAttributeSetId,
            $defaultAttributeGroupId,
            self::ATTRIBUTE_CODE
        );

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    /**
     * Insert attribute options and their store-scoped labels.
     *
     * @param array<string,int> $storeCodeToId
     */
    private function insertOptions(array $storeCodeToId): void
    {
        $connection = $this->moduleDataSetup->getConnection();

        // Retrieve the attribute id we just created.
        $eavSetup   = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $attributeId = (int) $eavSetup->getAttributeId(Product::ENTITY, self::ATTRIBUTE_CODE);

        $optionTable      = $this->moduleDataSetup->getTable('eav_attribute_option');
        $optionValueTable = $this->moduleDataSetup->getTable('eav_attribute_option_value');

        $sortOrder = 0;

        foreach (self::BADGE_OPTIONS as $optionLabels) {
            // Insert option row.
            $connection->insert($optionTable, [
                'attribute_id' => $attributeId,
                'sort_order'   => $sortOrder++,
            ]);
            $optionId = (int) $connection->lastInsertId($optionTable);

            // Insert admin (store 0) label.
            $connection->insert($optionValueTable, [
                'option_id' => $optionId,
                'store_id'  => 0,
                'value'     => $optionLabels[0],
            ]);

            // Insert per-store labels for known store codes.
            foreach (['en', 'fr', 'de'] as $code) {
                if (!isset($storeCodeToId[$code], $optionLabels[$code])) {
                    continue;
                }
                $connection->insert($optionValueTable, [
                    'option_id' => $optionId,
                    'store_id'  => $storeCodeToId[$code],
                    'value'     => $optionLabels[$code],
                ]);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getAliases(): array
    {
        return [];
    }
}

