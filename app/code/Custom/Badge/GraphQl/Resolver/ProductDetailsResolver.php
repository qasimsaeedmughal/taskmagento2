<?php
/**
 * GraphQL Resolver: productDetails
 *
 * Accepts a SKU argument and returns:
 *   sku, name (with badge suffix), price, currency, stock_status, badge
 *
 * The store view is determined from the Store header sent by the GraphQL client:
 *   Store: en  →  GBP, English badge labels
 *   Store: fr  →  EUR, French badge labels
 *   Store: de  →  EUR, German badge labels
 *
 * Invalid / unknown SKUs return a GraphQL user-friendly error.
 */
declare(strict_types=1);

namespace Custom\Badge\GraphQl\Resolver;

use Custom\Badge\Model\BadgeLabel;
use Custom\Badge\Setup\Patch\Data\AddBadgeAttribute;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Store\Model\StoreManagerInterface;

class ProductDetailsResolver implements ResolverInterface
{
    /**
     * Maps store codes to their default display currency.
     *
     * @var array<string, string>
     */
    private const STORE_CURRENCY_MAP = [
        'en' => 'GBP',
        'fr' => 'EUR',
        'de' => 'EUR',
    ];

    /**
     * Maps store codes to translated stock status labels.
     *
     * @var array<string, array{in_stock: string, out_of_stock: string}>
     */
    private const STORE_STOCK_STATUS_MAP = [
        'en' => [
            'in_stock'     => 'In stock',
            'out_of_stock' => 'Out of stock',
        ],
        'fr' => [
            'in_stock'     => 'En stock',
            'out_of_stock' => 'En rupture de stock',
        ],
        'de' => [
            'in_stock'     => 'Auf Lager',
            'out_of_stock' => 'Nicht vorrätig',
        ],
    ];

    /**
     * Fallback currency when the store code is not in the map.
     */
    private const DEFAULT_CURRENCY = 'EUR';

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StockRegistryInterface     $stockRegistry,
        private readonly StoreManagerInterface      $storeManager,
        private readonly BadgeLabel                 $badgeLabel,
    ) {}

    /**
     * {@inheritdoc}
     *
     * @throws GraphQlInputException         When SKU argument is missing or empty.
     * @throws GraphQlNoSuchEntityException  When no product is found for the given SKU.
     */
    public function resolve(
        Field       $field,
        mixed       $context,
        ResolveInfo $info,
        ?array      $value = null,
        ?array      $args  = null
    ): array {
        $sku = trim((string) ($args['sku'] ?? ''));

        if ($sku === '') {
            throw new GraphQlInputException(__('The "sku" argument is required and cannot be empty.'));
        }

        // Resolve current store from the GraphQL context (set by the Store header).
        $store = null;
        if (is_object($context) && method_exists($context, 'getExtensionAttributes')) {
            $ext = $context->getExtensionAttributes();
            if ($ext && method_exists($ext, 'getStore') && $ext->getStore()) {
                $store = $ext->getStore();
            }
        }
        if (!$store) {
            $store = $this->storeManager->getStore();
        }
        $storeId   = (int) $store->getId();
        $storeCode = $store->getCode();

        try {
            $product = $this->productRepository->get($sku, false, $storeId);
        } catch (NoSuchEntityException) {
            throw new GraphQlNoSuchEntityException(
                __('No product found with SKU "%1".', $sku)
            );
        }

        // Badge label (store-scoped, already translated).
        $badge = $this->badgeLabel->getLabel($product, $storeId);

        // Product name with badge appended when present.
        $name = $product->getName() ?? '';
        if ($badge !== '') {
            $suffix = ' [' . $badge . ']';
            if (!str_ends_with($name, $suffix)) {
                $name .= $suffix;
            }
        }

        // Stock status (store-scoped translation).
        $stockItem   = $this->stockRegistry->getStockItem($product->getId());
        $isInStock   = (bool) $stockItem->getIsInStock();
        $stockMap    = self::STORE_STOCK_STATUS_MAP[$storeCode] ?? self::STORE_STOCK_STATUS_MAP['en'];
        $stockStatus = $isInStock ? $stockMap['in_stock'] : $stockMap['out_of_stock'];

        // Currency for this store view.
        $currency = self::STORE_CURRENCY_MAP[$storeCode] ?? self::DEFAULT_CURRENCY;

        return [
            'sku'          => $product->getSku(),
            'name'         => $name,
            'price'        => (float) ($product->getPrice() ?? 0.0),
            'currency'     => $currency,
            'stock_status' => $stockStatus,
            'badge'        => $badge !== '' ? $badge : null,
        ];
    }
}

