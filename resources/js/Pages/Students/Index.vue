<script setup>
import { computed, reactive, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Badge from '@/Components/Badge.vue';
import Pagination from '@/Components/Pagination.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { studentStatus } from '@/lib/labels';
import { MagnifyingGlassIcon, PlusIcon, UserGroupIcon, FunnelIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ students: Object, filters: Object, colleges: Array, programs: Array, statuses: Object });
const page = usePage();
const f = reactive({ q: props.filters.q ?? '', college: props.filters.college ?? '', program: props.filters.program ?? '', status: props.filters.status ?? '', stage: props.filters.stage ?? '' });
const programs = computed(() => props.programs.filter((p) => !f.college || p.college_id == f.college));

let t;
watch(f, () => {
    clearTimeout(t);
    t = setTimeout(() => router.get(route('students.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300);
});
const reset = () => Object.assign(f, { q: '', college: '', program: '', status: '', stage: '' });
</script>

<template>
    <AppLayout title="الطلبة">
        <PageHeader title="الطلبة" :subtitle="`${students.total} طالب ضمن صلاحياتك`">
            <Link v-if="page.props.can.admit" :href="route('students.create')" class="btn-primary"><PlusIcon class="h-4 w-4" /> قبول طالب</Link>
        </PageHeader>

        <div class="card">
            <div class="grid gap-3 border-b border-slate-100 p-4 sm:grid-cols-2 lg:grid-cols-6">
                <div class="relative lg:col-span-2">
                    <MagnifyingGlassIcon class="pointer-events-none absolute right-3 top-2.5 h-5 w-5 text-slate-400" />
                    <input v-model="f.q" type="search" class="input pr-10" placeholder="بحث بالاسم أو الرقم الجامعي أو الوطني" autofocus />
                </div>
                <select v-model="f.college" class="input" @change="f.program = ''"><option value="">كل الكليات</option><option v-for="c in colleges" :key="c.id" :value="c.id">{{ c.name_ar }}</option></select>
                <select v-model="f.program" class="input"><option value="">كل البرامج</option><option v-for="p in programs" :key="p.id" :value="p.id">{{ p.name }}</option></select>
                <select v-model="f.stage" class="input"><option value="">كل المراحل</option><option v-for="s in 6" :key="s" :value="s">المرحلة {{ s }}</option></select>
                <select v-model="f.status" class="input"><option value="">كل الحالات</option><option v-for="(l, k) in statuses" :key="k" :value="k">{{ l }}</option></select>
            </div>

            <div class="overflow-x-auto">
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
                            <td class="tabular-nums">{{ s.stage ?? '—' }}</td>
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
