<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { ArrowDownTrayIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ years: Array, year: Number, tables: Array });
const sel = ref(props.year);
watch(sel, (y) => router.get(route('reports.index'), { year: y }, { preserveScroll: true }));
const isNum = (v) => typeof v === 'number';
// share of a numeric cell in its column, for the inline bar
const max = (t, i) => Math.max(1, ...t.rows.map((r) => (isNum(r[i]) ? r[i] : 0)));
</script>

<template>
    <AppLayout title="التقارير والإحصاءات">
        <PageHeader title="التقارير والإحصاءات" subtitle="أعداد الطلبة والقبول والنتائج والخريجين ضمن صلاحياتك. كل جدول قابل للتصدير إلى Excel.">
            <select v-model="sel" class="input w-44"><option v-for="y in years" :key="y.id" :value="y.id">{{ y.name }}</option></select>
        </PageHeader>

        <div class="space-y-6">
            <section v-for="t in tables" :key="t.key" class="card">
                <div class="card-header flex items-center gap-2">
                    <h2 class="card-title">{{ t.title }}</h2>
                    <a :href="route('reports.export', { table: t.key, year: sel })" class="btn-ghost ms-auto text-xs"><ArrowDownTrayIcon class="h-4 w-4" /> Excel</a>
                </div>
                <EmptyState v-if="!t.rows.length" title="لا توجد بيانات لهذا العام" />
                <div v-else class="overflow-x-auto">
                    <table class="table-base">
                        <thead><tr><th v-for="c in t.columns" :key="c" class="whitespace-nowrap">{{ c }}</th></tr></thead>
                        <tbody>
                            <tr v-for="(r, ri) in t.rows" :key="ri">
                                <td v-for="(v, i) in r" :key="i" class="whitespace-nowrap" :class="isNum(v) && 'tabular-nums'">
                                    <template v-if="isNum(v) && i > 0">
                                        <div class="flex items-center gap-2">
                                            <span class="w-8">{{ v }}</span>
                                            <span class="hidden h-1.5 rounded-full bg-brand-200 sm:block" :style="{ width: Math.round((v / max(t, i)) * 48) + 'px' }" />
                                        </div>
                                    </template>
                                    <template v-else>{{ v }}</template>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot v-if="t.total"><tr class="bg-slate-50 font-bold"><td v-for="(v, i) in t.total" :key="i" class="tabular-nums">{{ v }}</td></tr></tfoot>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
