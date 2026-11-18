<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Badge from '@/Components/Badge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Modal from '@/Components/Modal.vue';
import { ArrowDownTrayIcon, BanknotesIcon, ChevronLeftIcon, ChevronRightIcon, DocumentPlusIcon, ExclamationTriangleIcon, MagnifyingGlassIcon, TableCellsIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ years: Array, year: Number, filters: Object, totals: Object, rows: Array, pagination: Object, colleges: Array, programs: Array, channels: Object, shifts: Object, statuses: Object, schedules: Number, perm: Object });

const money = (n) => Number(n ?? 0).toLocaleString('en-US');
// an empty filter set arrives as [] (whose .shift is Array.prototype.shift), so read from a plain object
const fl = Array.isArray(props.filters) ? {} : props.filters;
const f = reactive({ year: props.year, q: fl.q ?? '', college: fl.college ?? '', program: fl.program ?? '', status: fl.status ?? '', channel: fl.channel ?? '', shift: fl.shift ?? '' });
const params = (extra = {}) => Object.fromEntries(Object.entries({ ...f, ...extra }).filter(([, v]) => v !== '' && v !== null));
const apply = (page = 1) => router.get(route('finance.index'), params(page > 1 ? { page } : {}), { preserveState: true, preserveScroll: true, replace: true });
let t;
watch(() => f.q, () => { clearTimeout(t); t = setTimeout(apply, 350); });
watch(() => [f.year, f.college, f.program, f.status, f.channel, f.shift], () => apply());

const rate = computed(() => {
    const due = props.totals.invoiced - props.totals.discounts;
    return due > 0 ? Math.round(props.totals.collected / due * 100) : 0;
});
const statusTone = { open: 'amber', paid: 'emerald', cancelled: 'slate' };

const bulk = ref(false);
const bulkForm = useForm({ year: props.year, program: '' });
const runBulk = () => bulkForm.post(route('finance.invoice-all'), { preserveScroll: true, onSuccess: () => { bulk.value = false; } });
</script>

<template>
    <AppLayout title="الرسوم والأقساط">
        <PageHeader title="الرسوم والأقساط" subtitle="مطالبات الطلبة وأقساطهم والمبالغ المحصلة والمتبقية. المبالغ بالدينار العراقي.">
            <Link :href="route('finance.schedules', { year: f.year })" class="btn-secondary"><TableCellsIcon class="h-4 w-4" /> تعرفة الرسوم</Link>
            <a :href="route('finance.export', params())" class="btn-secondary"><ArrowDownTrayIcon class="h-4 w-4" /> Excel</a>
            <button v-if="perm.collect" class="btn-primary" @click="bulk = true"><DocumentPlusIcon class="h-4 w-4" /> إصدار المطالبات</button>
        </PageHeader>

        <div v-if="!schedules" class="mb-4 flex items-start gap-2 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <ExclamationTriangleIcon class="mt-0.5 h-5 w-5 shrink-0" />
            <span>لا توجد تعرفة رسوم فعالة لهذا العام. أدخل المبالغ المعتمدة من الجامعة في <Link :href="route('finance.schedules', { year: f.year })" class="font-bold underline">تعرفة الرسوم</Link> قبل إصدار المطالبات. الطالب الذي لا تنطبق عليه تعرفة لا تصدر له مطالبة.</span>
        </div>

        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-5">
            <div class="card p-4"><p class="text-sm text-slate-500">المطالبات</p><p class="text-2xl font-extrabold tabular-nums">{{ money(totals.invoices) }}</p><p class="text-xs text-slate-400">مسددة بالكامل {{ money(totals.paid_invoices) }}</p></div>
            <div class="card p-4"><p class="text-sm text-slate-500">مجموع الرسوم</p><p class="text-2xl font-extrabold tabular-nums">{{ money(totals.invoiced - totals.discounts) }}</p><p v-if="totals.discounts" class="text-xs text-slate-400">بعد تخفيض {{ money(totals.discounts) }}</p></div>
            <div class="card p-4"><p class="text-sm text-slate-500">المحصّل</p><p class="text-2xl font-extrabold tabular-nums text-emerald-600">{{ money(totals.collected) }}</p>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-emerald-500" :style="{ width: rate + '%' }"></div></div><p class="mt-1 text-xs text-slate-400">{{ rate }}%</p></div>
            <div class="card p-4"><p class="text-sm text-slate-500">المتبقي</p><p class="text-2xl font-extrabold tabular-nums text-amber-600">{{ money(totals.outstanding) }}</p></div>
            <div class="card p-4"><p class="text-sm text-slate-500">متأخر السداد</p><p class="text-2xl font-extrabold tabular-nums" :class="totals.overdue ? 'text-rose-600' : ''">{{ money(totals.overdue) }}</p></div>
        </div>

        <div class="card mb-4 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="relative sm:col-span-2">
                <MagnifyingGlassIcon class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input v-model="f.q" class="input ps-9" placeholder="اسم الطالب أو رقمه الجامعي" />
            </div>
            <select v-model="f.year" class="input" aria-label="العام الدراسي"><option v-for="y in years" :key="y.id" :value="y.id">{{ y.name }}</option></select>
            <select v-model="f.status" class="input" aria-label="الحالة"><option value="">كل الحالات</option><option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option><option value="overdue">متأخرة السداد</option></select>
            <select v-model="f.college" class="input" aria-label="الكلية"><option value="">كل الكليات</option><option v-for="c in colleges" :key="c.id" :value="c.id">{{ c.name_ar }}</option></select>
            <select v-model="f.program" class="input" aria-label="البرنامج"><option value="">كل البرامج</option><option v-for="p in programs" :key="p.id" :value="p.id">{{ p.name }} — {{ p.department }}</option></select>
            <select v-model="f.channel" class="input" aria-label="قناة القبول"><option value="">كل القنوات</option><option v-for="(l, k) in channels" :key="k" :value="k">{{ l }}</option></select>
            <select v-model="f.shift" class="input" aria-label="الدراسة"><option value="">صباحي ومسائي</option><option v-for="(l, k) in shifts" :key="k" :value="k">{{ l }}</option></select>
        </div>

        <section class="card">
            <EmptyState v-if="!rows.length" :icon="BanknotesIcon" title="لا توجد مطالبات" :text="perm.collect ? 'أصدر المطالبات بعد إدخال التعرفة.' : ''" />
            <div v-else class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>الطالب</th><th>البرنامج</th><th>القناة</th><th>الرسوم</th><th>المدفوع</th><th>المتبقي</th><th>القسط القادم</th><th>الحالة</th></tr></thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.id" class="cursor-pointer hover:bg-slate-50" @click="router.visit(route('finance.student', r.student_id))">
                            <td><p class="font-bold text-slate-800">{{ r.name }}</p><p class="font-mono text-xs text-slate-400">{{ r.number }}</p></td>
                            <td class="text-sm">{{ r.program }}<span class="text-slate-400"> · {{ r.stage }}</span></td>
                            <td class="text-sm">{{ r.channel_label }}<Badge v-if="r.shift === 'evening'" tone="violet" class="ms-1">مسائي</Badge></td>
                            <td class="tabular-nums">{{ money(r.amount - r.discount) }}<p v-if="r.discount" class="text-xs text-slate-400">تخفيض {{ money(r.discount) }}</p></td>
                            <td class="tabular-nums text-emerald-700">{{ money(r.paid) }}</td>
                            <td class="font-bold tabular-nums">{{ money(r.balance) }}<p v-if="r.overdue" class="text-xs font-semibold text-rose-600">متأخر {{ money(r.overdue) }}</p></td>
                            <td class="ltr text-start text-sm tabular-nums text-slate-500">{{ r.next_due ?? '—' }}</td>
                            <td><Badge :tone="r.overdue ? 'rose' : statusTone[r.status]">{{ r.overdue ? 'متأخرة' : r.status_label }}</Badge></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="pagination.pages > 1" class="flex items-center justify-between border-t border-slate-100 px-5 py-3 text-sm text-slate-500">
                <span>{{ pagination.total }} مطالبة · صفحة {{ pagination.page }} من {{ pagination.pages }}</span>
                <div class="flex gap-2">
                    <button class="btn-secondary" :disabled="pagination.page <= 1" @click="apply(pagination.page - 1)"><ChevronRightIcon class="h-4 w-4 rtl:rotate-0 ltr:rotate-180" /> السابق</button>
                    <button class="btn-secondary" :disabled="pagination.page >= pagination.pages" @click="apply(pagination.page + 1)">التالي <ChevronLeftIcon class="h-4 w-4 ltr:rotate-180" /></button>
                </div>
            </div>
        </section>

        <Modal :show="bulk" max-width="lg" @close="bulk = false">
            <form class="p-6" @submit.prevent="runBulk">
                <h3 class="text-lg font-extrabold">إصدار المطالبات</h3>
                <p class="mt-2 text-sm text-slate-600">تصدر مطالبة لكل طالب مستمر تنطبق عليه تعرفة فعالة وليس لديه مطالبة لهذا العام. من لا تنطبق عليه تعرفة (مثل الدراسة الصباحية المركزية المجانية) لا تصدر له مطالبة.</p>
                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div><label class="label">العام الدراسي</label><select v-model="bulkForm.year" class="input"><option v-for="y in years" :key="y.id" :value="y.id">{{ y.name }}</option></select></div>
                    <div><label class="label">البرنامج</label><select v-model="bulkForm.program" class="input"><option value="">كل البرامج</option><option v-for="p in programs" :key="p.id" :value="p.id">{{ p.name }}</option></select></div>
                </div>
                <div class="mt-6 flex justify-end gap-2"><button type="button" class="btn-secondary" @click="bulk = false">إلغاء</button><button class="btn-primary" :disabled="bulkForm.processing">إصدار</button></div>
            </form>
        </Modal>
    </AppLayout>
</template>
