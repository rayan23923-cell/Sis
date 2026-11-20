# `sis:audit-policy-dates` — فحص تواريخ السياسات (قراءة فقط)

يكشف النتائج المخزنة التي تختلف لقطة سياساتها عن السياسات النافذة في تاريخ سنتها الدراسية (`CourseOffering::policyDate()` = نهاية السنة). هذا عَرَض X-9: نتيجة احتُسبت بسياسات «اليوم».

**لا يعدّل أي نتيجة أو لقطة.** القراءة فقط مفروضة مرتين: كل استعلام غير قراءة يُرفض قبل وصوله للقاعدة، وعلى MariaDB يجري كله داخل `START TRANSACTION READ ONLY`. أي تصحيح يكون بأمر إداري (FR-AR-19)، لا من هذا الأمر.

```bash
php artisan sis:audit-policy-dates                       # كل النتائج
php artisan sis:audit-policy-dates --year=2025-2026      # سنة واحدة
php artisan sis:audit-policy-dates --program=12          # برنامج واحد
php artisan sis:audit-policy-dates --output=/path/report.csv
php artisan sis:audit-policy-dates --show-student-number # الرقم الجامعي كاملاً (مخفي افتراضياً)
php artisan sis:audit-policy-dates --include-matching    # يضيف المطابق أيضاً
```

## المخرجات
- CSV (UTF-8 مع BOM ليفتح في Excel): سطر لكل (نتيجة × نوع سياسة) مختلف.
- `<report>.csv.meta.json`: وقت الإنشاء، المرشحات، الأعداد حسب التصنيف، هل الأرقام مخفية، وبصمة sha256 للـ CSV.
- الرقم الجامعي يظهر بآخر 3 خانات فقط إلا مع `--show-student-number`. التقرير يبقى على الخادم؛ يُعامل كبيانات طلبة.

## التصنيف
| التصنيف | السبب | المعنى |
|---|---|---|
| مشبوه | `UNKNOWN_POLICY` | النسخة المستخدمة غير موجودة |
| مشبوه | `OUT_OF_PERIOD` | النسخة المستخدمة لم تكن نافذة في تاريخ سنة النتيجة (عَرَض X-9) |
| مشبوه | `EXPECTED_EXISTED_BEFORE` | النسخة المتوقعة كانت معتمدة قبل الاحتساب ولم تُستخدم |
| يحتاج مراجعة | `NO_EXPECTED`، `TIMING_UNCLEAR`، `MISSING_IN_SNAPSHOT` | لا يمكن الحكم آلياً |
| قد يكون مشروعاً | `NEWER_VERSION_AFTER_RESULT` | النسخة المتوقعة اعتُمدت بعد احتساب النتيجة (قفلها) |
| قد يكون مشروعاً | `CORRECTION_KEEPS_ORIGINAL` | تصحيح يحتفظ بسياسة النتيجة الأصلية (FR-AR-19) |
| قد يكون مشروعاً | `ADDED_AFTER_RESULT` | نوع سياسة أُضيف بعد النتيجة |

«قد يكون مشروعاً» لا يعني «صحيح»: يبقى القرار للجنة المختصة. تاريخ السياسة (نهاية السنة) هو السلوك الحالي بانتظار قرار الجامعة (DOC-P3-01 R-3)، وليس قاعدة رسمية.
