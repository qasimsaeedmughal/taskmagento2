<?php
/**
 * Custom_Badge Module Registration
 */
declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'Custom_Badge',
    __DIR__
);

