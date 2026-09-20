# ماتریس انتقال داده

این ماتریس بر اساس OpenAPI ذخیره‌شده در `docs/reference/` و قواعد شناخته‌شده دو سیستم است. «قابل انتقال» به معنای وجود endpoint و schema است؛ فعال‌سازی production به mapping کسب‌وکار و تست واقعی نیاز دارد.

## Mahak → Mixin

| داده محک | مقصد Mixin | وضعیت | نکته mapping |
|---|---|---|---|
| ProductCategory | Category | قابل پیاده‌سازی | ساخت والد قبل از فرزند و نگاشت شناسه |
| Product | Product | پیاده‌سازی اولیه | نام، توضیح، ابعاد، وزن |
| ProductDetail | Product/Variant | نیازمند تصمیم | محصول مستقل یا variant بر اساس properties |
| VisitorProduct | Product stock/price | پیاده‌سازی اولیه | منبع اصلی موجودی و قیمت سایت |
| Picture/PhotoGallery | ProductImage | قابل پیاده‌سازی | upload، ترتیب و تصویر پیش‌فرض |
| ProductProperty | Attribute | قابل پیاده‌سازی | تفکیک ویژگی اصلی و ثانویه |
| Person | Customer | قابل پیاده‌سازی | duplicate بر اساس موبایل/کدملی/شناسه خارجی |
| PersonAddress | Customer Address | قابل پیاده‌سازی | شهر، استان، کدپستی و آدرس پیش‌فرض |
| Order | Remote Order | از نظر API ممکن | جهت تجاری معمولاً برعکس است؛ نیازمند تصمیم |

## Mixin → Mahak

| داده Mixin | مقصد محک | وضعیت | پیش‌نیاز |
|---|---|---|---|
| Customer | Person | برنامه‌ریزی‌شده | PersonGroup، personType و جلوگیری از تکرار |
| Address | PersonAddress | برنامه‌ریزی‌شده | نگاشت Person و قواعد آدرس |
| Order | Order | برنامه‌ریزی‌شده | orderType، settlementType، storeId و تاریخ |
| Order item | OrderDetail | برنامه‌ریزی‌شده | نگاشت product/variant به ProductDetail |
| Order payment | Payment/Receipt | برنامه‌ریزی‌شده | روش پرداخت، صندوق/بانک و وضعیت تسویه |
| Order status | وضعیت/گردش سفارش | نیازمند قاعده | mapping وضعیت‌ها و جلوگیری از loop |

## موجودیت‌های قابل مدیریت در Mixin

OpenAPI عملیات نوشتن برای این حوزه‌ها دارد:

- attributes
- brands
- categories
- products و bulk update
- product images و tags
- customers و addresses
- remote orders و order status
- coupons
- shipping methods و profiles

برای Bridge حسابداری، حوزه‌های coupon، shipping configuration و تنظیمات ظاهری فروشگاه خارج از scope پیش‌فرض هستند مگر مشتری صریحاً درخواست کند.

## ترتیب پیشنهادی توسعه

1. تست ایجاد/خواندن/حذف محصول غیرفعال در Mixin.
2. دریافت یک کالای واقعی محک و تأیید mapping قیمت و stock.
3. دسته‌بندی، تصویر و variant.
4. reconcile محصولات موجود Mixin برای جلوگیری از duplicate.
5. Customer و Address از Mixin به محک.
6. Order، OrderDetail و Payment از Mixin به محک.
7. status sync با idempotency و جلوگیری از loop.
