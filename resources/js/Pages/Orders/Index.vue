<script setup>
import { reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Badge from '@/Components/Badge.vue';
import Pagination from '@/Components/Pagination.vue';
import EmptyState from '@/Components/EmptyState.vue';
import OrderModal from '@/Components/OrderModal.vue';
import { DocumentTextIcon, MagnifyingGlassIcon, PlusIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ orders: Object, filters: Object, types: Object, colleges: Array });
const f = reactive({ q: props.filters.q ?? '', type: props.filters.type ?? '' });
let t;
watch(f, () => { clearTimeout(t); t = setTimeout(() => router.get(route('orders.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300); });
const show = ref(false);
const tone = { admission: 'emerald', dismissal: 'rose', result_correction: 'violet', postponement: 'amber', reinstatement: 'sky' };
</script>

<template>
    <AppLayout title="الأوامر الإدارية">
        <PageHeader title="الأوامر الإدارية" subtitle="كل تغيير في حالة الطالب أو تصحيح لنتيجة مقفلة يستند إلى أمر إداري مسجل هنا.">
            <button class="btn-primary" @click="show = true"><PlusIcon class="h-4 w-4" /> تسجيل أمر</button>
        </PageHeader>
        <div class="card">
            <div class="grid gap-3 border-b border-slate-100 p-4 sm:grid-cols-3">
                <div class="relative sm:col-span-2"><MagnifyingGlassIcon class="pointer-events-none absolute right-3 top-2.5 h-5 w-5 text-slate-400" /><input v-model="f.q" class="input pr-10" placeholder="رقم الأمر أو عنوانه" /></div>
                <select v-model="f.type" class="input"><option value="">كل الأنواع</option><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select>
            </div>
            <div class="overflow-x-auto">
                <table v-if="orders.data.length" class="table-base">
                    <thead><tr><th>الرقم</th><th>التاريخ</th><th>النوع</th><th>العنوان</th><th>الجهة</th><th>الكلية</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="o in orders.data" :key="o.id">
                            <td class="font-mono font-bold">{{ o.number }}</td><td class="tabular-nums">{{ o.issued_on }}</td>
                            <td><Badge :tone="tone[o.type] ?? 'slate'">{{ types[o.type] }}</Badge></td>
                            <td class="max-w-sm truncate font-semibold" :title="o.body">{{ o.title }}</td>
                            <td class="text-slate-500">{{ o.authority }}</td><td class="text-slate-500">{{ o.college ?? 'الجامعة' }}</td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-else :icon="DocumentTextIcon" title="لا توجد أوامر مسجلة" text="سجّل أوامر القبول والتأجيل والترقين وتصحيح النتائج لتُربط بسجلات الطلبة.">
                    <button class="btn-primary" @click="show = true"><PlusIcon class="h-4 w-4" /> تسجيل أمر</button>
                </EmptyState>
            </div>
            <Pagination :paginator="orders" />
        </div>
        <OrderModal :show="show" :types="types" :colleges="colleges" @close="show = false" />
    </AppLayout>
</template>
