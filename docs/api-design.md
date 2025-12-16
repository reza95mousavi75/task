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
  "slot_time": "2024-06-01 10:00"
}
```
- **Validation**: زمان معتبر، نیازمندی فیلدها، anti-double-booking روی provider+slot_time (پاسخ 409 در تعارض).
- **Response**: `id`, `status` (reserved) و `slot_time`.

### PATCH /ms/v1/appointments/{id}/status
- **Body**: `{ "status": "reserved|confirmed|cancelled|noshow" }`
- **Rule (پیاده‌سازی اولیه)**: نیاز به capability `edit_posts`؛ status به post_status متناظر (`ms_reserved|ms_confirmed|ms_cancelled|ms_noshow`) نگاشت می‌شود.

### GET /ms/v1/providers/{id}/availability
- **Query**: `from`, `to` (ISO datetime)
- **Response**: آرایه slotها `{slot_id, start, end, capacity, remaining}` با فیلد `branch_id` و `service_id`.

## Patient & EMR (Mini)
### POST /ms/v1/patients
- **Body**: `{"full_name":"...", "phone":"...", "national_code":"...", "otp":"123456"}` (OTP اختیاری در حالت reception entry)
- **Response**: `patient_id`, `is_new`, `jwt` (اگر ثبت‌نام کامل شد).

### GET /ms/v1/patients/{id}/records
- **Response**: پروفایل + آرایه ویزیت‌ها `{visit_id, diagnosis, meds[], created_at}` و لینک‌های فایل امن.

### POST /ms/v1/visits
- **Body**:
```json
{
  "appointment_id": 991,
  "provider_id": 12,
  "diagnosis": "Sinusitis",
  "recommendations": "Rest + nasal spray",
  "meds": [
    {"drug_id": 101, "dose": "2x/day", "duration": "5d"}
  ]
}
```
- **Response**: `visit_id`, `prescription_pdf_url`.

## Payments & Wallet
### POST /ms/v1/payments/webhook
- **Body نمونه** (درگاه فرضی):
```json
{ "payment_id": "abc123", "appointment_id": 991, "status": "succeeded", "amount": 500000, "signature": "..." }
```
- **Flow**: signature validation → idempotency (key=`payment_id`) → بروزرسانی `appointments.payment_status` → افزایش `wallet.pending_balance` → انتشار event `payment.succeeded`.

### GET /ms/v1/reports/finance/daily
- **Query**: `date=YYYY-MM-DD`, `provider_id`
- **Response**: جمع رزروها، پرداخت آنلاین، نقدی، کنسلی با جریمه؛ برای داشبورد منشی/پزشک.

## SMS/Automation
### POST /ms/v1/otp
- **Body**: `{ "phone": "+98912...", "context": "booking|login", "provider_id": 12 }`
- **Response**: `challenge_id`, `expires_in`.
- **Notes**: rate limit per phone/day؛ OTP hash در DB نگهداری و پس از مصرف حذف.

### POST /ms/v1/automation/rules
- **Body**: rule DSL ساده `{ "event": "appointment.created", "condition": {"status": "pending_payment"}, "action": {"type": "sms", "template": "reminder_24h"}, "delay_minutes": 1440 }`
- **Rule**: فقط admin/provider مجاز؛ ذخیره در جدول `wp_ms_rules`.

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
