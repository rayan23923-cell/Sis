<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Badge from '@/Components/Badge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import { CalendarDaysIcon, CheckIcon, ClipboardDocumentCheckIcon, ExclamationTriangleIcon, PencilSquareIcon, PlusIcon, TableCellsIcon, TrashIcon, UserGroupIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ offering: Object, students: Array, sessions: Array, policy: Object, statuses: Object, kinds: Object, canEdit: Boolean, today: String });

const tab = ref(props.canEdit ? 'register' : 'summary');
const tones = { present: 'emerald', absent: 'rose', excused: 'amber', late: 'sky' };
const short = { present: 'ح', absent: 'غ', excused: 'ع', late: 'ت' };
const btnOn = { present: 'bg-emerald-600 text-white', absent: 'bg-rose-600 text-white', excused: 'bg-amber-500 text-white', late: 'bg-sky-600 text-white' };

// ---------- register ----------
const editing = ref(null);
const nextPeriod = (date) => Math.max(0, ...props.sessions.filter((s) => s.held_on === date).map((s) => s.period)) + 1;
const blank = () => ({ held_on: props.today, period: nextPeriod(props.today), hours: props.sessions[0]?.hours ?? 2, kind: props.sessions[0]?.kind ?? 'lecture', topic: '', statuses: {} });
const form = useForm(blank());
const marks = reactive({});
const resetMarks = (src = {}) => {
    for (const s of props.students) marks[s.enrollment_id] = src[s.enrollment_id] ?? 'present';
};
resetMarks();

const startNew = () => {
    editing.value = null;
    form.defaults(blank()); form.reset(); form.clearErrors();
    resetMarks();
};
const edit = (s) => {
    editing.value = s;
    Object.assign(form, { held_on: s.held_on, period: s.period, hours: s.hours, kind: s.kind, topic: s.topic ?? '' });
    form.clearErrors();
    resetMarks(s.statuses);
    tab.value = 'register';
    window.scrollTo({ top: 0, behavior: 'smooth' });
};
watch(() => form.held_on, (d) => { if (!editing.value && d) form.period = nextPeriod(d); });

const counts = computed(() => {
    const c = { present: 0, absent: 0, excused: 0, late: 0 };
    Object.values(marks).forEach((v) => c[v]++);
    return c;
});
const setAll = (v) => props.students.forEach((s) => { marks[s.enrollment_id] = v; });

const save = () => {
    form.statuses = Object.fromEntries(Object.entries(marks).filter(([, v]) => v !== 'present'));
    const opts = { preserveScroll: true, onSuccess: () => startNew() };
    editing.value
        ? form.put(route('offerings.attendance.update', { offering: props.offering.id, session: editing.value.id }), opts)
        : form.post(route('offerings.attendance.store', props.offering.id), opts);
};

const confirmDelete = ref(false);
const destroy = () => router.delete(route('offerings.attendance.destroy', { offering: props.offering.id, session: editing.value.id }), {
    preserveScroll: true, onSuccess: () => { confirmDelete.value = false; startNew(); },
});

// ---------- summary ----------
const heldHours = computed(() => props.sessions.reduce((s, x) => s + x.hours, 0));
const warned = computed(() => props.students.filter((s) => s.level > 0).length);
const avgAttendance = computed(() => props.students.length ? (100 - props.students.reduce((s, x) => s + x.percent, 0) / props.students.length).toFixed(1) : '—');
const sortBy = ref('name');
const summary = computed(() => [...props.students].sort((a, b) => sortBy.value === 'percent' ? b.percent - a.percent : 0));
const levelTone = (s) => s.over_limit ? 'rose' : (s.level >= 2 ? 'rose' : s.level === 1 ? 'amber' : 'emerald');
const barColor = (s) => s.over_limit || s.level >= 2 ? 'bg-rose-500' : s.level === 1 ? 'bg-amber-500' : 'bg-emerald-500';
const maxScale = computed(() => Math.max(props.policy?.limit ?? 0, ...(props.policy?.warnings ?? []).map((w) => w.percent), 20) * 1.25);
</script>

<template>
    <AppLayout title="الحضور والغياب">
        <PageHeader :title="offering.name" :subtitle="`الحضور والغياب · ${offering.term}${offering.teacher ? ' · ' + offering.teacher : ''}`"
            :crumbs="[{ label: 'المواد والدرجات', href: route('offerings.index') }, { label: offering.code, href: route('offerings.show', offering.id) }, { label: 'الحضور' }]">
            <Link :href="route('offerings.show', offering.id)" class="btn-secondary"><TableCellsIcon class="h-4 w-4" /> سجل الدرجات</Link>
        </PageHeader>

        <div v-if="policy && !policy.official" class="mb-4 flex items-start gap-2 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <ExclamationTriangleIcon class="mt-0.5 h-5 w-5 shrink-0" />
            <span>حدود الإنذار مؤقتة وغير رسمية. لا يترتب على الغياب أي أثر على النتيجة حتى تُدخل تعليمات الجامعة المعتمدة.</span>
        </div>

        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div class="card p-4"><p class="text-sm text-slate-500">المحاضرات المسجلة</p><p class="text-2xl font-extrabold tabular-nums">{{ sessions.length }}</p></div>
            <div class="card p-4"><p class="text-sm text-slate-500">الساعات المنفذة</p><p class="text-2xl font-extrabold tabular-nums">{{ heldHours }}</p></div>
            <div class="card p-4"><p class="text-sm text-slate-500">متوسط الحضور</p><p class="text-2xl font-extrabold tabular-nums text-emerald-600">{{ avgAttendance }}<span v-if="students.length" class="text-base">%</span></p></div>
            <div class="card p-4"><p class="text-sm text-slate-500">طلبة لديهم إنذار</p><p class="text-2xl font-extrabold tabular-nums" :class="warned ? 'text-rose-600' : ''">{{ warned }}</p></div>
        </div>

        <div class="mb-4 flex gap-1 overflow-x-auto rounded-xl bg-slate-100 p-1 text-sm font-semibold sm:inline-flex">
            <button v-if="canEdit" class="flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 transition" :class="tab === 'register' ? 'bg-white text-brand-800 shadow-sm' : 'text-slate-500 hover:text-slate-700'" @click="tab = 'register'"><ClipboardDocumentCheckIcon class="h-4 w-4" /> تسجيل محاضرة</button>
            <button class="flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 transition" :class="tab === 'summary' ? 'bg-white text-brand-800 shadow-sm' : 'text-slate-500 hover:text-slate-700'" @click="tab = 'summary'"><UserGroupIcon class="h-4 w-4" /> نسب الغياب</button>
            <button class="flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 transition" :class="tab === 'sessions' ? 'bg-white text-brand-800 shadow-sm' : 'text-slate-500 hover:text-slate-700'" @click="tab = 'sessions'"><CalendarDaysIcon class="h-4 w-4" /> المحاضرات</button>
        </div>

        <!-- register -->
        <form v-if="tab === 'register' && canEdit" class="card" @submit.prevent="save">
            <div class="card-header flex flex-wrap items-center gap-2">
                <h2 class="card-title">{{ editing ? 'تعديل محاضرة' : 'محاضرة جديدة' }}</h2>
                <span v-if="editing" class="ltr text-sm text-slate-500">{{ editing.held_on }}</span>
                <button v-if="editing" type="button" class="btn-ghost ms-auto" @click="startNew"><PlusIcon class="h-4 w-4" /> محاضرة جديدة</button>
            </div>
            <div class="grid gap-4 border-b border-slate-100 p-5 sm:grid-cols-6">
                <div class="sm:col-span-2"><label class="label">تاريخ المحاضرة</label><input v-model="form.held_on" type="date" :max="today" class="input" required /><InputError :message="form.errors.held_on" class="mt-1" /></div>
                <div><label class="label">رقم المحاضرة</label><input v-model.number="form.period" type="number" min="1" max="12" class="input tabular-nums" required /></div>
                <div><label class="label">عدد الساعات</label><input v-model.number="form.hours" type="number" min="0.5" max="8" step="0.5" class="input tabular-nums" required /><InputError :message="form.errors.hours" class="mt-1" /></div>
                <div class="sm:col-span-2"><label class="label">النوع</label><select v-model="form.kind" class="input"><option v-for="(l, k) in kinds" :key="k" :value="k">{{ l }}</option></select></div>
                <div class="sm:col-span-6"><label class="label">موضوع المحاضرة (اختياري)</label><input v-model="form.topic" class="input" maxlength="255" /></div>
            </div>

            <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-3 text-sm">
                <span class="font-bold text-slate-700">الطلبة ({{ students.length }})</span>
                <Badge tone="emerald">حاضر {{ counts.present }}</Badge>
                <Badge tone="rose">غائب {{ counts.absent }}</Badge>
                <Badge v-if="counts.excused" tone="amber">بعذر {{ counts.excused }}</Badge>
                <Badge v-if="counts.late" tone="sky">متأخر {{ counts.late }}</Badge>
                <button type="button" class="btn-ghost ms-auto text-xs" @click="setAll('present')">الكل حاضر</button>
            </div>

            <EmptyState v-if="!students.length" title="لا يوجد طلبة مسجلون في هذه الشعبة" />
            <ul v-else class="divide-y divide-slate-100">
                <li v-for="(s, i) in students" :key="s.enrollment_id" class="flex flex-wrap items-center gap-3 px-5 py-2.5" :class="marks[s.enrollment_id] === 'absent' && 'bg-rose-50/50'">
                    <span class="w-6 text-center text-xs tabular-nums text-slate-400">{{ i + 1 }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="font-bold text-slate-800">{{ s.name }}</p>
                        <p class="text-[11px] text-slate-400"><span class="font-mono">{{ s.number }}</span><span v-if="s.percent"> · غياب {{ s.percent }}%</span><span v-if="s.warning" class="text-rose-600"> · {{ s.warning }}</span></p>
                    </div>
                    <div class="flex gap-1 rounded-xl bg-slate-100 p-1" role="radiogroup" :aria-label="s.name">
                        <button v-for="(label, k) in statuses" :key="k" type="button" role="radio" :aria-checked="marks[s.enrollment_id] === k" :title="label"
                            class="min-w-[2.25rem] rounded-lg px-2 py-1 text-xs font-bold transition"
                            :class="marks[s.enrollment_id] === k ? btnOn[k] : 'text-slate-500 hover:bg-white'"
                            @click="marks[s.enrollment_id] = k">
                            <span class="sm:hidden">{{ short[k] }}</span><span class="hidden sm:inline">{{ label }}</span>
                        </button>
                    </div>
                </li>
            </ul>
            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 p-4">
                <button v-if="editing" type="button" class="btn-ghost me-auto text-rose-600 hover:bg-rose-50" @click="confirmDelete = true"><TrashIcon class="h-4 w-4" /> حذف المحاضرة</button>
                <button class="btn-primary" :disabled="form.processing || !students.length"><CheckIcon class="h-4 w-4" /> {{ editing ? 'حفظ التعديل' : 'حفظ الحضور' }}</button>
            </div>
        </form>

        <!-- summary -->
        <section v-if="tab === 'summary'" class="card">
            <div class="card-header flex flex-wrap items-center gap-2">
                <h2 class="card-title">نسب الغياب</h2>
                <span v-if="policy" class="text-xs text-slate-500">
                    <template v-for="w in policy.warnings" :key="w.percent">{{ w.name_ar }} عند {{ w.percent }}% · </template>
                    <template v-if="policy.limit">الحد الأعلى {{ policy.limit }}%</template><template v-else>لا يوجد حد أعلى معتمد</template>
                    <template v-if="!policy.count_excused"> · الغياب بعذر لا يُحتسب</template>
                </span>
                <select v-model="sortBy" class="input ms-auto w-auto py-1.5 text-sm"><option value="name">ترتيب بالاسم</option><option value="percent">الأعلى غياباً أولاً</option></select>
            </div>
            <EmptyState v-if="!sessions.length" title="لم تُسجّل أي محاضرة بعد" />
            <div v-else class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>الطالب</th><th>غائب</th><th>بعذر</th><th>متأخر</th><th>ساعات الغياب</th><th class="min-w-[180px]">نسبة الغياب</th><th>الحالة</th></tr></thead>
                    <tbody>
                        <tr v-for="s in summary" :key="s.enrollment_id">
                            <td><Link :href="route('students.show', s.student_id)" class="font-semibold hover:text-brand-700">{{ s.name }}</Link><p class="font-mono text-xs text-slate-400">{{ s.number }}</p></td>
                            <td class="tabular-nums">{{ s.absent }}</td>
                            <td class="tabular-nums">{{ s.excused }}</td>
                            <td class="tabular-nums">{{ s.late }}</td>
                            <td class="tabular-nums">{{ s.absence_hours }} / {{ s.held_hours }}</td>
                            <td>
                                <div class="flex items-center gap-2">
                                    <div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full" :class="barColor(s)" :style="{ width: Math.min(100, s.percent / maxScale * 100) + '%' }"></div></div>
                                    <span class="w-12 text-end font-bold tabular-nums">{{ s.percent }}%</span>
                                </div>
                            </td>
                            <td><Badge :tone="levelTone(s)">{{ s.over_limit ? 'تجاوز الحد' : (s.warning ?? 'منتظم') }}</Badge></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- sessions -->
        <section v-if="tab === 'sessions'" class="card">
            <EmptyState v-if="!sessions.length" title="لم تُسجّل أي محاضرة بعد" />
            <ul v-else class="divide-y divide-slate-100">
                <li v-for="s in sessions" :key="s.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
                    <div class="grid h-11 w-11 place-items-center rounded-xl bg-brand-50 text-xs font-extrabold text-brand-800 tabular-nums">{{ s.period }}</div>
                    <div class="min-w-0 flex-1">
                        <p class="font-bold text-slate-800"><span class="ltr">{{ s.held_on }}</span> · {{ kinds[s.kind] }} · {{ s.hours }} ساعة</p>
                        <p v-if="s.topic" class="truncate text-sm text-slate-500">{{ s.topic }}</p>
                    </div>
                    <Badge :tone="s.absent ? 'rose' : 'emerald'">غائب {{ s.absent }}</Badge>
                    <Badge v-if="s.excused" tone="amber">بعذر {{ s.excused }}</Badge>
                    <Badge v-if="s.late" tone="sky">متأخر {{ s.late }}</Badge>
                    <button v-if="canEdit" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-brand-700" title="تعديل" @click="edit(s)"><PencilSquareIcon class="h-4 w-4" /></button>
                </li>
            </ul>
        </section>

        <Modal :show="confirmDelete" max-width="md" @close="confirmDelete = false">
            <div class="p-6">
                <h3 class="text-lg font-extrabold">حذف المحاضرة؟</h3>
                <p class="mt-2 text-sm text-slate-600">يُحذف سجل حضور هذه المحاضرة لكل الطلبة وتُعاد حساب نسب الغياب.</p>
                <div class="mt-6 flex justify-end gap-2"><button class="btn-secondary" @click="confirmDelete = false">إلغاء</button><button class="btn-danger" @click="destroy">حذف</button></div>
            </div>
        </Modal>
    </AppLayout>
</template>
