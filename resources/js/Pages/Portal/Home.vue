<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import Badge from '@/Components/Badge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import InputError from '@/Components/InputError.vue';
import WeekGrid from '@/Components/WeekGrid.vue';
import { outcome, studentStatus } from '@/lib/labels';
import { AcademicCapIcon, ArrowDownTrayIcon, BanknotesIcon, PrinterIcon, BookOpenIcon, ChartBarIcon, ClockIcon, ExclamationTriangleIcon, KeyIcon, CalendarDaysIcon, DocumentTextIcon, CreditCardIcon, LockClosedIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ student: Object, current: Array, years: Array, official: Boolean, history: Array, fees: { type: Array, default: () => [] }, timetable: { type: Array, default: () => [] }, days: Object, requests: { type: Array, default: () => [] }, requestTypes: { type: Object, default: () => ({}) }, gateways: { type: Object, default: () => ({}) }, minimumOnline: { type: Number, default: 1000 } });
const money = (n) => Number(n ?? 0).toLocaleString('en-US');
const lineTone = { paid: 'emerald', partial: 'sky', due: 'slate', overdue: 'rose' };
const lineLabel = { paid: 'مسدد', partial: 'مسدد جزئياً', due: 'مستحق', overdue: 'متأخر' };
const tab = ref(new URLSearchParams(window.location.search).get('tab') || 'current');
const req = useForm({ type: 'transcript', addressed_to: '', purpose: '', notes: '' });
const sendReq = () => req.post(route('portal.requests.store'), { preserveScroll: true, onSuccess: () => req.reset() });
const cancelReq = (r) => confirm('إلغاء الطلب؟') && router.post(route('portal.requests.cancel', r.id), {}, { preserveScroll: true });
const reqTone = { submitted: 'amber', ready: 'emerald', delivered: 'slate', rejected: 'rose', cancelled: 'slate' };
const lastGpa = computed(() => props.years.find((y) => y.gpa !== null)?.gpa ?? null);
const totalCredits = computed(() => props.years.reduce((s, y) => s + y.credits, 0));
const announced = computed(() => props.current.filter((c) => c.result).length);

// online payment
const payFor = ref(null);
const pay = useForm({ gateway: '', what: 'installment' });
const openPay = (f) => { payFor.value = f.id; pay.gateway = Object.keys(props.gateways)[0] ?? ''; pay.what = f.next < f.balance ? 'installment' : 'full'; };
const payAmount = (f) => (pay.what === 'full' ? f.balance : f.next);
const startPay = (f) => pay.post(route('payments.start', f.id), { preserveScroll: true });

const pw = useForm({ current_password: '', password: '', password_confirmation: '' });
const savePw = () => pw.put(route('password.update'), { preserveScroll: true, onSuccess: () => pw.reset(), onFinish: () => pw.reset('current_password', 'password', 'password_confirmation') });
</script>

<template>
    <PortalLayout title="بوابة الطالب">
        <!-- identity -->
        <section class="card overflow-hidden">
            <div class="flex flex-wrap items-center gap-4 rtl:bg-gradient-to-l ltr:bg-gradient-to-r from-brand-50 to-white p-5 sm:p-6">
                <div class="grid h-14 w-14 place-items-center rounded-2xl bg-brand-700 text-white"><AcademicCapIcon class="h-8 w-8" /></div>
                <div class="min-w-0 flex-1">
                    <h1 class="text-xl font-extrabold text-slate-900 sm:text-2xl">{{ student.name }}</h1>
                    <p class="mt-1 text-sm text-slate-500">{{ student.college }} · {{ student.department }}</p>
                    <p class="text-sm text-slate-500">{{ student.program }} · المرحلة {{ student.stage }} · نظام {{ student.system }}</p>
                </div>
                <div class="text-end">
                    <p class="text-xs text-slate-400">الرقم الجامعي</p>
                    <p class="ltr font-mono text-lg font-extrabold text-brand-800">{{ student.number }}</p>
                    <Badge :map="studentStatus" :value="student.status" dot class="mt-1" />
                </div>
            </div>
            <div class="grid grid-cols-2 divide-x rtl:divide-x-reverse divide-slate-100 border-t border-slate-100 sm:grid-cols-4">
                <div class="p-4"><p class="text-xs text-slate-500">آخر معدل سنوي</p><p class="text-xl font-extrabold tabular-nums">{{ lastGpa ?? '—' }}</p></div>
                <div class="p-4"><p class="text-xs text-slate-500">الوحدات المكتسبة</p><p class="text-xl font-extrabold tabular-nums">{{ totalCredits }}</p></div>
                <div class="p-4"><p class="text-xs text-slate-500">مواد هذا العام</p><p class="text-xl font-extrabold tabular-nums">{{ current.length }}</p></div>
                <div class="p-4"><p class="text-xs text-slate-500">نتائج معلنة</p><p class="text-xl font-extrabold tabular-nums text-emerald-600">{{ announced }} / {{ current.length }}</p></div>
            </div>
        </section>

        <div v-if="!official" class="mt-4 flex items-start gap-2 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <ExclamationTriangleIcon class="mt-0.5 h-5 w-5 shrink-0" /> بعض النتائج محسوبة بسياسات مؤقتة غير رسمية، ولا يُعتدّ بها رسمياً حتى اعتماد الأنظمة.
        </div>

        <div class="mt-6 flex flex-wrap items-center gap-2">
            <div class="flex max-w-full gap-1 overflow-x-auto rounded-xl bg-slate-100 p-1 text-sm font-semibold">
                <button v-for="[k, l, icon] in [['current', 'مواد هذا العام', BookOpenIcon], ['timetable', 'جدولي', CalendarDaysIcon], ['requests', 'طلباتي', DocumentTextIcon], ['results', 'النتائج السابقة', ChartBarIcon], ['fees', 'الرسوم', BanknotesIcon], ['history', 'السجل', ClockIcon], ['account', 'الحساب', KeyIcon]]" :key="k"
                    class="flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 transition" :class="tab === k ? 'bg-white text-brand-800 shadow-sm' : 'text-slate-500 hover:text-slate-700'" @click="tab = k">
                    <component :is="icon" class="h-4 w-4" /> {{ l }}
                </button>
            </div>
            <a v-if="student.status === 'graduated'" :href="route('students.graduation', student.id)" target="_blank" class="btn-secondary ms-auto">وثيقة التخرج PDF</a>
            <a :href="route('students.transcript', student.id)" target="_blank" class="btn-secondary" :class="student.status !== 'graduated' && 'ms-auto'"><ArrowDownTrayIcon class="h-4 w-4" /> كشف الدرجات PDF</a>
        </div>

        <!-- current year -->
        <section v-if="tab === 'current'" class="card mt-4">
            <EmptyState v-if="!current.length" title="لا توجد مواد مسجلة لهذا العام" />
            <ul v-else class="divide-y divide-slate-100">
                <li v-for="c in current" :key="c.code" class="flex flex-wrap items-center gap-3 px-5 py-3.5">
                    <div class="min-w-0 flex-1">
                        <p class="font-bold text-slate-800">{{ c.name }} <Badge v-if="c.carried" tone="amber">محمّلة</Badge></p>
                        <p class="text-xs text-slate-500"><span class="ltr font-mono">{{ c.code }}</span> · {{ c.term }} · {{ c.credits }} وحدة<span v-if="c.teacher"> · {{ c.teacher }}</span></p>
                        <p v-if="c.absence" class="mt-0.5 text-xs" :class="c.absence.level ? 'font-bold text-rose-600' : 'text-slate-500'">الغياب {{ c.absence.percent }}% ({{ c.absence.absent }} محاضرة<span v-if="c.absence.excused">، بعذر {{ c.absence.excused }}</span>)<span v-if="c.absence.warning"> · {{ c.absence.warning }}</span></p>
                    </div>
                    <template v-if="c.result">
                        <div class="text-end"><p class="text-lg font-extrabold tabular-nums">{{ c.result.mark }}</p><p class="text-xs text-slate-500">{{ c.result.grade }}</p></div>
                        <Badge :map="outcome" :value="c.result.outcome" />
                    </template>
                    <span v-else class="text-xs text-slate-400">لم تُعلن بعد</span>
                </li>
            </ul>
        </section>

        <!-- past years -->
        <template v-if="tab === 'results'">
            <EmptyState v-if="!years.length" class="card mt-4" title="لا توجد نتائج معلنة بعد" />
            <section v-for="y in years" :key="y.id" class="card mt-4">
                <div class="card-header flex flex-wrap items-center gap-2">
                    <h2 class="card-title">العام <span class="ltr">{{ y.name }}</span></h2>
                    <Badge v-if="y.decision" tone="brand">{{ y.decision }}</Badge>
                    <span class="ms-auto text-sm text-slate-500">المعدل: <b class="text-slate-800">{{ y.gpa ?? '—' }}</b> · الوحدات: <b class="text-slate-800">{{ y.credits }}</b></span>
                </div>
                <div class="overflow-x-auto">
                    <table class="table-base">
                        <thead><tr><th>المادة</th><th>الفصل</th><th>الوحدات</th><th>الدرجة</th><th>التقدير</th><th>الدور</th><th>النتيجة</th></tr></thead>
                        <tbody>
                            <tr v-for="r in y.rows" :key="r.code">
                                <td><p class="font-semibold">{{ r.name }}</p><p class="ltr text-start font-mono text-xs text-slate-400">{{ r.code }}</p></td>
                                <td>{{ r.term }}</td><td class="tabular-nums">{{ r.credits }}</td>
                                <td class="font-bold tabular-nums">{{ r.mark ?? '—' }}</td><td>{{ r.grade ?? '—' }}</td><td>{{ r.round }}</td>
                                <td><Badge v-if="r.outcome_code" :map="outcome" :value="r.outcome_code" /><span v-else class="text-xs text-slate-400">لم تُعلن</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>

        <!-- fees -->
        <template v-if="tab === 'fees'">
            <EmptyState v-if="!fees.length" class="card mt-4" title="لا توجد رسوم دراسية مطلوبة منك" />
            <section v-for="f in fees" :key="f.id" class="card mt-4">
                <div class="card-header flex flex-wrap items-center gap-2">
                    <h2 class="card-title">رسوم العام <span class="ltr">{{ f.year }}</span></h2>
                    <Badge :tone="f.status === 'paid' ? 'emerald' : 'amber'">{{ f.status === 'paid' ? 'مسددة' : 'غير مسددة' }}</Badge>
                    <span class="ms-auto text-sm text-slate-500">المدفوع <b class="text-emerald-700">{{ money(f.paid) }}</b> من {{ money(f.net) }} · المتبقي <b class="text-slate-800">{{ money(f.balance) }}</b> دينار</span>
                </div>
                <ul class="divide-y divide-slate-100">
                    <li v-for="l in f.lines" :key="l.number" class="flex items-center gap-3 px-5 py-3 text-sm">
                        <span class="grid h-8 w-8 place-items-center rounded-lg bg-slate-100 font-bold text-slate-600">{{ l.number }}</span>
                        <div class="flex-1"><p class="font-bold tabular-nums">{{ money(l.amount) }} دينار</p><p class="text-xs text-slate-400">يستحق <span class="ltr">{{ l.due_on }}</span></p></div>
                        <Badge :tone="lineTone[l.status]">{{ lineLabel[l.status] }}</Badge>
                    </li>
                </ul>
                <!-- pay online -->
                <div v-for="o in f.online" :key="o.uuid" class="flex flex-wrap items-center gap-2 border-t px-5 py-3 text-sm" :class="o.status === 'review' ? 'border-amber-100 bg-amber-50 text-amber-900' : 'border-sky-100 bg-sky-50 text-sky-900'">
                    <ClockIcon class="h-5 w-5 shrink-0" />
                    <span v-if="o.status === 'review'">دفعتك ({{ money(o.amount) }} دينار عبر {{ o.gateway }}) وصلت وتنتظر مراجعة المحاسب.</span>
                    <span v-else>لديك دفعة لم تكتمل: {{ money(o.amount) }} دينار عبر {{ o.gateway }}.</span>
                    <Link :href="route('payments.show', o.uuid)" class="ms-auto font-bold underline underline-offset-2">التفاصيل</Link>
                </div>
                <div v-if="f.balance > 0 && Object.keys(gateways).length && !f.online.some((o) => o.status === 'pending')" class="border-t border-slate-100 px-5 py-4">
                    <button v-if="payFor !== f.id" class="btn-primary" @click="openPay(f)"><CreditCardIcon class="h-5 w-5" /> ادفع إلكترونياً</button>
                    <form v-else class="space-y-4" @submit.prevent="startPay(f)">
                        <div>
                            <p class="mb-2 text-sm font-bold text-slate-700">المبلغ</p>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <label v-if="f.next < f.balance" class="flex cursor-pointer items-center gap-3 rounded-xl border p-3 transition" :class="pay.what === 'installment' ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-500' : 'border-slate-200 hover:border-slate-300'">
                                    <input v-model="pay.what" type="radio" value="installment" class="text-brand-600" />
                                    <span><span class="block font-bold tabular-nums">{{ money(f.next) }} دينار</span><span class="text-xs text-slate-500">القسط المستحق القادم</span></span>
                                </label>
                                <label class="flex cursor-pointer items-center gap-3 rounded-xl border p-3 transition" :class="pay.what === 'full' ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-500' : 'border-slate-200 hover:border-slate-300'">
                                    <input v-model="pay.what" type="radio" value="full" class="text-brand-600" />
                                    <span><span class="block font-bold tabular-nums">{{ money(f.balance) }} دينار</span><span class="text-xs text-slate-500">كامل المتبقي</span></span>
                                </label>
                            </div>
                        </div>
                        <div>
                            <p class="mb-2 text-sm font-bold text-slate-700">طريقة الدفع</p>
                            <div class="grid gap-2 sm:grid-cols-3">
                                <button v-for="(label, k) in gateways" :key="k" type="button" class="rounded-xl border p-3 text-sm font-bold transition" :class="pay.gateway === k ? 'border-brand-500 bg-brand-50 text-brand-800 ring-1 ring-brand-500' : 'border-slate-200 text-slate-700 hover:border-slate-300'" @click="pay.gateway = k">{{ label }}</button>
                            </div>
                            <InputError :message="pay.errors.gateway" class="mt-1" />
                        </div>
                        <p v-if="payAmount(f) < minimumOnline" class="text-xs text-amber-700">المبلغ أقل من الحد الأدنى للدفع الإلكتروني ({{ money(minimumOnline) }} دينار)، يُدفع لدى المحاسب.</p>
                        <div class="flex flex-wrap items-center gap-2">
                            <button class="btn-primary" :disabled="pay.processing || !pay.gateway || payAmount(f) < minimumOnline"><LockClosedIcon class="h-4 w-4" /> ادفع {{ money(payAmount(f)) }} دينار</button>
                            <button type="button" class="btn-ghost" @click="payFor = null">إلغاء</button>
                            <p class="w-full text-xs text-slate-400 sm:ms-auto sm:w-auto">يصدر الوصل تلقائياً بعد أن تؤكد البوابة الدفع.</p>
                        </div>
                    </form>
                </div>
                <div v-if="f.payments.length" class="border-t border-slate-100 px-5 py-3">
                    <p class="mb-2 text-sm font-bold text-slate-700">وصولات الدفع</p>
                    <a v-for="p in f.payments" :key="p.id" :href="route('finance.receipt', p.id)" target="_blank" class="me-2 mb-2 inline-flex items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-200">
                        <PrinterIcon class="h-4 w-4" /> <span class="ltr font-mono">{{ p.receipt }}</span> · {{ money(p.amount) }}
                    </a>
                </div>
            </section>
        </template>

        <section v-if="tab === 'timetable'" class="card mt-4 p-2 sm:p-4">
            <EmptyState v-if="!timetable.length" :icon="CalendarDaysIcon" title="لم يُنشر جدول هذا الفصل بعد" />
            <template v-else>
                <div class="mb-3 flex justify-end"><a :href="route('timetable.mine')" class="btn-secondary"><PrinterIcon class="h-4 w-4" /> طباعة</a></div>
                <WeekGrid :slots="timetable" :days="days" />
            </template>
        </section>

        <div v-if="tab === 'requests'" class="mt-4 grid gap-4 lg:grid-cols-5">
            <form class="card h-fit p-5 lg:col-span-2" @submit.prevent="sendReq">
                <h3 class="font-extrabold text-slate-800">طلب وثيقة</h3>
                <p class="mt-1 text-xs text-slate-500">تصدر الوثيقة برمز QR للتحقق، وتنزّلها من هنا عند جاهزيتها.</p>
                <div class="mt-4 space-y-3">
                    <div><label class="label">نوع الوثيقة</label><select v-model="req.type" class="input"><option v-for="(l, k) in requestTypes" :key="k" :value="k">{{ l }}</option></select><InputError :message="req.errors.type" class="mt-1" /></div>
                    <div><label class="label">الجهة المعنون إليها</label><input v-model="req.addressed_to" class="input" placeholder="مثال: مديرية التقاعد العامة" /></div>
                    <div><label class="label">الغرض</label><input v-model="req.purpose" class="input" placeholder="اختياري" /></div>
                    <div><label class="label">ملاحظات</label><textarea v-model="req.notes" rows="2" class="input" /></div>
                </div>
                <button class="btn-primary mt-4 w-full" :disabled="req.processing">إرسال الطلب</button>
            </form>
            <section class="card lg:col-span-3">
                <EmptyState v-if="!requests.length" :icon="DocumentTextIcon" title="لا توجد طلبات" />
                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="r in requests" :key="r.id" class="flex flex-wrap items-center gap-3 px-5 py-4">
                        <div class="min-w-0 flex-1">
                            <p class="font-bold text-slate-800">{{ r.type }}</p>
                            <p class="text-xs text-slate-400"><span class="ltr tabular-nums">{{ r.at }}</span><template v-if="r.addressed_to"> · إلى {{ r.addressed_to }}</template></p>
                            <p v-if="r.response" class="mt-1 text-xs" :class="r.status === 'rejected' ? 'text-rose-600' : 'text-slate-500'">{{ r.response }}</p>
                        </div>
                        <Badge :tone="reqTone[r.status]">{{ r.status_label }}</Badge>
                        <a v-if="r.file" :href="r.file" class="btn-secondary"><ArrowDownTrayIcon class="h-4 w-4" /> تنزيل</a>
                        <button v-if="r.status === 'submitted'" type="button" class="text-xs font-semibold text-slate-500 hover:text-rose-600" @click="cancelReq(r)">إلغاء</button>
                    </li>
                </ul>
            </section>
        </div>

        <!-- status history -->
        <section v-if="tab === 'history'" class="card mt-4">
            <EmptyState v-if="!history.length" title="لا يوجد سجل" />
            <ol v-else class="divide-y divide-slate-100">
                <li v-for="(h, i) in history" :key="i" class="flex flex-wrap items-center gap-3 px-5 py-3 text-sm">
                    <span class="font-bold text-slate-800">{{ h.to }}</span>
                    <span class="ltr tabular-nums text-slate-500">{{ h.on }}</span>
                    <span v-if="h.order" class="text-slate-500">{{ h.order }}</span>
                    <span v-if="h.notes" class="text-slate-400">{{ h.notes }}</span>
                </li>
            </ol>
        </section>

        <!-- account -->
        <form v-if="tab === 'account'" class="card mt-4 max-w-md p-6" @submit.prevent="savePw">
            <h2 class="card-title">تغيير كلمة المرور</h2>
            <p class="mt-1 text-sm text-slate-500">تدخل البوابة برقمك الجامعي <span class="ltr font-mono">{{ student.number }}</span>.</p>
            <div class="mt-5 space-y-4">
                <div><label class="label">كلمة المرور الحالية</label><input v-model="pw.current_password" type="password" dir="ltr" class="input" required autocomplete="current-password" /><InputError :message="pw.errors.current_password" class="mt-1" /></div>
                <div><label class="label">كلمة المرور الجديدة</label><input v-model="pw.password" type="password" dir="ltr" class="input" required autocomplete="new-password" /><InputError :message="pw.errors.password" class="mt-1" /></div>
                <div><label class="label">تأكيد كلمة المرور</label><input v-model="pw.password_confirmation" type="password" dir="ltr" class="input" required autocomplete="new-password" /></div>
            </div>
            <button class="btn-primary mt-5" :disabled="pw.processing">حفظ</button>
            <p v-if="pw.recentlySuccessful" class="mt-2 text-sm text-emerald-600">تم الحفظ.</p>
        </form>
    </PortalLayout>
</template>
