<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Badge from '@/Components/Badge.vue';
import Modal from '@/Components/Modal.vue';
import OrderModal from '@/Components/OrderModal.vue';
import EmptyState from '@/Components/EmptyState.vue';
import InputError from '@/Components/InputError.vue';
import { systems } from '@/lib/labels';
import { ArrowTrendingUpIcon, CalculatorIcon, CheckBadgeIcon, ExclamationTriangleIcon, PlusIcon, PrinterIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ years: Array, programs: Array, selected: Object, rows: Array, decisions: Object, canDecide: Boolean, orders: Array, orderTypes: Object });
const sel = reactive({ ...props.selected });
watch(sel, () => router.get(route('progression.index'), { ...sel }, { preserveState: true, replace: true }));
const program = computed(() => props.programs.find((p) => p.id == sel.program));
const tone = { promoted: 'emerald', promoted_with_carried: 'amber', repeat: 'rose', graduate: 'violet' };

const summary = computed(() => {
    const s = { ready: 0, problems: 0, saved: 0, applied: 0, byDecision: {} };
    for (const r of props.rows) {
        if (r.decision) { s.ready++; s.byDecision[r.decision] = (s.byDecision[r.decision] ?? 0) + 1; } else s.problems++;
        if (r.saved?.status === 'draft') s.saved++;
        if (r.saved?.status === 'applied') s.applied++;
    }
    return s;
});

const busy = ref(false);
const save = () => { busy.value = true; router.post(route('progression.save'), { ...sel }, { preserveScroll: true, onFinish: () => (busy.value = false) }); };
const showApply = ref(false);
const showOrder = ref(false);
const applyForm = useForm({ order_id: '' });
const apply = () => applyForm.transform((d) => ({ ...d, ...sel })).post(route('progression.apply'), { preserveScroll: true, onSuccess: () => (showApply.value = false) });
const orderSaved = (id) => router.reload({ only: ['orders'], onSuccess: () => { if (id) applyForm.order_id = id; } });
</script>

<template>
    <AppLayout title="نتائج نهاية السنة">
        <PageHeader title="نتائج نهاية السنة" subtitle="المعدل وقرار الترفيع أو التحميل أو الرسوب أو التخرج لكل طالب، حسب السياسات المعتمدة. لا يتغير شيء قبل التطبيق بأمر إداري." />

        <div class="card mb-6 grid gap-3 p-4 sm:grid-cols-3">
            <select v-model="sel.year" class="input"><option v-for="y in years" :key="y.id" :value="y.id">{{ y.name }}</option></select>
            <select v-model="sel.program" class="input"><option v-for="p in programs" :key="p.id" :value="p.id">{{ p.name }}</option></select>
            <select v-model="sel.stage" class="input"><option v-for="n in program?.years ?? 6" :key="n" :value="n">المرحلة {{ n }}</option></select>
        </div>

        <div class="mb-6 grid gap-4 sm:grid-cols-4">
            <div class="card p-4"><p class="text-sm text-slate-500">جاهز للقرار</p><p class="text-2xl font-extrabold tabular-nums">{{ summary.ready }}</p></div>
            <div class="card p-4"><p class="text-sm text-slate-500">ينتظر نتائج</p><p class="text-2xl font-extrabold tabular-nums text-amber-600">{{ summary.problems }}</p></div>
            <div class="card p-4"><p class="text-sm text-slate-500">محفوظ كمسودة</p><p class="text-2xl font-extrabold tabular-nums">{{ summary.saved }}</p></div>
            <div class="card p-4"><p class="text-sm text-slate-500">مُطبَّق</p><p class="text-2xl font-extrabold tabular-nums text-emerald-600">{{ summary.applied }}</p></div>
        </div>

        <div class="card">
            <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 p-4">
                <Badge v-for="(n, d) in summary.byDecision" :key="d" :tone="tone[d]">{{ decisions[d] }}: {{ n }}</Badge>
                <Badge v-if="program" tone="slate" class="ms-auto">{{ systems[program.system] }}</Badge>
                <a v-if="rows.length" :href="route('progression.pdf', { program: sel.program, stage: sel.stage, year: sel.year })" target="_blank" class="btn-secondary"><PrinterIcon class="h-4 w-4" /> طباعة PDF</a>
                <template v-if="canDecide">
                    <button class="btn-secondary" :disabled="busy || !summary.ready" @click="save"><CalculatorIcon class="h-4 w-4" /> حفظ القرارات كمسودة</button>
                    <button class="btn-success" :disabled="!summary.saved" @click="showApply = true"><CheckBadgeIcon class="h-4 w-4" /> تطبيق بأمر إداري</button>
                </template>
            </div>
            <div class="overflow-x-auto">
                <table v-if="rows.length" class="table-base">
                    <thead><tr><th>الطالب</th><th>المواد</th><th>الوحدات المكتسبة</th><th>المعدل</th><th>الراسب فيها</th><th>القرار</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="r in rows" :key="r.student_program_id">
                            <td><Link :href="route('students.show', r.student_id)" class="font-bold text-slate-800 hover:text-brand-700">{{ r.name }}</Link><p class="font-mono text-[11px] text-slate-400">{{ r.number }}</p></td>
                            <td class="tabular-nums">{{ r.courses }}</td>
                            <td class="tabular-nums">{{ r.credits }}</td>
                            <td class="text-lg font-extrabold tabular-nums">{{ r.gpa ?? '—' }}</td>
                            <td class="whitespace-normal text-xs"><span v-for="f in r.failed" :key="f.code" class="me-1 inline-block rounded bg-rose-50 px-1.5 py-0.5 text-rose-700">{{ f.name }}</span><span v-if="!r.failed.length" class="text-slate-300">—</span></td>
                            <td class="whitespace-normal">
                                <div v-if="r.decision" class="flex flex-wrap items-center gap-1">
                                    <Badge :tone="tone[r.decision]" dot>{{ decisions[r.decision] }}</Badge>
                                    <Badge v-if="r.saved?.status === 'applied'" tone="emerald">مُطبَّق</Badge>
                                    <Badge v-else-if="r.saved?.status === 'draft'" tone="slate">مسودة</Badge>
                                </div>
                                <p v-else class="flex items-start gap-1 text-xs text-amber-700"><ExclamationTriangleIcon class="mt-0.5 h-4 w-4 shrink-0" /> {{ r.problem }}</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-else :icon="ArrowTrendingUpIcon" title="لا يوجد طلبة مستمرون في هذه المرحلة" />
            </div>
        </div>

        <Modal :show="showApply" max-width="md" @close="showApply = false">
            <form class="p-6" @submit.prevent="apply">
                <h3 class="text-lg font-extrabold">تطبيق قرارات نهاية السنة</h3>
                <p class="mt-1 text-sm text-slate-500">سيُنقل المرفّعون إلى المرحلة التالية، ويُسجَّل المتخرجون بحالة «متخرج». القرار المطبق لا يُعدَّل.</p>
                <div class="mt-5">
                    <div class="mb-1.5 flex items-center justify-between"><label class="label mb-0">أمر إقرار النتائج</label>
                        <button v-if="$page.props.can.orders" type="button" class="flex items-center gap-1 text-xs font-bold text-brand-700 hover:underline" @click="showOrder = true"><PlusIcon class="h-3.5 w-3.5" /> أمر جديد</button></div>
                    <select v-model="applyForm.order_id" class="input" required><option value="" disabled>اختر الأمر</option><option v-for="o in orders" :key="o.id" :value="o.id">{{ o.label }}</option></select>
                    <InputError :message="applyForm.errors.order_id" class="mt-1" />
                </div>
                <div class="mt-6 flex justify-end gap-2"><button type="button" class="btn-secondary" @click="showApply = false">إلغاء</button><button class="btn-success" :disabled="applyForm.processing">تطبيق</button></div>
            </form>
        </Modal>
        <OrderModal :show="showOrder" :types="{ year_results: orderTypes.year_results }" type="year_results" @close="showOrder = false" @saved="orderSaved" />
    </AppLayout>
</template>
