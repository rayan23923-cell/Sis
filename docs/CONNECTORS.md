# الموصلات (Connectors)

تُربط أنظمة الجامعة الأخرى بنظام الطلبة كما تُربط الموصلات (MCP) في Claude: يربط مدير النظام الموصل أو يلغي ربطه من صفحة **الموصلات**، ويختار أي أدواته تعمل. إلغاء الربط أو إيقاف أداة يُغلق وصولها فوراً.

| الموصل | الاتجاه | الأدوات |
|---|---|---|
| المكتبة (FileNest) | النظام ← FileNest بمفتاح يصدره FileNest | `members_sync` مزامنة القرّاء (كتابة)، `clearance` براءة الذمة (قراءة) |
| نظام الامتحانات | نظام الامتحانات ← النظام بمفتاح يصدره النظام | `rosters` المواد وقوائم الطلبة (قراءة)، `marks_inbox` استلام الدرجات (كتابة) |

## الأمان
- المفتاح الذي يرسله النظام مخزَّن مشفراً (`encrypted`)؛ والمفتاح الذي يستقبله مخزَّن كبصمة `sha256` فقط، ويظهر مرة واحدة عند إصداره.
- كل تغيير على موصل يُسجَّل في سجل التدقيق (بدون المفاتيح)، وكل عملية في `integration_logs`.
- لا استدعاء خارجي داخل طلب المستخدم: مزامنة المكتبة في الطابور كل 30 دقيقة (`sis:connectors-sync`)، وكاملة مرة يومياً.
- **درجات نظام الامتحانات لا تدخل السجل مباشرة**: تُحفظ كدفعة تنتظر تدريسي المادة في صفحة المادة، فيعتمدها (تمر بنفس فحوص الإدخال اليدوي) أو يرفضها.
- معدل الطلبات: 120 طلباً في الدقيقة لكل موصل.

## المكتبة (FileNest)
1. في FileNest: إعدادات المكتبة ← «ربط نظام الطلبة (SIS)» ← إصدار مفتاح.
2. في النظام: الموصلات ← المكتبة ← ربط، ثم الصق عنوان FileNest والمفتاح. يُختبر الاتصال قبل الحفظ.

واجهة FileNest التي يستدعيها النظام (`Authorization: Bearer fnsis_…`):
- `GET /api/sis/v1/manifest` ← `{system: "filenest", tools: [...]}`
- `POST /api/sis/v1/members` ← `{members: [{card_number, name, stage, phone, email, active}]}` (حتى 500). رقم البطاقة = الرقم الجامعي. الطالب غير المستمر يُرسل `active: false`.
- `POST /api/sis/v1/clearance` ← `{cards: [...]}` (حتى 1000) ← `{members: {card: {active_loans, overdue_loans, fines_due}}, missing: [...]}`

تظهر براءة الذمة في صفحة الطالب، وبجانب طلبات «وثيقة تخرج».

## نظام الامتحانات
1. في النظام: الموصلات ← نظام الامتحانات ← ربط. يظهر مفتاح `sisc_…` مرة واحدة.
2. في نظام الامتحانات: ضع عنوان الواجهة `https://<sis>/api/connectors/v1/exams` والمفتاح.

كل الطلبات بترويسة `Authorization: Bearer sisc_…` و `Accept: application/json`.

| الطلب | الأداة | الوصف |
|---|---|---|
| `GET /manifest` | — | النظام والأدوات المفعّلة |
| `GET /offerings?term_id=&updated_since=` | `rosters` | شعب السنة الحالية مع المكونات (`code`, `max_mark`, `exam_round`, `accepts_marks`) |
| `GET /offerings/{id}/students` | `rosters` | الطلبة المشمولون بالدور الحالي: `university_number`, `name_ar`, `shift`, `attempt` |
| `POST /offerings/{id}/marks` | `marks_inbox` | إرسال درجات مكوّن واحد |
| `GET /batches/{id}` | `marks_inbox` | حالة دفعة: `pending`, `applied`, `rejected`, `superseded` |

مثال إرسال الدرجات:
```json
POST /api/connectors/v1/exams/offerings/42/marks
{
  "component": "final",
  "reference": "exam-2026-s1-42-final",
  "marks": [
    {"university_number": "202501001", "mark": 47.5},
    {"university_number": "202501002", "absent": true}
  ]
}
```
الرد `202`: `{batch_id, status: "pending", accepted, unknown: [...]}`. إعادة الإرسال بنفس `reference` تعيد الدفعة نفسها (`200`) ولا تكررها. إرسال جديد لنفس المكوّن يستبدل الدفعة المعلّقة السابقة.

الأخطاء: `401` مفتاح خاطئ أو موصل غير مربوط، `403` الأداة متوقفة، `422` مكوّن غير موجود في الدور الحالي، أو شعبة مرفوعة/مقفلة، أو درجة خارج المدى (`problems` تذكر الأرقام).
