# منابع کامل API

این پوشه snapshot ماشین‌خوان مستندات هر دو سرویس را نگهداری می‌کند تا توسعه، مقایسه schema و تولید تست بدون وابستگی دائم به Swagger UI ممکن باشد.

## فایل‌ها

| فایل | منبع | نسخه اعلامی | Path | Schema | SHA-256 |
|---|---|---:|---:|---:|---|
| `mahak-openapi-v3.json` | Swagger عمومی Mahak API v3 | `V2` در metadata سند | 12 | 74 | `bdc58e0f212942cb4cadcf4264793a55c5a8e4e635eaa0890917316b80c28915` |
| `mixin-openapi-v4.json` | فایل OpenAPI مستندات Mixin | `4.0.0` | 195 | 595 | `572bf3d0b050d6aac8d5472a21853a69b50c451da8b7348c9004ddd21bf8d106` |

تاریخ snapshot: `2026-09-17`.

## نکته مهم درباره نام نسخه محک

metadata فایل Swagger محک مقدار `V2` دارد و خود سند هم endpointهای دارای V2 و بدون V2 را فهرست می‌کند. این موضوع به معنای مجاز بودن endpointهای V2 برای این integration نیست. طبق اعلام مستقیم پشتیبانی محک، Bridge فقط از مسیرهای زیر استفاده می‌کند:

```text
/Sync/Login
/Sync/GetAllData
/Sync/SaveAllData
```

بنابراین OpenAPI مرجع schema است، اما تصمیم عملیاتی endpoint بر اساس راهنمای پشتیبانی ثبت‌شده در [راهنمای محک](../mahak-setup.md) انجام می‌شود.

## بروزرسانی snapshot محک

منبع عمومی:

```text
https://mahakacc.mahaksoft.com/API/v3/swagger/v1/swagger.json
```

بعد از جایگزینی فایل، تغییر schema و endpointها باید review و hash این سند بروزرسانی شود. فایل Mixin باید از مستندات همان نسخه فروشگاه دریافت شود؛ `servers` داخل OpenAPI ممکن است placeholder باشد و دامنه واقعی از `MIXIN_BASE_URL` می‌آید.

## امنیت و منشأ داده

- این فایل‌ها schema و مثال مستندات هستند، نه export داده مشتری.
- فایل‌ها برای شناسه‌ها و credentialهای مشاهده‌شده در محیط واقعی اسکن شده‌اند و موردی در آن‌ها ثبت نشده است.
- هرگز پاسخ واقعی API، token، `.env` یا dump SQLite را در این پوشه commit نکنید.
- تغییر فایل‌های مرجع به‌تنهایی نباید رفتار production را عوض کند؛ تغییر Mapper یا Client نیازمند تست و review جداگانه است.
