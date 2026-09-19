# یافته‌های سازگاری APIها

این سند تفاوت بین schema عمومی و رفتار مشاهده‌شده در اتصال واقعی را ثبت می‌کند. هیچ credential یا شناسه مشتری در آن نگهداری نمی‌شود.

آخرین بررسی عملیاتی: `2026-09-19`

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
- پاسخ واقعی از نام‌گذاری PascalCase استفاده می‌کند.

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
- در آزمایش واقعی، پاسخ شامل ۴ Product، ۲ ProductCategory، ۴ ProductDetail و ۴ VisitorProduct بود.
- داده واقعی نیز PascalCase بود و Bridge هر دو حالت نام‌گذاری مورد انتظار را نرمال می‌کند.
- شناسه‌های عددی پس از عبور از کلیدهای آرایه PHP دوباره به string تبدیل می‌شوند تا قرارداد StateStore نقض نشود.

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

این مقدار توسط Login برگردانده می‌شود و جزئی از payload درخواست‌های Bridge نیست. در محیط آزمایش‌شده مقدار آن `false` بود، با این حال `GetAllData` داده محصول را برگرداند. بنابراین Bridge آن را فقط برای مشاهده تشخیصی نگه می‌دارد و بر اساس آن عملیات را فعال یا غیرفعال نمی‌کند.

### رفتار قیمت و موجودی

در داده واقعی آزمایش‌شده:

- `VisitorProduct.Price` برای هر چهار کالا صفر بود.
- `ProductDetail.Price1` مقدار داشت.
- `DefaultSellPriceLevel=1` مشاهده شد.
- Mapper فقط وقتی قیمت VisitorProduct مثبت باشد آن را ترجیح می‌دهد؛ در غیر این صورت از `ProductDetail.Price1` استفاده می‌کند.
- موجودی فعلی از `VisitorProduct.Count1` خوانده می‌شود.
- `Count2` نیز مقدار داشت، اما معنای کسب‌وکاری آن هنوز تأیید نشده است.

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

خروجی واقعی health وضعیت `healthy` و نسخه `4.0.0` را برگرداند. info نیز عنوان `Mixin API v4`، توضیح `Modern Management API`، روش‌های Session/API Key و مسیرهای `/api/v4/docs/` و `/api/v4/openapi.json` را تأیید کرد.

## واحد پول

Mixin قیمت محصول را تومان تعریف می‌کند. واحد واقعی خروجی هر دیتابیس محک باید با کالای نمونه کنترل شود. تبدیل با `SYNC_PRICE_DIVISOR` انجام می‌شود:

- محک ریال، Mixin تومان: `10`
- هر دو تومان: `1`

با `SYNC_PRICE_DIVISOR=10` قیمت‌های dry-run معقول و غیرصفر شدند، اما این مشاهده جای تطبیق دستی با فاکتور یا پنل محک را نمی‌گیرد. پیش از خروج از dry-run حداقل سه قیمت و موجودی باید دستی مقایسه شود.
