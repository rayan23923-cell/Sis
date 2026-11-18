<script setup>
import { reactive, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Badge from '@/Components/Badge.vue';
import Pagination from '@/Components/Pagination.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { offeringStatus, roundName } from '@/lib/labels';
import { MagnifyingGlassIcon, ClipboardDocumentListIcon, UserIcon, UsersIcon, PlusIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ offerings: Object, filters: Object, terms: Array, colleges: Array, statuses: Object });
const f = reactive({ q: props.filters.q ?? '', term: props.filters.term ?? '', college: props.filters.college ?? '', status: props.filters.status ?? '', mine: props.filters.mine ?? '' });
let t;
watch(f, () => {
    clearTimeout(t);
    t = setTimeout(() => router.get(route('offerings.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300);
});
const fill = (o) => Math.min(100, Math.round((o.students / Math.max(1, o.capacity)) * 100));
</script>

<template>
    <AppLayout title="المواد والدرجات">
        <PageHeader title="المواد والدرجات" subtitle="شعب المواد في الفصل الحالي، وحالة إدخال الدرجات والمصادقة عليها.">
            <Link v-if="$page.props.can.offerings" :href="route('offerings.create')" class="btn-primary"><PlusIcon class="h-4 w-4" /> فتح شعب</Link>
        </PageHeader>

        <div class="card mb-6 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-5">
            <div class="relative lg:col-span-2">
                <MagnifyingGlassIcon class="pointer-events-none absolute right-3 top-2.5 h-5 w-5 text-slate-400" />
                <input v-model="f.q" type="search" class="input pr-10" placeholder="اسم المادة أو رمزها" />
            </div>
            <select v-model="f.term" class="input"><option value="">الفصول الحالية</option><option v-for="t in terms" :key="t.id" :value="t.id">{{ t.name }}</option></select>
            <select v-model="f.college" class="input"><option value="">كل الكليات</option><option v-for="c in colleges" :key="c.id" :value="c.id">{{ c.name_ar }}</option></select>
            <div class="flex gap-2">
                <select v-model="f.status" class="input"><option value="">كل المراحل</option><option v-for="(s, k) in { open: 'إدخال الدرجات', submitted: 'بانتظار اللجنة', approved: 'مصادق عليها', locked: 'مقفلة' }" :key="k" :value="k">{{ s }}</option></select>
                <button class="btn shrink-0 border" :class="f.mine ? 'border-brand-300 bg-brand-50 text-brand-800' : 'border-slate-200 bg-white text-slate-600'" title="موادي فقط" @click="f.mine = f.mine ? '' : '1'"><UserIcon class="h-4 w-4" /></button>
            </div>
        </div>

        <div v-if="offerings.data.length" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <Link v-for="o in offerings.data" :key="o.id" :href="route('offerings.show', o.id)"
                class="card group flex flex-col p-5 transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-md">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-mono text-xs font-bold text-brand-600">{{ o.code }}</p>
                        <h3 class="mt-0.5 truncate text-base font-extrabold text-slate-800 group-hover:text-brand-800">{{ o.name }}</h3>
                    </div>
                    <Badge :map="offeringStatus" :value="o.status" dot />
                </div>
                <p class="mt-2 text-xs text-slate-500">{{ o.department }}<span v-if="o.stage"> · المرحلة {{ o.stage }}</span> · شعبة {{ o.section }}</p>
                <div class="mt-4 flex items-center gap-2 text-sm text-slate-600">
                    <UserIcon class="h-4 w-4 text-slate-400" /><span class="truncate">{{ o.teacher ?? 'لم يُحدد التدريسي' }}</span>
                    <Badge v-if="o.mine" tone="brand" class="mr-auto">مادتي</Badge>
                </div>
                <div class="mt-auto pt-4">
                    <div class="mb-1 flex justify-between text-xs text-slate-500"><span class="flex items-center gap-1"><UsersIcon class="h-3.5 w-3.5" /> {{ o.students }} / {{ o.capacity }}</span><span>{{ roundName(o.round) }}</span></div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-brand-500" :style="{ width: fill(o) + '%' }" /></div>
                </div>
            </Link>
        </div>
        <div v-else class="card"><EmptyState :icon="ClipboardDocumentListIcon" title="لا توجد شعب مطابقة" text="جرّب فصلاً آخر أو امسح المرشحات." /></div>
        <div class="card mt-4 overflow-hidden" v-if="offerings.last_page > 1"><Pagination :paginator="offerings" /></div>
    </AppLayout>
</template>
