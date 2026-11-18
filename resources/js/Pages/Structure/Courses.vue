<script setup>
import { reactive, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Badge from '@/Components/Badge.vue';
import Modal from '@/Components/Modal.vue';
import Pagination from '@/Components/Pagination.vue';
import EmptyState from '@/Components/EmptyState.vue';
import InputError from '@/Components/InputError.vue';
import { BookOpenIcon, MagnifyingGlassIcon, PencilSquareIcon, PlusIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ courses: Object, filters: Object, departments: Array });
const f = reactive({ q: props.filters.q ?? '', department: props.filters.department ?? '' });
let t;
watch(f, () => { clearTimeout(t); t = setTimeout(() => router.get(route('courses.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300); });
const colleges = [...new Map(props.departments.map((d) => [d.college_id, d.college])).entries()];

const show = ref(false);
const editing = ref(null);
const form = useForm({ department_id: '', code: '', name_ar: '', name_en: '', credit_hours: 3, ects: '', theory_hours: 2, practical_hours: 0, is_active: true });
const open = (c = null) => {
    editing.value = c; form.reset(); form.clearErrors();
    if (c) Object.assign(form, { ...c, ects: c.ects ?? '', name_en: c.name_en ?? '' });
    else if (f.department) form.department_id = Number(f.department);
    show.value = true;
};
const save = () => {
    const opts = { preserveScroll: true, onSuccess: () => (show.value = false) };
    editing.value ? form.put(route('courses.update', editing.value.id), opts) : form.post(route('courses.store'), opts);
};
</script>

<template>
    <AppLayout title="دليل المواد">
        <PageHeader title="دليل المواد" subtitle="المادة تُعرَّف مرة واحدة في قسمها، ثم تُضاف إلى المناهج بمرحلتها وفصلها."
            :crumbs="[{ label: 'الهيكل والمناهج', href: route('structure.index') }, { label: 'دليل المواد' }]">
            <button class="btn-primary" @click="open()"><PlusIcon class="h-4 w-4" /> مادة</button>
        </PageHeader>
        <div class="card">
            <div class="grid gap-3 border-b border-slate-100 p-4 sm:grid-cols-3">
                <div class="relative sm:col-span-2"><MagnifyingGlassIcon class="pointer-events-none absolute right-3 top-2.5 h-5 w-5 text-slate-400" /><input v-model="f.q" class="input pr-10" placeholder="اسم المادة أو رمزها" /></div>
                <select v-model="f.department" class="input"><option value="">كل الأقسام</option><optgroup v-for="[cid, cname] in colleges" :key="cid" :label="cname"><option v-for="d in departments.filter((x) => x.college_id === cid)" :key="d.id" :value="d.id">{{ d.name }}</option></optgroup></select>
            </div>
            <div class="overflow-x-auto">
                <table v-if="courses.data.length" class="table-base">
                    <thead><tr><th>الرمز</th><th>المادة</th><th>القسم</th><th>الوحدات</th><th>ECTS</th><th>نظري/عملي</th><th></th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="c in courses.data" :key="c.id" :class="!c.is_active && 'opacity-50'">
                            <td class="font-mono text-xs font-bold text-brand-700">{{ c.code }}</td>
                            <td><p class="font-bold">{{ c.name_ar }} <Badge v-if="!c.is_active" tone="slate">معطلة</Badge></p><p class="text-xs text-slate-400" dir="ltr">{{ c.name_en }}</p></td>
                            <td class="text-slate-500">{{ c.department }}</td>
                            <td class="tabular-nums">{{ c.credit_hours }}</td><td class="tabular-nums">{{ c.ects ?? '—' }}</td>
                            <td class="tabular-nums">{{ c.theory_hours }} / {{ c.practical_hours }}</td>
                            <td><button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-brand-700" @click="open(c)"><PencilSquareIcon class="h-4 w-4" /></button></td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-else :icon="BookOpenIcon" title="لا توجد مواد مطابقة" />
            </div>
            <Pagination :paginator="courses" />
        </div>

        <Modal :show="show" max-width="lg" @close="show = false">
            <form class="p-6" @submit.prevent="save">
                <h3 class="text-lg font-extrabold">{{ editing ? 'تعديل مادة' : 'مادة جديدة' }}</h3>
                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2"><label class="label">القسم</label><select v-model="form.department_id" class="input" required><optgroup v-for="[cid, cname] in colleges" :key="cid" :label="cname"><option v-for="d in departments.filter((x) => x.college_id === cid)" :key="d.id" :value="d.id">{{ d.name }}</option></optgroup></select></div>
                    <div><label class="label">الرمز</label><input v-model="form.code" dir="ltr" class="input font-mono" required /><InputError :message="form.errors.code" class="mt-1" /></div>
                    <div><label class="label">الاسم</label><input v-model="form.name_ar" class="input" required /></div>
                    <div class="sm:col-span-2"><label class="label">الاسم بالإنكليزية</label><input v-model="form.name_en" dir="ltr" class="input" /></div>
                    <div><label class="label">الوحدات</label><input v-model="form.credit_hours" type="number" step="0.5" min="0" class="input" required /></div>
                    <div><label class="label">ECTS (بولونيا)</label><input v-model="form.ects" type="number" step="0.5" min="0" class="input" /></div>
                    <div><label class="label">ساعات نظري</label><input v-model="form.theory_hours" type="number" min="0" class="input" /></div>
                    <div><label class="label">ساعات عملي</label><input v-model="form.practical_hours" type="number" min="0" class="input" /></div>
                    <label v-if="editing" class="flex items-center gap-2 text-sm font-semibold"><input v-model="form.is_active" type="checkbox" class="rounded border-slate-300 text-brand-600" /> فعالة</label>
                </div>
                <div class="mt-6 flex justify-end gap-2"><button type="button" class="btn-secondary" @click="show = false">إلغاء</button><button class="btn-primary" :disabled="form.processing">حفظ</button></div>
            </form>
        </Modal>
    </AppLayout>
</template>
