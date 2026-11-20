# قواعد البيانات في الاختبارات (B-05)

## الإصدارات المستخدمة

| البيئة | الإصدار | المصدر |
|---|---|---|
| خادم Windows دون اتصال | MariaDB 10.11.10 | `.github/workflows/offline-windows.yml` (`MARIADB_VERSION`) |
| staging على الخادم | MariaDB 11.8 | `deploy/vps/docker-compose.yml` |
| CI: اختبارات سريعة | SQLite في الذاكرة | `phpunit.xml` |
| CI: اختبارات تكاملية | MariaDB 10.11.10 **و** 11.8 | `.github/workflows/tests.yml` (job `mariadb`) |

**القرار الحالي:** الإصداران مدعومان، والمجموعة كاملة تعمل على كل منهما في كل PR. لا يُدمج تغيير يفشل على أي منهما.
توحيد Windows على 11.8 ممكن لاحقاً، لكنه ترقية رئيسية لكل تثبيت قائم (تحتاج `mariadb-upgrade` ونسخة قبلها)، فيُعالج مع خطة التحديث لا هنا.

## الفروقات المعروفة والمختبرة

| الفرق | الأثر | أين يُختبر |
|---|---|---|
| متغير مستوى العزل: 10.11 يعرف `tx_isolation` فقط، و11.1+ يضيف `transaction_isolation` | أي كود يقرأ أحدهما بالاسم يفشل على الآخر | `MariaDbEnvironmentTest::test_row_locks_are_available_inside_transactions` |
| SQLite لا يطبق أطوال الأعمدة ولا الوضع الصارم | قيمة أطول من العمود تمر في SQLite وتفشل في الإنتاج | `test_session_runs_in_strict_mode` |
| SQLite لا يدعم أقفال الصفوف (`FOR UPDATE`) ولا الـ Triggers بنفس الصيغة | اختبارات التزامن (B-06، B-07) والـ Trigger (B-09) لا معنى لها إلا على MariaDB | مجموعة `mariadb` |

## المجموعة `mariadb`

اختبارات بالوسم `#[Group('mariadb')]` تتخطى نفسها على SQLite، وتعمل في job الـ MariaDB. الـ job يشغلها مرة ثانية بـ `--group mariadb --fail-on-skipped`، فيفشل إذا تخطّت نفسها هناك، حتى لا يمر فحص لم يعمل.

بعضها يشغل عملية PHP ثانية حقيقية (عامل `queue:work`، أمر artisan) تقرأ نفس القاعدة، فتستخدم بيانات ملتزمة (`migrate:fresh`) لا معاملة `RefreshDatabase`.

## التشغيل محلياً

```bash
# قاعدة فارغة ومستخدم لها (مثال)
mariadb -uroot -e "CREATE DATABASE sis_test; CREATE USER 'sis'@'localhost' IDENTIFIED BY 'sis'; GRANT ALL ON sis_test.* TO 'sis'@'localhost';"

DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_DATABASE=sis_test DB_USERNAME=sis DB_PASSWORD=sis \
  php vendor/bin/phpunit
```

متغيرات البيئة تتقدم على قيم `phpunit.xml`، فلا حاجة لتعديله. **لا تُستخدم بيانات حقيقية**: الاختبارات تبني بياناتها وتمسحها (`RefreshDatabase`).
