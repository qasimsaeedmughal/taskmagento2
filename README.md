# Custom_Badge — Magento 2 Module

Adds a translatable `badge` product attribute (New / Best Seller / Sale) and a `productDetails` GraphQL query that returns the badge label alongside core product data.

---

## Requirements

- PHP 8.1+
- Magento 2.4.x

---

## Installation

```bash
# Copy the module
cp -r app/code/Custom /path/to/magento/app/code/

# Enable and install
php bin/magento module:enable Custom_Badge
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

> **No frontend build required.** The module uses the standard Magento admin and frontend. The badge suffix is appended to product names via a PHP plugin — no npm, webpack, or custom theme needed.

---

## Demo Products

`setup:upgrade` automatically seeds three ready-to-use products:


| `BADGE-NEW-001` | Heritage Canvas Tote | New | £29.99 |
| `BADGE-BEST-001` | Classic Running Shoe | Best Seller | £89.99 |
| `BADGE-SALE-001` | Slim Chino Trousers | Sale | £49.99 |

All three are simple products, set to **visible in catalog & search**, with **100 units in stock**. They appear immediately in the standard Magento storefront and are queryable via GraphQL using their SKUs above.

---

## Assigning a Badge to a Product

1. Go to **Admin → Catalog → Products** and open a product.
2. Find the **Badge** attribute and select one of: **New**, **Best Seller**, **Sale**.
3. Save the product.

---

## GraphQL Usage

Send a `POST` request to `/graphql` with the `Store` header (`en`, `fr`, or `de`).

### Query
```graphql
{
  productDetails(sku: "BADGE-BEST-001") {
    sku
    name
    price
    currency
    stock_status
    badge
  }
}
```

### Store View Responses

#### 🇬🇧 English (`Store: en`)
```json
{
  "data": {
    "productDetails": {
      "sku": "BADGE-BEST-001",
      "name": "Classic Running Shoe [Best Seller]",
      "price": 89.99,
      "currency": "GBP",
      "stock_status": "In stock",
      "badge": "Best Seller"
    }
  }
}
```

#### 🇫🇷 French (`Store: fr`)
```json
{
  "data": {
    "productDetails": {
      "sku": "BADGE-BEST-001",
      "name": "Chaussure de Running Classique [Meilleure Vente]",
      "price": 89.99,
      "currency": "EUR",
      "stock_status": "En stock",
      "badge": "Meilleure Vente"
    }
  }
}
```

#### 🇩🇪 German (`Store: de`)
```json
{
  "data": {
    "productDetails": {
      "sku": "BADGE-BEST-001",
      "name": "Klassischer Laufschuh [Bestseller]",
      "price": 89.99,
      "currency": "EUR",
      "stock_status": "Auf Lager",
      "badge": "Bestseller"
    }
  }
}
```

---

## Store View Translations Reference

| Attribute Key | English (`en`) | French (`fr`) | German (`de`) |
| `new` | New | Nouveau | Neu |
| `best_seller` | Best Seller | Meilleure Vente | Bestseller |
| `sale` | Sale | Soldes | Angebot |

Product names and stock statuses are also localized according to the store view context.

---


1. Store views with codes `en`, `fr`, and `de` exist in Magento (used to resolve store-scoped badge labels, names, and currency).
2. Currencies are assigned per requirement: **GBP** for EN, **EUR** for FR and DE.
3. The `badge` attribute is added to the default product attribute set.
4. Stock status uses standard Magento stock (`In stock` / `En stock` / `Auf Lager`).
5. PHP 8.1+ is required (uses `readonly` properties and modern syntax).
