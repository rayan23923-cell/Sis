<script setup>
import { reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Badge from '@/Components/Badge.vue';
import Pagination from '@/Components/Pagination.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { ShieldCheckIcon, ChevronDownIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ logs: Object, filters: Object, models: Object, users: Array });
const f = reactive({ model: props.filters.model ?? '', action: props.filters.action ?? '', user: props.filters.user ?? '', from: props.filters.from ?? '', to: props.filters.to ?? '' });
watch(f, () => router.get(route('audit.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }));
const open = ref(null);
const actions = { created: ['إنشاء', 'emerald'], updated: ['تعديل', 'sky'], deleted: ['حذف', 'rose'] };
const keys = (l) => [...new Set([...Object.keys(l.old ?? {}), ...Object.keys(l.new ?? {})])];
const show = (v) => (v === null || v === undefined ? '—' : typeof v === 'object' ? JSON.stringify(v) : String(v));
</script>

<template>
    <AppLayout title="سجل التدقيق">
        <PageHeader title="سجل التدقيق" subtitle="كل إنشاء وتعديل وحذف: من قام به، ومتى، ومن أين، والقيم قبل التغيير وبعده. السجل لا يُعدَّل ولا يُحذف." />
        <div class="card">
            <div class="grid gap-3 border-b border-slate-100 p-4 sm:grid-cols-2 lg:grid-cols-5">
                <select v-model="f.model" class="input"><option value="">كل السجلات</option><option v-for="(l, k) in models" :key="k" :value="k">{{ l }}</option></select>
                <select v-model="f.action" class="input"><option value="">كل العمليات</option><option v-for="(a, k) in actions" :key="k" :value="k">{{ a[0] }}</option></select>
                <select v-model="f.user" class="input"><option value="">كل المستخدمين</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select>
                <input v-model="f.from" type="date" class="input" title="من تاريخ" />
                <input v-model="f.to" type="date" class="input" title="إلى تاريخ" />
            </div>
            <ul v-if="logs.data.length" class="divide-y divide-slate-100">
                <li v-for="l in logs.data" :key="l.id">
                    <button class="flex w-full items-center gap-3 px-5 py-3 text-start text-sm transition hover:bg-slate-50" @click="open = open === l.id ? null : l.id">
                        <Badge :tone="actions[l.action]?.[1] ?? 'slate'">{{ actions[l.action]?.[0] ?? l.action }}</Badge>
                        <span class="font-semibold text-slate-800">{{ l.model }} #{{ l.model_id }}</span>
                        <span class="hidden truncate text-slate-500 sm:inline">{{ l.reason }}</span>
                        <span class="mr-auto shrink-0 text-xs text-slate-500">{{ l.user }}</span>
                        <span class="shrink-0 text-xs tabular-nums text-slate-400">{{ l.at }}</span>
                        <ChevronDownIcon class="h-4 w-4 shrink-0 text-slate-400 transition" :class="open === l.id && 'rotate-180'" />
                    </button>
                    <div v-if="open === l.id" class="bg-slate-50 px-5 pb-4">
                        <p v-if="l.reason" class="mb-2 text-sm text-slate-600">السبب: {{ l.reason }}</p>
                        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                            <table class="min-w-full text-xs">
                                <thead class="bg-slate-50 text-slate-500"><tr><th class="px-3 py-2 text-start">الحقل</th><th class="px-3 py-2 text-start">قبل</th><th class="px-3 py-2 text-start">بعد</th></tr></thead>
                                <tbody class="divide-y divide-slate-100 font-mono">
                                    <tr v-for="k in keys(l)" :key="k"><td class="px-3 py-1.5 font-sans font-semibold text-slate-600">{{ k }}</td><td class="max-w-xs truncate px-3 py-1.5 text-rose-700">{{ show(l.old?.[k]) }}</td><td class="max-w-xs truncate px-3 py-1.5 text-emerald-700">{{ show(l.new?.[k]) }}</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-if="l.ip" class="mt-2 text-[11px] text-slate-400" dir="ltr">IP {{ l.ip }}</p>
                    </div>
                </li>
            </ul>
            <EmptyState v-else :icon="ShieldCheckIcon" title="لا توجد سجلات مطابقة" />
            <Pagination :paginator="logs" />
        </div>
    </AppLayout>
</template>
