# طراحی API و قراردادهای کلیدی (MVP)

این سند قراردادهای REST برای ماژول‌های MVP را مشخص می‌کند تا پیاده‌سازی و تست با حداقل تفسیر انجام شود. تمام پاسخ‌ها JSON استاندارد با ساختار `{ success: bool, data: any, error?: {code, message, details} }` هستند مگر خلاف آن ذکر شود.

## اصول امنیت و نرخ‌دهی
- احراز هویت: JWT برای اپ موبایل/کلاینت عمومی، nonce برای درخواست‌های داخل WP Admin.
- Rate limit پایه: 30 req/minute per IP برای endpointهای عمومی (رزرو، OTP)، 120 req/min برای داشبورد.
- همه ورودی‌ها sanitize/validate؛ فیلدهای حساس در لاگ mask می‌شوند.

## Appointment
### POST /ms/v1/appointments
- **هدف**: رزرو نوبت (OTP یا کاربر ثبت‌نام‌شده).
- **Body (پیاده‌سازی اولیه فعلی)**:
```json
{
  "patient_name": "Ali Rezai",
  "phone": "+989121234567",
  "service_id": 5,
  "provider_id": 12,
  "slot_time": "2024-06-01 10:00",
  "otp_challenge_id": 15,
  "otp_code": "123456"
}
```
- **Validation**: زمان معتبر، نیازمندی فیلدها، anti-double-booking روی provider+slot_time (پاسخ 409 در تعارض)، در صورت ارسال OTP هر دو فیلد challenge+code باید معتبر باشد.
- **Response**: `id`, `status` (reserved) و `slot_time`.

### GET /ms/v1/appointments
- **هدف**: لیست نوبت‌ها برای منشی/پزشک.
- **Query**:
  - `provider_id` (اختیاری)
  - `status` یکی از `reserved|confirmed|cancelled|noshow`
  - `date_from` / `date_to` (DATETIME)
  - `page`, `per_page` (حداکثر 50)
- **Permission**: `ms_manage_appointments`.
- **Response**: `{ data: [ {id, status, patient_name, phone, provider_id, service_id, slot_time, otp_reference} ], total, page }` مرتب‌شده بر اساس `slot_time` صعودی.

### GET /ms/v1/appointments/{id}
- **هدف**: دریافت جزئیات یک نوبت برای داشبورد.
- **Permission**: `ms_manage_appointments`.
- **Response**: payload مشابه آبجکت داخل لیست.

### PATCH /ms/v1/appointments/{id}/status
- **Body**: `{ "status": "reserved|confirmed|cancelled|noshow" }`
- **Rule (پیاده‌سازی اولیه)**: نیاز به capability `ms_manage_appointments`؛ status به post_status متناظر (`ms_reserved|ms_confirmed|ms_cancelled|ms_noshow`) نگاشت می‌شود.

### GET /ms/v1/availability (پیاده‌سازی فعلی)
- **هدف**: نمایش slotهای رزروشده برای جلوگیری از double-booking سمت کلاینت.
- **Query**: `provider_id` (اجباری)، `date_from`, `date_to` (DATETIME؛ پیش‌فرض از اکنون تا 7 روز بعد).
- **Response**:
```json
{
  "provider_id": 12,
  "date_from": "2024-06-01 00:00:00",
  "date_to": "2024-06-08 00:00:00",
  "busy": ["2024-06-01 10:00:00", "2024-06-02 09:30:00"]
}
```

## Patient & EMR (Mini)
### POST /ms/v1/patients
- **Body (پیاده‌سازی فعلی)**: `{ "full_name":"...", "phone":"...", "national_code":"...", "meta": {"blood_type":"A+"} }`
- **Permission**: `ms_manage_emr`.
- **Response**: `id`, `full_name`, `phone`, `national_code`, `meta`.

### GET /ms/v1/patients
- **Query**: `search` (نام/تلفن)، `phone`, `national_code`, `per_page`, `page`.
- **Permission**: `ms_manage_emr`.
- **Response**: صفحه‌بندی‌شده با کلیدهای `data`, `total`, `page`؛ هر آیتم شامل پروفایل پایه بیمار.

### GET /ms/v1/patients/{id}
- **Permission**: `ms_manage_emr` (پزشک/منشی).
- **Response**: پروفایل پایه با فیلدهای متای ذخیره‌شده.

### POST /ms/v1/visits
- **Body (پیاده‌سازی فعلی)**:
```json
{
  "patient_id": 991,
  "provider_id": 12,
  "summary": "Short visit note",
  "diagnosis": "Sinusitis",
  "medications": ["Azithromycin 250mg", "Nasal spray"]
}
```
- **Permission**: `ms_manage_emr`.
- **Response**: `id`, `patient_id`, `provider_id`, `summary`, `diagnosis`, `medications`, `visit_date` (datetime ذخیره‌شده یا post_date).

### GET /ms/v1/visits/{id}
- **Permission**: `ms_manage_emr`.
- **Response**: Visit payload شامل summary، diagnosis، medications و `visit_date`.

### GET /ms/v1/visits
- **Query**: `patient_id`, `provider_id`, `date_from`, `date_to`, `per_page`, `page`.
- **Permission**: `ms_manage_emr`.
- **Response**: صفحه‌بندی‌شده با آرایه visitها (دارای visit_date) و total/page.

## Payments & Wallet
### POST /ms/v1/payments (پیاده‌سازی فعلی)
- **Body**:
```json
{
  "provider_id": 7,
  "appointment_id": 1201,
  "amount": 480000,
  "method": "online",
  "gateway_ref": "invoice-abc",
  "meta": {"trace": "..."}
}
```
- **Rules**: amount>0 و provider اجباری، متد الزامی (online/cash/...)، پاسخ شامل `id` و status اولیه `pending`.

### POST /ms/v1/payments/webhook (پیاده‌سازی فعلی)
- **Permission**: header `X-MS-Signature` باید با مقدار `ms_payment_webhook_secret` برابر باشد.
- **Body**: `{ "payment_id": 10, "status": "paid|failed|refunded" }`
- **Flow**: بروزرسانی status پرداخت؛ در حالت `paid` کیف پول provider بلافاصله شارژ می‌شود.

### GET /ms/v1/wallets/{provider_id} (پیاده‌سازی فعلی)
- **Permission**: `ms_manage_finance` (منشی/پزشک/مالی).
- **Response**: `{ provider_id, balance, pending_balance }` (در صورت نبود رکورد مقدار صفر برگردانده می‌شود).

## SMS/Automation (پیاده‌سازی فعلی)
### POST /ms/v1/otp
- **Body**: `{ "phone": "+98912...", "context": "booking|login", "provider_id": 12 }`
- **Response**: `challenge_id`, `expires_in`.
- **Notes**: rate limit 3 درخواست در 5 دقیقه برای هر شماره؛ OTP با hash ذخیره می‌شود و در جدول `ms_otps` نگهداری می‌شود؛ پیامک با sender داخلی لاگ می‌شود.

### POST /ms/v1/otp/verify
- **Body**: `{ "challenge_id": 10, "code": "123456", "phone": "+98912...", "context": "booking" }`
- **Response**: `{ "valid": true, "challenge_id": 10 }`
- **Notes**: در صورت انقضا/مصرف، خطای 410 برگردانده می‌شود؛ phone/context در صورت ارسال باید با رکورد اولیه تطبیق داشته باشد.

### POST /ms/v1/automation/rules
- **Body**: rule DSL ساده `{ "event": "appointment.created", "condition": {"status": "pending_payment"}, "action": {"type": "sms", "template": "reminder_24h"}, "delay_minutes": 1440 }`
- **Rule**: فقط نقش‌های دارای `ms_manage_settings` (ادمین/مدیر کلینیک) مجاز؛ ذخیره در جدول `ms_rules`.

## Settings (پیاده‌سازی فعلی)
- **هدف**: ذخیره و بازیابی تنظیمات مطب (اطلاعات کلینیک، ساعات کاری، قالب پیامک، تنظیمات پرداخت پایه).
- **Permission**: `ms_manage_settings`.
- **Endpoints**:
  - `GET /ms/v1/settings` — دریافت تنظیمات ذخیره‌شده (با defaults در صورت نبود).
  - `POST|PUT|PATCH /ms/v1/settings` — بروزرسانی تنظیمات؛ payload نمونه:
```json
{
  "clinic": {
    "name": "Dr. Rezai Clinic",
    "phone": "+9821123456",
    "address": "Tehran, ...",
    "timezone": "Asia/Tehran",
    "working_days": ["sat", "sun", "mon", "tue", "wed"],
    "working_hours": {"start": "08:00", "end": "18:00"}
  },
  "sms_templates": {
    "appointment_confirmation": "Your appointment is confirmed for {date} at {time}",
    "appointment_reminder": "Reminder: appointment on {date} at {time}",
    "otp": "Your verification code is {code}"
  },
  "payment": {
    "currency": "IRR",
    "visit_price": 500000,
    "gateway": "zarinpal",
    "success_url": "https://example.com/success",
    "failure_url": "https://example.com/fail"
  }
}
```


## Support/Ticketing (پیاده‌سازی فعلی)
### POST /ms/v1/support/tickets
- **هدف**: ثبت تیکت پشتیبانی از سمت بیمار/کاربر (بدون نیاز به احراز هویت) با امکان اتصال به patient.
- **Body**:
```json
{
  "subject": "مشکل در پرداخت",
  "message": "بعد از پرداخت خطا دیدم.",
  "patient_id": 123,
  "phone": "+98912...",
  "email": "patient@example.com",
  "priority": "normal|high"
}
```
- **Response**: `id`, `subject`, `status` (open), `priority`, `patient_id`, `phone`, `email`, `messages[]` (با پیام اولیه).

### GET /ms/v1/support/tickets
- **Permission**: `ms_manage_support` (منشی/پزشک/ادمین).
- **Query**: `status`, `patient_id`, `per_page`, `page`.
- **Response**: صفحه‌بندی‌شده با آرایه تیکت‌ها و meta `{total, totalPages, page, per_page}`.

### GET /ms/v1/support/tickets/{id}
- **Permission**: `ms_manage_support`.
- **Response**: جزئیات تیکت و آرایه پیام‌ها.

### POST /ms/v1/support/tickets/{id}/messages
- **Permission**: `ms_manage_support`.
- **Body**: `{ "message": "پاسخ منشی..." }` — پیام به آرایه پیام‌ها اضافه می‌شود و شامل meta نویسنده (کاربر فعلی یا مهمان) و زمان ایجاد است.
- **Response**: تیکت به‌روزشده.

### PATCH /ms/v1/support/tickets/{id}/status
- **Permission**: `ms_manage_support`.
- **Body**: `{ "status": "open|pending|resolved|closed" }`.
- **Response**: تیکت به‌روزشده.


## Growth/Marketing (پیاده‌سازی فعلی)
### POST /ms/v1/reviews
- **Body**: `{ "provider_id": 22, "rating": 5, "title": "Great visit", "comment": "...", "patient_name": "Ali" }`
- **Permission**: عمومی (برای سناریوهای رزرو سریع یا بیمار لاگین‌نشده) اما باید همراه با rate-limit در gateway باشد.
- **Response**: داده review ذخیره‌شده با `id`, `provider_id`, `rating`, `title`, `comment`, `patient_name`, `created_at`.

### GET /ms/v1/reviews
- **Query**: `provider_id` اختیاری برای فیلتر، `per_page`, `page`.
- **Permission**: `ms_manage_growth` (پزشک/منشی) برای جلوگیری از اسپم خواندن عمومی.
- **Response**: لیست صفحه‌بندی‌شده از بررسی‌ها با total/page.

### POST /ms/v1/profile-views
- **Body**: `{ "provider_id": 22 }`
- **Permission**: عمومی (با rate-limit لایه gateway/CDN).
- **Effect**: شمارنده بازدید پروفایل پزشک در جدول `ms_profile_stats` یک واحد افزایش می‌یابد.

### GET /ms/v1/providers/{provider_id}/growth
- **Permission**: `ms_manage_growth`.
- **Response**: `{ provider_id, views, bookings, avg_rating, reviews }` با داده‌های جدول stats و خلاصه امتیازدهی.

## خطاهای استاندارد
- `400`: validation_error (جزئیات فیلد)
- `401`: unauthorized / invalid_token / otp_required
- `403`: forbidden (capability یا مالکیت داده)
- `404`: not_found
- `409`: conflict (double_booking_detected, payment_already_processed)
- `429`: rate_limit
- `500`: server_error

## وبهوک‌ها (برای اکوسیستم خارجی)
- Endpoint تنظیم‌شدنی، با secret و signature.
- رخدادهای کلیدی: `appointment.created`, `appointment.cancelled`, `payment.succeeded`, `payment.failed`, `prescription.issued`.
- ارسال مجدد (retry) با backoff تا 5 بار؛ لاگ وضعیت ارسال.

### پیاده‌سازی فعلی (API Gateway Module)
- `GET /ms/v1/webhooks` — لیست hookهای فعال/غیرفعال (نیاز به `ms_manage_gateway`)
- `POST /ms/v1/webhooks` — ایجاد webhook جدید با فیلدهای `name`, `target_url`, `event`, `secret?`, `status?` (نیاز به `ms_manage_gateway`)
- `DELETE /ms/v1/webhooks/{id}` — حذف یک webhook (soft delete در MVP نیست) (نیاز به `ms_manage_gateway`)
- `POST /ms/v1/webhooks/test` — ارسال رویداد تست/دلخواه به همه hookهایی که event یکسان دارند و گزارش کد پاسخ را برمی‌گرداند (نیاز به `ms_manage_gateway`)
- HMAC امضای payload: هدر `X-MS-Signature` برابر `hash_hmac('sha256', body, secret)` و `X-MS-Event` برای نام رخداد؛ بدنه JSON شامل `{ event, payload, sent_at }`.
- رویدادهای جاری: `appointment.booked` و `appointment.status_changed`.

## Directory (Providers & Services)

- `GET /ms/v1/providers` — لیست پزشکان/ارائه‌دهندگان با فیلتر specialty، جست‌وجوی متنی، pagination (`per_page`, `page`).
- `GET /ms/v1/providers/{id}` — جزئیات پزشک به‌همراه لیست خدمات.
- `POST /ms/v1/providers` — ایجاد/به‌روزرسانی پزشک (نیاز به `ms_manage_directory`) با فیلدهای `name`, `bio`, `specialties[]`, `rating`.
- `POST /ms/v1/services` — ثبت خدمت جدید برای پزشک (نیاز به `ms_manage_directory`) با `provider_id`, `title`, `duration`, `price`, `description`.
- `GET /ms/v1/providers/{id}/services` — لیست خدمات فعال یک پزشک.

### Notes
- همه متادیتا در meta post ذخیره می‌شود (`ms_specialties`, `ms_rating`, `ms_provider_id`, `ms_duration`, `ms_price`).
- تمامی endpoints GET عمومی هستند؛ endpoints ایجاد نیازمند capability `ms_manage_directory` است.
