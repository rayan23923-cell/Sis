<script setup>
import { computed, reactive, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Badge from '@/Components/Badge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { ArrowDownTrayIcon, ExclamationTriangleIcon, PrinterIcon, TableCellsIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ years: Array, programs: Array, selected: Object, sheet: Object });

const f = reactive({ ...props.selected });
const program = computed(() => props.programs.find((p) => p.id == f.program));
watch(() => ({ ...f }), (v) => router.get(route('master-sheet.show'), v, { preserveState: true, preserveScroll: true, replace: true }), { deep: true });

const priorityLabel = { reduce_failures: 'تقليل عدد مواد الرسوب', lower_coursework: 'المواد ذات السعي الأقل', more_units: 'المواد ذات الوحدات الأكثر', closest_specialty: 'المواد الأقرب للتخصص' };
const modeLabel = { benefit: 'حيثما يستفيد الطالب', status: 'لتغيير الحالة فقط' };
const resultTone = (r) => r.startsWith('ناجح') ? (r.includes('بالعبور') ? 'violet' : r.includes('بقرار') ? 'sky' : 'emerald') : r.startsWith('راسب') ? 'rose' : 'slate';

// cell colours as in the paper master sheet: decision green, fail red, round-2 deduction blue, carried purple
const cellClass = (c) => {
    if (!c || c.entitlement === null) return 'text-slate-300';
    if (!c.passed) return 'bg-rose-50 text-rose-700 font-bold';
    if (c.award) return 'bg-emerald-50 text-emerald-700 font-bold';
    if (c.deducted && c.carried) return 'bg-violet-50 text-violet-700 font-bold';
    if (c.deducted) return 'bg-sky-50 text-sky-700 font-bold';
    return 'font-bold text-slate-800';
};
const carriedOf = (s) => Object.values(s.cells).filter((c) => c && c.carried);
const params = computed(() => ({ ...f }));
</script>

<template>
    <AppLayout title="الماستر شيت">
        <PageHeader title="الماستر شيت" subtitle="درجات المرحلة لكل الطلبة والمواد: السعي والدوران والقرار والاستحقاق والمعدل والنتيجة، بطريقة الماستر شيت للنظام السنوي.">
            <Link :href="route('progression.index', { program: f.program, stage: f.stage, year: f.year })" class="btn-secondary">نتائج نهاية السنة</Link>
            <a v-if="sheet" :href="route('master-sheet.excel', params)" class="btn-secondary"><ArrowDownTrayIcon class="h-4 w-4" /> Excel</a>
            <a v-if="sheet" :href="route('master-sheet.pdf', params)" target="_blank" class="btn-primary"><PrinterIcon class="h-4 w-4" /> PDF للطباعة</a>
        </PageHeader>

        <div class="card mb-4 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <select v-model="f.program" class="input" aria-label="البرنامج"><option v-for="p in programs" :key="p.id" :value="p.id">{{ p.name }} — {{ p.department }}</option></select>
            <select v-model.number="f.stage" class="input" aria-label="المرحلة"><option v-for="n in program?.years ?? 6" :key="n" :value="n">المرحلة {{ n }}</option></select>
            <select v-model="f.year" class="input" aria-label="العام الدراسي"><option v-for="y in years" :key="y.id" :value="y.id">{{ y.name }}</option></select>
            <div class="flex gap-1 rounded-xl bg-slate-100 p-1 text-sm font-semibold">
                <button v-for="[k, l] in [[1, 'الدور الأول'], [2, 'بعد الدور الثاني']]" :key="k" class="flex-1 rounded-lg px-3 py-1.5 transition" :class="f.round === k ? 'bg-white text-brand-800 shadow-sm' : 'text-slate-500'" @click="f.round = k">{{ l }}</button>
            </div>
        </div>

        <template v-if="sheet">
            <div v-for="p in sheet.problems" :key="p" class="mb-3 flex items-start gap-2 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800"><ExclamationTriangleIcon class="mt-0.5 h-5 w-5 shrink-0" /> {{ p }}</div>

            <div v-if="sheet.policy" class="card mb-4 p-4 text-sm text-slate-600">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <span>القرار للدور الأول: <b class="text-slate-800">{{ sheet.policy.amount1 }} درجات</b> ({{ modeLabel[sheet.policy.mode1] }})</span>
                    <span>إضافي للدور الثاني: <b class="text-slate-800">{{ sheet.policy.amount2 }}</b></span>
                    <span>خصم الدور الثاني والتحميل: <b class="text-slate-800">{{ sheet.policy.deduction }}</b></span>
                    <span>العبور: <b class="text-slate-800">{{ sheet.policy.max_carried ? `حتى ${sheet.policy.max_carried} مادة` : 'لا يوجد' }}</b></span>
                    <Badge v-if="!sheet.policy.official" tone="amber">قيم غير رسمية</Badge>
                    <Link :href="route('policies.index')" class="ms-auto text-xs font-semibold text-brand-700 hover:underline">تعديل من السياسات</Link>
                </div>
                <p class="mt-1 text-xs text-slate-400">أولويات القرار: مواد التحميل أولاً، ثم <span v-for="(p, i) in sheet.policy.priorities" :key="p">{{ priorityLabel[p] }}<span v-if="i < sheet.policy.priorities.length - 1">، ثم </span></span>.</p>
            </div>

            <div class="mb-4 grid grid-cols-2 gap-4 lg:grid-cols-5">
                <div class="card p-4"><p class="text-sm text-slate-500">الطلبة</p><p class="text-2xl font-extrabold tabular-nums">{{ sheet.stats.students }}</p><p class="text-xs text-slate-400">مكتملة النتائج {{ sheet.stats.complete }}</p></div>
                <div class="card p-4"><p class="text-sm text-slate-500">الناجحون</p><p class="text-2xl font-extrabold tabular-nums text-emerald-600">{{ sheet.stats.passed }}</p></div>
                <div class="card p-4"><p class="text-sm text-slate-500">نسبة النجاح</p><p class="text-2xl font-extrabold tabular-nums">{{ sheet.stats.rate ?? '—' }}<span v-if="sheet.stats.rate !== null" class="text-base">%</span></p></div>
                <div class="card p-4"><p class="text-sm text-slate-500">ناجح بقرار</p><p class="text-2xl font-extrabold tabular-nums text-sky-600">{{ sheet.stats.with_decision }}</p></div>
                <div class="card p-4"><p class="text-sm text-slate-500">ناجح بالعبور</p><p class="text-2xl font-extrabold tabular-nums text-violet-600">{{ sheet.stats.pass_through }}</p></div>
            </div>

            <div class="mb-2 flex flex-wrap gap-3 text-xs text-slate-500">
                <span class="flex items-center gap-1"><span class="h-3 w-3 rounded bg-emerald-100"></span> بقرار</span>
                <span class="flex items-center gap-1"><span class="h-3 w-3 rounded bg-rose-100"></span> راسب</span>
                <span class="flex items-center gap-1"><span class="h-3 w-3 rounded bg-sky-100"></span> خصم الدور الثاني</span>
                <span class="flex items-center gap-1"><span class="h-3 w-3 rounded bg-violet-100"></span> خصم التحميل</span>
            </div>

            <section class="card overflow-hidden">
                <EmptyState v-if="!sheet.courses.length" :icon="TableCellsIcon" title="لا توجد شعب لهذه المرحلة في هذا العام" />
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full border-collapse text-xs">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th rowspan="2" class="sticky start-0 z-10 min-w-[200px] border-b border-e border-slate-200 bg-slate-50 px-3 py-2 text-start">الطالب</th>
                                <th v-for="c in sheet.courses" :key="c.code" colspan="4" class="border-b border-e border-slate-200 px-2 py-2 text-center">
                                    <span class="block font-bold text-slate-800">{{ c.name }}</span>
                                    <span class="font-normal text-slate-400">{{ c.units }} وحدة · سعي {{ c.coursework_max }}</span>
                                </th>
                                <th rowspan="2" class="border-b border-e border-slate-200 px-2 py-2">التحميل</th>
                                <th rowspan="2" class="border-b border-e border-slate-200 px-2 py-2">المعدل</th>
                                <th rowspan="2" class="min-w-[150px] border-b border-slate-200 px-2 py-2">النتيجة</th>
                            </tr>
                            <tr>
                                <template v-for="c in sheet.courses" :key="c.code + 'h'">
                                    <th class="border-b border-slate-200 px-1.5 py-1 font-semibold">سعي</th>
                                    <th class="border-b border-slate-200 px-1.5 py-1 font-semibold">د1</th>
                                    <th class="border-b border-slate-200 px-1.5 py-1 font-semibold">د2</th>
                                    <th class="border-b border-e border-slate-200 px-1.5 py-1 font-semibold">الاستحقاق</th>
                                </template>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="s in sheet.students" :key="s.student_program_id" class="hover:bg-slate-50/60">
                                <td class="sticky start-0 z-10 border-e border-slate-200 bg-white px-3 py-2">
                                    <Link :href="route('students.show', s.student_id)" class="font-bold text-slate-800 hover:text-brand-700">{{ s.name }}</Link>
                                    <p class="font-mono text-[10px] text-slate-400">{{ s.number }}</p>
                                </td>
                                <template v-for="c in sheet.courses" :key="c.code">
                                    <template v-if="s.cells[c.code]">
                                        <td class="px-1.5 py-2 text-center tabular-nums text-slate-500">{{ s.cells[c.code].coursework ?? '—' }}</td>
                                        <td class="px-1.5 py-2 text-center tabular-nums" :class="s.cells[c.code].r1 !== null && s.cells[c.code].r1 < 50 ? 'text-rose-600' : ''">{{ s.cells[c.code].r1 ?? '—' }}</td>
                                        <td class="px-1.5 py-2 text-center tabular-nums">{{ s.cells[c.code].r2 ?? '' }}</td>
                                        <td class="border-e border-slate-100 px-1.5 py-2 text-center tabular-nums" :class="cellClass(s.cells[c.code])" :title="s.cells[c.code].award ? `قرار ${s.cells[c.code].award}` : ''">
                                            {{ s.cells[c.code].entitlement ?? '—' }}
                                            <span v-if="s.cells[c.code].grade" class="block text-[10px] font-normal">{{ s.cells[c.code].grade }}</span>
                                        </td>
                                    </template>
                                    <td v-else colspan="4" class="border-e border-slate-100 text-center text-slate-300">غير مسجل</td>
                                </template>
                                <td class="border-e border-slate-100 px-2 py-2">
                                    <p v-for="x in carriedOf(s)" :key="x.enrollment_id" class="whitespace-nowrap" :class="cellClass(x)">{{ x.name }}: {{ x.entitlement ?? '—' }}</p>
                                </td>
                                <td class="border-e border-slate-100 px-2 py-2 text-center font-extrabold tabular-nums">{{ s.gpa ?? '—' }}</td>
                                <td class="px-2 py-2">
                                    <Badge :tone="resultTone(s.result)">{{ s.result }}</Badge>
                                    <p v-if="s.failed.length" class="mt-1 text-[10px] text-rose-600">{{ s.failed.map((x) => x.name).join('، ') }}</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="sheet.carried.length" class="card mt-6">
                <div class="card-header"><h2 class="card-title">طلبة المرحلة الأعلى المحمّلون بمواد هذه المرحلة</h2></div>
                <ul class="divide-y divide-slate-100">
                    <li v-for="s in sheet.carried" :key="s.student_id" class="flex flex-wrap items-center gap-3 px-5 py-2.5 text-sm">
                        <span class="font-bold text-slate-800">{{ s.name }}</span><span class="font-mono text-xs text-slate-400">{{ s.number }}</span>
                        <span v-for="c in s.courses" :key="c.enrollment_id" class="rounded-lg px-2 py-0.5 text-xs" :class="cellClass({ ...c, passed: (c.mark ?? 0) >= 50, deducted: c.entitlement < c.mark, carried: true })">{{ c.name }}: {{ c.entitlement ?? '—' }}</span>
                    </li>
                </ul>
            </section>
        </template>
    </AppLayout>
</template>
