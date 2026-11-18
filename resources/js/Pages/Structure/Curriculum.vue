<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Badge from '@/Components/Badge.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import { systems, policyStatus } from '@/lib/labels';
import { DocumentDuplicateIcon, PlusIcon, TrashIcon, CheckBadgeIcon, BookOpenIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ curriculum: Object, stages: Array, canManage: Boolean, allCourses: Array, years: Array });
const draft = computed(() => props.curriculum.status === 'draft');
const editable = computed(() => props.canManage && draft.value);
const inCurriculum = computed(() => new Set(props.stages.flatMap((s) => s.courses.map((c) => c.code))));
const available = computed(() => props.allCourses.filter((c) => !inCurriculum.value.has(c.code)));
const prereqOptions = computed(() => props.stages.flatMap((s) => s.courses).filter((c) => true));

const showAdd = ref(false);
const addForm = useForm({ course_id: '', stage: 1, term_no: props.curriculum.system === 'annual' ? '' : 1, is_mandatory: true, prerequisites: [] });
const add = () => addForm.post(route('curricula.courses.store', props.curriculum.id), { preserveScroll: true, onSuccess: () => { addForm.reset('course_id', 'prerequisites'); } });
const remove = (c) => confirm(`إزالة ${c.name} من المسودة؟`) && router.delete(route('curricula.courses.destroy', [props.curriculum.id, c.id]), { preserveScroll: true });
const courseIdByCode = (code) => props.allCourses.find((c) => c.code === code)?.id;

const showApprove = ref(false);
const approveForm = useForm({ approval_reference: '', effective_from_year_id: '' });
const approve = () => approveForm.post(route('curricula.approve', props.curriculum.id), { preserveScroll: true, onSuccess: () => (showApprove.value = false) });
const newVersion = () => router.post(route('curricula.version', props.curriculum.id));
</script>

<template>
    <AppLayout :title="curriculum.name">
        <PageHeader :title="curriculum.name" :subtitle="`${curriculum.program} · ${curriculum.department} · ${curriculum.college}`"
            :crumbs="[{ label: 'الهيكل والمناهج', href: route('structure.index') }, { label: `إصدار ${curriculum.version}` }]">
            <Badge :map="policyStatus" :value="curriculum.status" dot />
            <Badge tone="brand">{{ systems[curriculum.system] }}</Badge>
            <template v-if="editable">
                <button class="btn-secondary" @click="showAdd = true"><PlusIcon class="h-4 w-4" /> إضافة مادة</button>
                <button class="btn-success" @click="showApprove = true"><CheckBadgeIcon class="h-4 w-4" /> اعتماد المنهج</button>
            </template>
            <button v-else-if="canManage && !curriculum.has_draft" class="btn-primary" @click="newVersion"><DocumentDuplicateIcon class="h-4 w-4" /> إصدار جديد منه</button>
        </PageHeader>
        <p class="mb-6 rounded-2xl px-4 py-3 text-sm" :class="draft ? 'bg-amber-50 text-amber-900' : 'bg-slate-100 text-slate-600'">
            <template v-if="draft">مسودة: يمكن إضافة المواد وإزالتها. بعد الاعتماد لا تتغير، ويُقبل عليها الطلبة الجدد فقط.</template>
            <template v-else>المنهج المعتمد لا يُعدَّل؛ أي تغيير يُنشئ إصداراً جديداً، ويبقى الطلبة على الإصدار الذي قُبلوا عليه.<span v-if="curriculum.reference"> المرجع: {{ curriculum.reference }}</span></template>
        </p>

        <div v-if="!stages.length" class="card flex flex-col items-center px-6 py-14 text-center">
            <BookOpenIcon class="h-10 w-10 text-slate-300" /><p class="mt-2 font-bold text-slate-700">المنهج فارغ</p>
            <button v-if="editable" class="btn-primary mt-4" @click="showAdd = true"><PlusIcon class="h-4 w-4" /> إضافة أول مادة</button>
        </div>
        <section v-for="s in stages" :key="s.stage" class="card mb-6 overflow-hidden">
            <div class="card-header"><h2 class="card-title">المرحلة {{ s.stage }}</h2><span class="text-sm text-slate-500">{{ s.credits }} وحدة<span v-if="s.ects"> · {{ s.ects }} ECTS</span></span></div>
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>الرمز</th><th>المادة</th><th>الفصل</th><th>الوحدات</th><th v-if="curriculum.system === 'bologna'">ECTS</th><th>النوع</th><th>المتطلب السابق</th><th v-if="editable"></th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="c in s.courses" :key="c.id">
                            <td class="font-mono text-xs font-bold text-brand-700">{{ c.code }}</td>
                            <td><p class="font-bold">{{ c.name }}</p><p class="text-xs text-slate-400" dir="ltr">{{ c.name_en }}</p></td>
                            <td>{{ c.term ? (c.term === 1 ? 'الأول' : 'الثاني') : 'سنوي' }}</td>
                            <td class="tabular-nums">{{ c.credits }}</td>
                            <td v-if="curriculum.system === 'bologna'" class="tabular-nums">{{ c.ects ?? '—' }}</td>
                            <td><Badge :tone="c.mandatory ? 'brand' : 'slate'">{{ c.mandatory ? 'إلزامية' : 'اختيارية' }}</Badge></td>
                            <td class="font-mono text-xs">{{ c.prerequisites.join('، ') || '—' }}</td>
                            <td v-if="editable"><button class="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600" @click="remove(c)"><TrashIcon class="h-4 w-4" /></button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <Modal :show="showAdd" max-width="lg" @close="showAdd = false">
            <form class="p-6" @submit.prevent="add">
                <h3 class="text-lg font-extrabold">إضافة مادة إلى المنهج</h3>
                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2"><label class="label">المادة</label>
                        <select v-model="addForm.course_id" class="input" required><option value="" disabled>اختر من دليل مواد الكلية</option><option v-for="c in available" :key="c.id" :value="c.id">{{ c.code }} — {{ c.name_ar }} ({{ c.credit_hours }} وحدة)</option></select>
                        <InputError :message="addForm.errors.course_id" class="mt-1" /></div>
                    <div><label class="label">المرحلة</label><select v-model="addForm.stage" class="input"><option v-for="n in curriculum.stages_max" :key="n" :value="n">{{ n }}</option></select></div>
                    <div><label class="label">الفصل</label><select v-model="addForm.term_no" class="input"><option value="">سنوية</option><option :value="1">الأول</option><option :value="2">الثاني</option></select></div>
                    <div class="sm:col-span-2"><label class="label">المتطلبات السابقة</label>
                        <select v-model="addForm.prerequisites" multiple class="input h-28"><option v-for="c in prereqOptions" :key="c.code" :value="courseIdByCode(c.code)">{{ c.code }} — {{ c.name }}</option></select>
                        <p class="mt-1 text-xs text-slate-500">اختر بالضغط مع Ctrl. يجب أن يجتازها الطالب قبل التسجيل.</p></div>
                    <label class="flex items-center gap-2 text-sm font-semibold"><input v-model="addForm.is_mandatory" type="checkbox" class="rounded border-slate-300 text-brand-600" /> إلزامية</label>
                </div>
                <div class="mt-6 flex justify-end gap-2"><button type="button" class="btn-secondary" @click="showAdd = false">إغلاق</button><button class="btn-primary" :disabled="addForm.processing">إضافة</button></div>
            </form>
        </Modal>

        <Modal :show="showApprove" max-width="md" @close="showApprove = false">
            <form class="p-6" @submit.prevent="approve">
                <h3 class="text-lg font-extrabold">اعتماد المنهج</h3>
                <p class="mt-1 text-sm text-slate-500">بعد الاعتماد لا يمكن تعديل المنهج، وأي تغيير يكون بإصدار جديد.</p>
                <div class="mt-5 space-y-4">
                    <div><label class="label">مرجع المصادقة (رقم الكتاب وتاريخه)</label><input v-model="approveForm.approval_reference" class="input" required /><InputError :message="approveForm.errors.approval_reference" class="mt-1" /></div>
                    <div><label class="label">نافذ من السنة</label><select v-model="approveForm.effective_from_year_id" class="input"><option value="">—</option><option v-for="y in years" :key="y.id" :value="y.id">{{ y.name }}</option></select></div>
                </div>
                <div class="mt-6 flex justify-end gap-2"><button type="button" class="btn-secondary" @click="showApprove = false">إلغاء</button><button class="btn-success" :disabled="approveForm.processing">اعتماد</button></div>
            </form>
        </Modal>
    </AppLayout>
</template>
