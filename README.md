# GAMMAX: E-Commerce REST API

REST API for **GAMMAX**, an online shop for gaming gear and PC parts in Cambodia.
Customers browse products, check out with **KHQR** or **Cash on Delivery**, and track their orders.
Staff run the shop from an admin panel: catalog, orders, payments and refunds.

Built with **Laravel 12**, **PostgreSQL** and **Sanctum** token authentication.

- Frontend (React): [Chana758/EC-GAMAX-Front](https://github.com/Chana758/EC-GAMAX-Front)
- Live demo: coming soon

---

## Features

### Customers
- Register, log in and log out with a Bearer token (Sanctum). Login and register are rate limited.
- Browse products with search, category, brand, price range, "on sale" and "featured" filters, plus sorting.
- Products have variants (for example colour or storage), each with its own price, stock and SKU.
- Save delivery addresses for all 25 provinces of Cambodia.
- **Checkout priced by the server.** The browser sends only item ids and quantities. Prices, delivery fee and COD fee are calculated from the database, so they can't be changed by the browser.
- If a price or fee changed while the customer was on the checkout page, the API answers `409` with the new total, and the customer confirms again.
- **KHQR payment:** the customer sees the amount in riel, pays in their bank app and taps "I've paid". Staff then confirm the payment.
- Unpaid KHQR orders are cancelled automatically after 24 hours and their stock goes back on the shelf.
- Order history, order details and cancelling an order.

### Staff and admin
- Role-based access with **admin**, **staff** and **customer** roles (spatie/laravel-permission). Each action is checked by a Policy.
- Manage categories, brands, products, variants and product images (upload jpg, png or webp up to 2 MB).
- **Order center:** filter by status, payment, channel and date. Search and sort. Quick views for orders to call, payments to check, refunds and orders to ship.
- Owner stats: money received this month compared with last month, orders, average order value and money still to collect.
- Move orders through their steps: awaiting payment → to call → to pack → packing → shipped → delivered.
- Fix the customer's contact details until the order ships.
- **Payments ledger:** every amount received or refunded is its own row (USD or KHR, exchange rate, reference). Refunds are admin-only.

### Reliability
- **No overselling.** Stock is taken inside a database transaction with the variant rows locked (`lockForUpdate`), so two customers can never buy the last unit twice.
- Order items keep a copy of the product name, price and image, so old orders stay correct after a product changes.
- 94 feature tests cover auth, catalog, checkout, KHQR, payments, refunds and admin orders.

---

## Tech stack

| Area | Tools |
|---|---|
| Language and framework | PHP 8.2+, Laravel 12 |
| Database | PostgreSQL (SQLite in memory for tests) |
| Auth | Laravel Sanctum (Bearer tokens) |
| Roles and permissions | spatie/laravel-permission + Laravel Policies |
| Tests | PHPUnit 11 (feature tests) |
| Scheduling | Laravel scheduler (artisan commands) |

---

## How a request flows

```
Route (routes/v1)  →  FormRequest (validation)  →  Controller (thin)
       →  Service (business rules, transactions)  →  Model (Eloquent)
       →  API Resource (JSON shape)
```

```
app/
├── Console/Commands/      # orders:cancel-unpaid, images:cleanup-orphans, catalog:fix
├── Http/
│   ├── Controllers/Api/V1/     # customer endpoints
│   ├── Controllers/Admin/V1/   # admin endpoints
│   ├── Requests/               # validation rules and messages
│   └── Resources/              # JSON responses
├── Models/                # Product, ProductVariant, Order, OrderItem, Payment, Address ...
├── Policies/              # who may do what
├── Services/              # OrderService, OrderPaymentService, ProductService ...
└── Support/ShopRules.php  # delivery zones, fees, payment methods
config/shop.php            # shop settings: provinces, fees, KHQR, COD
routes/v1/api_v1.php       # /api/v1/...
routes/v1/admin_v1.php     # /api/v1/admin/...
```

---

## API overview

All endpoints start with `/api/v1`. Protected endpoints need `Authorization: Bearer <token>`.

| Method | Endpoint | Who | Purpose |
|---|---|---|---|
| POST | `/auth/register`, `/auth/login` | Guest | Get a token |
| GET | `/auth/me`, POST `/auth/logout` | Logged in | Current user, log out |
| GET | `/products`, `/products/{slug}` | Public | Product list with filters, product details |
| GET | `/categories`, `/brands`, `/settings` | Public | Catalog data, delivery fees and payment methods |
| CRUD | `/addresses` | Customer | Saved delivery addresses |
| POST | `/orders/quote` | Customer | Totals for the review step (nothing is saved) |
| POST | `/orders` | Customer | Place an order |
| GET | `/orders`, `/orders/{number}` | Customer | Order history and details |
| POST | `/orders/{number}/cancel` | Customer | Cancel an order |
| POST | `/orders/{number}/payment` | Customer | "I've paid" for a KHQR order |
| CRUD | `/admin/categories`, `/admin/brands`, `/admin/products`, `/admin/products/{id}/variants` | Staff | Catalog management |
| POST/DELETE | `/admin/uploads/image` | Staff | Upload or remove an image |
| GET | `/admin/orders`, `/admin/orders/summary`, `/admin/orders/stats` | Staff | Order list, counters, owner stats |
| GET/PATCH | `/admin/orders/{number}` | Staff | Order details, change status or contact |
| POST | `/admin/orders/{number}/payments` | Staff | Record money received |
| POST | `/admin/orders/{number}/refunds` | Admin | Record a refund |

---

## Getting started

### Requirements
- PHP 8.2+ and Composer
- PostgreSQL 14+

### Install

```bash
git clone https://github.com/Chana758/GAMAX_API.git
cd GAMAX_API
composer install
cp .env.example .env
php artisan key:generate
```

Create a PostgreSQL database named `gammax`, then set the `DB_*` values in `.env`.

```bash
php artisan migrate --seed     # tables, roles, permissions and demo users
php artisan storage:link       # public URLs for uploaded images
php artisan serve              # http://127.0.0.1:8000/api/v1
```

Run the scheduler in a second terminal so unpaid KHQR orders are cancelled on time:

```bash
php artisan schedule:work
```

### Demo accounts (local only)

| Role | Email | Password |
|---|---|---|
| Admin | admin@gammax.com | Password123 |
| Staff | staff@gammax.com | Password123 |
| Customer | customer@gammax.com | Password123 |

These accounts come from `AdminUserSeeder`. Do not run that seeder on a public server, or change the passwords right away.

### Shop settings

Delivery zones, fees, the free-shipping threshold, payment methods and KHQR details live in `config/shop.php`.
The fees and the exchange rate in the repo are sample values.

---

## Tests

```bash
php artisan test
```

Tests run on SQLite in memory, so they don't touch your database. One test about database CHECK constraints only runs on PostgreSQL, so it is skipped on SQLite.

---

## Scheduled commands

| Command | When | What it does |
|---|---|---|
| `orders:cancel-unpaid` | Every 15 minutes | Cancels KHQR orders not paid in time and returns their stock |
| `images:cleanup-orphans` | Daily at 03:00 | Deletes uploaded images nothing uses any more |
| `catalog:fix` | By hand | Cleans product names, slugs, brands and variant data |

---

## Roadmap

- Dashboard endpoint with real sales data
- Coupons
- Product reviews from customers who received their order
- Telegram notification for new orders
- Deployment

---

## Author

**Sam Channa**, ITE student at the Royal University of Phnom Penh

- GitHub: [@Chana758](https://github.com/Chana758)
- Email: csam26176@gmail.com
