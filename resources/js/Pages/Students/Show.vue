<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Badge from '@/Components/Badge.vue';
import Modal from '@/Components/Modal.vue';
import OrderModal from '@/Components/OrderModal.vue';
import CredentialsModal from '@/Components/CredentialsModal.vue';
import InputError from '@/Components/InputError.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { studentStatus, outcome, resultStatus, systems, roundName } from '@/lib/labels';
import { ArrowPathIcon, BookOpenIcon, ClockIcon, DocumentTextIcon, PlusIcon, PrinterIcon, KeyIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ student: Object, programs: Array, history: Array, courses: Array, statuses: Object, transitions: Array, orders: Array, orderTypes: Object, programOptions: { type: Array, default: () => [] }, documents: { type: Array, default: () => [] } });
const revoke = (d) => {
    const reason = prompt(`سبب إلغاء الوثيقة ${d.code}:`);
    if (reason) router.post(route('documents.revoke', d.id), { reason }, { preserveScroll: true });
};
const page = usePage();
const issuing = ref(false);
const issueAccount = () => {
    if (props.student.has_account && !confirm('ستُلغى كلمة المرور الحالية للطالب. متابعة؟')) return;
    issuing.value = true;
    router.post(route('students.account', props.student.id), {}, { preserveScroll: true, onFinish: () => (issuing.value = false) });
};
const current = computed(() => props.programs.find((p) => p.current));
const tab = ref('courses');

const showStatus = ref(false);
const showOrder = ref(false);
const form = useForm({ to: '', order_id: '', effective_on: '', notes: '', program_id: '', stage: 1 });
const targetProgram = computed(() => props.programOptions.find((p) => p.id == form.program_id));
const transition = computed(() => props.transitions.find((t) => t.to === form.to));
const matchingOrders = computed(() => props.orders.filter((o) => !transition.value || o.type === transition.value.order_type || o.type === 'other'));
const openStatus = (to) => { form.reset(); form.to = to; showStatus.value = true; };
const saveStatus = () => form.post(route('students.status', props.student.id), { preserveScroll: true, onSuccess: () => (showStatus.value = false) });
const orderSaved = (id) => { if (id) form.order_id = id; };

const info = computed(() => [
    ['الرقم الجامعي', props.student.number, true],
    ['الرقم الوطني', props.student.national_id ?? '—', true],
    ['اسم الأم', props.student.mother ?? '—'],
    ['تاريخ الميلاد', props.student.birth_date ?? '—', true],
    ['الجنس', { m: 'ذكر', f: 'أنثى' }[props.student.gender] ?? '—'],
    ['الهاتف', props.student.phone ?? '—', true],
    ['سنة القبول', props.student.admission_year ?? '—'],
    ['قناة القبول', { central: 'القبول المركزي', parallel: 'الموازي', evening: 'المسائي', transfer: 'انتقال', other: 'أخرى' }[props.student.channel] ?? props.student.channel ?? '—'],
    ['الدراسة', props.student.shift === 'evening' ? 'مسائية' : 'صباحية'],
]);
const passedCount = computed(() => props.courses.filter((c) => c.result?.status === 'locked' && c.result.outcome === 'pass').length);
</script>

<template>
    <AppLayout :title="student.name">
        <PageHeader :title="student.name" :crumbs="[{ label: 'الطلبة', href: route('students.index') }, { label: student.number }]">
            <template #meta>
                <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-slate-500">
                    <Badge :map="studentStatus" :value="student.status" dot />
                    <span v-if="current">{{ current.department }} · المرحلة {{ current.stage }}</span>
                    <span v-if="student.name_en" dir="ltr" class="text-xs">{{ student.name_en }}</span>
                </div>
            </template>
            <a v-if="student.status === 'graduated'" :href="route('students.graduation', student.id)" target="_blank" class="btn-secondary"><PrinterIcon class="h-4 w-4" /> وثيقة التخرج PDF</a>
            <a :href="route('students.transcript', student.id)" target="_blank" class="btn-secondary"><PrinterIcon class="h-4 w-4" /> كشف الدرجات PDF</a>
            <button v-if="transitions.length" class="btn-primary" @click="openStatus('')"><ArrowPathIcon class="h-4 w-4" /> تغيير الحالة</button>
        </PageHeader>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6">
                <section class="card">
                    <div class="card-header"><h2 class="card-title">البيانات الأساسية</h2></div>
                    <dl class="divide-y divide-slate-100 text-sm">
                        <div v-for="[k, v, ltr] in info" :key="k" class="flex justify-between gap-4 px-5 py-2.5">
                            <dt class="text-slate-500">{{ k }}</dt><dd class="font-semibold text-slate-800" :class="ltr && 'font-mono'" :dir="ltr ? 'ltr' : null">{{ v }}</dd>
                        </div>
                    </dl>
                </section>
                <section v-if="student.can_issue_account" class="card p-5">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h2 class="card-title flex items-center gap-2"><KeyIcon class="h-5 w-5 text-brand-600" /> بوابة الطالب</h2>
                            <p class="mt-1 text-xs text-slate-500">{{ student.has_account ? 'للطالب حساب. يمكنك إصدار كلمة مرور مؤقتة جديدة إن نسيها.' : 'لا يوجد حساب بعد. يدخل الطالب برقمه الجامعي.' }}</p>
                        </div>
                        <button class="btn-secondary shrink-0" :disabled="issuing" @click="issueAccount">{{ student.has_account ? 'كلمة مرور جديدة' : 'إنشاء حساب' }}</button>
                    </div>
                </section>
                <section v-if="documents.length" class="card">
                    <div class="card-header"><h2 class="card-title">الوثائق الصادرة</h2></div>
                    <ul class="divide-y divide-slate-100 text-sm">
                        <li v-for="d in documents" :key="d.id" class="flex items-center gap-2 px-5 py-2.5">
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-slate-800">{{ d.type }} <a :href="d.url" target="_blank" class="ltr ms-1 font-mono text-xs text-brand-700 hover:underline">{{ d.code }}</a></p>
                                <p class="text-xs text-slate-400"><span class="ltr">{{ d.at }}</span> · {{ d.by }}<span v-if="!d.valid" class="text-rose-600"> · ملغاة: {{ d.reason }}</span></p>
                            </div>
                            <button v-if="d.valid && student.can_issue_account !== undefined && page.props.can.admit" class="text-xs font-bold text-rose-600 hover:underline" @click="revoke(d)">إلغاء الوثيقة</button>
                        </li>
                    </ul>
                </section>
                <section class="card">
                    <div class="card-header"><h2 class="card-title">المسار الدراسي</h2></div>
                    <ul class="space-y-3 p-5">
                        <li v-for="p in programs" :key="p.id" class="rounded-xl border p-4" :class="p.current ? 'border-brand-200 bg-brand-50/50' : 'border-slate-100'">
                            <div class="flex items-center justify-between"><p class="font-bold text-slate-800">{{ p.program }}</p><Badge v-if="p.current" tone="brand">حالي</Badge></div>
                            <p class="mt-1 text-xs text-slate-500">{{ p.college }} · {{ p.department }}</p>
                            <p class="mt-1 text-xs text-slate-500">نظام {{ systems[p.system] }} · المرحلة {{ p.stage }} · {{ p.curriculum }}</p>
                        </li>
                    </ul>
                </section>
            </div>

            <div class="lg:col-span-2">
                <div class="card">
                    <div class="flex gap-1 border-b border-slate-100 px-3 pt-3">
                        <button v-for="[k, l, icon] in [['courses', 'المواد والنتائج', BookOpenIcon], ['history', 'سجل الحالة والأوامر', ClockIcon]]" :key="k"
                            class="flex items-center gap-2 rounded-t-xl border-b-2 px-4 py-2.5 text-sm font-bold transition"
                            :class="tab === k ? 'border-brand-600 text-brand-800' : 'border-transparent text-slate-500 hover:text-slate-700'" @click="tab = k">
                            <component :is="icon" class="h-4 w-4" /> {{ l }}
                        </button>
                    </div>

                    <div v-if="tab === 'courses'">
                        <div v-if="courses.length" class="flex gap-6 border-b border-slate-100 px-5 py-3 text-sm">
                            <span class="text-slate-500">المسجلة: <b class="text-slate-800">{{ courses.length }}</b></span>
                            <span class="text-slate-500">المجتازة (مقفلة): <b class="text-emerald-700">{{ passedCount }}</b></span>
                        </div>
                        <div class="overflow-x-auto">
                            <table v-if="courses.length" class="table-base">
                                <thead><tr><th>المادة</th><th>الفصل</th><th>الدرجة</th><th>التقدير</th><th>النتيجة</th></tr></thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr v-for="c in courses" :key="c.id">
                                        <td><Link :href="route('offerings.show', c.offering_id)" class="font-bold text-slate-800 hover:text-brand-700">{{ c.name }}</Link><p class="text-xs text-slate-400">{{ c.code }}<span v-if="c.attempt > 1"> · محاولة {{ c.attempt }}</span><span v-if="c.carried"> · محملة</span></p></td>
                                        <td class="text-xs text-slate-500">{{ c.term }}</td>
                                        <td class="text-lg font-extrabold tabular-nums">{{ c.result?.mark ?? '—' }}</td>
                                        <td>{{ c.result?.grade ?? '—' }}</td>
                                        <td>
                                            <div v-if="c.result" class="flex flex-wrap items-center gap-1">
                                                <Badge :map="outcome" :value="c.result.outcome" />
                                                <Badge :map="resultStatus" :value="c.result.status" />
                                                <Badge v-if="c.result.round > 1" tone="sky">{{ roundName(c.result.round) }}</Badge>
                                                <Badge v-if="!c.result.official" tone="amber">غير رسمية</Badge>
                                            </div>
                                            <span v-else class="text-xs text-slate-400">لم تُحتسب بعد</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <EmptyState v-else :icon="BookOpenIcon" title="لم يُسجَّل الطالب في أي مادة بعد" />
                        </div>
                    </div>

                    <div v-else class="p-5">
                        <ol class="relative space-y-6 border-s-2 border-slate-100 ps-6">
                            <li v-for="h in history" :key="h.id" class="relative">
                                <span class="absolute -start-[31px] top-1 h-3.5 w-3.5 rounded-full border-2 border-white bg-brand-500 ring-2 ring-brand-100" />
                                <div class="flex flex-wrap items-center gap-2">
                                    <Badge v-if="h.from" :map="studentStatus" :value="h.from" /><span v-if="h.from" class="text-slate-400">←</span>
                                    <Badge :map="studentStatus" :value="h.to" dot />
                                    <span class="text-xs font-semibold tabular-nums text-slate-500">{{ h.on }}</span>
                                </div>
                                <p v-if="h.order" class="mt-1.5 flex items-center gap-1.5 text-sm text-slate-700"><DocumentTextIcon class="h-4 w-4 text-slate-400" /> أمر إداري {{ h.order }} — {{ h.order_title }}</p>
                                <p v-else class="mt-1.5 text-xs text-amber-700">بدون أمر إداري مرفق</p>
                                <p v-if="h.notes" class="mt-1 text-sm text-slate-500">{{ h.notes }}</p>
                                <p v-if="h.by" class="mt-1 text-xs text-slate-400">بواسطة {{ h.by }}</p>
                            </li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <Modal :show="showStatus" max-width="lg" @close="showStatus = false">
            <form class="p-6" @submit.prevent="saveStatus">
                <h3 class="text-lg font-extrabold">تغيير حالة الطالب</h3>
                <p class="mt-1 text-sm text-slate-500">لا تتغير حالة الطالب إلا بأمر إداري. يُحفظ التغيير في السجل ولا يُحذف.</p>
                <div class="mt-5 space-y-4">
                    <div>
                        <label class="label">الحالة الجديدة <span class="text-rose-500">*</span></label>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                            <button v-for="t in transitions" :key="t.to" type="button" class="rounded-xl border px-3 py-2 text-sm font-bold transition"
                                :class="form.to === t.to ? 'border-brand-500 bg-brand-50 text-brand-800' : 'border-slate-200 text-slate-600 hover:bg-slate-50'" @click="form.to = t.to; form.order_id = ''">{{ t.label }}</button>
                        </div>
                        <InputError :message="form.errors.to" class="mt-1" />
                    </div>
                    <div>
                        <div class="mb-1.5 flex items-center justify-between"><label class="label mb-0">الأمر الإداري <span class="text-rose-500">*</span></label>
                            <button v-if="page.props.can.orders" type="button" class="flex items-center gap-1 text-xs font-bold text-brand-700 hover:underline" @click="showOrder = true"><PlusIcon class="h-3.5 w-3.5" /> أمر جديد</button></div>
                        <select v-model="form.order_id" class="input" required><option value="" disabled>اختر الأمر</option><option v-for="o in matchingOrders" :key="o.id" :value="o.id">{{ o.label }}</option></select>
                        <p v-if="transition" class="mt-1 text-xs text-slate-500">نوع الأمر المطلوب: {{ orderTypes[transition.order_type] }}</p>
                        <InputError :message="form.errors.order_id" class="mt-1" />
                    </div>
                    <div v-if="form.to === 'program_change'" class="grid gap-3 rounded-xl bg-slate-50 p-3 sm:grid-cols-3">
                        <div class="sm:col-span-2">
                            <label class="label">البرنامج الجديد <span class="text-rose-500">*</span></label>
                            <select v-model="form.program_id" class="input" required><option value="" disabled>اختر البرنامج</option><option v-for="p in programOptions" :key="p.id" :value="p.id">{{ p.name }} — {{ p.department }}</option></select>
                            <InputError :message="form.errors.program_id" class="mt-1" />
                        </div>
                        <div><label class="label">المرحلة</label><select v-model="form.stage" class="input"><option v-for="n in targetProgram?.years ?? 6" :key="n" :value="n">{{ n }}</option></select></div>
                        <p class="text-xs text-slate-500 sm:col-span-3">يبقى الرقم الجامعي نفسه، ويُغلق البرنامج الحالي ويبقى في السجل. معادلة المواد المجتازة تُعالج حسب تعليمات الجامعة.</p>
                    </div>
                    <div v-else><label class="label">تاريخ النفاذ</label><input v-model="form.effective_on" type="date" class="input" /><p class="mt-1 text-xs text-slate-500">يُترك فارغاً ليُعتمد تاريخ الأمر.</p></div>
                    <div><label class="label">ملاحظات</label><textarea v-model="form.notes" rows="2" class="input" /></div>
                </div>
                <div class="mt-6 flex justify-end gap-2"><button type="button" class="btn-secondary" @click="showStatus = false">إلغاء</button><button class="btn-primary" :disabled="form.processing || !form.to">تأكيد التغيير</button></div>
            </form>
        </Modal>
        <CredentialsModal />
        <OrderModal :show="showOrder" :types="orderTypes" :type="transition?.order_type ?? ''" @close="showOrder = false" @saved="orderSaved" />
    </AppLayout>
</template>
