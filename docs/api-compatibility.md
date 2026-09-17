# یافته‌های سازگاری APIها

این سند تفاوت بین schema عمومی و رفتار مشاهده‌شده در اتصال واقعی را ثبت می‌کند. هیچ credential یا شناسه مشتری در آن نگهداری نمی‌شود.

## Mahak API v3

Base URL:

```text
https://mahakacc.mahaksoft.com/API/v3
```

### Login

- endpoint عملیاتی: `POST /Sync/Login`
- payload اثبات‌شده: `userName` و `password`
- پاسخ موفق شامل `UserToken`، `VisitorId`، `DatabaseId`، `ServerTime` و مشخصات بسته است.
- Bridge توکن را در خروجی CLI با `[REDACTED]` جایگزین می‌کند.

### Authentication

در درخواست‌های بعدی:

```http
Authorization: Bearer USER_TOKEN
```

### GetAllData

- endpoint: `POST /Sync/GetAllData`
- فقط RowVersion موجودیت‌های موردنیاز ارسال می‌شود.
- مقدار صفر یعنی دریافت اولیه.
- `currentVisitorId` باید Visitor برگشتی از Login باشد.
- کالاهای قابل استفاده برای سایت از ارتباط `VisitorProduct` تشخیص داده می‌شوند.
- پاسخ موفق می‌تواند collectionهای خالی داشته باشد؛ خالی بودن داده خطای transport نیست.

نمونه درخواست اولیه محدود:

```json
{
  "currentVisitorId": 12345,
  "fromProductVersion": 0,
  "fromProductDetailVersion": 0,
  "fromProductCategoryVersion": 0,
  "fromVisitorProductVersion": 0,
  "pageSize": 5
}
```

### WithDataTransfer

این مقدار توسط Login برگردانده می‌شود و جزئی از payload درخواست‌های Bridge نیست. چون معنای رفتاری آن در Swagger توضیح داده نشده، Bridge آن را فقط برای مشاهده تشخیصی نگه می‌دارد و بر اساس آن عملیات را فعال یا غیرفعال نمی‌کند.

## Mixin API v4

Base URL در هر فروشگاه متفاوت است:

```text
https://SHOP_DOMAIN
```

Authentication:

```http
Authorization: Api-Key API_KEY
```

کلاینت Bridge خودش `/api/v4` را به origin اضافه می‌کند. بنابراین مقدار زیر اشتباه است:

```dotenv
MIXIN_BASE_URL=https://SHOP_DOMAIN/api/v4
```

و مقدار صحیح:

```dotenv
MIXIN_BASE_URL=https://SHOP_DOMAIN
```

endpointهای اتصال آزمایش‌شده:

```text
GET /api/v4/health/
GET /api/v4/info/
```

## واحد پول

Mixin قیمت محصول را تومان تعریف می‌کند. واحد واقعی خروجی هر دیتابیس محک باید با کالای نمونه کنترل شود. تبدیل با `SYNC_PRICE_DIVISOR` انجام می‌شود:

- محک ریال، Mixin تومان: `10`
- هر دو تومان: `1`

قبل از خروج از dry-run حداقل سه قیمت و موجودی باید دستی مقایسه شود.
