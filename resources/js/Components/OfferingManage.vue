<script setup>
import { ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import { PlusIcon, TrashIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ show: Boolean, offering: Object, components: Array, canEditComponents: Boolean });
const emit = defineEmits(['close']);
const tab = ref('info');
const teachers = ref([]);

const info = useForm({ teacher_id: '', capacity: 60, section: 'A', shift: 'morning' });
const comps = useForm({ components: [] });
watch(() => props.show, async (s) => {
    if (!s) return;
    tab.value = 'info';
    Object.assign(info, { teacher_id: props.offering.teacher_id ?? '', capacity: props.offering.capacity, section: props.offering.section, shift: props.offering.shift ?? 'morning' });
    comps.components = props.components.map((c) => ({ ...c }));
    if (!teachers.value.length) teachers.value = (await axios.get(route('offerings.teachers'))).data;
});
const saveInfo = () => info.put(route('offerings.update', props.offering.id), { preserveScroll: true, onSuccess: () => emit('close') });
const sum = (round) => {
    const list = comps.components;
    const replaced = new Set(list.filter((c) => c.exam_round > 1 && c.exam_round <= round).map((c) => c.code.replace(/_r\d+$/, '')));
    return list.filter((c) => (c.exam_round === 1 && (round === 1 || !replaced.has(c.code))) || (c.exam_round > 1 && c.exam_round <= round)).reduce((s, c) => s + Number(c.weight || 0), 0);
};
const rounds = () => [...new Set(comps.components.map((c) => Number(c.exam_round)))].sort();
const saveComps = () => comps.put(route('offerings.components', props.offering.id), { preserveScroll: true, onSuccess: () => emit('close') });

const q = ref('');
const found = ref([]);
const carried = ref(false);
let t;
watch(q, (v) => { clearTimeout(t); t = setTimeout(async () => { found.value = v.length < 2 ? [] : (await axios.get(route('offerings.students.search', props.offering.id), { params: { q: v } })).data; }, 300); });
const add = (s) => router.post(route('offerings.students.store', props.offering.id), { student_id: s.id, carried: carried.value }, { preserveScroll: true, onSuccess: () => { found.value = found.value.filter((x) => x.id !== s.id); } });
</script>

<template>
    <Modal :show="show" max-width="2xl" @close="emit('close')">
        <div class="p-6">
            <h3 class="text-lg font-extrabold">إدارة الشعبة</h3>
            <div class="mt-4 flex rounded-xl bg-slate-100 p-1 text-sm font-bold">
                <button v-for="[k, l] in [['info', 'البيانات والتدريسي'], ['components', 'مكونات التقييم'], ['students', 'إضافة طالب']]" :key="k" type="button"
                    class="flex-1 rounded-lg px-3 py-2 transition" :class="tab === k ? 'bg-white text-brand-800 shadow-sm' : 'text-slate-500'" @click="tab = k">{{ l }}</button>
            </div>

            <form v-if="tab === 'info'" class="mt-5 space-y-4" @submit.prevent="saveInfo">
                <div><label class="label">التدريسي</label>
                    <select v-model="info.teacher_id" class="input"><option value="">— بدون —</option><option v-for="t in teachers" :key="t.id" :value="t.id">{{ t.name }}<template v-if="t.department"> · {{ t.department }}</template></option></select>
                    <p class="mt-1 text-xs text-slate-500">التدريسي وحده يُدخل درجات الشعبة. لإضافة تدريسي جديد أنشئ له حساباً بدور «تدريسي» في قسمه.</p></div>
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="label">الشعبة</label><input v-model="info.section" class="input" /><InputError :message="info.errors.section" class="mt-1" /></div>
                    <div><label class="label">الدراسة</label><select v-model="info.shift" class="input"><option value="morning">صباحية</option><option value="evening">مسائية</option></select></div>
                    <div><label class="label">السعة</label><input v-model="info.capacity" type="number" min="1" class="input" /></div>
                </div>
                <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" @click="emit('close')">إغلاق</button><button class="btn-primary" :disabled="info.processing">حفظ</button></div>
            </form>

            <form v-else-if="tab === 'components'" class="mt-5" @submit.prevent="saveComps">
                <p v-if="!canEditComponents" class="mb-3 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-800">أُدخلت درجات في هذه الشعبة، فلا يمكن تغيير المكونات.</p>
                <div class="space-y-2">
                    <div class="grid grid-cols-[1fr_1.5fr_.7fr_.7fr_.7fr_auto] gap-2 text-xs font-bold text-slate-500"><span>الرمز</span><span>الاسم</span><span>الوزن %</span><span>من</span><span>الدور</span><span></span></div>
                    <div v-for="(c, i) in comps.components" :key="i" class="grid grid-cols-[1fr_1.5fr_.7fr_.7fr_.7fr_auto] gap-2">
                        <input v-model="c.code" dir="ltr" class="input font-mono text-xs" :disabled="!canEditComponents" />
                        <input v-model="c.name_ar" class="input" :disabled="!canEditComponents" />
                        <input v-model.number="c.weight" type="number" step="0.5" class="input" :disabled="!canEditComponents" />
                        <input v-model.number="c.max_mark" type="number" step="0.5" class="input" :disabled="!canEditComponents" />
                        <select v-model.number="c.exam_round" class="input" :disabled="!canEditComponents"><option :value="1">1</option><option :value="2">2</option></select>
                        <button type="button" class="rounded-lg p-2 text-slate-400 hover:text-rose-600" :disabled="!canEditComponents" @click="comps.components.splice(i, 1)"><TrashIcon class="h-4 w-4" /></button>
                    </div>
                </div>
                <button v-if="canEditComponents" type="button" class="btn-ghost btn-sm mt-2" @click="comps.components.push({ code: '', name_ar: '', weight: 0, max_mark: 100, exam_round: 1 })"><PlusIcon class="h-4 w-4" /> مكوّن</button>
                <div class="mt-3 flex flex-wrap gap-2 text-sm">
                    <span v-for="r in rounds()" :key="r" class="rounded-lg px-3 py-1 font-bold" :class="Math.abs(sum(r) - 100) < 0.01 ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'">مجموع الدور {{ r }}: {{ sum(r) }}%</span>
                </div>
                <p class="mt-2 text-xs text-slate-500">مكوّن الدور الثاني يحل محل مكوّن الدور الأول الذي يحمل الرمز نفسه مع ‎_r2 (مثل final_r2 بدل final).</p>
                <div class="mt-5 flex justify-end gap-2"><button type="button" class="btn-secondary" @click="emit('close')">إغلاق</button><button class="btn-primary" :disabled="comps.processing || !canEditComponents">حفظ المكونات</button></div>
            </form>

            <div v-else class="mt-5">
                <div class="relative"><MagnifyingGlassIcon class="pointer-events-none absolute start-3 top-2.5 h-5 w-5 text-slate-400" /><input v-model="q" class="input ps-10" placeholder="اسم الطالب أو رقمه الجامعي" autofocus /></div>
                <label class="mt-3 flex items-center gap-2 text-sm font-semibold"><input v-model="carried" type="checkbox" class="rounded border-slate-300 text-brand-600" /> مادة محملة (من مرحلة سابقة)</label>
                <ul class="mt-3 divide-y divide-slate-100 rounded-xl border border-slate-100">
                    <li v-for="s in found" :key="s.id" class="flex items-center justify-between px-4 py-2.5 text-sm">
                        <div><p class="font-bold">{{ s.name }}</p><p class="text-xs text-slate-500">{{ s.number }} · {{ s.program }} · المرحلة {{ s.stage }}</p></div>
                        <button class="btn-primary btn-sm" @click="add(s)"><PlusIcon class="h-4 w-4" /> تسجيل</button>
                    </li>
                    <li v-if="q.length >= 2 && !found.length" class="px-4 py-6 text-center text-sm text-slate-500">لا يوجد طلبة مطابقون غير مسجلين.</li>
                </ul>
                <p class="mt-2 text-xs text-slate-500">يُفحص قبل التسجيل: حالة الطالب، السعة، والمتطلبات السابقة.</p>
            </div>
        </div>
    </Modal>
</template>
