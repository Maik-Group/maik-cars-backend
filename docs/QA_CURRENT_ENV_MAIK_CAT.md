# Maik Cat — خطة الاختبار الشامل على البيئة الحالية

**التاريخ:** 2026-11-01
**القاعدة المستخدمة:** MySQL المحلية `maik_cat_final_pro` (بدون إنشاء قاعدة أخرى).
**Backend المحلي:** `http://127.0.0.1:8018` على الجهاز، `http://10.0.2.2:8018` من محاكي Android.
**Android:** محاكي `emulator-5554`، الحزمة `maik.cat.android`.
**Frontend:** Metro على `localhost:8081`، و `adb reverse tcp:8081 tcp:8081`.
**النسخة الاحتياطية قبل التغيير:** `C:\laragon\www\maik\backups\maik_cat_final_pro_before_subscriptions_20261101_155131.sql` (12,959,044 bytes).

## الضوابط العامة

1. عدم تنفيذ `migrate:fresh` أو حذف المستخدمين والمنتجات أو استعادة قاعدة البيانات فوق الحالة الحالية. المعاملات التجريبية تُنفذ فقط بحسابات QA واضحة الاسم، وبعد توثيق التغييرات والقدرة على عكسها إن أمكن؛ سجلات المحفظة المالية **لا تُحذف** بغرض تنظيف الاختبار.
2. البيئة الحالية لا تحتوي على عروض Stripe منشورة أو أرصدة مستخدمين. لا نُنشئ أسعارًا تجارية تقديرية ولا ننفّذ عملية دفع حقيقية، ولا نمنح نقاطًا لمستخدم فعلي دون اختياره صراحةً.
> Historical QA-only environment switches referenced in this section were removed. Stripe mode now derives from STRIPE_SECRET_KEY; this historic snapshot does not describe current configuration.
5. قبل أي تعديل QA مالي على المستخدمين: سجل `user_id`، السبب، عدد النقاط، `environment`، الصلاحية، المرجع، ثم تحقّق من ledger قبل/بعد. لا ترسل push أو بريد جماعي أثناء QA.

## مراحل التنفيذ ونتائجها

| المرحلة | خطوات الاختبار والتوقعات | الحالة |
|---|---|---|
| Q00 — سلامة البيانات | نسخة MySQL، عدد المستخدمين/المنتجات قبل/بعد، `migrate:status`، صلاحيات Filament | **PASS جزئي**: النسخة محفوظة؛ 7 migrations Ran؛ 19 permission؛ يوجد مستخدمان و10,298 منتجًا. لا توجد حسابات QA مستقلة بعد |
| Q01 — الاتصال | فتح `GET /api/v1/billing/plans`، اختبار `GET /api/v1/wallet` بلا token | **PASS**: 200 مع قوائم عروض فارغة وexternalCheckoutAllowed=false؛ 401 للمحفظة غير الموثقة |
| Q02 — إعداد Android | بناء آخر debug APK، تثبيته، تشغيله على emulator-5554، التأكد من وصوله فقط إلى `10.0.2.2:8018` وعدم وجود crashes | **محجوب بأدوات البناء**: المحاكي شغّال لكن Maik Cat لم يُثبت؛ NDK 27 مطلوب وغير موجود؛ مكتبة AndroidX 2.9.0 لم تُحلّ من Maven المحلي أو المصدر الأساسي |
| Q03 — الحساب والهوية | التسجيل، تسجيل الدخول/الخروج، نسيان كلمة المرور، صلاحية session، حد الأجهزة وإعادة تعيين الجهاز، تعطيل الحساب | **معلق** على إتمام محاكي QA وتوفير حساب اختبار لا يخص مستخدمًا فعليًا |
| Q04 — واجهة التطبيق | Home، Tabs، البحث، التطبيع والحرفين، مجموعات السيارات، التصنيفات، Saved Products، Settings، Profile، Notifications | **معلق** على التحقق اليدوي على المحاكي |
| Q05 — الحماية والرصيد الصفري | السعر والمعادن محجوبة بـGET، رصيد 0 لا يعرض valuation، تظهر شاشة الاشتراكات، الاستثناءات لا تكشف قيمًا | **PASS آليًا / معلق على شاشة المحاكي** |
| Q06 — خصم تقييم واحد | POST valuation يخصم 1 فقط لأول serial normalized/UTC day، الوصول لنفس العائلة في اليوم ذاته لا يخصم مجددًا؛ 402 عند النفاد | **PASS في الاختبارات المعزولة / معلق على حساب QA الحالي** |
| Q07 — صلاحية الإيصال | validUntil قصير (90 ثانية)، freeReopenUntil نهاية يوم UTC، revalidate_date يعيد الإيصال فقط، 409 بعد منتصف الليل أو لعائلة جديدة، no GET debit | **PASS في الاختبارات المعزولة** |
| Q08 — ترتيب استهلاك النقاط | paid_cycle ثم complimentary/free بحسب انتهاء الصلاحية ثم purchased_topup؛ رفض negative balance؛ عدم إعادة استخدام receipt ملغى | **PASS في اختبارات المحفظة** |
| Q09 — منح مجانية فردية | admin يمنح points محددة، مع اختيار انتهاء اختياري؛ ledger قابل للتدقيق؛ revoke لا يلغي grants أخرى؛ صلاحية admin | **PASS آليًا / معلق على QA عبر Filament** |
| Q10 — اشتراك مجاني | once وper_period، الشهور 1/3/6/12، تاريخ أصلي مثل 31 يناير، قصر المدة حتى ends_at، بيئة ثابتة، لا انتقال مجاني للمستخدمين القدامى | **PASS آليًا / معلق على QA عبر Filament** |
| Q11 — المنح الجماعية | اختيار محددين أو كل App Users الموجودين لحظة Preview، hash confirmation، 50 كل دفعة، إلغاء، استئناف، فشل دون تكرار، إعادة فحص صلاحية المدير | **PASS آليًا / معلق على QA باللوحة** |
| Q12 — خطط Stripe | إضافة Draft من Filament، تحقق product/price/currency/term بالـProvider، publish يمنع التعديل، retired hidden، لا checkout لسعر غير محقق | **PASS بمعزل Stripe Mock / معلّق على Stripe Test** |
| Q13 — Checkout | idempotency، عقد شراء immutable، eligibility للبلد والمنصة، success/cancel لا يمنحان نقاطًا؛ pending status؛ app link foreground/cold start | **PASS جزئيًا آليًا / موافقات المتجر والربط HTTPS معلقة** |
| Q14 — التجديد والنقاط الإضافية | فاتورة مدفوعة واحدة لكل دورة، 1/3/6/12 أشهر، paid-first spend، top-up لا تنتهي صلاحيته؛ إعادة webhooks لا تتسبب بمنح مضاعف | **PASS بالـMock / E2E Stripe Test معلّق** |
| Q15 — أحداث المال الخطرة | webhook signature، encrypted durable event، refund pending hold، failed release، succeeded revoke، partial review، dispute متعدد، audit/recovery | **PASS آليًا / E2E Stripe Test معلّق** |
| Q16 — الإدارة وإيقاف الاشتراك | Portal، cancel-at-period-end، تغيير الخطة في التجديد التالي، عدم إسقاط نقاط الفترة الحالية، الصلاحيات والتدقيق | **معلق على Stripe Test وQA لوحة الإدارة** |
| Q17 — تعدد اللغات | AR/EN/BG/DE/HR، RTL للعربية، الأسعار، أزرار الدفع والرصيد، نصوص النفاد، اختلاف المنطقة الزمنية | **فحص ملفات اللغات وTypeScript ناجح / المرئي معلّق** |
| Q18 — جودة وتجربة الاستخدام | dark/light إن كان مدعومًا، شاشات 540x1200 وأصغر، offline/slow network، background/foreground، التحديث بعد الدفع، إشعارات، عدم تسرب قيم مع cache | **معلق على محاكي QA** |
| Q19 — السلامة والأداء | الطلب المتزامن 20+ لنفس المستخدم/العائلة، عدم ازدواج الاستهلاك، MySQL lock contention، queue/scheduler، retries، audit ledgers | **اختبارات وحدات ناجحة / تزامن MySQL حقيقي يحتاج تنفيذًا مضبوطًا** |

## سجل التحقق الفعلي — 1 نوفمبر 2026

- **MySQL الحالي:** جميع migrations السبع الجديدة ناجحة في batch 13؛ `migrate:status` لا يعرض Pending. عدد المستخدمين الحاليين 2، والمنتجات 10,298، ولم تُنشأ أرصدة أو بيانات Stripe مدفوعة أثناء اختبار الاتصال.
- **حساب اختبار الواجهات:** قاعدة البيانات لا تحتوي حاليًا على أي مستخدم App User نشط (يوجد حساب واحد بدور `super_admin`). لا نستخدم حساب المدير كتجربة لمحفظة المستخدم؛ يلزم إنشاء مستخدم QA معرّف بوضوح على القاعدة الحالية أو استخدام حساب اختبار يحدده صاحب المشروع قبل إتمام خطوات Q03 وQ05–Q11 يدويًا.
- **صلاحيات الإدارة:** `RolesAndPermissionsSeeder` نجح؛ 19 صلاحية منها أربع للمنح والإلغاء، و41 ربط صلاحية-دور.
- **API عبر الخادم الصحيح 8018:** `GET /api/v1/billing/plans` = 200 مع `externalCheckoutAllowed=false`، `GET /api/v1/wallet` = 401، `GET /api/v1/wallet/transactions` = 401، `GET /api/v1/billing/subscription` = 401، `GET /billing/return` = 200، وWebhook دون توقيع صالح = 400.
- **تدقيق المحفظة:** `stripe:audit-wallet` = 0 مصادر حالية، 0 اختلافات؛ لم تُستعمل مفاتيح دفع حقيقية.
- **الاختبارات الآلية:** Backend: 89/89، 479 assertions في آخر suite مجمّعة؛ Mobile: TypeScript PASS، Jest 9/9، build environment 5/5. تم إصلاح تعريف نوع `fetch` في الاختبار لضمان فحص TypeScript.
- **المحاكي:** `emulator-5554` متصل ويعمل، ومعماريته `x86_64`. ملف APK القديم المتاح من 22 أكتوبر مخصص `arm64-v8a` ولا يمكن تثبيته على هذا المحاكي. بناء نسخة debug جديدة احتاج NDK 27 غير مثبت؛ جرّبنا NDK 28 المثبت بإعداد مؤقت خارج المستودع. يلزم تأكيد نجاح البناء والتثبيت قبل اعتبار Q02 ناجحًا.
- **اعتماديات البناء:** Gradle لم يستطع تحميل `androidx.lifecycle:lifecycle-common-java8:2.9.0`؛ رد Maven Central بـHTTP 403. اختُبر مصدر Maven بديل قابل للوصول عبر إعداد مؤقت خارج المستودع، دون إضافته إلى ملفات الإنتاج. لا يعدّ هذا دليلًا على نجاح التثبيت؛ يبقى اختبار واجهة المستخدم على المحاكي محجوبًا حتى بناء APK بملفات x86_64.

## أوامر التحقق القابلة للتكرار

```powershell
# المسار: C:\laragon\www\maik\maik-cat-backend
php artisan migrate:status
php artisan route:list --path=api/v1/billing
php artisan route:list --path=api/v1/wallet
php artisan test tests/Feature/Billing tests/Feature/Credits tests/Feature/Subscriptions tests/Feature/FilamentAccessTest.php tests/Feature/AppUserDeviceBindingFilamentTest.php
php artisan stripe:audit-wallet
php artisan schedule:list

# المسار: C:\laragon\www\maik\maik-cat-mobile
$env:EXPO_PUBLIC_APP_ENV='local'
$env:EXPO_PUBLIC_API_BASE_URL='http://10.0.2.2:8018'
$env:EXPO_PUBLIC_API_DEV_BASE_URL='http://10.0.2.2:8018'
$env:EXPO_PUBLIC_SUBSCRIPTIONS_ENABLED='true'
$env:EXPO_PUBLIC_STRIPE_PURCHASE_CONTEXT_ID=''
# يجب تشغيل PHP artisan serve --host=127.0.0.1 --port=8018 بالتوازي
# لا تغيّر ملف .env المنتج، ولا توجّه محاكي QA إلى maikcat.com
adb reverse tcp:8081 tcp:8081
npx expo start --dev-client --localhost --port 8081
npx tsc --noEmit
npm test -- --silent
npm run test:build-environment
adb devices -l
adb shell am start -n maik.cat.android/.MainActivity
```

## معايير القبول

- **وظائف عامة:** لا أخطاء تحميل حرج ولا API 500، وتعمل الواجهات الأساسية على المحاكي مع الخادم وقاعدة البيانات الحاليين.
- **نقاط وفوترة:** لا تسريب لقيم محمية قبل التقييم، لا خصومات مضاعفة، لا grants بدون Payment مؤكد أو قرار إداري موثق، لا خلط بين بيئتين، ولا إلغاء لرصيد مصدر آخر.
- **إدارة:** صلاحيات واضحة، السجلات مفسَّرة، المنح الجماعية قابلة للاستئناف والإيقاف دون مضاعفة.
- **إطلاق:** لا يُفعّل Checkout أو رابط شراء داخل تطبيق المتجر قبل الموافقات القانونية/التقنية وربط الـHTTPS واختبار Stripe Test E2E. نجاح SQLite/Jest لا يعادل موافقة المتجر.

## قائمة الفجوات المفتوحة

- صفر عروض Stripe منشورة وصفر Grants على قاعدة البيانات الحالية؛ إعداد الأسعار/الخطة التجارية يحتاج بيانات رسمية، لا تخمين.
- لا توجد مفاتيح Stripe Test أو تعريف webhook مؤكد في هذه الجولة، لذا لا يوجد دفع/تجديد حي خاضع للاختبار.
- الوصول لحساب App User صالح لاختبار الجلسات والرصيد والتقييم على المحاكي لم يتم حسمه؛ لا نستخدم كلمات مرور المستخدمين الفعليين ولا نعدل أرصدتهم تلقائيًا.
- الموافقة الخاصة بالروابط الخارجية في Apple/Google وتكوين HTTPS App Links على الدومين لم تُثبت؛ شراء Native مغلق افتراضيًا.

---

## التحديث النهائي لتجربة QA على البيئة الحالية — 1 نوفمبر 2026

**هذا القسم أحدث من نتائج الجدول وسجل المحاولة الأول أعلاه، ويلغي أوصاف «معلّق» القديمة فيما تم إنجازه لاحقًا.**

### التهيئة والبيانات المنفذة

- استُخدمت قاعدة **MySQL المحلية الحالية `maik_cat_final_pro`** نفسها دون إنشاء قاعدة جديدة أو تشغيل `migrate:fresh`. السبعة migrations الجديدة كلها `Ran` ضمن batch 13. بقيت المنتجات الأصلية البالغ عددها 10,298، وبقي المستخدمان الأصليان دون منحهم أي رصيد تجريبي.
- نسخة SQL قبل التعديلات: `C:\laragon\www\maik\backups\maik_cat_final_pro_before_subscriptions_20261101_155131.sql`. نسخة `.env` قبل تغيير إعدادات QA: `C:\laragon\www\maik\backups\maik_backend_env_before_qa_20261101_163530.env`؛ يحتوي هذا الأخير على أسرار البيئة ويجب عدم مشاركته.
> Historical QA-only environment switches referenced in this section were removed. Stripe mode now derives from STRIPE_SECRET_KEY; this historic snapshot does not describe current configuration.
- **تنبيه:** فرض الاشتراك وحجب السعر مفعّل في هذا الخادم المحلي للجميع، وليس لمستخدمي QA فقط. أما الأرصدة التجريبية فهي محصورة بمستخدمي QA ضمن `sandbox` ولا تمنح أرصدة حقيقية لبقية المستخدمين. للرجوع إلى إعدادات ما قبل QA استخدم النسخة الاحتياطية للبيئة ثم `php artisan config:clear` بعد انتهاء الاختبار وبقرار صريح.
- Seeder مستقل قابل للتكرار دون مضاعفة الأرصدة: `php artisan db:seed --class=MaikCatQaBillingSeeder --force`. أنشأ 11 مستخدمًا خاصًا بالاختبار، أربعة خطط من 1/3/6/12 شهرًا، ثلاث باقات Top-up وهمية وغير منشورة، حالة مجانية دورية وأخرى لمرة واحدة، ومحفظة فارغة، وأرصدة مدفوعة *محاكاة محلية*، ورصيد مختلط، وانتهاء/إلغاء، وإيقاف مصدر. كما اختبر دفعة إدارية تمنح ثلاث نقاط لمستخدم QA واحد وحالة أخرى ألغيت دون منح 99 نقطة.
- أرصدة المستخدمين التجريبية عند التهيئة: `empty=0`, `free=7`, `paid=15` (أصبحت **14** بعد أول طلب تقييم فعلي)، `topup=10`, `mixed=17`, `complimentary=6`, `once=4`, `expired=0`, `held=2`, `bulk=3`, `admin=0`.
- الحسابات وعناوين بريدها وكلمات المرور **محفوظة محليًا فقط** في `storage/app/private/maik-qa-credentials.json`. لا تُنسخ كلمات المرور إلى توثيق عام أو Git، ولا تنشأ أسعار Stripe موثّقة اصطناعيًا. حسابات QA موسومة `qa-maik-*@example.test`، باستثناء حساب المدير التجريبي الذي له الصلاحيات اللازمة فقط للاختبار.

### نتائج الاختبار المنفذ فعلًا

- **MySQL + HTTP:** سكربت `powershell -NoProfile -ExecutionPolicy Bypass -File scripts\qa-current-db-smoke.ps1` نجح **33/33 فحصًا** مع الخادم على `127.0.0.1:8018`؛ اشتمل على تسجيل الدخول/الخروج، صلاحيات 401، السعر المحجوب على GET، رصيد صفر مع 402، منع Checkout غير الموثق 403، عرض عروض الاختبار مع `checkoutEligible=false`، كشف تقييم واحد (15→14)، إعادة الكشف بلا خصم مضاعف، التحقق من الاشتراك المجاني لمرة واحدة/الدوري، المصدر المعلق، والدفعة الجماعية.
- `php artisan stripe:audit-wallet`: **13 مصدر محفظة، صفر اختلافات**. لم تُنشأ دفعات Stripe حقيقية ولا أحداث دفع موثقة.
- **React Native:** تصحيح عدم توافق `camelCase` بين middleware Laravel وقراءة wallet/valuation في التطبيق، مع تجاهل الأرصدة المعلقة/المنتهية والملغاة. `npx tsc --noEmit` ناجح، `npm test -- --silent` **12/12 اختبارًا** (أربع مجموعات)، و`npm run test:build-environment` **5/5**.
- **Android المحاكي:** أُنتج APK جديد معماريته `x86_64` وثُبِّت على `emulator-5554`، وتصل Metro على 8081 والخدمة المحلية على `10.0.2.2:8018`. فحص Android UI hierarchy تحقق من شاشة الدخول ثم الصفحة الرئيسية وأسعار المعادن والتنقل؛ الدخول الحقيقي بمستخدم `mixed` نجح، وصفحة الاشتراكات عرضت **17 = 5 paid + 4 free + 8 topups**، وخطط الشهر/الربع/النصف/السنة والشحن الإضافي مع تعطيل الدفع. تسجيل الخروج أزال Sanctum token من قاعدة البيانات. ثم نجح تسجيل الدخول بمستخدم `empty`، وظهرت شاشة **0 credits** ورسالة **Your credits are exhausted** وحظر العرض بدون رصيد. لم يظهر crash حرج أو خطأ `ReactNativeJS:E` خلال هذه الرحلة.
- **حدود التحقق المرئي:** محاكي Google ATD الذي يعمل على الجهاز يُعيد صور screencap سوداء، لذلك تم التأكد من ظهور عناصر النص والصفحات عبر `adb shell uiautomator dump` وليس فحصًا بصريًا دقيقًا لمواقع العناصر والصور والألوان. يلزم جهاز أو محاكٍ رسومي آخر لاختبار Pixel/RTL الكامل.

### مهام QA لا تزال خارج نطاق النجاح الحالي

- لم يُجر اختبار Checkout/renewal/refund حقيقي عبر Stripe Test لغياب بيانات تفعيل Test ومطابقة Price/Product والـwebhook الآمن. ما زال **الشراء مغلقًا**، ونجاح محاكاة النقاط لا يعني نجاح الربط الخارجي أو موافقة متاجر Apple/Google.
- اختبارات كل اللغات الخمس والـRTL، الإشعارات، تعطل الشبكة، التزامن الفعلي العالي على MySQL، والمسار الإداري اليدوي المتكامل عبر Filament لم تُستكمل بشكل شامل بعد. توجد اختبارات وحدات تغطي بعض العقود، لكنها ليست بديلًا عن E2E.
- اختبار السعر الفعلي عبر التطبيق أثبت الحجب لحساب الرصيد الصفري. يلزم تكرار رحلة الواجهة المرئية على منتجات مختلفة وعائلات serial وبتواريخ UTC مختلفة وعلى أكثر من مقاس شاشة.

### إعادة تشغيل الاختبار

```powershell
# في C:\laragon\www\maik\maik-cat-backend
php artisan migrate:status
php artisan db:seed --class=MaikCatQaBillingSeeder --force
php artisan stripe:audit-wallet
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\qa-current-db-smoke.ps1

# في C:\laragon\www\maik\maik-cat-mobile
npx tsc --noEmit
npm test -- --silent
npm run test:build-environment
adb devices -l
adb reverse tcp:8081 tcp:8081
# لتبديل مستخدم المحاكي: افتح Settings ثم Logout وأكّد؛ بعد ذلك
# من backend، دون عرض كلمة المرور:
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\qa-emulator-login.ps1 -Scenario mixed
# سيناريو الرصيد الصفري:
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\qa-emulator-login.ps1 -Scenario empty
```
---

## اختبار Android الفعلي على المحاكي — 1 نوفمبر 2026

**ملاحظة:** هذه الجولة منفذة باستخدام شاشة التطبيق و `adb shell input` و `uiautomator dump` على `emulator-5554`، مع التحقق من معاملات قاعدة MySQL المحلية الفعلية، وليست محاكاة HTTP وحدها أو Jest.

| الاختبار | التنفيذ والدليل | النتيجة |
|---|---|---|
| بدء التشغيل | APK `maik.cat.android` بمعمارية `x86_64` وMetro `127.0.0.1:8081` وLaravel API `127.0.0.1:8018` | PASS |
| الرصيد الصفري | QA user 3، ظهرت شاشة `0 credits available` ورسالة نفاد النقاط | PASS |
| حجب التسعير | فتح أول منتج من Home بعرض محجوب، وزر `Subscriptions` بدل تحليل المعادن | PASS |
| تحويل الاشتراك | الضغط على `Subscriptions` فتح صفحة الاشتراكات | PASS |
| Checkout مغلق | الضغط على Subscribe في صفحة العرض التجريبي لم ينشئ سجلًا في `billing_orders`؛ العدد بقي 0 | PASS |
| منع خصم الرصيد الصفري | `credit_consumptions` للمستخدم QA 3 بقيت صفرًا | PASS |
| تسجيل خروج/دخول | تسجيل خروج QA empty ألغى Sanctum token ثم تسجيل دخول QA free (id=4) بنجاح من المحاكي | PASS |
| البحث الحقيقي | البحث `50527957` عرض منتج FIAT وفتح صفحته | PASS |
| الكشف الأول | ضغط `View price and analysis` أظهر **$203.24**، وخفض رصيد QA free من **7 إلى 6** وسجّل استهلاكًا واحدًا | PASS |
| إعادة كشف نفس العائلة | العودة إلى نتائج البحث ثم إعادة فتح `50527957` وتقييمه أظهرت السعر نفسه من دون خصم ثانٍ (6 نقاط، استهلاك واحد) | PASS |
| سرية القيم بالخلفية | Home ثم العودة إلى التطبيق أخفت السعر والتحليل، وتطلب كشفًا جديدًا؛ بعد الكشف عاد السعر ولم تتغير نقاط المستخدم (6) | PASS |
| اختلاف العائلة | فتح منتج مرتبط برمز مختلف **46449335**، وضغط كشفه عرض **$453.13** وخفض الرصيد **6 إلى 5** | PASS |
| سلامة المحفظة | `credit_consumptions` ودفتر spend سجّل كل منهما **سجلين بالضبط** لعائلتين مختلفتين | PASS |
| تحليل المعادن | بعد كشف 50527957 ظهرت `kg=0.92`، `Pd ppm=5302.5`، `Rh ppm=467.25` داخل واجهة التفاصيل | PASS |
| العربية/RTL | من Settings تم اختيار العربية وحفظها وظهور رسالة النجاح؛ انعكست مواضع عناصر التنقل وأصبح قسم الاشتراكات عربيًا | PASS |
| توحيد الرصيد بعد الترجمة | عرضت صفحة الاشتراكات بالعربية `الرصيد المتاح: 5 نقطة` متطابقًا مع MySQL | PASS |
| Crash / JS errors | لم تُظهر سجلات `ReactNativeJS:E` و`AndroidRuntime:E` أخطاء أثناء الجولات المرصودة | PASS |
| تدقيق المحفظة | `php artisan stripe:audit-wallet`: **13 source، 0 inconsistencies** | PASS |
| Mobile Jest + TypeScript | `npx tsc --noEmit` PASS، `npm test -- --silent` **4 suites / 12 tests passed** | PASS |

### النتيجة والقيود

- النتيجة المحلية الحالية لحساب QA free (id=4) **5 نقاط متبقية** بعد عمليتي كشف حقيقيتين. الـseeder مصمم ليكون idempotent، فلا يعيد تعبئة الرصيد عمدًا ولا يمحو سجلات الاستهلاك لاختبار التكرار.
- التأكد من الواجهات تم عبر Android accessibility/UI hierarchy؛ صورة `adb screencap` لهذا المحاكي الآلي Google ATD سوداء، لذلك لا يُعد الاختبار إثباتًا لجودة البكسلات والألوان والمحاذاة على محاكي رسومي أو هاتف آخر.
- اختبار بيئة Stripe Test الحقيقي والـwebhook والتجديد/الاسترداد ومتطلبات Apple/Google الخارجية **لم ينفذ**، ولم يُفعّل الدفع. تغيير اللغة إلى العربية تم اختبارها من الواجهة؛ باقي اللغات تظهر في قائمة الاختيار لكن لم تُستكمل جولة كاملة لكل واحدة.
- ظهر النص المؤقت `Checking payment and refreshing your wallet…` لحظات أثناء كشف التقييم (ثم اكتمل الكشف بنجاح). يُوصى بتغيير نص حالة التحميل في واجهة التقييم لتصف التقييم/الرصيد بدل التحقق من دفع، دون أن يشكل هذا خطأ ماليًا.
- الجهاز بقي على صفحة الاشتراكات بالعربية ومسجل الدخول بحساب QA free بعد الاختبار؛ لم تُنشأ فواتير Stripe ولم يُغيّر أي رصيد مستخدم حقيقي.