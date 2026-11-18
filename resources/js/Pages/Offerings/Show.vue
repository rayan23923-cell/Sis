<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Badge from '@/Components/Badge.vue';
import Modal from '@/Components/Modal.vue';
import OrderModal from '@/Components/OrderModal.vue';
import OfferingManage from '@/Components/OfferingManage.vue';
import InputError from '@/Components/InputError.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { offeringStatus, outcome, resultStatus, roundName, systems } from '@/lib/labels';
import {
    ArrowUturnRightIcon, CalculatorIcon, CheckBadgeIcon, CheckIcon, CloudArrowUpIcon, ExclamationTriangleIcon,
    LockClosedIcon, MagnifyingGlassIcon, PrinterIcon, ArrowDownTrayIcon, ArrowUpTrayIcon, Cog6ToothIcon, UserMinusIcon, PencilSquareIcon, PlusIcon, ArrowPathIcon, UsersIcon, InformationCircleIcon,
} from '@heroicons/vue/24/outline';

const props = defineProps({ offering: Object, round: Number, components: Array, rows: Array, gradeScale: Array, official: Boolean, perm: Object, allComponents: Array, orders: Array, statuses: Object });
const page = usePage();

// ---------- editable sheet ----------
const sheet = reactive({});
const original = ref('');
const load = () => {
    for (const k of Object.keys(sheet)) delete sheet[k];
    for (const r of props.rows) {
        sheet[r.enrollment_id] = {};
        for (const c of props.components) {
            const m = r.marks[c.id] ?? {};
            sheet[r.enrollment_id][c.id] = { mark: m.mark ?? '', absent: !!m.absent };
        }
    }
    original.value = JSON.stringify(sheet);
};
load();
watch(() => props.rows, load);

const editable = computed(() => props.components.filter((c) => c.editable));
const changedRows = computed(() => {
    const before = JSON.parse(original.value);
    return Object.keys(sheet).filter((id) => JSON.stringify(sheet[id]) !== JSON.stringify(before[id]));
});
const dirty = computed(() => changedRows.value.length > 0);
const invalid = (c, v) => v.mark !== '' && v.mark !== null && (Number(v.mark) < 0 || Number(v.mark) > c.max || isNaN(Number(v.mark)));
const hasInvalid = computed(() => props.rows.some((r) => editable.value.some((c) => invalid(c, sheet[r.enrollment_id][c.id]))));

const preview = (r) => {
    // A live estimate while typing; the official figure is always the one the engine computes.
    let total = 0;
    for (const c of props.components) {
        const v = sheet[r.enrollment_id][c.id];
        if (v.absent) continue;
        if (v.mark === '' || v.mark === null) return null;
        total += (Number(v.mark) / c.max) * c.weight;
    }
    return Math.round(total * 100) / 100;
};

const saving = ref(false);
const save = (compute = false) => {
    const rows = {};
    for (const id of changedRows.value) rows[id] = Object.fromEntries(editable.value.map((c) => [c.id, sheet[id][c.id]]));
    if (!Object.keys(rows).length && !compute) return;
    saving.value = true;
    if (!Object.keys(rows).length) return post('offerings.compute');
    router.post(route('offerings.marks', props.offering.id), { rows, compute }, { preserveScroll: true, onFinish: () => (saving.value = false) });
};
const importMarks = (e) => {
    const file = e.target.files[0];
    e.target.value = '';
    if (!file) return;
    saving.value = true;
    router.post(route('offerings.marks.import', props.offering.id), { file }, { forceFormData: true, preserveScroll: true, onFinish: () => (saving.value = false) });
};
const post = (name, data = {}, opts = {}) => {
    saving.value = true;
    router.post(route(name, props.offering.id), data, { preserveScroll: true, onFinish: () => (saving.value = false), ...opts });
};

const beforeUnload = (e) => { if (dirty.value) { e.preventDefault(); e.returnValue = ''; } };
window.addEventListener('beforeunload', beforeUnload);
const removeNav = router.on('before', (e) => {
    if (dirty.value && e.detail.visit.method === 'get' && !confirm('لديك درجات غير محفوظة. هل تريد المغادرة دون حفظ؟')) e.preventDefault();
});
onBeforeUnmount(() => { window.removeEventListener('beforeunload', beforeUnload); removeNav(); });

// keyboard: Enter / ↓ next row, ↑ previous row, same column
const move = (e, rowIdx, colId, dir) => {
    e.preventDefault();
    const next = document.querySelector(`[data-cell="${rowIdx + dir}-${colId}"]`);
    if (next) { next.focus(); next.select?.(); }
};

// ---------- filters ----------
const q = ref('');
const only = ref('all');
const visible = computed(() => props.rows.map((r, i) => ({ ...r, idx: i })).filter((r) => {
    if (q.value && !r.name.includes(q.value) && !r.number.includes(q.value)) return false;
    if (only.value === 'missing') return editable.value.some((c) => { const v = sheet[r.enrollment_id][c.id]; return !v.absent && (v.mark === '' || v.mark === null); });
    if (only.value === 'resit') return r.result?.outcome === 'resit';
    if (only.value === 'fail') return r.result && !['pass'].includes(r.result.outcome);
    return true;
}));

const stats = computed(() => {
    const n = props.rows.length;
    const complete = props.rows.filter((r) => editable.value.every((c) => { const v = sheet[r.enrollment_id][c.id]; return v.absent || (v.mark !== '' && v.mark !== null); })).length;
    const computed = props.rows.filter((r) => r.result).length;
    const stale = props.rows.filter((r) => r.result?.stale).length;
    const passed = props.rows.filter((r) => r.result?.outcome === 'pass').length;
    return { n, complete, computed, stale, passed, pct: n ? Math.round((complete / n) * 100) : 0 };
});

// ---------- workflow ----------
const steps = [
    { key: 'open', label: 'إدخال الدرجات', who: 'التدريسي' },
    { key: 'submitted', label: 'رفع للجنة', who: 'التدريسي' },
    { key: 'approved', label: 'المصادقة', who: 'اللجنة الامتحانية' },
    { key: 'locked', label: 'القفل', who: 'اللجنة الامتحانية' },
];
const stepIndex = computed(() => steps.findIndex((s) => s.key === props.offering.status));
const viewingPast = computed(() => props.round < props.offering.current_round);

const confirmBox = reactive({ show: false, title: '', text: '', action: null, tone: 'primary' });
const ask = (title, text, action, tone = 'primary') => Object.assign(confirmBox, { show: true, title, text, action, tone });
const runConfirm = () => { confirmBox.show = false; confirmBox.action(); };

const returnForm = useForm({ action: 'return', reason: '' });
const showReturn = ref(false);
const sendBack = () => returnForm.post(route('offerings.decide', props.offering.id), { preserveScroll: true, onSuccess: () => { showReturn.value = false; returnForm.reset(); } });

// ---------- correction ----------
const showCorrect = ref(false);
const showOrder = ref(false);
const correctRow = ref(null);
const correctForm = useForm({ order_id: '', reason: '', marks: {} });
const openCorrect = (r) => {
    correctRow.value = r;
    correctForm.reset();
    correctForm.clearErrors();
    correctForm.marks = Object.fromEntries(props.components.map((c) => [c.id, { mark: r.marks[c.id]?.mark ?? '', absent: !!r.marks[c.id]?.absent }]));
    showCorrect.value = true;
};
const saveCorrect = () => correctForm.post(route('offerings.correct', [props.offering.id, correctRow.value.result.id]), { preserveScroll: true, onSuccess: () => (showCorrect.value = false) });
const orderSaved = (id) => { router.reload({ only: ['orders'], onSuccess: () => { if (id) correctForm.order_id = id; } }); };

const showManage = ref(false);
const withdraw = (r) => {
    const reason = prompt(`سبب سحب ${r.name} من الشعبة:`);
    if (reason) router.post(route('offerings.withdraw', [props.offering.id, r.enrollment_id]), { reason }, { preserveScroll: true });
};
const outcomeTone = { pass: 'text-emerald-700', fail: 'text-rose-700', resit: 'text-amber-700' };
</script>

<template>
    <AppLayout :title="offering.name">
        <PageHeader :title="offering.name" :crumbs="[{ label: 'المواد والدرجات', href: route('offerings.index') }, { label: offering.code }]">
            <template #meta>
                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-slate-500">
                    <span class="font-mono font-bold text-brand-700">{{ offering.code }}</span>
                    <span>{{ offering.department }}</span>
                    <span v-if="offering.stage">المرحلة {{ offering.stage }}</span>
                    <span>شعبة {{ offering.section }}</span>
                    <span>{{ offering.term }}</span>
                    <span>{{ offering.teacher ?? 'بدون تدريسي' }}</span>
                    <span>{{ offering.credits }} وحدة<span v-if="offering.ects"> · {{ offering.ects }} ECTS</span></span>
                    <Badge v-if="offering.system" tone="slate">{{ systems[offering.system] }}</Badge>
                </div>
            </template>
            <button v-if="perm.enroll && !rows.length" class="btn-secondary" @click="post('offerings.enroll')"><UsersIcon class="h-4 w-4" /> تسجيل طلبة المرحلة</button>
            <button v-if="perm.manage" class="btn-secondary" @click="showManage = true"><Cog6ToothIcon class="h-4 w-4" /> إدارة الشعبة</button>
            <a v-if="rows.length" :href="route('offerings.sheet', { offering: offering.id, round })" target="_blank" class="btn-secondary"><PrinterIcon class="h-4 w-4" /> قائمة الدرجات PDF</a>
        </PageHeader>

        <!-- workflow stepper -->
        <div class="card mb-6 p-5">
            <ol class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <li v-for="(s, i) in steps" :key="s.key" class="relative flex items-center gap-3">
                    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full text-sm font-extrabold ring-4"
                        :class="i < stepIndex || offering.status === 'locked' ? 'bg-emerald-500 text-white ring-emerald-100' : i === stepIndex ? 'bg-brand-700 text-white ring-brand-100' : 'bg-slate-100 text-slate-400 ring-slate-50'">
                        <CheckIcon v-if="i < stepIndex || offering.status === 'locked'" class="h-5 w-5" /><template v-else>{{ i + 1 }}</template>
                    </span>
                    <div class="leading-tight"><p class="text-sm font-bold" :class="i <= stepIndex ? 'text-slate-800' : 'text-slate-400'">{{ s.label }}</p><p class="text-xs text-slate-400">{{ s.who }}</p></div>
                </li>
            </ol>
            <div v-if="offering.current_round > 1" class="mt-4 flex gap-2 border-t border-slate-100 pt-4">
                <Link v-for="r in offering.current_round" :key="r" :href="route('offerings.show', { offering: offering.id, round: r })" preserve-scroll
                    class="rounded-lg px-3 py-1.5 text-sm font-bold" :class="r === round ? 'bg-brand-700 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'">{{ roundName(r) }}</Link>
            </div>
        </div>

        <div v-if="!official" class="mb-6 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <ExclamationTriangleIcon class="mt-0.5 h-5 w-5 shrink-0" />
            <p>النتائج تُحتسب بسياسات مؤقتة غير رسمية. ستظهر بعلامة «غير رسمية» حتى تُدخل الجامعة النص المعتمد.</p>
        </div>

        <div class="grid gap-6 xl:grid-cols-4">
            <div class="xl:col-span-3">
                <div class="card overflow-hidden">
                    <!-- toolbar -->
                    <div class="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:items-center">
                        <div class="relative flex-1">
                            <MagnifyingGlassIcon class="pointer-events-none absolute right-3 top-2.5 h-5 w-5 text-slate-400" />
                            <input v-model="q" class="input pr-10" placeholder="بحث عن طالب" />
                        </div>
                        <div class="flex rounded-xl bg-slate-100 p-1 text-xs font-bold">
                            <button v-for="[k, l] in [['all', 'الكل'], ['missing', 'ناقصة'], ['resit', 'دور ثانٍ'], ['fail', 'غير ناجح']]" :key="k"
                                class="rounded-lg px-3 py-1.5 transition" :class="only === k ? 'bg-white text-brand-800 shadow-sm' : 'text-slate-500'" @click="only = k">{{ l }}</button>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <template v-if="perm.edit && !viewingPast">
                                <button class="btn-secondary" :disabled="!dirty || hasInvalid || saving" @click="save(false)"><CheckIcon class="h-4 w-4" /> حفظ <span v-if="dirty" class="rounded-full bg-brand-100 px-1.5 text-[11px] text-brand-800">{{ changedRows.length }}</span></button>
                                <button class="btn-primary" :disabled="hasInvalid || saving" @click="save(true)"><CalculatorIcon class="h-4 w-4" /> حفظ واحتساب</button>
                                <a :href="route('offerings.marks.template', offering.id)" class="btn-ghost" title="تنزيل الدرجات بملف Excel لتعبئتها"><ArrowDownTrayIcon class="h-4 w-4" /> Excel</a>
                                <label class="btn-ghost cursor-pointer" :class="(dirty || saving) && 'pointer-events-none opacity-50'" title="رفع ملف Excel المعبّأ">
                                    <ArrowUpTrayIcon class="h-4 w-4" /> رفع
                                    <input type="file" class="hidden" accept=".xlsx,.xls,.csv" @change="importMarks" />
                                </label>
                            </template>
                            <button v-if="perm.submit && !viewingPast" class="btn-success" :disabled="dirty || saving || stats.computed < stats.n || stats.stale > 0"
                                @click="ask('رفع النتائج إلى اللجنة الامتحانية', 'بعد الرفع لا يمكنك تعديل الدرجات إلا إذا أعادتها اللجنة. تأكد من مراجعة جميع الدرجات.', () => post('offerings.submit'), 'success')">
                                <CloudArrowUpIcon class="h-4 w-4" /> رفع للجنة</button>
                            <template v-if="perm.review && !viewingPast">
                                <button class="btn-secondary" @click="showReturn = true"><ArrowUturnRightIcon class="h-4 w-4" /> إعادة للتدريسي</button>
                                <button class="btn-primary" @click="ask('المصادقة على النتائج', `ستُعتمد نتائج ${stats.n} طالب. الخطوة التالية بعد المصادقة هي القفل.`, () => post('offerings.decide', { action: 'approve' }))"><CheckBadgeIcon class="h-4 w-4" /> مصادقة</button>
                            </template>
                            <button v-if="perm.lock && !viewingPast" class="btn-success" @click="ask('قفل النتائج نهائياً', 'بعد القفل لا تتغير أي نتيجة إلا بتصحيح مستند إلى أمر إداري، ويبقى الإصدار السابق محفوظاً.', () => post('offerings.decide', { action: 'lock' }), 'success')"><LockClosedIcon class="h-4 w-4" /> قفل النتائج</button>
                            <button v-if="perm.nextRound" class="btn-primary" @click="ask('فتح الدور الثاني', 'سيُفتح الدور الثاني لطلبة الإعادة فقط لإدخال درجة امتحان الدور الثاني.', () => post('offerings.decide', { action: 'next_round' }))"><ArrowPathIcon class="h-4 w-4" /> فتح الدور الثاني</button>
                        </div>
                    </div>

                    <div v-if="stats.stale && perm.edit" class="flex items-center gap-2 border-b border-amber-100 bg-amber-50 px-4 py-2 text-xs font-semibold text-amber-800">
                        <ExclamationTriangleIcon class="h-4 w-4" /> تغيرت درجات {{ stats.stale }} طالب بعد آخر احتساب. اضغط «حفظ واحتساب» لتحديث النتائج.
                    </div>

                    <div class="max-h-[70vh] overflow-auto">
                        <table v-if="rows.length" class="min-w-full text-sm">
                            <thead class="sticky top-0 z-10 bg-slate-50 text-xs font-bold text-slate-500 shadow-[0_1px_0_0_theme(colors.slate.200)]">
                                <tr>
                                    <th class="w-10 px-3 py-3 text-center">#</th>
                                    <th class="min-w-[220px] px-3 py-3 text-start">الطالب</th>
                                    <th v-for="c in components" :key="c.id" class="min-w-[120px] px-2 py-3 text-center">
                                        <span class="block text-slate-700">{{ c.name }}</span>
                                        <span class="font-normal text-slate-400">من {{ c.max }} · وزن {{ c.weight }}%</span>
                                    </th>
                                    <th class="px-3 py-3 text-center">المجموع</th>
                                    <th class="px-3 py-3 text-center">التقدير</th>
                                    <th class="px-3 py-3 text-center">النتيجة</th>
                                    <th v-if="perm.correct || perm.manage" class="w-10 px-2 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="r in visible" :key="r.enrollment_id" :class="changedRows.includes(String(r.enrollment_id)) ? 'bg-sky-50/60' : 'hover:bg-slate-50/60'">
                                    <td class="px-3 py-2 text-center text-xs tabular-nums text-slate-400">{{ r.idx + 1 }}</td>
                                    <td class="px-3 py-2">
                                        <Link :href="route('students.show', r.student_id)" class="font-bold text-slate-800 hover:text-brand-700">{{ r.name }}</Link>
                                        <p class="font-mono text-[11px] text-slate-400">{{ r.number }}<span v-if="r.carried" class="font-sans"> · محملة</span><span v-if="r.attempt > 1" class="font-sans"> · محاولة {{ r.attempt }}</span></p>
                                    </td>
                                    <td v-for="c in components" :key="c.id" class="px-2 py-1.5 text-center">
                                        <div v-if="perm.edit && c.editable && !viewingPast" class="flex items-center justify-center gap-1">
                                            <input v-model="sheet[r.enrollment_id][c.id].mark" :data-cell="`${r.idx}-${c.id}`" type="number" step="0.5" min="0" :max="c.max" inputmode="decimal"
                                                :disabled="sheet[r.enrollment_id][c.id].absent"
                                                class="w-20 rounded-lg border px-2 py-1.5 text-center font-bold tabular-nums focus:ring-2 disabled:bg-slate-100 disabled:text-slate-300"
                                                :class="invalid(c, sheet[r.enrollment_id][c.id]) ? 'border-rose-400 bg-rose-50 text-rose-700 focus:border-rose-500 focus:ring-rose-200' : 'border-slate-200 focus:border-brand-500 focus:ring-brand-100'"
                                                @keydown.enter="move($event, r.idx, c.id, 1)" @keydown.down="move($event, r.idx, c.id, 1)" @keydown.up="move($event, r.idx, c.id, -1)" @focus="$event.target.select()" />
                                            <button type="button" tabindex="-1" class="rounded-md px-1.5 py-1 text-[11px] font-bold transition"
                                                :class="sheet[r.enrollment_id][c.id].absent ? 'bg-slate-700 text-white' : 'text-slate-400 hover:bg-slate-100'"
                                                title="غائب" @click="sheet[r.enrollment_id][c.id].absent = !sheet[r.enrollment_id][c.id].absent; sheet[r.enrollment_id][c.id].mark = ''">غ</button>
                                        </div>
                                        <span v-else-if="sheet[r.enrollment_id][c.id].absent" class="chip bg-slate-100 text-slate-600 ring-slate-300">غائب</span>
                                        <span v-else class="font-semibold tabular-nums">{{ sheet[r.enrollment_id][c.id].mark === '' ? '—' : sheet[r.enrollment_id][c.id].mark }}</span>
                                    </td>
                                    <td class="px-3 py-2 text-center">
                                        <template v-if="r.result && !changedRows.includes(String(r.enrollment_id))">
                                            <span class="text-lg font-extrabold tabular-nums text-slate-900">{{ r.result.rounded }}</span>
                                            <span v-if="r.result.total !== r.result.rounded" class="block text-[10px] tabular-nums text-slate-400">{{ r.result.total }}</span>
                                        </template>
                                        <span v-else-if="preview(r) !== null" class="font-bold tabular-nums text-slate-400" title="تقدير مبدئي قبل الاحتساب">≈ {{ preview(r) }}</span>
                                        <span v-else class="text-slate-300">—</span>
                                    </td>
                                    <td class="px-3 py-2 text-center"><span v-if="r.result" class="font-semibold">{{ r.result.grade }}</span><span v-else class="text-slate-300">—</span></td>
                                    <td class="px-3 py-2 text-center">
                                        <div v-if="r.result" class="flex flex-col items-center gap-1">
                                            <Badge :map="outcome" :value="r.result.outcome" />
                                            <span class="text-[10px] text-slate-400">{{ resultStatus[r.result.status].label }}<span v-if="r.result.version > 1"> · إصدار {{ r.result.version }}</span><span v-if="r.result.stale" class="text-amber-600"> · تحتاج احتساب</span></span>
                                        </div>
                                        <span v-else class="text-xs text-slate-300">لم تُحتسب</span>
                                    </td>
                                    <td v-if="perm.correct || perm.manage" class="px-2 py-2 text-center">
                                        <button v-if="perm.manage && offering.status === 'open' && round === 1 && (!r.result || r.result.status === 'draft')" class="rounded-lg p-1.5 text-slate-300 hover:bg-rose-50 hover:text-rose-600" title="سحب من الشعبة" @click="withdraw(r)"><UserMinusIcon class="h-4 w-4" /></button>
                                        <button v-if="perm.correct && r.result?.status === 'locked'" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-brand-700" title="تصحيح بأمر إداري" @click="openCorrect(r)"><PencilSquareIcon class="h-4 w-4" /></button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <EmptyState v-else :icon="UsersIcon" :title="round > 1 ? 'لا يوجد طلبة في هذا الدور' : 'لا يوجد طلبة مسجلون'" :text="round > 1 ? '' : 'سجّل طلبة المرحلة في هذه الشعبة لبدء إدخال الدرجات.'">
                            <button v-if="perm.enroll" class="btn-primary" @click="post('offerings.enroll')"><UsersIcon class="h-4 w-4" /> تسجيل طلبة المرحلة</button>
                        </EmptyState>
                    </div>
                    <div v-if="perm.edit && rows.length" class="flex items-center gap-2 border-t border-slate-100 bg-slate-50 px-4 py-2 text-xs text-slate-500">
                        <InformationCircleIcon class="h-4 w-4" /> اضغط Enter أو السهم للانتقال للطالب التالي. «غ» تعني غائب. الرقم الرمادي ≈ تقدير مبدئي حتى الاحتساب.
                    </div>
                </div>
            </div>

            <aside class="space-y-6">
                <section class="card p-5">
                    <h3 class="card-title mb-4">ملخص {{ roundName(round) }}</h3>
                    <div class="mb-1 flex justify-between text-sm"><span class="text-slate-500">اكتمال الدرجات</span><span class="font-bold tabular-nums">{{ stats.complete }} / {{ stats.n }}</span></div>
                    <div class="h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-brand-500 transition-all" :style="{ width: stats.pct + '%' }" /></div>
                    <dl class="mt-4 space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">محتسبة</dt><dd class="font-bold tabular-nums">{{ stats.computed }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">ناجح</dt><dd class="font-bold tabular-nums text-emerald-700">{{ stats.passed }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">نسبة النجاح</dt><dd class="font-bold tabular-nums">{{ stats.computed ? Math.round((stats.passed / stats.computed) * 100) : 0 }}%</dd></div>
                    </dl>
                    <div class="mt-4 flex items-center gap-2 border-t border-slate-100 pt-4"><span class="text-sm text-slate-500">الحالة:</span><Badge :map="offeringStatus" :value="offering.status" dot /></div>
                </section>
                <section v-if="gradeScale.length" class="card p-5">
                    <h3 class="card-title mb-3">سلّم التقديرات</h3>
                    <ul class="space-y-1.5 text-sm">
                        <li v-for="b in gradeScale" :key="b.code" class="flex items-center justify-between rounded-lg px-2 py-1" :class="b.outcome === 'pass' ? 'bg-emerald-50/50' : b.outcome === 'resit' ? 'bg-amber-50/60' : 'bg-rose-50/50'">
                            <span class="font-semibold" :class="outcomeTone[b.outcome]">{{ b.name }} <span class="text-xs text-slate-400">{{ b.code }}</span></span>
                            <span class="ltr tabular-nums text-slate-500">{{ b.min }}–{{ b.max }}</span>
                        </li>
                    </ul>
                </section>
            </aside>
        </div>

        <!-- confirm -->
        <Modal :show="confirmBox.show" max-width="md" @close="confirmBox.show = false">
            <div class="p-6">
                <h3 class="text-lg font-extrabold">{{ confirmBox.title }}</h3>
                <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ confirmBox.text }}</p>
                <div class="mt-6 flex justify-end gap-2">
                    <button class="btn-secondary" @click="confirmBox.show = false">إلغاء</button>
                    <button :class="confirmBox.tone === 'success' ? 'btn-success' : 'btn-primary'" @click="runConfirm">تأكيد</button>
                </div>
            </div>
        </Modal>

        <!-- return -->
        <Modal :show="showReturn" max-width="md" @close="showReturn = false">
            <form class="p-6" @submit.prevent="sendBack">
                <h3 class="text-lg font-extrabold">إعادة النتائج إلى التدريسي</h3>
                <p class="mt-1 text-sm text-slate-500">سيتمكن التدريسي من تعديل الدرجات ثم رفعها مجدداً. يُحفظ السبب في سجل التدقيق.</p>
                <label class="label mt-4">سبب الإعادة</label>
                <textarea v-model="returnForm.reason" rows="3" class="input" required />
                <InputError :message="returnForm.errors.reason" class="mt-1" />
                <div class="mt-6 flex justify-end gap-2"><button type="button" class="btn-secondary" @click="showReturn = false">إلغاء</button><button class="btn-danger" :disabled="returnForm.processing">إعادة</button></div>
            </form>
        </Modal>

        <!-- correction -->
        <Modal :show="showCorrect" max-width="xl" @close="showCorrect = false">
            <form v-if="correctRow" class="p-6" @submit.prevent="saveCorrect">
                <h3 class="text-lg font-extrabold">تصحيح نتيجة مقفلة</h3>
                <p class="mt-1 text-sm text-slate-500">{{ correctRow.name }} · النتيجة الحالية {{ correctRow.result.rounded }} ({{ correctRow.result.grade }}). يُنشأ إصدار جديد ويبقى الإصدار الحالي محفوظاً في السجل.</p>
                <div class="mt-5 grid gap-3 sm:grid-cols-3">
                    <div v-for="c in components" :key="c.id">
                        <label class="label">{{ c.name }} <span class="font-normal text-slate-400">/ {{ c.max }}</span></label>
                        <div class="flex gap-1">
                            <input v-model="correctForm.marks[c.id].mark" type="number" step="0.5" min="0" :max="c.max" class="input text-center font-bold" :disabled="correctForm.marks[c.id].absent" />
                            <button type="button" class="rounded-lg px-2 text-xs font-bold" :class="correctForm.marks[c.id].absent ? 'bg-slate-700 text-white' : 'bg-slate-100 text-slate-500'" @click="correctForm.marks[c.id].absent = !correctForm.marks[c.id].absent">غ</button>
                        </div>
                    </div>
                </div>
                <div class="mt-4">
                    <div class="mb-1.5 flex items-center justify-between"><label class="label mb-0">أمر التصحيح الإداري <span class="text-rose-500">*</span></label>
                        <button v-if="page.props.can.orders" type="button" class="flex items-center gap-1 text-xs font-bold text-brand-700 hover:underline" @click="showOrder = true"><PlusIcon class="h-3.5 w-3.5" /> أمر جديد</button></div>
                    <select v-model="correctForm.order_id" class="input" required><option value="" disabled>اختر الأمر</option><option v-for="o in orders" :key="o.id" :value="o.id">{{ o.label }}</option></select>
                    <p v-if="!orders.length" class="mt-1 text-xs text-amber-700">لا توجد أوامر «تصحيح نتيجة» مسجلة. سجّل الأمر أولاً.</p>
                    <InputError :message="correctForm.errors.order_id" class="mt-1" />
                </div>
                <div class="mt-4"><label class="label">سبب التصحيح <span class="text-rose-500">*</span></label><textarea v-model="correctForm.reason" rows="2" class="input" required /><InputError :message="correctForm.errors.reason" class="mt-1" /></div>
                <div class="mt-6 flex justify-end gap-2"><button type="button" class="btn-secondary" @click="showCorrect = false">إلغاء</button><button class="btn-primary" :disabled="correctForm.processing">حفظ التصحيح</button></div>
            </form>
        </Modal>
        <OfferingManage :show="showManage" :offering="offering" :components="allComponents" :can-edit-components="perm.editComponents" @close="showManage = false" />
        <OrderModal :show="showOrder" :types="{ result_correction: 'تصحيح نتيجة' }" type="result_correction" @close="showOrder = false" @saved="orderSaved" />
    </AppLayout>
</template>
