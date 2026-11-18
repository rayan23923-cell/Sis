<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Badge from '@/Components/Badge.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import { systems, policyStatus } from '@/lib/labels';
import { BuildingLibraryIcon, ChevronDownIcon, CalendarDaysIcon, BookOpenIcon, UsersIcon, PlusIcon, PencilSquareIcon, StarIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ colleges: Array, years: Array, canManage: Boolean, termStatuses: Array });
const openIds = ref(new Set(props.colleges.slice(0, 1).map((c) => c.id)));
const toggle = (id) => { const s = new Set(openIds.value); s.has(id) ? s.delete(id) : s.add(id); openIds.value = s; };
const termTone = { planning: 'slate', registration: 'sky', teaching: 'brand', exams: 'amber', closed: 'emerald' };
const termLabel = { planning: 'تخطيط', registration: 'تسجيل', teaching: 'تدريس', exams: 'امتحانات', closed: 'مغلق' };
const count = (c, key) => c.departments.reduce((s, d) => s + d.programs.reduce((x, p) => x + (key === 'students' ? p.students : 1), 0), 0);

// ---------- one modal for college / department / program ----------
const kind = ref(null);
const editing = ref(null);
const form = useForm({ code: '', name_ar: '', name_en: '', is_active: true, college_id: '', department_id: '', study_system: 'bologna', degree: 'bachelor', duration_years: 4 });
const titles = { college: 'كلية', department: 'قسم', program: 'برنامج' };
const open = (k, item = null, parent = {}) => {
    kind.value = k; editing.value = item; form.reset(); form.clearErrors();
    if (item) Object.assign(form, { code: item.code, name_ar: item.name, name_en: item.name_en ?? '', is_active: item.active ?? true, college_id: item.college_id ?? '', department_id: item.department_id ?? '', study_system: item.system ?? 'bologna', degree: item.degree ?? 'bachelor', duration_years: item.years ?? 4 });
    Object.assign(form, parent);
};
const save = () => {
    const base = { college: 'colleges', department: 'departments', program: 'programs' }[kind.value];
    const opts = { preserveScroll: true, onSuccess: () => (kind.value = null) };
    editing.value ? form.put(route(`${base}.update`, editing.value.id), opts) : form.post(route(`${base}.store`), opts);
};

// ---------- calendar ----------
const showYear = ref(false);
const yearForm = useForm({ name: '', starts_on: '', ends_on: '', terms: 'both' });
const saveYear = () => yearForm.post(route('years.store'), { preserveScroll: true, onSuccess: () => { showYear.value = false; yearForm.reset(); } });
const setTerm = (t, status) => router.put(route('terms.update', t.id), { status, starts_on: t.starts, ends_on: t.ends }, { preserveScroll: true });
const makeCurrent = (y) => confirm(`جعل ${y.name} السنة الحالية؟`) && router.post(route('years.current', y.id), {}, { preserveScroll: true });
</script>

<template>
    <AppLayout title="الهيكل والمناهج">
        <PageHeader title="الهيكل الأكاديمي والمناهج" subtitle="الكليات والأقسام والبرامج والمناهج المعتمدة، والتقويم الأكاديمي. الوحدات تُعطَّل ولا تُحذف حفاظاً على السجل.">
            <template v-if="canManage">
                <Link :href="route('courses.index')" class="btn-secondary"><BookOpenIcon class="h-4 w-4" /> دليل المواد</Link>
                <button class="btn-primary" @click="open('college')"><PlusIcon class="h-4 w-4" /> كلية</button>
            </template>
        </PageHeader>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <section v-for="c in colleges" :key="c.id" class="card overflow-hidden" :class="!c.active && 'opacity-60'">
                    <div class="flex items-center gap-4 px-5 py-4">
                        <button class="flex min-w-0 flex-1 items-center gap-4 text-start" @click="toggle(c.id)">
                            <div class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-700"><BuildingLibraryIcon class="h-6 w-6" /></div>
                            <div class="min-w-0 flex-1">
                                <p class="font-extrabold text-slate-800">{{ c.name }} <Badge v-if="!c.active" tone="slate">معطلة</Badge></p>
                                <p class="text-xs text-slate-500">{{ c.departments.length }} أقسام · {{ count(c, 'programs') }} برامج · {{ count(c, 'students') }} طالب</p>
                            </div>
                            <ChevronDownIcon class="h-5 w-5 text-slate-400 transition" :class="openIds.has(c.id) && 'rotate-180'" />
                        </button>
                        <button v-if="canManage" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-brand-700" title="تعديل" @click="open('college', c)"><PencilSquareIcon class="h-4 w-4" /></button>
                    </div>
                    <div v-if="openIds.has(c.id)" class="divide-y divide-slate-100 border-t border-slate-100">
                        <div v-for="d in c.departments" :key="d.id" class="px-5 py-4" :class="!d.active && 'opacity-60'">
                            <div class="mb-3 flex items-center gap-2">
                                <p class="font-bold text-slate-700">{{ d.name }} <span class="font-mono text-xs text-slate-400">{{ d.code }}</span></p>
                                <button v-if="canManage" class="rounded p-1 text-slate-400 hover:text-brand-700" @click="open('department', d)"><PencilSquareIcon class="h-3.5 w-3.5" /></button>
                                <span class="ms-auto flex items-center gap-1 text-xs text-slate-500"><BookOpenIcon class="h-4 w-4" /> {{ d.courses }} مادة</span>
                                <button v-if="canManage" class="btn-ghost btn-sm" @click="open('program', null, { department_id: d.id })"><PlusIcon class="h-3.5 w-3.5" /> برنامج</button>
                            </div>
                            <div v-for="p in d.programs" :key="p.id" class="mb-2 rounded-xl border border-slate-100 bg-slate-50/50 p-4 last:mb-0" :class="!p.active && 'opacity-60'">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="font-bold text-slate-800">{{ p.name }}</p>
                                    <Badge tone="brand">{{ systems[p.system] }}</Badge>
                                    <Badge tone="slate">{{ p.years }} سنوات</Badge>
                                    <button v-if="canManage" class="rounded p-1 text-slate-400 hover:text-brand-700" @click="open('program', p)"><PencilSquareIcon class="h-3.5 w-3.5" /></button>
                                    <span class="ms-auto flex items-center gap-1 text-xs text-slate-500"><UsersIcon class="h-4 w-4" /> {{ p.students }}</span>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <Link v-for="v in p.curricula" :key="v.id" :href="route('curricula.show', v.id)" class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:border-brand-300 hover:text-brand-800">
                                        إصدار {{ v.version }} · {{ v.courses }} مادة <Badge :map="policyStatus" :value="v.status" />
                                    </Link>
                                    <span v-if="!p.curricula.length" class="text-xs text-amber-700">لا يوجد منهج</span>
                                </div>
                            </div>
                        </div>
                        <div v-if="canManage" class="px-5 py-3"><button class="btn-ghost btn-sm" @click="open('department', null, { college_id: c.id })"><PlusIcon class="h-3.5 w-3.5" /> قسم في {{ c.name }}</button></div>
                    </div>
                </section>
            </div>
            <aside>
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title flex items-center gap-2"><CalendarDaysIcon class="h-5 w-5 text-brand-600" /> التقويم الأكاديمي</h2>
                        <button v-if="canManage" class="btn-ghost btn-sm" @click="showYear = true"><PlusIcon class="h-4 w-4" /> سنة</button>
                    </div>
                    <div v-for="y in years" :key="y.id" class="border-b border-slate-100 p-5 last:border-0">
                        <div class="mb-3 flex items-center justify-between">
                            <p class="ltr font-extrabold">{{ y.name }}</p>
                            <Badge v-if="y.current" tone="emerald" dot>الحالية</Badge>
                            <button v-else-if="canManage" class="flex items-center gap-1 text-xs font-bold text-brand-700 hover:underline" @click="makeCurrent(y)"><StarIcon class="h-3.5 w-3.5" /> اجعلها الحالية</button>
                        </div>
                        <ul class="space-y-2">
                            <li v-for="t in y.terms" :key="t.id" class="flex items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm">
                                <div><p class="font-semibold">{{ t.name }}</p><p v-if="t.starts" class="ltr text-[11px] tabular-nums text-slate-400">{{ t.starts }} → {{ t.ends }}</p></div>
                                <select v-if="canManage" :value="t.status" class="rounded-lg border-slate-200 py-1 pe-7 ps-2 text-xs font-semibold" @change="setTerm(t, $event.target.value)">
                                    <option v-for="s in termStatuses" :key="s" :value="s">{{ termLabel[s] }}</option>
                                </select>
                                <Badge v-else :tone="termTone[t.status]">{{ termLabel[t.status] }}</Badge>
                            </li>
                        </ul>
                    </div>
                </section>
            </aside>
        </div>

        <Modal :show="!!kind" max-width="lg" @close="kind = null">
            <form class="p-6" @submit.prevent="save">
                <h3 class="text-lg font-extrabold">{{ editing ? 'تعديل' : 'إضافة' }} {{ titles[kind] }}</h3>
                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div v-if="kind === 'department'" class="sm:col-span-2"><label class="label">الكلية</label><select v-model="form.college_id" class="input" required><option v-for="c in colleges" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
                    <div v-if="kind === 'program'" class="sm:col-span-2"><label class="label">القسم</label>
                        <select v-model="form.department_id" class="input" required><optgroup v-for="c in colleges" :key="c.id" :label="c.name"><option v-for="d in c.departments" :key="d.id" :value="d.id">{{ d.name }}</option></optgroup></select></div>
                    <div><label class="label">الرمز</label><input v-model="form.code" dir="ltr" class="input font-mono" required /><InputError :message="form.errors.code" class="mt-1" /></div>
                    <div><label class="label">الاسم</label><input v-model="form.name_ar" class="input" required /><InputError :message="form.errors.name_ar" class="mt-1" /></div>
                    <div class="sm:col-span-2"><label class="label">الاسم بالإنكليزية</label><input v-model="form.name_en" dir="ltr" class="input" /></div>
                    <template v-if="kind === 'program'">
                        <div><label class="label">نظام الدراسة</label><select v-model="form.study_system" class="input"><option v-for="k in ['bologna', 'semester', 'annual']" :key="k" :value="k">{{ systems[k] }}</option></select><InputError :message="form.errors.study_system" class="mt-1" /></div>
                        <div><label class="label">مدة الدراسة (سنوات)</label><input v-model="form.duration_years" type="number" min="1" max="7" class="input" /></div>
                        <div><label class="label">الشهادة</label><select v-model="form.degree" class="input"><option value="bachelor">بكالوريوس</option><option value="diploma">دبلوم</option><option value="master">ماجستير</option><option value="phd">دكتوراه</option></select></div>
                    </template>
                    <label v-if="editing" class="flex items-center gap-2 self-end pb-2 text-sm font-semibold"><input v-model="form.is_active" type="checkbox" class="rounded border-slate-300 text-brand-600" /> فعال</label>
                </div>
                <div class="mt-6 flex justify-end gap-2"><button type="button" class="btn-secondary" @click="kind = null">إلغاء</button><button class="btn-primary" :disabled="form.processing">حفظ</button></div>
            </form>
        </Modal>

        <Modal :show="showYear" max-width="md" @close="showYear = false">
            <form class="p-6" @submit.prevent="saveYear">
                <h3 class="text-lg font-extrabold">سنة دراسية جديدة</h3>
                <div class="mt-5 space-y-4">
                    <div><label class="label">السنة</label><input v-model="yearForm.name" dir="ltr" class="input" placeholder="2026-2027" required /><InputError :message="yearForm.errors.name" class="mt-1" /></div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="label">تبدأ</label><input v-model="yearForm.starts_on" type="date" class="input" required /></div>
                        <div><label class="label">تنتهي</label><input v-model="yearForm.ends_on" type="date" class="input" required /><InputError :message="yearForm.errors.ends_on" class="mt-1" /></div>
                    </div>
                    <div><label class="label">الفصول</label><select v-model="yearForm.terms" class="input"><option value="both">فصلان + سنة كاملة (لكل الأنظمة)</option><option value="semesters">فصلان فقط</option><option value="annual">سنة كاملة فقط</option></select></div>
                </div>
                <div class="mt-6 flex justify-end gap-2"><button type="button" class="btn-secondary" @click="showYear = false">إلغاء</button><button class="btn-primary" :disabled="yearForm.processing">إنشاء</button></div>
            </form>
        </Modal>
    </AppLayout>
</template>
