<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Badge from '@/Components/Badge.vue';
import InputError from '@/Components/InputError.vue';
import OrderModal from '@/Components/OrderModal.vue';
import { ArrowDownTrayIcon, ArrowUpTrayIcon, CheckCircleIcon, DocumentArrowUpIcon, PlusIcon, XCircleIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ programs: Array, years: Array, orders: Array, orderTypes: Object, token: String, preview: Object });

const form = useForm({ file: null, program_id: '', year_id: props.years.find((y) => y.is_current)?.id ?? props.years[0]?.id ?? '', stage: 1 });
const program = computed(() => props.programs.find((p) => p.id == form.program_id));
const fileName = ref('');
const pick = (e) => { form.file = e.target.files[0] ?? null; fileName.value = form.file?.name ?? ''; };
const upload = () => form.post(route('students.import.preview'), { forceFormData: true });

const commit = useForm({ token: props.token, include_duplicates: false, order_id: '' });
const filter = ref('all');
const counts = computed(() => {
    const c = { ok: 0, duplicate: 0, error: 0 };
    (props.preview?.rows ?? []).forEach((r) => c[r.state]++);
    return c;
});
const rows = computed(() => (props.preview?.rows ?? []).filter((r) => filter.value === 'all' || r.state === filter.value));
const toSave = computed(() => counts.value.ok + (commit.include_duplicates ? counts.value.duplicate : 0));
const confirm = () => commit.post(route('students.import.commit'));
const showOrder = ref(false);
const orderSaved = (id) => router.reload({ only: ['orders'], onSuccess: () => { if (id) commit.order_id = id; } });
const tone = { ok: 'emerald', duplicate: 'amber', error: 'rose' };
const stateLabel = { ok: 'جاهز', duplicate: 'مشابه لطالب موجود', error: 'خطأ' };
</script>

<template>
    <AppLayout title="استيراد الطلبة">
        <PageHeader title="استيراد الطلبة من Excel" subtitle="قبول دفعة كاملة من ملف واحد. يُفحص كل سطر وتُعرض النتيجة قبل الحفظ."
            :crumbs="[{ label: 'الطلبة', href: route('students.index') }, { label: 'استيراد' }]">
            <a :href="route('students.import.template')" class="btn-secondary"><ArrowDownTrayIcon class="h-4 w-4" /> تنزيل القالب</a>
        </PageHeader>

        <!-- step 1: upload -->
        <form v-if="!preview" class="card max-w-3xl p-6" @submit.prevent="upload">
            <ol class="mb-6 space-y-1 text-sm text-slate-600">
                <li>١. نزّل القالب واملأ سطراً لكل طالب (الاسم الرباعي، اسم الأم، تاريخ الميلاد، الجنس إلزامية).</li>
                <li>٢. اختر البرنامج والعام والمرحلة التي يُقبل فيها الطلبة.</li>
                <li>٣. ارفع الملف وراجع النتيجة، ثم أكّد الحفظ.</li>
            </ol>
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-3">
                    <label class="label">البرنامج <span class="text-rose-500">*</span></label>
                    <select v-model="form.program_id" class="input" required>
                        <option value="" disabled>اختر البرنامج</option>
                        <option v-for="p in programs" :key="p.id" :value="p.id">{{ p.name }} — {{ p.department }}</option>
                    </select>
                    <InputError :message="form.errors.program_id" class="mt-1" />
                </div>
                <div class="sm:col-span-2"><label class="label">العام الدراسي</label><select v-model="form.year_id" class="input"><option v-for="y in years" :key="y.id" :value="y.id">{{ y.name }}</option></select></div>
                <div><label class="label">المرحلة</label><select v-model="form.stage" class="input"><option v-for="n in program?.years ?? 6" :key="n" :value="n">{{ n }}</option></select></div>
                <label class="sm:col-span-3 flex cursor-pointer flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-slate-300 bg-slate-50 px-6 py-10 text-center transition hover:border-brand-400 hover:bg-brand-50/40">
                    <DocumentArrowUpIcon class="h-10 w-10 text-brand-600" />
                    <span class="text-sm font-bold text-slate-700">{{ fileName || 'اختر ملف Excel أو CSV' }}</span>
                    <span class="text-xs text-slate-400">حتى 5 ميغابايت و2000 طالب</span>
                    <input type="file" class="hidden" accept=".xlsx,.xls,.csv" @change="pick" />
                </label>
                <InputError :message="form.errors.file" class="sm:col-span-3" />
            </div>
            <div class="mt-6 flex justify-end">
                <button class="btn-primary" :disabled="form.processing || !form.file || !form.program_id"><ArrowUpTrayIcon class="h-4 w-4" /> {{ form.processing ? 'جارٍ الفحص…' : 'فحص الملف' }}</button>
            </div>
        </form>

        <!-- step 2: preview -->
        <template v-else>
            <div class="mb-6 grid gap-4 sm:grid-cols-4">
                <button class="card p-4 text-right transition" :class="filter === 'all' && 'ring-2 ring-brand-500'" @click="filter = 'all'"><p class="text-sm text-slate-500">مجموع السطور</p><p class="text-2xl font-extrabold tabular-nums">{{ preview.rows.length }}</p></button>
                <button class="card p-4 text-right transition" :class="filter === 'ok' && 'ring-2 ring-emerald-500'" @click="filter = 'ok'"><p class="text-sm text-slate-500">جاهز للقبول</p><p class="text-2xl font-extrabold tabular-nums text-emerald-600">{{ counts.ok }}</p></button>
                <button class="card p-4 text-right transition" :class="filter === 'duplicate' && 'ring-2 ring-amber-500'" @click="filter = 'duplicate'"><p class="text-sm text-slate-500">مشابه لطالب موجود</p><p class="text-2xl font-extrabold tabular-nums text-amber-600">{{ counts.duplicate }}</p></button>
                <button class="card p-4 text-right transition" :class="filter === 'error' && 'ring-2 ring-rose-500'" @click="filter = 'error'"><p class="text-sm text-slate-500">أخطاء (لن تُحفظ)</p><p class="text-2xl font-extrabold tabular-nums text-rose-600">{{ counts.error }}</p></button>
            </div>

            <div class="card">
                <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4 text-sm">
                    <span class="font-bold text-slate-800">{{ preview.program }}</span>
                    <span class="text-slate-500">العام <span class="ltr">{{ preview.year }}</span> · المرحلة {{ preview.stage }}</span>
                    <Link :href="route('students.import')" class="btn-ghost mr-auto">ملف آخر</Link>
                </div>
                <div class="overflow-x-auto">
                    <table class="table-base">
                        <thead><tr><th>السطر</th><th>الاسم الرباعي</th><th>اسم الأم</th><th>الميلاد</th><th>الجنس</th><th>الرقم الوطني</th><th>الحالة</th></tr></thead>
                        <tbody>
                            <tr v-for="r in rows" :key="r.line">
                                <td class="tabular-nums text-slate-400">{{ r.line }}</td>
                                <td class="font-semibold">{{ r.name_ar }}</td>
                                <td>{{ r.mother_name_ar }}</td>
                                <td class="ltr tabular-nums">{{ r.birth_date }}</td>
                                <td>{{ { m: 'ذكر', f: 'أنثى' }[r.gender] ?? r.gender }}</td>
                                <td class="ltr font-mono text-xs">{{ r.national_id }}</td>
                                <td>
                                    <Badge :tone="tone[r.state]">{{ stateLabel[r.state] }}</Badge>
                                    <p v-for="(e, i) in r.errors" :key="i" class="mt-1 text-xs text-rose-600">{{ e }}</p>
                                    <p v-for="(m, i) in r.matches" :key="'m' + i" class="mt-1 text-xs text-amber-700">{{ m }}</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <form class="card mt-6 p-5" @submit.prevent="confirm">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label">أمر القبول (اختياري)</label>
                        <div class="flex gap-2">
                            <select v-model="commit.order_id" class="input"><option value="">بدون أمر</option><option v-for="o in orders" :key="o.id" :value="o.id">{{ o.label }}</option></select>
                            <button type="button" class="btn-secondary shrink-0" @click="showOrder = true"><PlusIcon class="h-4 w-4" /></button>
                        </div>
                    </div>
                    <label v-if="counts.duplicate" class="flex items-center gap-2 self-end rounded-xl bg-amber-50 p-3 text-sm font-semibold text-amber-900">
                        <input v-model="commit.include_duplicates" type="checkbox" class="rounded border-amber-400 text-amber-600" />
                        راجعت المشابهين وهم أشخاص مختلفون، اقبلهم أيضاً ({{ counts.duplicate }})
                    </label>
                </div>
                <div class="mt-5 flex flex-wrap items-center justify-end gap-3">
                    <p v-if="counts.error" class="flex items-center gap-1 text-sm text-rose-600"><XCircleIcon class="h-4 w-4" /> صحّح السطور الخاطئة في الملف وارفعه مجدداً إن أردت قبولها.</p>
                    <button class="btn-primary" :disabled="commit.processing || !toSave"><CheckCircleIcon class="h-4 w-4" /> قبول {{ toSave }} طالباً</button>
                </div>
            </form>
            <OrderModal :show="showOrder" :types="orderTypes" type="admission" @close="showOrder = false" @saved="orderSaved" />
        </template>
    </AppLayout>
</template>
