<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Badge from '@/Components/Badge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import StatCard from '@/Components/StatCard.vue';
import { ChatBubbleLeftEllipsisIcon, ChevronDownIcon, ClipboardDocumentListIcon, PlusIcon, StarIcon, TrashIcon, UserGroupIcon, ArrowTrendingUpIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ rounds: Array, selected: Number, results: Object, mine: Array, forms: Array, terms: Array, colleges: Array, perm: Object });

const stateTone = { open: 'emerald', upcoming: 'sky', closed: 'slate' };
const stateLabel = { open: 'مفتوحة', upcoming: 'لم تبدأ', closed: 'مغلقة' };
const round = computed(() => props.rounds.find((r) => r.id === props.selected));
const pick = (id) => router.get(route('evaluations.index'), { round: id }, { preserveScroll: true, preserveState: true });
const pct = (v) => (v == null ? '—' : Math.round(((v - 1) / 4) * 100) + '%');
const tone = (v) => (v == null ? 'bg-slate-200' : v >= 4 ? 'bg-emerald-500' : v >= 3 ? 'bg-amber-400' : 'bg-rose-500');
const open = ref(null);
const sorted = computed(() => [...(props.results?.rows ?? [])].sort((a, b) => (b.average ?? -1) - (a.average ?? -1)));
const rate = computed(() => (props.results?.enrolled ? Math.round((props.results.answers / props.results.enrolled) * 100) : 0));

// round
const showRound = ref(false);
const rf = useForm({ name: '', evaluation_form_id: null, academic_term_id: null, college_id: null, opens_on: '', closes_on: '', min_responses: 5 });
const newRound = () => { rf.reset(); rf.evaluation_form_id = props.forms.find((f) => f.is_active)?.id ?? null; rf.academic_term_id = props.terms[0]?.id ?? null; if (!props.perm.allColleges) rf.college_id = props.colleges[0]?.id ?? null; showRound.value = true; };
const saveRound = () => rf.post(route('evaluations.rounds.store'), { preserveScroll: true, onSuccess: () => (showRound.value = false) });

// forms
const showForm = ref(false);
const editing = ref(null);
const ff = useForm({ name: '', questions: [], is_official: false, source_reference: '', is_active: true });
const editForm = (f = null) => {
    editing.value = f;
    ff.clearErrors();
    Object.assign(ff, f ? { name: f.name, questions: f.questions.map((q) => ({ ...q })), is_official: f.is_official, source_reference: f.source_reference ?? '', is_active: f.is_active }
        : { name: '', questions: [{ text: '', kind: 'scale' }], is_official: false, source_reference: '', is_active: true });
    showForm.value = true;
};
const saveForm = () => {
    const opts = { preserveScroll: true, onSuccess: () => (showForm.value = false) };
    editing.value ? ff.put(route('evaluations.forms.update', editing.value.id), opts) : ff.post(route('evaluations.forms.store'), opts);
};
</script>

<template>
    <AppLayout title="تقييم التدريس">
        <PageHeader title="تقييم التدريس والجودة" subtitle="يقيّم الطلبة موادهم وتدريسييها بسرية. لا تظهر نتيجة مادة حتى يكتمل الحد الأدنى من الإجابات.">
            <template v-if="perm.manage">
                <button class="btn-secondary" @click="editForm()"><ClipboardDocumentListIcon class="h-5 w-5" /> استمارة جديدة</button>
                <button class="btn-primary" :disabled="!forms.length" @click="newRound"><PlusIcon class="h-5 w-5" /> فترة تقييم جديدة</button>
            </template>
        </PageHeader>

        <!-- rounds -->
        <template v-if="perm.view">
            <EmptyState v-if="!rounds.length" class="card" :icon="StarIcon" title="لا توجد فترات تقييم بعد" :text="perm.manage ? 'افتح فترة تقييم لفصل دراسي ليظهر الاستبيان للطلبة في بوابتهم.' : ''" />
            <template v-else>
                <div class="flex gap-2 overflow-x-auto pb-1">
                    <button v-for="r in rounds" :key="r.id" class="shrink-0 rounded-xl border px-4 py-2.5 text-start transition" :class="r.id === selected ? 'border-brand-500 bg-white shadow-sm ring-1 ring-brand-500' : 'border-slate-200 bg-white/60 hover:bg-white'" @click="pick(r.id)">
                        <span class="flex items-center gap-2"><span class="text-sm font-bold text-slate-800">{{ r.name }}</span><Badge :tone="stateTone[r.state]">{{ stateLabel[r.state] }}</Badge></span>
                        <span class="mt-0.5 block text-xs text-slate-500">{{ r.term }} · {{ r.college }}</span>
                    </button>
                </div>

                <div v-if="round" class="mt-3 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                    <span><span class="ltr">{{ round.opens_on }}</span> ← <span class="ltr">{{ round.closes_on }}</span></span>
                    <span>· {{ round.form }}</span>
                    <Badge v-if="!round.official" tone="amber">استمارة غير رسمية</Badge>
                    <span>· الحد الأدنى {{ round.min }} إجابات للمادة</span>
                </div>

                <div v-if="results" class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4">
                    <StatCard label="المعدل العام" :value="results.overall ? results.overall + ' / 5' : '—'" :hint="results.overall ? 'رضا ' + pct(results.overall) : 'لا توجد نتائج كافية بعد'" :icon="StarIcon" tone="amber" />
                    <StatCard label="الإجابات" :value="results.answers.toLocaleString('en-US')" :hint="`من ${results.enrolled.toLocaleString('en-US')} طالب مسجل`" :icon="UserGroupIcon" />
                    <StatCard label="نسبة المشاركة" :value="rate + '%'" hint="كلما ارتفعت كانت النتائج أدق" :icon="ArrowTrendingUpIcon" tone="emerald" />
                </div>

                <section v-if="results" class="card mt-5 overflow-hidden">
                    <EmptyState v-if="!sorted.length" title="لا توجد مواد في هذا الفصل ضمن صلاحيتك" />
                    <ul v-else class="divide-y divide-slate-100">
                        <li v-for="r in sorted" :key="r.offering_id">
                            <button class="flex w-full flex-wrap items-center gap-3 px-5 py-3.5 text-start hover:bg-slate-50/70" :disabled="!r.shown" @click="open = open === r.offering_id ? null : r.offering_id">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-bold text-slate-800">{{ r.course }} <span class="ltr font-mono text-xs font-normal text-slate-400">{{ r.code }}</span><span v-if="r.section" class="text-xs font-normal text-slate-400"> · شعبة {{ r.section }}</span></p>
                                    <p class="truncate text-xs text-slate-500">{{ r.teacher || 'بلا تدريسي' }} · {{ r.department }}</p>
                                </div>
                                <span class="text-xs tabular-nums text-slate-500">{{ r.answers }} / {{ r.enrolled }}</span>
                                <div v-if="r.shown" class="flex w-40 items-center gap-2">
                                    <div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full" :class="tone(r.average)" :style="{ width: ((r.average ?? 0) / 5) * 100 + '%' }" /></div>
                                    <span class="w-9 text-end text-sm font-extrabold tabular-nums">{{ r.average }}</span>
                                </div>
                                <span v-else class="w-40 text-end text-xs text-slate-400">إجابات غير كافية</span>
                                <ChevronDownIcon class="h-4 w-4 text-slate-400 transition" :class="[open === r.offering_id && 'rotate-180', !r.shown && 'invisible']" />
                            </button>
                            <div v-if="open === r.offering_id && r.shown" class="grid gap-5 bg-slate-50/60 px-5 py-4 lg:grid-cols-2">
                                <div class="space-y-2">
                                    <div v-for="q in results.questions" :key="q.index" class="text-xs">
                                        <div class="flex items-start justify-between gap-3"><span class="text-slate-600">{{ q.text }}</span><b class="tabular-nums">{{ r.per_question[q.index] ?? '—' }}</b></div>
                                        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-200"><div class="h-full rounded-full" :class="tone(r.per_question[q.index])" :style="{ width: ((r.per_question[q.index] ?? 0) / 5) * 100 + '%' }" /></div>
                                    </div>
                                </div>
                                <div>
                                    <p class="mb-2 flex items-center gap-1.5 text-xs font-bold text-slate-600"><ChatBubbleLeftEllipsisIcon class="h-4 w-4" /> ملاحظات الطلبة ({{ r.comments.length }})</p>
                                    <p v-if="!r.comments.length" class="text-xs text-slate-400">لا توجد ملاحظات مكتوبة.</p>
                                    <ul v-else class="max-h-64 space-y-1.5 overflow-y-auto">
                                        <li v-for="(c, k) in r.comments" :key="k" class="rounded-lg bg-white px-3 py-2 text-xs text-slate-700 shadow-sm">{{ c }}</li>
                                    </ul>
                                </div>
                            </div>
                        </li>
                    </ul>
                </section>
            </template>

            <!-- forms -->
            <section v-if="perm.manage && forms.length" class="card mt-6">
                <div class="card-header"><h2 class="card-title">الاستمارات</h2></div>
                <ul class="divide-y divide-slate-100">
                    <li v-for="f in forms" :key="f.id" class="flex flex-wrap items-center gap-3 px-5 py-3 text-sm">
                        <div class="min-w-0 flex-1"><p class="font-bold">{{ f.name }}</p><p class="text-xs text-slate-500">{{ f.questions.length }} سؤال<span v-if="f.source_reference"> · {{ f.source_reference }}</span></p></div>
                        <Badge :tone="f.is_official ? 'emerald' : 'amber'">{{ f.is_official ? 'رسمية' : 'غير رسمية' }}</Badge>
                        <Badge v-if="!f.is_active" tone="slate">موقوفة</Badge>
                        <button class="btn-ghost !py-1 text-xs" @click="editForm(f)">تعديل</button>
                    </li>
                </ul>
            </section>
        </template>

        <!-- the teacher's own results -->
        <section v-if="mine.length" class="mt-6 space-y-4">
            <h2 class="text-lg font-extrabold text-slate-800">نتائج تقييمي</h2>
            <div v-for="m in mine" :key="m.round.id" class="card overflow-hidden">
                <div class="card-header flex items-center gap-2"><h3 class="card-title">{{ m.round.name }}</h3><span class="text-xs text-slate-500">{{ m.round.term }}</span><span class="ms-auto text-sm font-extrabold">{{ m.overall ?? '—' }} <span class="text-xs font-normal text-slate-400">/ 5</span></span></div>
                <ul class="divide-y divide-slate-100">
                    <li v-for="r in m.rows" :key="r.offering_id" class="px-5 py-3 text-sm">
                        <div class="flex items-center gap-3"><span class="flex-1 font-bold">{{ r.course }}</span><span class="text-xs text-slate-500">{{ r.answers }} إجابة</span><b class="tabular-nums">{{ r.shown ? r.average : '—' }}</b></div>
                        <ul v-if="r.comments.length" class="mt-2 space-y-1"><li v-for="(c, k) in r.comments" :key="k" class="rounded-lg bg-slate-50 px-3 py-1.5 text-xs text-slate-600">{{ c }}</li></ul>
                    </li>
                </ul>
            </div>
        </section>
        <EmptyState v-if="!perm.view && !mine.length" class="card" :icon="StarIcon" title="لا توجد نتائج تقييم لك بعد" text="تظهر هنا بعد غلق فترة التقييم واكتمال الحد الأدنى من إجابات الطلبة." />

        <!-- new round -->
        <Modal :show="showRound" max-width="lg" @close="showRound = false">
            <form class="space-y-4 p-6" @submit.prevent="saveRound">
                <h3 class="text-lg font-extrabold">فترة تقييم جديدة</h3>
                <div><label class="label">الاسم</label><input v-model="rf.name" class="input" placeholder="تقييم الفصل الأول 2025-2026" /><InputError :message="rf.errors.name" /></div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="label">الاستمارة</label><select v-model="rf.evaluation_form_id" class="input"><option v-for="f in forms.filter((x) => x.is_active)" :key="f.id" :value="f.id">{{ f.name }}</option></select></div>
                    <div><label class="label">الفصل</label><select v-model="rf.academic_term_id" class="input"><option v-for="t in terms" :key="t.id" :value="t.id">{{ t.name }}</option></select></div>
                    <div class="sm:col-span-2"><label class="label">الكلية</label><select v-model="rf.college_id" class="input"><option v-if="perm.allColleges" :value="null">كل الكليات</option><option v-for="c in colleges" :key="c.id" :value="c.id">{{ c.name_ar }}</option></select></div>
                    <div><label class="label">يفتح</label><input v-model="rf.opens_on" type="date" class="input" /><InputError :message="rf.errors.opens_on" /></div>
                    <div><label class="label">يغلق</label><input v-model="rf.closes_on" type="date" class="input" /><InputError :message="rf.errors.closes_on" /></div>
                    <div><label class="label">أقل عدد إجابات لإظهار نتيجة المادة</label><input v-model="rf.min_responses" type="number" min="3" max="50" class="input" /><InputError :message="rf.errors.min_responses" /></div>
                </div>
                <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="showRound = false">إلغاء</button><button class="btn-primary" :disabled="rf.processing">فتح الفترة</button></div>
            </form>
        </Modal>

        <!-- form editor -->
        <Modal :show="showForm" max-width="2xl" @close="showForm = false">
            <form class="space-y-4 p-6" @submit.prevent="saveForm">
                <h3 class="text-lg font-extrabold">{{ editing ? 'تعديل الاستمارة' : 'استمارة جديدة' }}</h3>
                <p v-if="editing?.used" class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">استُعملت هذه الاستمارة، فلا تتغير أسئلتها. يمكن تعديل اسمها وحالتها فقط.</p>
                <div><label class="label">الاسم</label><input v-model="ff.name" class="input" /><InputError :message="ff.errors.name" /></div>
                <div class="space-y-2">
                    <div v-for="(q, i) in ff.questions" :key="i" class="flex items-start gap-2">
                        <span class="mt-2.5 w-5 text-xs text-slate-400">{{ i + 1 }}</span>
                        <input v-model="q.text" class="input flex-1" :disabled="editing?.used" />
                        <select v-model="q.kind" class="input !w-32" :disabled="editing?.used"><option value="scale">مقياس 1–5</option><option value="text">نص حر</option></select>
                        <button v-if="!editing?.used" type="button" class="mt-1.5 rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600" @click="ff.questions.splice(i, 1)"><TrashIcon class="h-4 w-4" /></button>
                    </div>
                    <InputError :message="ff.errors.questions" />
                    <button v-if="!editing?.used" type="button" class="btn-ghost text-xs" @click="ff.questions.push({ text: '', kind: 'scale' })"><PlusIcon class="h-4 w-4" /> سؤال</button>
                </div>
                <label class="flex items-center gap-2 text-sm"><input v-model="ff.is_official" type="checkbox" class="rounded text-brand-600" /> استمارة رسمية معتمدة</label>
                <div v-if="ff.is_official"><label class="label">مرجع الاعتماد (كتاب أو قرار)</label><input v-model="ff.source_reference" class="input" /><InputError :message="ff.errors.source_reference" /></div>
                <label class="flex items-center gap-2 text-sm"><input v-model="ff.is_active" type="checkbox" class="rounded text-brand-600" /> فعّالة</label>
                <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="showForm = false">إلغاء</button><button class="btn-primary" :disabled="ff.processing">حفظ</button></div>
            </form>
        </Modal>
    </AppLayout>
</template>
