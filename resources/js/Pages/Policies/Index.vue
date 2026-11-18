<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Badge from '@/Components/Badge.vue';
import Modal from '@/Components/Modal.vue';
import InputError from '@/Components/InputError.vue';
import { policyStatus } from '@/lib/labels';
import { ScaleIcon, ExclamationTriangleIcon, ShieldCheckIcon, DocumentDuplicateIcon, ChevronDownIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ policies: Array, types: Object, systems: Object, canManage: Boolean });
const system = ref(Object.keys(props.systems).find((s) => props.policies.some((p) => p.system === s)) ?? 'bologna');
const groups = computed(() => {
    const list = props.policies.filter((p) => p.system === system.value);
    const byType = {};
    for (const p of list) (byType[p.type] ??= []).push(p);
    return Object.entries(byType).map(([type, versions]) => ({
        type, label: props.types[type] ?? type,
        live: versions.find((v) => v.status === 'approved' && !v.to) ?? versions.find((v) => v.status === 'approved'),
        versions,
    }));
});
const expanded = ref(null);

const describe = (p) => {
    const x = p.parameters;
    switch (p.type) {
        case 'rounding': return `التقريب: ${{ half_up: 'لأقرب عدد صحيح (0.5 فأكثر للأعلى)', ceil: 'للأعلى دائماً', floor: 'للأسفل دائماً', none: 'بدون تقريب' }[x.mode] ?? x.mode}${x.precision ? `، ${x.precision} مراتب عشرية` : ''}`;
        case 'total': return `${x.absent_counts_as_zero ? 'الغياب يُحتسب صفراً' : 'الغياب بلا قاعدة'} · ${x.require_all_marks ? 'يجب اكتمال جميع الدرجات' : 'يُسمح بدرجات ناقصة'}`;
        case 'rounds': return `عدد الأدوار: ${x.max_rounds} · ${x.resit_cap ? `سقف درجة الدور الثاني ${x.resit_cap}` : 'بدون سقف لدرجة الدور الثاني'}`;
        case 'gpa': return `المعدل على أساس ${x.basis === 'marks' ? 'الدرجات' : 'النقاط'} موزوناً بـ ${x.weight === 'ects' ? 'ECTS' : 'الوحدات'} · ${x.include_failed ? 'تدخل المواد الراسبة' : 'تُستبعد المواد الراسبة'}`;
        case 'progression': return `الحد الأعلى للمواد المحملة: ${x.max_carried} · ${x.carry_in_final_stage ? 'يُسمح بالتحميل في المرحلة المنتهية' : 'لا تحميل في المرحلة المنتهية'}`;
        default: return null;
    }
};

const showNew = ref(false);
const base = ref(null);
const form = useForm({ parameters: '', effective_from: new Date().toISOString().slice(0, 10), source_reference: '', approving_authority: '', is_official: false, changelog: '' });
const newVersion = (p) => { base.value = p; form.reset(); form.clearErrors(); form.parameters = JSON.stringify(p.parameters, null, 2); showNew.value = true; };
const save = () => form.post(route('policies.version', base.value.id), { preserveScroll: true, onSuccess: () => (showNew.value = false) });
const approve = (p) => confirm(`اعتماد الإصدار ${p.version}؟ سيُطبق على كل احتساب من ${p.from}.`) && router.post(route('policies.approve', p.id), {}, { preserveScroll: true });
const discard = (p) => confirm('حذف هذه المسودة؟') && router.delete(route('policies.destroy', p.id), { preserveScroll: true });
</script>

<template>
    <AppLayout title="السياسات الأكاديمية">
        <PageHeader title="السياسات الأكاديمية" subtitle="القواعد التي يحتسب بها النظام الدرجات والتقديرات والمعدلات. كل سياسة مؤرخة ومرقمة بإصدار، ولا يُعدَّل الإصدار المعتمد." />

        <div class="mb-6 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <ExclamationTriangleIcon class="mt-0.5 h-5 w-5 shrink-0" />
            <div><p class="font-bold">السياسات المؤقتة لا تُعد قواعد رسمية</p><p class="mt-0.5">وُضعت لتشغيل النظام فقط. عند استلام الكتاب الرسمي أنشئ إصداراً جديداً بالنص المعتمد ورقم الكتاب وتاريخه ثم اعتمده؛ النتائج المقفلة سابقاً تبقى محفوظة بالسياسة التي احتُسبت بها.</p></div>
        </div>

        <div class="mb-6 flex w-fit rounded-xl bg-white p-1 shadow-sm ring-1 ring-slate-200">
            <button v-for="(l, k) in systems" :key="k" class="rounded-lg px-4 py-2 text-sm font-bold transition" :class="system === k ? 'bg-brand-700 text-white' : 'text-slate-600 hover:bg-slate-50'" @click="system = k" v-show="policies.some((p) => p.system === k)">{{ l }}</button>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section v-for="g in groups" :key="g.type" class="card flex flex-col">
                <div class="card-header">
                    <div class="flex items-center gap-3">
                        <div class="grid h-10 w-10 place-items-center rounded-xl bg-brand-50 text-brand-700"><ScaleIcon class="h-5 w-5" /></div>
                        <div><h2 class="card-title">{{ g.label }}</h2><p class="text-xs text-slate-500">{{ g.live?.name }}</p></div>
                    </div>
                    <div v-if="g.live" class="flex items-center gap-1.5">
                        <Badge tone="slate">إصدار {{ g.live.version }}</Badge>
                        <Badge v-if="g.live.official" tone="emerald"><ShieldCheckIcon class="h-3.5 w-3.5" /> رسمية</Badge>
                        <Badge v-else tone="amber">غير رسمية</Badge>
                    </div>
                </div>
                <div v-if="g.live" class="flex-1 p-5">
                    <table v-if="g.type === 'grade_scale'" class="w-full text-sm">
                        <thead><tr class="text-xs text-slate-500"><th class="pb-2 text-start">التقدير</th><th class="pb-2 text-start">الرمز</th><th class="pb-2 text-start">المدى</th><th class="pb-2 text-start">النتيجة</th></tr></thead>
                        <tbody>
                            <tr v-for="b in g.live.parameters.bands" :key="b.code" class="border-t border-slate-100">
                                <td class="py-1.5 font-semibold">{{ b.name_ar }}</td><td class="font-mono text-xs">{{ b.code }}</td>
                                <td><span class="ltr tabular-nums">{{ b.min }}–{{ b.max }}</span></td>
                                <td><Badge :tone="b.outcome === 'pass' ? 'emerald' : b.outcome === 'resit' ? 'amber' : 'rose'">{{ { pass: 'ناجح', resit: 'دور ثانٍ', fail: 'راسب' }[b.outcome] }}</Badge></td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="text-sm leading-relaxed text-slate-700">{{ describe(g.live) }}</p>
                    <dl class="mt-4 grid grid-cols-2 gap-2 text-xs">
                        <div><dt class="text-slate-400">نافذة من</dt><dd class="font-semibold tabular-nums">{{ g.live.from }}<span v-if="g.live.to"> إلى {{ g.live.to }}</span></dd></div>
                        <div><dt class="text-slate-400">المرجع الرسمي</dt><dd class="font-semibold">{{ g.live.reference ?? 'لم يُحدد بعد' }}</dd></div>
                    </dl>
                </div>
                <div class="flex items-center justify-between border-t border-slate-100 px-5 py-3">
                    <button class="flex items-center gap-1 text-xs font-bold text-slate-500 hover:text-slate-800" @click="expanded = expanded === g.type ? null : g.type">
                        سجل الإصدارات ({{ g.versions.length }}) <ChevronDownIcon class="h-4 w-4 transition" :class="expanded === g.type && 'rotate-180'" />
                    </button>
                    <button v-if="canManage && g.live" class="btn-secondary btn-sm" @click="newVersion(g.live)"><DocumentDuplicateIcon class="h-4 w-4" /> إصدار جديد</button>
                </div>
                <ul v-if="expanded === g.type" class="divide-y divide-slate-100 border-t border-slate-100 bg-slate-50/50 text-sm">
                    <li v-for="v in g.versions" :key="v.id" class="px-5 py-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-bold">إصدار {{ v.version }}</span><Badge :map="policyStatus" :value="v.status" /><Badge v-if="!v.official" tone="amber">غير رسمية</Badge>
                            <span class="text-xs tabular-nums text-slate-500">{{ v.from }}{{ v.to ? ' ← ' + v.to : '' }}</span>
                            <span v-if="canManage && v.status === 'draft'" class="ms-auto flex gap-1">
                                <button class="btn-success btn-sm" @click="approve(v)">اعتماد</button><button class="btn-ghost btn-sm" @click="discard(v)">حذف</button>
                            </span>
                        </div>
                        <p v-if="v.changelog" class="mt-1 text-xs text-slate-500">{{ v.changelog }}</p>
                        <p v-if="v.by" class="mt-0.5 text-[11px] text-slate-400">{{ v.by }}</p>
                    </li>
                </ul>
            </section>
        </div>

        <Modal :show="showNew" max-width="2xl" @close="showNew = false">
            <form v-if="base" class="p-6" @submit.prevent="save">
                <h3 class="text-lg font-extrabold">إصدار جديد: {{ base.name }}</h3>
                <p class="mt-1 text-sm text-slate-500">يُحفظ كمسودة ولا يُطبق قبل الاعتماد. الإصدارات السابقة تبقى كما هي.</p>
                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2"><label class="label">المعاملات (JSON)</label><textarea v-model="form.parameters" dir="ltr" rows="10" class="input font-mono text-xs" /><InputError :message="form.errors.parameters" class="mt-1" /></div>
                    <div><label class="label">تاريخ النفاذ</label><input v-model="form.effective_from" type="date" class="input" required /></div>
                    <div><label class="label">رقم الكتاب الرسمي وتاريخه</label><input v-model="form.source_reference" class="input" /><InputError :message="form.errors.source_reference" class="mt-1" /></div>
                    <div><label class="label">الجهة المصادقة</label><input v-model="form.approving_authority" class="input" placeholder="مجلس الجامعة / الوزارة" /></div>
                    <label class="flex items-center gap-2 self-end pb-2 text-sm font-semibold"><input v-model="form.is_official" type="checkbox" class="rounded border-slate-300 text-brand-600" /> نص رسمي معتمد</label>
                    <div class="sm:col-span-2"><label class="label">ما الذي تغير ولماذا</label><textarea v-model="form.changelog" rows="2" class="input" required /><InputError :message="form.errors.changelog" class="mt-1" /></div>
                </div>
                <div class="mt-6 flex justify-end gap-2"><button type="button" class="btn-secondary" @click="showNew = false">إلغاء</button><button class="btn-primary" :disabled="form.processing">حفظ كمسودة</button></div>
            </form>
        </Modal>
    </AppLayout>
</template>
