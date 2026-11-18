<script setup>
import { computed, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import InputError from '@/Components/InputError.vue';
import { systems } from '@/lib/labels';
import { ExclamationTriangleIcon, IdentificationIcon, AcademicCapIcon, PhoneIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ programs: Array, years: Array, orders: Array });
const form = useForm({
    name_ar: '', name_en: '', mother_name_ar: '', national_id: '', birth_date: '', gender: '',
    phone: '', email: '', admission_channel: 'central',
    program_id: '', year_id: props.years.find((y) => y.is_current)?.id ?? props.years[0]?.id ?? '', stage: 1, order_id: '',
    allow_duplicate: false,
});
const program = computed(() => props.programs.find((p) => p.id == form.program_id));
const colleges = computed(() => [...new Set(props.programs.map((p) => p.college))]);
const dups = ref([]);
let t;
watch(() => [form.national_id, form.name_ar, form.birth_date, form.mother_name_ar], () => {
    clearTimeout(t);
    t = setTimeout(async () => {
        if (!form.national_id && !(form.name_ar && form.birth_date)) return (dups.value = []);
        const { data } = await axios.get(route('students.duplicates'), { params: { national_id: form.national_id, name_ar: form.name_ar, birth_date: form.birth_date, mother_name_ar: form.mother_name_ar } });
        dups.value = data;
        if (!data.length) form.allow_duplicate = false;
    }, 400);
});
const channels = { central: 'القبول المركزي', parallel: 'الدراسة الموازية', evening: 'الدراسة المسائية', transfer: 'انتقال', other: 'أخرى' };
const submit = () => form.post(route('students.store'));
</script>

<template>
    <AppLayout title="قبول طالب">
        <PageHeader title="قبول طالب جديد" subtitle="يُولَّد الرقم الجامعي تلقائياً عند الحفظ، ويُفحص التكرار قبل الإنشاء."
            :crumbs="[{ label: 'الطلبة', href: route('students.index') }, { label: 'قبول طالب' }]" />

        <form class="grid gap-6 lg:grid-cols-3" @submit.prevent="submit">
            <div class="space-y-6 lg:col-span-2">
                <section class="card">
                    <div class="card-header"><h2 class="card-title flex items-center gap-2"><IdentificationIcon class="h-5 w-5 text-brand-600" /> البيانات الشخصية</h2></div>
                    <div class="grid gap-4 p-5 sm:grid-cols-2">
                        <div class="sm:col-span-2"><label class="label">الاسم الرباعي <span class="text-rose-500">*</span></label><input v-model="form.name_ar" class="input" required autofocus /><InputError :message="form.errors.name_ar" class="mt-1" /></div>
                        <div><label class="label">اسم الأم الثلاثي <span class="text-rose-500">*</span></label><input v-model="form.mother_name_ar" class="input" required /><InputError :message="form.errors.mother_name_ar" class="mt-1" /></div>
                        <div><label class="label">الاسم بالإنكليزية</label><input v-model="form.name_en" dir="ltr" class="input" /></div>
                        <div><label class="label">الرقم الوطني (البطاقة الموحدة)</label><input v-model="form.national_id" dir="ltr" inputmode="numeric" class="input font-mono" /><InputError :message="form.errors.national_id" class="mt-1" /></div>
                        <div><label class="label">تاريخ الميلاد <span class="text-rose-500">*</span></label><input v-model="form.birth_date" type="date" class="input" required /><InputError :message="form.errors.birth_date" class="mt-1" /></div>
                        <div class="sm:col-span-2">
                            <label class="label">الجنس <span class="text-rose-500">*</span></label>
                            <div class="grid grid-cols-2 gap-2 sm:w-72">
                                <label v-for="(l, k) in { m: 'ذكر', f: 'أنثى' }" :key="k" class="flex cursor-pointer items-center justify-center gap-2 rounded-xl border px-3 py-2 text-sm font-semibold transition"
                                    :class="form.gender === k ? 'border-brand-500 bg-brand-50 text-brand-800' : 'border-slate-200 hover:bg-slate-50'">
                                    <input v-model="form.gender" type="radio" :value="k" class="sr-only" />{{ l }}
                                </label>
                            </div>
                            <InputError :message="form.errors.gender" class="mt-1" />
                        </div>
                    </div>
                </section>

                <div v-if="dups.length" class="rounded-2xl border border-amber-300 bg-amber-50 p-5">
                    <p class="flex items-center gap-2 font-bold text-amber-900"><ExclamationTriangleIcon class="h-5 w-5" /> يوجد طالب مسجل بالبيانات نفسها</p>
                    <p class="mt-1 text-sm text-amber-800">الشخص الواحد يُسجَّل مرة واحدة. إن كان هو نفسه فانتقل لملفه بدلاً من إنشاء سجل جديد.</p>
                    <ul class="mt-3 space-y-2">
                        <li v-for="d in dups" :key="d.id" class="flex items-center justify-between rounded-xl bg-white px-4 py-2.5 text-sm shadow-sm">
                            <div><p class="font-bold">{{ d.name }}</p><p class="text-xs text-slate-500">{{ d.number }} · الأم: {{ d.mother ?? '—' }} · {{ d.birth_date ?? '—' }} · {{ d.status }}</p></div>
                            <Link :href="route('students.show', d.id)" class="btn-secondary btn-sm">فتح الملف</Link>
                        </li>
                    </ul>
                    <label class="mt-3 flex items-center gap-2 text-sm font-semibold text-amber-900"><input v-model="form.allow_duplicate" type="checkbox" class="rounded border-amber-400 text-amber-600" /> تأكدت أنه شخص مختلف، أكمل القبول</label>
                </div>

                <section class="card">
                    <div class="card-header"><h2 class="card-title flex items-center gap-2"><PhoneIcon class="h-5 w-5 text-brand-600" /> التواصل</h2></div>
                    <div class="grid gap-4 p-5 sm:grid-cols-2">
                        <div><label class="label">الهاتف</label><input v-model="form.phone" dir="ltr" class="input" placeholder="07xx xxx xxxx" /></div>
                        <div><label class="label">البريد الإلكتروني</label><input v-model="form.email" type="email" dir="ltr" class="input" /><InputError :message="form.errors.email" class="mt-1" /></div>
                    </div>
                </section>
            </div>

            <div class="space-y-6">
                <section class="card lg:sticky lg:top-6">
                    <div class="card-header"><h2 class="card-title flex items-center gap-2"><AcademicCapIcon class="h-5 w-5 text-brand-600" /> القبول</h2></div>
                    <div class="space-y-4 p-5">
                        <div>
                            <label class="label">البرنامج <span class="text-rose-500">*</span></label>
                            <select v-model="form.program_id" class="input" required>
                                <option value="" disabled>اختر البرنامج</option>
                                <optgroup v-for="c in colleges" :key="c" :label="c">
                                    <option v-for="p in programs.filter((x) => x.college === c)" :key="p.id" :value="p.id">{{ p.name }}</option>
                                </optgroup>
                            </select>
                            <p v-if="program" class="mt-1.5 text-xs text-slate-500">{{ program.department }} · نظام {{ systems[program.system] }} · {{ program.years }} سنوات</p>
                            <InputError :message="form.errors.program_id" class="mt-1" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div><label class="label">سنة القبول</label><select v-model="form.year_id" class="input"><option v-for="y in years" :key="y.id" :value="y.id">{{ y.name }}</option></select></div>
                            <div><label class="label">المرحلة</label><select v-model="form.stage" class="input"><option v-for="s in program?.years ?? 6" :key="s" :value="s">{{ s }}</option></select></div>
                        </div>
                        <div><label class="label">قناة القبول</label><select v-model="form.admission_channel" class="input"><option v-for="(l, k) in channels" :key="k" :value="k">{{ l }}</option></select></div>
                        <div>
                            <label class="label">أمر القبول الإداري</label>
                            <select v-model="form.order_id" class="input"><option value="">— بدون (يُضاف لاحقاً) —</option><option v-for="o in orders" :key="o.id" :value="o.id">{{ o.number }} — {{ o.title }}</option></select>
                        </div>
                        <button type="submit" class="btn-primary w-full py-3" :disabled="form.processing || (dups.length && !form.allow_duplicate)">حفظ وقبول الطالب</button>
                        <Link :href="route('students.index')" class="btn-ghost w-full">إلغاء</Link>
                    </div>
                </section>
            </div>
        </form>
    </AppLayout>
</template>
