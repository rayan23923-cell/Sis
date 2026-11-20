# `sis:audit-policy-dates` — فحص تواريخ السياسات (قراءة فقط)

يكشف النتائج المخزنة التي تختلف لقطة سياساتها عن السياسات النافذة في تاريخ سنتها الدراسية (`CourseOffering::policyDate()` = نهاية السنة). هذا عَرَض X-9: نتيجة احتُسبت بسياسات «اليوم».

**لا يعدّل أي نتيجة أو لقطة.** القراءة فقط مفروضة مرتين: كل استعلام غير قراءة يُرفض قبل وصوله للقاعدة، وعلى MariaDB يجري كله داخل `START TRANSACTION READ ONLY`. أي تصحيح يكون بأمر إداري (FR-AR-19)، لا من هذا الأمر.

```bash
php artisan sis:audit-policy-dates                       # كل النتائج
php artisan sis:audit-policy-dates --year=2025-2026      # سنة واحدة
php artisan sis:audit-policy-dates --program=12          # برنامج واحد
php artisan sis:audit-policy-dates --output=/path/report.csv
php artisan sis:audit-policy-dates --format=json           # JSON بدل CSV
php artisan sis:audit-policy-dates --show-student-number # الرقم الجامعي كاملاً (مخفي افتراضياً)
php artisan sis:audit-policy-dates --include-matching    # يضيف المطابق أيضاً
```

## المخرجات
- CSV (UTF-8 مع BOM ليفتح في Excel) أو JSON: سطر لكل (نتيجة × نوع سياسة) مختلف.
- `<report>.meta.json`: وقت الإنشاء، المرشحات، الأعداد حسب التصنيف، هل الأرقام مخفية، وبصمة sha256 للـ CSV.
- الرقم الجامعي يظهر بآخر 3 خانات فقط إلا مع `--show-student-number`. التقرير يبقى على الخادم؛ يُعامل كبيانات طلبة.

## التصنيف
ثلاثة تصنيفات فقط. الأمر لا يقرر أن اختلافاً «صحيح»؛ عمود `likely_legitimate` تلميح للمراجع فقط.

| التصنيف | السبب | المعنى | `likely_legitimate` |
|---|---|---|---|
| مطابق (`match`) | `MATCH` | اللقطة = السياسة النافذة في تاريخ السنة | — |
| مشتبه به (`suspicious`) | `UNKNOWN_POLICY` | النسخة المستخدمة غير موجودة | 0 |
| مشتبه به | `OUT_OF_PERIOD` | النسخة المستخدمة لم تكن نافذة في تاريخ سنة النتيجة (عَرَض X-9) | 0 |
| مشتبه به | `EXPECTED_EXISTED_BEFORE` | النسخة المتوقعة كانت معتمدة قبل الاحتساب ولم تُستخدم | 0 |
| يحتاج مراجعة بشرية (`needs_review`) | `NO_EXPECTED`، `TIMING_UNCLEAR`، `MISSING_IN_SNAPSHOT` | لا يمكن الحكم آلياً | 0 |
| يحتاج مراجعة بشرية | `NEWER_VERSION_AFTER_RESULT` | النسخة المتوقعة اعتُمدت بعد قفل النتيجة | 1 |
| يحتاج مراجعة بشرية | `CORRECTION_KEEPS_ORIGINAL` | تصحيح يحتفظ بسياسة النتيجة الأصلية (FR-AR-19) | 1 |
| يحتاج مراجعة بشرية | `ADDED_AFTER_RESULT` | نوع سياسة أُضيف بعد النتيجة | 1 |

تاريخ السياسة (نهاية السنة) هو السلوك الحالي بانتظار قرار الجامعة (DOC-P3-01 R-3)، وليس قاعدة رسمية.

## الأعمدة
`result_id`، `student` (مخفي)، `academic_year`، `term`، `policy_date`، `program_id`، `course`، `exam_round`، `result_version`، `status`، `is_current`، `computed_at`، `policy_type`، النسخة المستخدمة (`used_policy_id`، `used_version`، `used_effective_from/to`)، المتوقعة (`expected_policy_id`، `expected_version`، `expected_effective_from/to`، `expected_approved_at`)، `classification`، `reason` وترجمتهما، `likely_legitimate`.

## الصلاحيات
- يعمل من سطر أوامر الخادم فقط، لا من الواجهة.
- التقرير تحت `storage/app/reports` (غير منشور)، بصلاحية 0640 داخل مجلد 0750.
