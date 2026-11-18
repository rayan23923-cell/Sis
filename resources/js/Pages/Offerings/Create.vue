<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import InputError from '@/Components/InputError.vue';
import { systems } from '@/lib/labels';
import { RectangleStackIcon, DocumentPlusIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ terms: Array, programs: Array, courses: Array, teachers: Array });
const form = useForm({
    mode: 'stage', academic_term_id: props.terms.find((t) => t.current)?.id ?? '', program_id: '', stage: 1,
    course_id: '', section: 'A', shift: 'morning', teacher_id: '', capacity: 60, enroll: true,
});
const program = computed(() => props.programs.find((p) => p.id == form.program_id));
const courses = computed(() => props.courses.filter((c) => !program.value || c.department_id === program.value.department_id));
const teachers = computed(() => props.teachers.filter((t) => !program.value || !t.department_ids.length || t.department_ids.includes(program.value.department_id)));
const submit = () => form.post(route('offerings.store'));
</script>

<template>
    <AppLayout title="فتح شعب">
        <PageHeader title="فتح شعب دراسية" subtitle="افتح كل مواد مرحلة من المنهج المعتمد دفعة واحدة، أو شعبة لمادة واحدة."
            :crumbs="[{ label: 'المواد والدرجات', href: route('offerings.index') }, { label: 'فتح شعب' }]" />
        <form class="grid gap-6 lg:grid-cols-3" @submit.prevent="submit">
            <div class="card space-y-5 p-6 lg:col-span-2">
                <div class="grid grid-cols-2 gap-3">
                    <button v-for="[k, l, d, icon] in [['stage', 'مرحلة كاملة', 'كل مواد المرحلة لهذا الفصل من المنهج المعتمد', RectangleStackIcon], ['single', 'مادة واحدة', 'شعبة إضافية أو مادة خارج المنهج', DocumentPlusIcon]]" :key="k" type="button"
                        class="flex items-start gap-3 rounded-2xl border p-4 text-start transition" :class="form.mode === k ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-500' : 'border-slate-200 hover:bg-slate-50'" @click="form.mode = k">
                        <component :is="icon" class="h-6 w-6 shrink-0 text-brand-600" /><div><p class="font-bold">{{ l }}</p><p class="text-xs text-slate-500">{{ d }}</p></div>
                    </button>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="label">الفصل</label><select v-model="form.academic_term_id" class="input" required><option v-for="t in terms" :key="t.id" :value="t.id">{{ t.name }}</option></select><InputError :message="form.errors.academic_term_id" class="mt-1" /></div>
                    <div><label class="label">البرنامج</label><select v-model="form.program_id" class="input" required><option value="" disabled>اختر</option><option v-for="p in programs" :key="p.id" :value="p.id">{{ p.name }}</option></select>
                        <p v-if="program" class="mt-1 text-xs text-slate-500">{{ program.department }} · {{ systems[program.system] }}</p></div>
                    <div><label class="label">المرحلة</label><select v-model="form.stage" class="input"><option v-for="n in program?.years ?? 6" :key="n" :value="n">{{ n }}</option></select></div>
                    <div><label class="label">الشعبة</label><input v-model="form.section" class="input" required /></div>
                    <div><label class="label">الدراسة</label><select v-model="form.shift" class="input"><option value="morning">صباحية</option><option value="evening">مسائية</option></select></div>
                    <template v-if="form.mode === 'single'">
                        <div><label class="label">المادة</label><select v-model="form.course_id" class="input" required><option value="" disabled>اختر</option><option v-for="c in courses" :key="c.id" :value="c.id">{{ c.code }} — {{ c.name_ar }}</option></select><InputError :message="form.errors.course_id" class="mt-1" /></div>
                        <div><label class="label">التدريسي</label><select v-model="form.teacher_id" class="input"><option value="">— لاحقاً —</option><option v-for="t in teachers" :key="t.id" :value="t.id">{{ t.name }}</option></select></div>
                    </template>
                    <div><label class="label">السعة</label><input v-model="form.capacity" type="number" min="1" class="input" /></div>
                </div>
                <label class="flex items-center gap-2 text-sm font-semibold"><input v-model="form.enroll" type="checkbox" class="rounded border-slate-300 text-brand-600" /> سجّل طلبة المرحلة المستمرين تلقائياً</label>
            </div>
            <div class="card h-fit space-y-3 p-6">
                <p class="text-sm text-slate-600">تُنشأ لكل شعبة مكونات التقييم الافتراضية (سعي 40، نهائي 60، دور ثانٍ 60)، ويمكن تعديلها من «إدارة الشعبة» قبل إدخال الدرجات.</p>
                <p v-if="form.mode === 'stage'" class="text-sm text-slate-600">يُعيَّن التدريسي لكل شعبة بعد فتحها من صفحة الشعبة.</p>
                <button class="btn-primary w-full py-3" :disabled="form.processing">فتح الشعب</button>
                <Link :href="route('offerings.index')" class="btn-ghost w-full">إلغاء</Link>
            </div>
        </form>
    </AppLayout>
</template>
