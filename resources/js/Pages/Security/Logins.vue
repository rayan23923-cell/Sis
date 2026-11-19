<script setup>
import { reactive, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Badge from '@/Components/Badge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { FingerPrintIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ rows: Object, filters: Object, events: Object, stats: Object });
const fl = Array.isArray(props.filters) ? {} : props.filters;
const f = reactive({ q: fl.q ?? '', event: fl.event ?? '' });
const apply = () => router.get(route('logins.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true });
let t;
watch(() => f.q, () => { clearTimeout(t); t = setTimeout(apply, 350); });
watch(() => f.event, apply);
const tone = { success: 'emerald', logout: 'slate', failed: 'rose', two_factor_failed: 'rose', lockout: 'amber' };
</script>

<template>
    <AppLayout title="سجل الدخول">
        <PageHeader title="سجل الدخول" subtitle="كل محاولات الدخول إلى النظام، الناجحة والفاشلة، مع العنوان والجهاز." />

        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <div class="card p-4"><p class="text-sm text-slate-500">محاولات فاشلة (24 ساعة)</p><p class="text-2xl font-extrabold tabular-nums" :class="stats.failed_24h ? 'text-rose-600' : ''">{{ stats.failed_24h }}</p></div>
            <div class="card p-4"><p class="text-sm text-slate-500">إيقاف مؤقت (24 ساعة)</p><p class="text-2xl font-extrabold tabular-nums" :class="stats.lockouts_24h ? 'text-amber-600' : ''">{{ stats.lockouts_24h }}</p></div>
            <div class="card p-4"><p class="text-sm text-slate-500">حسابات إلزامية بلا تحقق بخطوتين</p><p class="text-2xl font-extrabold tabular-nums" :class="stats.without_2fa ? 'text-amber-600' : 'text-emerald-600'">{{ stats.without_2fa }}</p></div>
        </div>

        <div class="card mb-4 grid gap-3 p-4 sm:grid-cols-3">
            <div class="relative sm:col-span-2">
                <MagnifyingGlassIcon class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input v-model="f.q" class="input ps-9" placeholder="الاسم أو البريد أو العنوان" />
            </div>
            <select v-model="f.event" class="input" aria-label="العملية"><option value="">كل العمليات</option><option v-for="(l, k) in events" :key="k" :value="k">{{ l }}</option></select>
        </div>

        <section class="card">
            <EmptyState v-if="!rows.data.length" :icon="FingerPrintIcon" title="لا توجد عمليات" />
            <div v-else class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>الوقت</th><th>الحساب</th><th>العملية</th><th>الجهاز</th><th>العنوان</th></tr></thead>
                    <tbody>
                        <tr v-for="r in rows.data" :key="r.id">
                            <td class="ltr text-start text-sm tabular-nums text-slate-500">{{ r.at }}</td>
                            <td><p class="font-bold text-slate-800">{{ r.user ?? '—' }}</p><p class="ltr text-start text-xs text-slate-400">{{ r.login }}</p></td>
                            <td><Badge :tone="tone[r.event] ?? 'slate'">{{ r.label }}</Badge></td>
                            <td class="text-sm">{{ r.device }}</td>
                            <td class="ltr text-start font-mono text-xs text-slate-500">{{ r.ip }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="rows.last_page > 1" class="flex items-center justify-between border-t border-slate-100 px-5 py-3 text-sm text-slate-500">
                <span>{{ rows.total }} عملية · صفحة {{ rows.current_page }} من {{ rows.last_page }}</span>
                <div class="flex gap-2">
                    <button class="btn-secondary" :disabled="!rows.prev_page_url" @click="router.visit(rows.prev_page_url, { preserveState: true })">السابق</button>
                    <button class="btn-secondary" :disabled="!rows.next_page_url" @click="router.visit(rows.next_page_url, { preserveState: true })">التالي</button>
                </div>
            </div>
        </section>
    </AppLayout>
</template>
