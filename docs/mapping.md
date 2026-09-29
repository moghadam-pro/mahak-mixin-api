# قرارداد نگاشت داده

آخرین اعتبارسنجی با داده واقعی: `2026-09-19`

## کالا: Mahak → Mixin

| Mahak | Mixin | توضیح |
|---|---|---|
| `Product.Name` | `name` | اجباری؛ ابتدا و انتهای نام trim می‌شود و رکورد بدون نام رد می‌شود |
| `Product.Description` | `description` | رشته خالی ارسال نمی‌شود |
| `ProductDetail.Price2 > 0`، وگرنه `ProductDetail.Price1` | `price` | قیمت دوم فروش سایت است؛ سپس بر `SYNC_PRICE_DIVISOR` تقسیم می‌شود؛ اگر هر دو صفر باشند صفر ارسال می‌شود |
| `VisitorProduct.Count1` | `stock` | مقدار منفی به صفر تبدیل می‌شود؛ انتخاب Count1 هنوز نیازمند تأیید کسب‌وکار است |
| موجودی مثبت | `stock_type=limited` | موجودی صفر `out_of_stock` است |
| وضعیت حذف + موجودی | `available` | رکورد حذف‌شده یا بدون موجودی ناموجود است |
| `ProductDetail.Barcode` | `barcode` | اختیاری |
| `ProductDetail.ProductDetailId` | `product_identifier` | شناسه پایدار detail و همیشه string |
| شناسه‌های Product/Detail | `external_ids` | برای audit و بازیابی mapping |
| ابعاد/وزن Product | همان فیلدها | گرد شده و منفی‌ها صفر می‌شوند |

> قاعده کسب‌وکار تأییدشده در ۲۰۲۶-۰۹-۲۳ این است که `Price1` قیمت عمده و `Price2` قیمت سایت است. `VisitorProduct.Price` در انتخاب قیمت سایت دخالت ندارد. بررسی واقعی کد کالای ۵ مقدار `Price1=897000` و `Price2=1000000` و بررسی کد ۱۶۸۱ مقدار `Price1=5379000` و `Price2=200` را از API برگرداند؛ Bridge مقدار سطح دوم را بدون قضاوت درباره بزرگی آن می‌پذیرد.

## موجودیت واسط

محک محصول قابل ارائه به هر سایت را با `VisitorProduct` مشخص می‌کند. joinهای نسخه فعلی:

```text
Product.ProductId = ProductDetail.ProductId
ProductDetail.ProductDetailId = VisitorProduct.ProductDetailId
VisitorProduct.VisitorId = MAHAK_VISITOR_ID
```

`currentVisitorId` نیز در `GetAllData` ارسال می‌شود. کلیدهای عددی PHP ممکن است به integer تبدیل شوند؛ Bridge شناسه‌های detail را پیش از مراجعه به StateStore با `strval` نرمال می‌کند.

## نرمال‌سازی نام

فعلاً فقط فاصله ابتدا و انتهای نام حذف می‌شود. موارد زیر عمداً تا تأیید کسب‌وکار تغییر نمی‌کنند:

- فاصله‌های تکراری داخل نام
- تبدیل حروف عربی `ي/ك` به حروف فارسی `ی/ک`
- نیم‌فاصله و قواعد نمایشی عنوان

## مشتری و سفارش

سمت Mixin فیلدهای مشتری شامل username، نام، نام خانوادگی، ایمیل، موبایل و کد ملی است. سمت محک Person علاوه بر این‌ها شناسه گروه شخص، نوع شخص و قواعد مالی دارد. تا زمانی که مقادیر پیش‌فرض کسب‌وکار تعیین نشده‌اند، Bridge مشتری را در محک ایجاد نمی‌کند.

برای تست کنترل‌شده نسخه `0.5.0`، سفارش میکسین به `Order` با `orderType=201`، `settlementType=1` و ردیف‌های `OrderDetail.storeId=1` نگاشت می‌شود. مبلغ واحد، تخفیف و ارسال از تومان سایت با ضریب `10` به ریال محک تبدیل می‌شوند. هر `product_id` میکسین باید در SQLite به `ProductDetailId` محک نگاشت شده باشد؛ در غیر این صورت نوشتن متوقف می‌شود.

تست اولیه از `MAHAK_ORDER_PERSON_ID` موجود استفاده می‌کند تا Person تکراری ساخته نشود. status سفارش و پرداخت در توضیحات فاکتور حفظ می‌شود، چون `orderType` نوع سند مالی است. ثبت خودکار Person، PersonAddress و Payment/Receipt و همچنین worker عمومی سفارش تا تأیید نمونه واقعی فعال نیست.
