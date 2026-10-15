<?php
/**
 * Plugin: Append [Badge] to the product name
 *
 * Intercepts \Magento\Catalog\Model\Product::getName() and appends the
 * translated badge label in square brackets when a badge is assigned.
 *
 * The plugin is scoped to frontend area only (see etc/frontend/di.xml) so
 * the raw name is preserved in admin and CLI contexts.
 */
declare(strict_types=1);

namespace Custom\Badge\Plugin;

use Custom\Badge\Model\BadgeLabel;
use Magento\Catalog\Model\Product;

class ProductNamePlugin
{
    public function __construct(
        private readonly BadgeLabel $badgeLabel,
    ) {}

    /**
     * After plugin on Product::getName().
     *
     * @param Product     $subject
     * @param string|null $result
     * @return string|null
     */
    public function afterGetName(Product $subject, ?string $result): ?string
    {
        if ($result === null) {
            return $result;
        }

        $badge = $this->badgeLabel->getLabel($subject);

        if ($badge === '') {
            return $result;
        }

        // Avoid double-appending if getName() is called multiple times.
        $suffix = ' [' . $badge . ']';
        if (str_ends_with($result, $suffix)) {
            return $result;
        }

        return $result . $suffix;
    }
}

