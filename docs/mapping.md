# قرارداد نگاشت داده

## کالا: Mahak → Mixin

| Mahak | Mixin | توضیح |
|---|---|---|
| `Product.name` | `name` | اجباری؛ رکورد بدون نام رد می‌شود |
| `Product.description` | `description` | رشته خالی ارسال نمی‌شود |
| `VisitorProduct.price` یا `ProductDetail.price1` | `price` | اولویت با قیمت ویزیتور؛ تقسیم بر `SYNC_PRICE_DIVISOR` |
| `VisitorProduct.count1` | `stock` | مقدار منفی به صفر تبدیل می‌شود |
| موجودی مثبت | `stock_type=limited` | موجودی صفر `out_of_stock` است |
| وضعیت حذف + موجودی | `available` | رکورد حذف‌شده یا بدون موجودی ناموجود است |
| `ProductDetail.barcode` | `barcode` | اختیاری |
| `ProductDetail.productDetailId` | `product_identifier` | شناسه پایدار detail |
| شناسه‌های Product/Detail | `external_ids` | برای audit و بازیابی mapping |
| ابعاد/وزن Product | همان فیلدها | گرد شده و منفی‌ها صفر می‌شوند |

## موجودیت واسط

محک محصول قابل ارائه به هر سایت را با `VisitorProduct` مشخص می‌کند. joinهای نسخه فعلی:

```text
Product.productId = ProductDetail.productId
ProductDetail.productDetailId = VisitorProduct.productDetailId
VisitorProduct.visitorId = MAHAK_VISITOR_ID
```

`currentVisitorId` نیز در `GetAllData` ارسال می‌شود.

## مشتری و سفارش

سمت Mixin فیلدهای مشتری شامل username، نام، نام خانوادگی، ایمیل، موبایل و کد ملی است. سمت محک Person علاوه بر این‌ها شناسه گروه شخص، نوع شخص و قواعد مالی دارد. تا زمانی که مقادیر پیش‌فرض کسب‌وکار تعیین نشده‌اند، Bridge مشتری را در محک ایجاد نمی‌کند.

ثبت سفارش محک حداقل نیازمند تصمیم درباره موارد زیر است:

- `orderType`
- `settlementType`
- `storeId`
- ارتباط هر Mixin product/variant با `productDetailId`
- شخص موجود یا Person جدید
- تبدیل تاریخ و واحد پول
- وضعیت پرداخت و ساخت Payment/Receipt

این تصمیم‌ها باید پیش از فعال‌سازی `SaveAllData` در محیط production ثبت و تست شوند.
