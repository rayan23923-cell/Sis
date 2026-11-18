<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Badge from '@/Components/Badge.vue';
import Modal from '@/Components/Modal.vue';
import Pagination from '@/Components/Pagination.vue';
import EmptyState from '@/Components/EmptyState.vue';
import InputError from '@/Components/InputError.vue';
import { MagnifyingGlassIcon, PlusIcon, PencilSquareIcon, TrashIcon, UsersIcon, KeyIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ users: Object, filters: Object, roles: Object, roleScope: Object, colleges: Array, departments: Array });
const f = reactive({ q: props.filters.q ?? '', role: props.filters.role ?? '', status: props.filters.status ?? '' });
let t;
watch(f, () => { clearTimeout(t); t = setTimeout(() => router.get(route('users.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300); });

const roleTone = { super_admin: 'rose', registrar: 'violet', rules_admin: 'violet', auditor: 'slate', dean: 'brand', college_registrar: 'brand', committee: 'amber', head: 'sky', teacher: 'emerald' };
const scopeLabel = { university: 'على مستوى الجامعة', college: 'كلية', department: 'قسم' };

const show = ref(false);
const editing = ref(null);
const form = useForm({ name: '', email: '', password: '', active: true, roles: [] });
const openNew = () => { editing.value = null; form.reset(); form.clearErrors(); form.roles = [{ role: 'teacher', college_id: '', department_id: '' }]; show.value = true; };
const openEdit = (u) => {
    editing.value = u; form.clearErrors();
    Object.assign(form, { name: u.name, email: u.email, password: '', active: u.active, roles: u.roles.map((r) => ({ role: r.role, college_id: r.college_id ?? '', department_id: r.department_id ?? '' })) });
    show.value = true;
};
const addRole = () => form.roles.push({ role: 'teacher', college_id: '', department_id: '' });
const genPassword = () => {
    const a = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    const buf = new Uint32Array(14); crypto.getRandomValues(buf);
    form.password = Array.from(buf, (n) => a[n % a.length]).join('');
};
const save = () => {
    const opts = { preserveScroll: true, onSuccess: () => (show.value = false) };
    editing.value ? form.put(route('users.update', editing.value.id), opts) : form.post(route('users.store'), opts);
};
const roleErrors = computed(() => Object.entries(form.errors).filter(([k]) => k.startsWith('roles')).map(([, v]) => v));
</script>

<template>
    <AppLayout title="المستخدمون والأدوار">
        <PageHeader title="المستخدمون والأدوار" subtitle="الحسابات ونطاق صلاحية كل دور: الجامعة كلها، أو كلية، أو قسم.">
            <button class="btn-primary" @click="openNew"><PlusIcon class="h-4 w-4" /> حساب جديد</button>
        </PageHeader>

        <div class="card">
            <div class="grid gap-3 border-b border-slate-100 p-4 sm:grid-cols-4">
                <div class="relative sm:col-span-2"><MagnifyingGlassIcon class="pointer-events-none absolute right-3 top-2.5 h-5 w-5 text-slate-400" /><input v-model="f.q" class="input pr-10" placeholder="الاسم أو البريد" /></div>
                <select v-model="f.role" class="input"><option value="">كل الأدوار</option><option v-for="(l, k) in roles" :key="k" :value="k">{{ l }}</option></select>
                <select v-model="f.status" class="input"><option value="">كل الحسابات</option><option value="active">فعالة</option><option value="inactive">معطلة</option></select>
            </div>
            <div class="overflow-x-auto">
                <table v-if="users.data.length" class="table-base">
                    <thead><tr><th>الاسم</th><th>البريد</th><th>الأدوار والنطاق</th><th>الحالة</th><th></th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="u in users.data" :key="u.id" :class="!u.active && 'opacity-60'">
                            <td class="font-bold text-slate-800">{{ u.name }} <Badge v-if="u.self" tone="brand">أنت</Badge></td>
                            <td dir="ltr" class="text-end font-mono text-xs text-slate-500">{{ u.email }}</td>
                            <td class="whitespace-normal">
                                <div class="flex flex-wrap gap-1">
                                    <Badge v-for="(r, i) in u.roles" :key="i" :tone="roleTone[r.role]">{{ roles[r.role] }}<span v-if="r.scope" class="font-normal opacity-75"> · {{ r.scope }}</span></Badge>
                                    <span v-if="!u.roles.length" class="text-xs text-amber-700">بدون دور (لا يرى شيئاً)</span>
                                </div>
                            </td>
                            <td><Badge :tone="u.active ? 'emerald' : 'slate'" dot>{{ u.active ? 'فعال' : 'معطل' }}</Badge></td>
                            <td><button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-brand-700" title="تعديل" @click="openEdit(u)"><PencilSquareIcon class="h-4 w-4" /></button></td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-else :icon="UsersIcon" title="لا توجد حسابات مطابقة" />
            </div>
            <Pagination :paginator="users" />
        </div>

        <Modal :show="show" max-width="2xl" @close="show = false">
            <form class="p-6" @submit.prevent="save">
                <h3 class="text-lg font-extrabold">{{ editing ? 'تعديل حساب' : 'حساب جديد' }}</h3>
                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div><label class="label">الاسم</label><input v-model="form.name" class="input" required /><InputError :message="form.errors.name" class="mt-1" /></div>
                    <div><label class="label">البريد الإلكتروني</label><input v-model="form.email" type="email" dir="ltr" class="input" required /><InputError :message="form.errors.email" class="mt-1" /></div>
                    <div>
                        <label class="label">{{ editing ? 'كلمة مرور جديدة (اختياري)' : 'كلمة المرور' }}</label>
                        <div class="flex gap-2">
                            <input v-model="form.password" type="text" dir="ltr" autocomplete="new-password" class="input font-mono" :required="!editing" minlength="10" />
                            <button type="button" class="btn-secondary shrink-0 px-3" title="توليد كلمة مرور" @click="genPassword"><KeyIcon class="h-4 w-4" /></button>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">10 أحرف على الأقل. سلّمها لصاحب الحساب بطريقة آمنة.</p>
                        <InputError :message="form.errors.password" class="mt-1" />
                    </div>
                    <label class="flex items-center gap-2 self-center text-sm font-semibold"><input v-model="form.active" type="checkbox" class="rounded border-slate-300 text-brand-600" /> الحساب فعال</label>
                </div>

                <div class="mt-6">
                    <div class="mb-2 flex items-center justify-between"><p class="label mb-0">الأدوار</p><button type="button" class="btn-ghost btn-sm" @click="addRole"><PlusIcon class="h-4 w-4" /> دور</button></div>
                    <div class="space-y-2">
                        <div v-for="(r, i) in form.roles" :key="i" class="grid gap-2 rounded-xl border border-slate-200 bg-slate-50/50 p-3 sm:grid-cols-[1fr_1.4fr_auto]">
                            <select v-model="r.role" class="input"><option v-for="(l, k) in roles" :key="k" :value="k">{{ l }}</option></select>
                            <select v-if="roleScope[r.role] === 'college'" v-model="r.college_id" class="input" required><option value="" disabled>اختر الكلية</option><option v-for="c in colleges" :key="c.id" :value="c.id">{{ c.name_ar }}</option></select>
                            <select v-else-if="roleScope[r.role] === 'department'" v-model="r.department_id" class="input" required>
                                <option value="" disabled>اختر القسم</option>
                                <optgroup v-for="c in colleges" :key="c.id" :label="c.name_ar"><option v-for="d in departments.filter((x) => x.college_id === c.id)" :key="d.id" :value="d.id">{{ d.name }}</option></optgroup>
                            </select>
                            <p v-else class="self-center text-sm text-slate-500">{{ scopeLabel.university }}</p>
                            <button type="button" class="rounded-lg p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="إزالة" @click="form.roles.splice(i, 1)"><TrashIcon class="h-4 w-4" /></button>
                        </div>
                        <p v-if="!form.roles.length" class="rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-800">حساب بلا دور لا يرى أي بيانات.</p>
                    </div>
                    <p v-for="(e, i) in roleErrors" :key="i" class="mt-1 text-sm text-rose-600">{{ e }}</p>
                </div>

                <div class="mt-6 flex justify-end gap-2"><button type="button" class="btn-secondary" @click="show = false">إلغاء</button><button class="btn-primary" :disabled="form.processing">حفظ</button></div>
            </form>
        </Modal>
    </AppLayout>
</template>
