<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Badge from '@/Components/Badge.vue';
import Pagination from '@/Components/Pagination.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { studentStatus } from '@/lib/labels';
import axios from 'axios';
import { MagnifyingGlassIcon, PlusIcon, UserGroupIcon, FunnelIcon, ArrowUpTrayIcon, KeyIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ students: Object, filters: Object, colleges: Array, programs: Array, statuses: Object });
const page = usePage();
// an empty filter set arrives as [] (whose .shift is Array.prototype.shift), so read from a plain object
const fl = Array.isArray(props.filters) ? {} : props.filters;
const f = reactive({ q: fl.q ?? '', college: fl.college ?? '', program: fl.program ?? '', status: fl.status ?? '', stage: fl.stage ?? '', shift: fl.shift ?? '' });
const showFilters = ref(false);
const wide = ref(typeof window !== 'undefined' && window.matchMedia('(min-width: 640px)').matches);
if (typeof window !== 'undefined') window.matchMedia('(min-width: 640px)').addEventListener?.('change', (e) => (wide.value = e.matches));
const active = computed(() => ['college', 'program', 'stage', 'shift', 'status'].filter((k) => f[k]).length);
const programs = computed(() => props.programs.filter((p) => !f.college || p.college_id == f.college));

let t;
watch(f, () => {
    clearTimeout(t);
    t = setTimeout(() => router.get(route('students.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300);
});
const issuing = ref(false);
const bulkError = ref('');
const bulkAccounts = async () => {
    const p = props.programs.find((x) => x.id == f.program);
    if (!confirm(`إنشاء حسابات بوابة لكل طلبة «${p?.name}» المرحلة ${f.stage} الذين ليس لهم حساب؟ ستُنزَّل قسائم الدخول PDF مرة واحدة فقط.`)) return;
    issuing.value = true;
    try {
        const r = await axios.post(route('students.accounts.bulk'), { program: f.program, stage: f.stage }, { responseType: 'blob' });
        if (r.headers['content-type']?.includes('pdf')) {
            const a = Object.assign(document.createElement('a'), { href: URL.createObjectURL(r.data), download: `portal-accounts-${f.stage}.pdf` });
            a.click();
        }
    } catch (e) {
        bulkError.value = JSON.parse(await e.response?.data?.text?.() || '{}').message ?? 'تعذر إنشاء الحسابات.';
        setTimeout(() => (bulkError.value = ''), 8000);
    } finally {
        issuing.value = false;
    }
};
const reset = () => Object.assign(f, { q: '', college: '', program: '', status: '', stage: '', shift: '' });
</script>

<template>
    <AppLayout title="الطلبة">
        <PageHeader title="الطلبة" :subtitle="`${students.total} طالب ضمن صلاحياتك`">
            <button v-if="page.props.can.admit && f.program && f.stage" class="btn-secondary" :disabled="issuing" @click="bulkAccounts"><KeyIcon class="h-4 w-4" /> حسابات البوابة للمرحلة</button>
            <Link v-if="page.props.can.admit" :href="route('students.import')" class="btn-secondary"><ArrowUpTrayIcon class="h-4 w-4" /> استيراد من Excel</Link>
            <Link v-if="page.props.can.admit" :href="route('students.create')" class="btn-primary"><PlusIcon class="h-4 w-4" /> قبول طالب</Link>
        </PageHeader>
        <p v-if="bulkError" class="mb-4 rounded-xl bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">{{ bulkError }}</p>

        <div class="card">
            <div class="grid gap-3 border-b border-slate-100 p-4 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-8">
                <div class="flex gap-2 sm:col-span-2">
                    <div class="relative flex-1">
                        <MagnifyingGlassIcon class="pointer-events-none absolute start-3 top-2.5 h-5 w-5 text-slate-400" />
                        <input v-model="f.q" type="search" class="input ps-10" placeholder="بحث بالاسم أو الرقم الجامعي أو الوطني" />
                    </div>
                    <button type="button" class="btn shrink-0 border sm:hidden" :class="active ? 'border-brand-300 bg-brand-50 text-brand-800' : 'border-slate-200 bg-white text-slate-600'" @click="showFilters = !showFilters">
                        <FunnelIcon class="h-4 w-4" /><span v-if="active" class="tabular-nums">{{ active }}</span>
                    </button>
                </div>
                <template v-if="showFilters || wide">
                <select v-model="f.college" class="input" @change="f.program = ''"><option value="">كل الكليات</option><option v-for="c in colleges" :key="c.id" :value="c.id">{{ c.name_ar }}</option></select>
                <select v-model="f.program" class="input"><option value="">كل البرامج</option><option v-for="p in programs" :key="p.id" :value="p.id">{{ p.name }}</option></select>
                <select v-model="f.stage" class="input"><option value="">كل المراحل</option><option v-for="s in 6" :key="s" :value="s">المرحلة {{ s }}</option></select>
                <select v-model="f.shift" class="input"><option value="">كل الدوامات</option><option value="morning">صباحي</option><option value="evening">مسائي</option></select>
                <select v-model="f.status" class="input"><option value="">كل الحالات</option><option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option></select>
                </template>
            </div>

            <ul v-if="students.data.length" class="divide-y divide-slate-100 sm:hidden">
                <li v-for="s in students.data" :key="s.id">
                    <Link :href="route('students.show', s.id)" class="flex items-center gap-3 px-4 py-3 active:bg-slate-50">
                        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full text-xs font-bold" :class="s.gender === 'f' ? 'bg-rose-50 text-rose-600' : 'bg-brand-50 text-brand-700'">{{ s.name.charAt(0) }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-bold text-slate-800">{{ s.name }}</p>
                            <p class="truncate text-xs text-slate-500"><span class="ltr font-mono">{{ s.number }}</span> · {{ s.program }}<span v-if="s.stage"> · م{{ s.stage }}</span><span v-if="s.shift === 'evening'" class="font-bold text-violet-700"> · مسائي</span></p>
                        </div>
                        <Badge :map="studentStatus" :value="s.status" dot />
                    </Link>
                </li>
            </ul>
            <div class="overflow-x-auto" :class="students.data.length && 'hidden sm:block'">
                <table v-if="students.data.length" class="table-base">
                    <thead><tr><th>الرقم الجامعي</th><th>الاسم</th><th>القسم / البرنامج</th><th>المرحلة</th><th>الحالة</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="s in students.data" :key="s.id" class="cursor-pointer" @click="router.visit(route('students.show', s.id))">
                            <td class="font-mono text-xs text-slate-500">{{ s.number }}</td>
                            <td>
                                <Link :href="route('students.show', s.id)" class="flex items-center gap-3 font-bold text-slate-800 hover:text-brand-700" @click.stop>
                                    <span class="grid h-8 w-8 place-items-center rounded-full text-xs font-bold" :class="s.gender === 'f' ? 'bg-rose-50 text-rose-600' : 'bg-brand-50 text-brand-700'">{{ s.name.charAt(0) }}</span>
                                    {{ s.name }}
                                </Link>
                            </td>
                            <td><p class="font-semibold">{{ s.department ?? '—' }}</p><p class="text-xs text-slate-500">{{ s.program }}</p></td>
                            <td class="tabular-nums">{{ s.stage ?? "—" }}<span v-if="s.shift === 'evening'" class="ms-1 rounded bg-violet-50 px-1 text-[11px] font-bold text-violet-700">مسائي</span></td>
                            <td><Badge :map="studentStatus" :value="s.status" dot /></td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-else :icon="UserGroupIcon" title="لا توجد نتائج" text="غيّر كلمات البحث أو المرشحات.">
                    <button class="btn-secondary" @click="reset"><FunnelIcon class="h-4 w-4" /> مسح المرشحات</button>
                </EmptyState>
            </div>
            <Pagination :paginator="students" />
        </div>
    </AppLayout>
</template>
