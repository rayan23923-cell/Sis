<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Badge from '@/Components/Badge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import { PencilSquareIcon, PlusIcon, TableCellsIcon, TrashIcon, XMarkIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ years: Array, year: Number, schedules: Array, colleges: Array, programs: Array, channels: Object, shifts: Object, canManage: Boolean });

const money = (n) => Number(n ?? 0).toLocaleString('en-US');
const yearId = ref(props.year);
watch(yearId, (y) => router.get(route('finance.schedules'), { year: y }, { preserveState: true, replace: true }));

const show = ref(false);
const editing = ref(null);
const blank = () => ({ academic_year_id: yearId.value, name: '', college_id: '', program_id: '', admission_channel: '', study_shift: '', stage: '', amount: '', installments: [{ percent: 100, due_on: '' }], is_active: true, source_reference: '' });
const form = useForm(blank());
const programsOf = computed(() => props.programs.filter((p) => !form.college_id || p.college_id == form.college_id));
const sum = computed(() => form.installments.reduce((s, l) => s + Number(l.percent || 0), 0));
const locked = computed(() => editing.value?.invoices > 0);

const openNew = () => { editing.value = null; form.defaults(blank()); form.reset(); form.clearErrors(); show.value = true; };
const openEdit = (s) => {
    editing.value = s;
    Object.assign(form, { academic_year_id: yearId.value, name: s.name, college_id: s.college_id ?? '', program_id: s.program_id ?? '', admission_channel: s.admission_channel ?? '', study_shift: s.study_shift ?? '', stage: s.stage ?? '', amount: s.amount, installments: s.installments.map((l) => ({ ...l })), is_active: s.is_active, source_reference: s.source_reference ?? '' });
    form.clearErrors(); show.value = true;
};
const split = (n) => {
    const base = Math.floor(100 / n);
    form.installments = Array.from({ length: n }, (_, i) => ({ percent: i === n - 1 ? 100 - base * (n - 1) : base, due_on: form.installments[i]?.due_on ?? '' }));
};
const clean = (d) => ({ ...d, college_id: d.college_id || null, program_id: d.program_id || null, admission_channel: d.admission_channel || null, study_shift: d.study_shift || null, stage: d.stage || null });
const save = () => {
    const opts = { preserveScroll: true, onSuccess: () => { show.value = false; } };
    editing.value ? form.transform(clean).put(route('finance.schedules.update', editing.value.id), opts) : form.transform(clean).post(route('finance.schedules.store'), opts);
};
const remove = (s) => router.delete(route('finance.schedules.destroy', s.id), { preserveScroll: true });

const scope = (s) => [s.program ?? s.college ?? 'كل الكليات', s.stage ? `المرحلة ${s.stage}` : null].filter(Boolean).join(' · ');
</script>

<template>
    <AppLayout title="تعرفة الرسوم">
        <PageHeader title="تعرفة الرسوم" subtitle="مبالغ الرسوم المعتمدة لكل عام حسب الكلية أو البرنامج وقناة القبول ونوع الدراسة والمرحلة، وتقسيطها."
            :crumbs="[{ label: 'الرسوم والأقساط', href: route('finance.index', { year: yearId }) }, { label: 'التعرفة' }]">
            <select v-model="yearId" class="input w-auto"><option v-for="y in years" :key="y.id" :value="y.id">{{ y.name }}</option></select>
            <button v-if="canManage" class="btn-primary" @click="openNew"><PlusIcon class="h-4 w-4" /> تعرفة جديدة</button>
        </PageHeader>

        <div class="mb-4 rounded-xl bg-slate-100 px-4 py-3 text-sm text-slate-600">
            تُطبّق على كل طالب التعرفةُ الأكثر تحديداً التي تطابقه (المرحلة ثم البرنامج ثم الكلية، ثم القناة ونوع الدراسة). الطالب الذي لا تطابقه أي تعرفة لا تصدر له مطالبة، وهذا هو حال الدراسة المجانية. لا يضع النظام أي مبلغ من عنده.
        </div>

        <section class="card">
            <EmptyState v-if="!schedules.length" :icon="TableCellsIcon" title="لا توجد تعرفة لهذا العام" :text="canManage ? 'أضف التعرفة بالمبالغ المعتمدة من الجامعة.' : 'يضيفها محاسب الجامعة.'" />
            <div v-else class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>التعرفة</th><th>تنطبق على</th><th>القناة</th><th>الدراسة</th><th>المبلغ (دينار)</th><th>الأقساط</th><th>المطالبات</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="s in schedules" :key="s.id" :class="!s.is_active && 'opacity-60'">
                            <td><p class="font-bold text-slate-800">{{ s.name }}</p><p v-if="s.source_reference" class="text-xs text-slate-400">{{ s.source_reference }}</p><Badge v-if="!s.is_active" tone="slate">موقوفة</Badge></td>
                            <td class="text-sm">{{ scope(s) }}</td>
                            <td class="text-sm">{{ s.admission_channel ? channels[s.admission_channel] : 'الكل' }}</td>
                            <td class="text-sm">{{ s.study_shift ? shifts[s.study_shift] : 'الكل' }}</td>
                            <td class="font-bold tabular-nums">{{ money(s.amount) }}</td>
                            <td class="text-xs text-slate-500"><p v-for="(l, i) in s.installments" :key="i" class="tabular-nums">{{ i + 1 }}. {{ l.percent }}% · <span class="ltr">{{ l.due_on }}</span></p></td>
                            <td class="tabular-nums">{{ s.invoices }}</td>
                            <td class="whitespace-nowrap">
                                <template v-if="canManage">
                                    <button class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-brand-700" title="تعديل" @click="openEdit(s)"><PencilSquareIcon class="h-4 w-4" /></button>
                                    <button v-if="!s.invoices" class="rounded-lg p-1.5 text-slate-300 hover:bg-rose-50 hover:text-rose-600" title="حذف" @click="remove(s)"><TrashIcon class="h-4 w-4" /></button>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <Modal :show="show" max-width="2xl" @close="show = false">
            <form class="p-6" @submit.prevent="save">
                <h3 class="text-lg font-extrabold">{{ editing ? 'تعديل التعرفة' : 'تعرفة جديدة' }}</h3>
                <p v-if="locked" class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">صدرت {{ editing.invoices }} مطالبة بهذه التعرفة، فيمكن تعديل الاسم والمرجع والحالة فقط. لتغيير المبلغ أوقفها وأضف تعرفة جديدة.</p>
                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2"><label class="label">اسم التعرفة <span class="text-rose-500">*</span></label><input v-model="form.name" class="input" required placeholder="مثال: الدراسة المسائية — كلية الهندسة" /><InputError :message="form.errors.name" class="mt-1" /></div>
                    <div><label class="label">الكلية</label><select v-model="form.college_id" class="input" :disabled="locked" @change="form.program_id = ''"><option value="">كل الكليات</option><option v-for="c in colleges" :key="c.id" :value="c.id">{{ c.name_ar }}</option></select></div>
                    <div><label class="label">البرنامج</label><select v-model="form.program_id" class="input" :disabled="locked"><option value="">كل البرامج</option><option v-for="p in programsOf" :key="p.id" :value="p.id">{{ p.name }} — {{ p.department }}</option></select></div>
                    <div><label class="label">قناة القبول</label><select v-model="form.admission_channel" class="input" :disabled="locked"><option value="">كل القنوات</option><option v-for="(l, k) in channels" :key="k" :value="k">{{ l }}</option></select></div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="label">الدراسة</label><select v-model="form.study_shift" class="input" :disabled="locked"><option value="">الكل</option><option v-for="(l, k) in shifts" :key="k" :value="k">{{ l }}</option></select></div>
                        <div><label class="label">المرحلة</label><select v-model="form.stage" class="input" :disabled="locked"><option value="">كل المراحل</option><option v-for="n in 7" :key="n" :value="n">{{ n }}</option></select></div>
                    </div>
                    <div><label class="label">المبلغ السنوي (دينار) <span class="text-rose-500">*</span></label><input v-model.number="form.amount" type="number" min="1000" step="1000" dir="ltr" class="input tabular-nums" :disabled="locked" required /><InputError :message="form.errors.amount" class="mt-1" /></div>
                    <div><label class="label">مرجع القرار</label><input v-model="form.source_reference" class="input" placeholder="رقم وتاريخ كتاب الرسوم" /></div>
                </div>

                <div class="mt-5">
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="label mb-0">الأقساط</label>
                        <span class="text-xs" :class="Math.abs(sum - 100) > 0.01 ? 'font-bold text-rose-600' : 'text-slate-400'">المجموع {{ sum }}%</span>
                        <div v-if="!locked" class="ms-auto flex gap-1 text-xs"><button v-for="n in [1, 2, 3, 4]" :key="n" type="button" class="rounded-lg px-2 py-1 font-bold text-slate-500 hover:bg-slate-100" @click="split(n)">{{ n }} {{ n === 1 ? 'دفعة' : 'أقساط' }}</button></div>
                    </div>
                    <div v-for="(l, i) in form.installments" :key="i" class="mt-2 flex items-center gap-2">
                        <span class="w-6 text-center text-sm font-bold text-slate-400">{{ i + 1 }}</span>
                        <input v-model.number="l.percent" type="number" min="1" max="100" step="0.5" dir="ltr" class="input w-24 tabular-nums" :disabled="locked" required /><span class="text-sm text-slate-400">%</span>
                        <input v-model="l.due_on" type="date" class="input" :disabled="locked" required aria-label="تاريخ الاستحقاق" />
                        <span v-if="form.amount" class="hidden w-28 text-end text-sm tabular-nums text-slate-500 sm:block">{{ money(Math.round(form.amount * l.percent / 100)) }}</span>
                        <button v-if="!locked && form.installments.length > 1" type="button" class="rounded-lg p-1.5 text-slate-300 hover:text-rose-600" @click="form.installments.splice(i, 1)"><XMarkIcon class="h-4 w-4" /></button>
                    </div>
                    <InputError :message="form.errors.installments" class="mt-1" />
                    <button v-if="!locked && form.installments.length < 12" type="button" class="btn-ghost mt-2 text-xs" @click="form.installments.push({ percent: 0, due_on: '' })"><PlusIcon class="h-4 w-4" /> قسط</button>
                </div>

                <label class="mt-5 flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="rounded border-slate-300 text-brand-600" /> فعالة (تُستخدم في إصدار المطالبات الجديدة)</label>
                <div class="mt-6 flex justify-end gap-2"><button type="button" class="btn-secondary" @click="show = false">إلغاء</button><button class="btn-primary" :disabled="form.processing">حفظ</button></div>
            </form>
        </Modal>
    </AppLayout>
</template>
