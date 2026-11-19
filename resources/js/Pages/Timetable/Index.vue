<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import WeekGrid from '@/Components/WeekGrid.vue';
import { BuildingOfficeIcon, CalendarDaysIcon, PlusIcon, PrinterIcon, TrashIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ filters: Object, terms: Array, programs: Array, colleges: Array, rooms: Array, teachers: Array, slots: Array, offerings: Array, days: Object, kinds: Object, canEdit: Boolean, canManageRooms: Boolean });

const f = reactive({ ...props.filters });
const clean = () => Object.fromEntries(Object.entries(f).filter(([, v]) => v !== null && v !== ''));
watch(f, () => router.get(route('timetable.index'), clean(), { preserveState: true, preserveScroll: true, replace: true }));
const program = computed(() => props.programs.find((p) => p.id === Number(f.program)));
const roomsHere = computed(() => (program.value ? props.rooms.filter((r) => r.college_id === program.value.college_id) : props.rooms));
const views = [['cohort', 'المرحلة والشعبة'], ['room', 'القاعة'], ['teacher', 'التدريسي']];

const show = ref(false);
const editing = ref(null);
const form = useForm({ course_offering_id: '', day: 0, starts_at: '08:30', ends_at: '10:30', room_id: '', kind: 'lecture' });
const openAdd = (at = {}) => {
    if (!props.canEdit) return;
    editing.value = null;
    form.reset(); form.clearErrors();
    Object.assign(form, { course_offering_id: props.offerings.find((o) => o.placed < o.credits)?.id ?? props.offerings[0]?.id ?? '', ...at });
    show.value = true;
};
const openEdit = (s) => {
    if (!props.canEdit) return;
    editing.value = s;
    form.clearErrors();
    Object.assign(form, { course_offering_id: s.offering_id, day: s.day, starts_at: s.starts_at, ends_at: s.ends_at, room_id: s.room_id ?? '', kind: s.kind });
    show.value = true;
};
const save = () => {
    const opts = { preserveScroll: true, onSuccess: () => (show.value = false) };
    editing.value ? form.put(route('timetable.update', editing.value.id), opts) : form.post(route('timetable.store'), opts);
};
const remove = () => confirm('حذف هذا الموعد؟') && router.delete(route('timetable.destroy', editing.value.id), { preserveScroll: true, onSuccess: () => (show.value = false) });
const hoursTotal = computed(() => props.slots.reduce((a, s) => { const [h1, m1] = s.starts_at.split(':'); const [h2, m2] = s.ends_at.split(':'); return a + (h2 * 60 + +m2 - h1 * 60 - m1) / 60; }, 0));
</script>

<template>
    <AppLayout title="الجدول الأسبوعي">
        <PageHeader title="الجدول الأسبوعي" subtitle="مواعيد المحاضرات لكل مرحلة وقاعة وتدريسي، مع منع التعارض تلقائياً.">
            <Link v-if="canManageRooms" :href="route('rooms.index')" class="btn-secondary"><BuildingOfficeIcon class="h-4 w-4" /> القاعات</Link>
            <a v-if="slots.length" :href="route('timetable.pdf', clean())" class="btn-secondary"><PrinterIcon class="h-4 w-4" /> PDF للطباعة</a>
            <button v-if="canEdit" class="btn-primary" @click="openAdd()"><PlusIcon class="h-4 w-4" /> موعد</button>
        </PageHeader>

        <div class="card mb-4 space-y-3 p-4">
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex overflow-x-auto rounded-xl bg-slate-100 p-1 text-sm font-semibold">
                    <button v-for="[k, l] in views" :key="k" type="button" class="shrink-0 whitespace-nowrap rounded-lg px-4 py-1.5 transition" :class="f.view === k ? 'bg-white text-brand-800 shadow-sm' : 'text-slate-500'" @click="f.view = k">{{ l }}</button>
                </div>
                <select v-model="f.term" class="input ms-auto w-auto" aria-label="الفصل"><option v-for="t in terms" :key="t.id" :value="t.id">{{ t.name }}</option></select>
            </div>
            <div v-if="f.view === 'cohort'" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <select v-model="f.program" class="input sm:col-span-2" aria-label="البرنامج"><option :value="null">اختر البرنامج</option><option v-for="p in programs" :key="p.id" :value="p.id">{{ p.name }} — {{ p.department }}</option></select>
                <select v-model.number="f.stage" class="input" aria-label="المرحلة"><option v-for="n in program?.years ?? 6" :key="n" :value="n">المرحلة {{ n }}</option></select>
                <div class="grid grid-cols-2 gap-3">
                    <input v-model="f.section" class="input" placeholder="الشعبة" aria-label="الشعبة" />
                    <select v-model="f.shift" class="input" aria-label="الدراسة"><option value="morning">صباحي</option><option value="evening">مسائي</option></select>
                </div>
            </div>
            <select v-else-if="f.view === 'room'" v-model="f.room" class="input sm:max-w-md" aria-label="القاعة"><option :value="null">اختر القاعة</option><option v-for="r in rooms" :key="r.id" :value="r.id">{{ r.code }} — {{ r.name_ar }} ({{ r.capacity }})</option></select>
            <select v-else v-model="f.teacher" class="input sm:max-w-md" aria-label="التدريسي"><option :value="null">اختر التدريسي</option><option v-for="t in teachers" :key="t.id" :value="t.id">{{ t.name }}</option></select>
        </div>

        <div class="grid gap-4" :class="f.view === 'cohort' && offerings.length ? 'xl:grid-cols-[1fr_280px]' : ''">
            <section class="card overflow-hidden p-2 sm:p-4">
                <EmptyState v-if="(f.view === 'cohort' && !f.program) || (f.view === 'room' && !f.room) || (f.view === 'teacher' && !f.teacher)" :icon="CalendarDaysIcon" title="اختر ما تريد عرض جدوله" text="المرحلة والشعبة، أو القاعة، أو التدريسي." />
                <template v-else>
                    <p class="mb-3 px-2 text-sm text-slate-500">{{ slots.length }} موعد · {{ hoursTotal }} ساعة أسبوعياً<span v-if="canEdit"> · اضغط على مكان فارغ لإضافة موعد، أو على موعد لتعديله.</span></p>
                    <WeekGrid :slots="slots" :days="days" :editable="canEdit" :show-program="f.view !== 'cohort'" @add="openAdd" @select="openEdit" />
                </template>
            </section>

            <aside v-if="f.view === 'cohort' && offerings.length" class="card h-fit p-4">
                <h3 class="font-extrabold text-slate-800">مواد المرحلة</h3>
                <p class="mt-1 text-xs text-slate-500">الساعات الموزعة من وحدات كل مادة.</p>
                <ul class="mt-3 space-y-2">
                    <li v-for="o in offerings" :key="o.id" class="rounded-xl border border-slate-100 p-3">
                        <div class="flex items-start gap-2">
                            <p class="min-w-0 flex-1 text-sm font-bold text-slate-800">{{ o.name }}</p>
                            <span class="shrink-0 text-xs font-bold tabular-nums" :class="o.placed >= o.credits ? 'text-emerald-600' : 'text-amber-600'">{{ o.placed }}/{{ o.credits }}</span>
                        </div>
                        <p class="text-xs text-slate-400">{{ o.teacher ?? 'بدون تدريسي' }}</p>
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full" :class="o.placed >= o.credits ? 'bg-emerald-500' : 'bg-amber-400'" :style="{ width: Math.min(100, (o.placed / (o.credits || 1)) * 100) + '%' }"></div></div>
                    </li>
                </ul>
            </aside>
        </div>

        <Modal :show="show" max-width="lg" @close="show = false">
            <form class="p-6" @submit.prevent="save">
                <h3 class="text-lg font-extrabold">{{ editing ? 'تعديل موعد' : 'إضافة موعد' }}</h3>
                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2"><label class="label">المادة</label>
                        <select v-model="form.course_offering_id" class="input" :disabled="!!editing" required><option v-for="o in offerings" :key="o.id" :value="o.id">{{ o.name }}{{ o.teacher ? ' — ' + o.teacher : '' }}</option></select>
                        <InputError :message="form.errors.course_offering_id" class="mt-1" /></div>
                    <div><label class="label">اليوم</label><select v-model.number="form.day" class="input"><option v-for="(n, d) in days" :key="d" :value="Number(d)">{{ n }}</option></select></div>
                    <div><label class="label">النوع</label><select v-model="form.kind" class="input"><option v-for="(l, k) in kinds" :key="k" :value="k">{{ l }}</option></select></div>
                    <div><label class="label">من</label><input v-model="form.starts_at" type="time" step="900" class="input" dir="ltr" required /><InputError :message="form.errors.starts_at" class="mt-1" /></div>
                    <div><label class="label">إلى</label><input v-model="form.ends_at" type="time" step="900" class="input" dir="ltr" required /><InputError :message="form.errors.ends_at" class="mt-1" /></div>
                    <div class="sm:col-span-2"><label class="label">القاعة</label>
                        <select v-model="form.room_id" class="input"><option value="">— بدون —</option><option v-for="r in roomsHere" :key="r.id" :value="r.id">{{ r.code }} — {{ r.name_ar }} (سعة {{ r.capacity }})</option></select>
                        <p v-if="!roomsHere.length" class="mt-1 text-xs text-slate-500">لا توجد قاعات لهذه الكلية. أضفها من صفحة القاعات.</p></div>
                </div>
                <div class="mt-6 flex items-center gap-2">
                    <button v-if="editing" type="button" class="btn-secondary text-rose-700" @click="remove"><TrashIcon class="h-4 w-4" /> حذف</button>
                    <div class="ms-auto flex gap-2"><button type="button" class="btn-secondary" @click="show = false">إلغاء</button><button class="btn-primary" :disabled="form.processing">حفظ</button></div>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
