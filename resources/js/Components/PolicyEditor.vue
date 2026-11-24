<script setup>
// A form for a policy's parameters, one per policy type, so a registrar or teacher never edits JSON.
// v-model is the parameters object; anything the form does not show (e.g. excluded_colleges) is kept as is.
import { computed, ref, watch } from 'vue';
import { ArrowUpIcon, ArrowDownIcon, PlusIcon, TrashIcon, CheckCircleIcon, ExclamationTriangleIcon, CodeBracketIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ type: String, modelValue: Object });
const emit = defineEmits(['update:modelValue', 'invalid']);

const p = ref(JSON.parse(JSON.stringify(props.modelValue ?? {})));
watch(p, (v) => emit('update:modelValue', JSON.parse(JSON.stringify(v))), { deep: true });

// Missing nested blocks get their defaults so every field has something to bind to.
if (props.type === 'decision') {
    p.value.amounts ??= { 1: 0, 2: 0 };
    p.value.modes ??= { 1: 'benefit', 2: 'benefit' };
    p.value.priorities ??= ['reduce_failures', 'more_units', 'closest_specialty', 'lower_coursework'];
    p.value.evaluative ??= { fail_below: 20, out_of: 60, treatment: 0, treatment_scale: 'weight', final_stage_bonus: 0, excluded_colleges: [] };
}
if (props.type === 'attendance') p.value.warnings ??= [];
if (props.type === 'rounds') p.value.resit_outcomes ??= ['resit'];

const known = ['rounding', 'total', 'rounds', 'gpa', 'progression', 'grade_scale', 'decision', 'attendance'];
const advanced = ref(!known.includes(props.type));
const raw = ref(JSON.stringify(p.value, null, 2));
const rawError = ref('');
watch(advanced, (on) => { if (on) raw.value = JSON.stringify(p.value, null, 2); });
const applyRaw = () => {
    try { p.value = JSON.parse(raw.value); rawError.value = ''; } catch { rawError.value = 'النص غير صحيح، تأكد من الأقواس والفواصل.'; }
};

// Number input that may be left empty (null = "no limit").
const optional = (obj, key, on, fallback) => { obj[key] = on ? fallback : null; };

// Grade scale: bands must cover 0–100 with no gap or overlap.
const scaleProblem = computed(() => {
    if (props.type !== 'grade_scale') return null;
    const b = [...(p.value.bands ?? [])].map((x) => ({ ...x, min: Number(x.min), max: Number(x.max) })).sort((a, c) => a.min - c.min);
    if (!b.length) return 'أضف تقديراً واحداً على الأقل.';
    if (b.some((x) => !x.name_ar || !x.code)) return 'كل تقدير يحتاج اسماً ورمزاً.';
    if (b.some((x) => x.min > x.max)) return 'في تقدير «من» أكبر من «إلى».';
    if (b[0].min !== 0 || b[b.length - 1].max !== 100) return 'التقديرات لازم تغطي الدرجات من 0 إلى 100.';
    for (let i = 1; i < b.length; i++) if (b[i].min !== b[i - 1].max + 1) return `بين «${b[i - 1].name_ar}» و«${b[i].name_ar}» فجوة أو تداخل.`;
    return null;
});
const attendanceProblem = computed(() => {
    if (props.type !== 'attendance') return null;
    if ((p.value.warnings ?? []).some((w) => w.percent === '' || w.percent == null || !w.name_ar)) return 'كل إنذار يحتاج نسبة واسماً.';
    return null;
});
watch([scaleProblem, attendanceProblem], ([a, b]) => emit('invalid', a ?? b), { immediate: true });
const sortBands = () => p.value.bands.sort((a, c) => Number(c.min) - Number(a.min));
const addBand = () => p.value.bands.push({ code: '', name_ar: '', name_en: '', min: 0, max: 0, points: null, passed: false, outcome: 'fail' });

const priorityLabels = {
    reduce_failures: 'تقليل عدد المواد الراسبة',
    more_units: 'المادة ذات الوحدات الأكثر',
    closest_specialty: 'الأقرب للاختصاص',
    lower_coursework: 'المادة ذات السعي الأقل',
};
const move = (i, d) => { const a = p.value.priorities; [a[i], a[i + d]] = [a[i + d], a[i]]; };

const lateOptions = [
    { v: 0, l: 'لا يُحتسب' }, { v: 0.25, l: 'ربع ساعة غياب' }, { v: 0.5, l: 'نصف ساعة غياب' }, { v: 1, l: 'ساعة غياب كاملة' },
];
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <p class="label mb-0">إعدادات القاعدة</p>
            <button type="button" class="flex items-center gap-1 text-xs font-semibold text-slate-400 hover:text-slate-700" @click="advanced = !advanced">
                <CodeBracketIcon class="h-4 w-4" /> {{ advanced ? 'رجوع للنموذج' : 'وضع متقدم' }}
            </button>
        </div>

        <!-- Advanced: the raw parameters, for types the form does not know or for technical staff -->
        <div v-if="advanced">
            <textarea v-model="raw" dir="ltr" rows="10" class="input font-mono text-xs" @blur="applyRaw" />
            <p v-if="rawError" class="mt-1 text-xs text-red-600">{{ rawError }}</p>
            <p v-else class="mt-1 text-xs text-slate-400">للمختصين فقط. التعديل هنا يُطبق على النموذج عند الخروج من المربع.</p>
        </div>

        <template v-else>
            <!-- Rounding -->
            <template v-if="type === 'rounding'">
                <fieldset>
                    <legend class="mb-2 text-sm font-semibold text-slate-700">طريقة التقريب</legend>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label v-for="o in [{ v: 'half_up', t: 'لأقرب عدد', d: '49.5 تصير 50، و49.4 تصير 49' }, { v: 'ceil', t: 'للأعلى دائماً', d: '49.1 تصير 50' }, { v: 'floor', t: 'للأسفل دائماً', d: '49.9 تصير 49' }, { v: 'none', t: 'بدون تقريب', d: 'تبقى الدرجة كما هي' }]" :key="o.v"
                            class="option" :class="p.mode === o.v && 'option-on'">
                            <input v-model="p.mode" type="radio" :value="o.v" class="sr-only" />
                            <span class="font-bold">{{ o.t }}</span><span class="text-xs text-slate-500">{{ o.d }}</span>
                        </label>
                    </div>
                </fieldset>
                <div v-if="p.mode !== 'none'" class="field-row">
                    <span>عدد المراتب العشرية</span>
                    <select v-model.number="p.precision" class="input w-40"><option :value="0">بدون كسور</option><option :value="1">مرتبة واحدة</option><option :value="2">مرتبتان</option></select>
                </div>
            </template>

            <!-- Total from components -->
            <template v-else-if="type === 'total'">
                <label class="toggle-row"><input v-model="p.absent_counts_as_zero" type="checkbox" class="toggle" /><span><b>الغياب عن الامتحان يُحتسب صفراً</b><small>إذا أُطفئ، النظام يوقف الاحتساب ويطلب قراراً للطالب الغائب.</small></span></label>
                <label class="toggle-row"><input v-model="p.require_all_marks" type="checkbox" class="toggle" /><span><b>لازم تكتمل كل الدرجات قبل الاحتساب</b><small>يمنع احتساب النتيجة إذا بقت درجة فارغة.</small></span></label>
            </template>

            <!-- Exam rounds -->
            <template v-else-if="type === 'rounds'">
                <div class="field-row">
                    <span>عدد الأدوار الامتحانية</span>
                    <div class="segmented"><button v-for="n in [1, 2, 3]" :key="n" type="button" :class="p.max_rounds === n && 'on'" @click="p.max_rounds = n">{{ n === 1 ? 'دور واحد' : n === 2 ? 'دوران' : '3 أدوار' }}</button></div>
                </div>
                <label class="toggle-row"><input :checked="p.resit_cap != null" type="checkbox" class="toggle" @change="optional(p, 'resit_cap', $event.target.checked, 60)" /><span><b>سقف لدرجة الدور الثاني</b><small>أعلى درجة ممكن تنسجل للطالب بالدور الثاني.</small></span></label>
                <div v-if="p.resit_cap != null" class="field-row"><span>السقف</span><input v-model.number="p.resit_cap" type="number" min="0" max="100" class="input w-28" /></div>
            </template>

            <!-- GPA -->
            <template v-else-if="type === 'gpa'">
                <div class="field-row">
                    <span>يُحسب المعدل من</span>
                    <div class="segmented"><button type="button" :class="p.basis === 'marks' && 'on'" @click="p.basis = 'marks'">الدرجات</button><button type="button" :class="p.basis === 'points' && 'on'" @click="p.basis = 'points'">النقاط</button></div>
                </div>
                <div class="field-row">
                    <span>وزن كل مادة حسب</span>
                    <div class="segmented"><button type="button" :class="p.weight === 'ects' && 'on'" @click="p.weight = 'ects'">ECTS</button><button type="button" :class="p.weight === 'credit_hours' && 'on'" @click="p.weight = 'credit_hours'">الوحدات</button></div>
                </div>
                <div class="field-row">
                    <span>المراتب العشرية للمعدل</span>
                    <select v-model.number="p.precision" class="input w-40"><option :value="0">بدون كسور</option><option :value="1">1 (مثل 78.4)</option><option :value="2">2 (مثل 78.43)</option><option :value="3">3 (مثل 78.432)</option></select>
                </div>
                <label class="toggle-row"><input v-model="p.include_failed" type="checkbox" class="toggle" /><span><b>تدخل المواد الراسبة بالمعدل</b><small>إذا أُطفئ، تُستبعد المواد غير الناجحة من الحساب.</small></span></label>
            </template>

            <!-- Progression -->
            <template v-else-if="type === 'progression'">
                <div class="field-row">
                    <span>أكثر عدد مواد يُحمّلها الطالب للسنة الجاية</span>
                    <div class="stepper"><button type="button" :disabled="p.max_carried <= 0" @click="p.max_carried--">−</button><b>{{ p.max_carried }}</b><button type="button" @click="p.max_carried++">+</button></div>
                </div>
                <label class="toggle-row"><input v-model="p.carry_in_final_stage" type="checkbox" class="toggle" /><span><b>يُسمح بالتحميل في المرحلة المنتهية</b></span></label>
            </template>

            <!-- Grade scale -->
            <template v-else-if="type === 'grade_scale'">
                <div class="space-y-2">
                    <div v-for="(b, i) in p.bands" :key="i" class="grid grid-cols-6 items-end gap-2 rounded-xl border border-slate-200 p-2.5 sm:grid-cols-12">
                        <label class="col-span-4 sm:col-span-4"><span class="mini">التقدير</span><input v-model="b.name_ar" class="input" /></label>
                        <label class="col-span-2 sm:col-span-2"><span class="mini">الرمز</span><input v-model="b.code" dir="ltr" class="input font-mono" /></label>
                        <label class="col-span-2 sm:col-span-2"><span class="mini">من</span><input v-model.number="b.min" type="number" min="0" max="100" class="input" @change="sortBands" /></label>
                        <label class="col-span-2 sm:col-span-2"><span class="mini">إلى</span><input v-model.number="b.max" type="number" min="0" max="100" class="input" @change="sortBands" /></label>
                        <label class="col-span-2 sm:col-span-2"><span class="mini">النتيجة</span><select v-model="b.outcome" class="input" :class="{ pass: 'text-emerald-700', resit: 'text-amber-700', fail: 'text-red-700' }[b.outcome]" @change="b.passed = b.outcome === 'pass'"><option value="pass">ناجح</option><option value="resit">دور ثانٍ</option><option value="fail">راسب</option></select></label>
                        <button type="button" class="col-span-6 flex items-center justify-center gap-1 rounded-lg py-1 text-xs text-slate-400 hover:bg-red-50 hover:text-red-600 sm:col-span-12" @click="p.bands.splice(i, 1)"><TrashIcon class="h-4 w-4" /> حذف هذا التقدير</button>
                    </div>
                </div>
                <button type="button" class="btn-ghost btn-sm" @click="addBand"><PlusIcon class="h-4 w-4" /> إضافة تقدير</button>
            </template>

            <!-- Decision (قرار) -->
            <template v-else-if="type === 'decision'">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="box"><p class="box-title">الدور الأول</p>
                        <div class="field-row"><span>درجات القرار</span><input v-model.number="p.amounts[1]" type="number" min="0" class="input w-24" /></div>
                        <div class="field-row"><span>يُمنح</span><select v-model="p.modes[1]" class="input w-44"><option value="benefit">حيثما يستفيد الطالب</option><option value="status">فقط إذا ينجح بكل المواد</option></select></div>
                    </div>
                    <div class="box"><p class="box-title">الدور الثاني</p>
                        <div class="field-row"><span>درجات إضافية</span><input v-model.number="p.amounts[2]" type="number" min="0" class="input w-24" /></div>
                        <div class="field-row"><span>يُمنح</span><select v-model="p.modes[2]" class="input w-44"><option value="benefit">حيثما يستفيد الطالب</option><option value="status">فقط إذا ينجح بكل المواد</option></select></div>
                    </div>
                </div>
                <div class="field-row"><span>درجة النجاح</span><input v-model.number="p.pass_mark" type="number" min="0" max="100" class="input w-24" /></div>
                <div class="field-row"><span>خصم الدور الثاني والمواد المحملة</span><div class="flex items-center gap-2"><input v-model.number="p.resit_deduction" type="number" min="0" class="input w-24" /><small class="text-slate-500">درجة</small></div></div>
                <div class="field-row"><span>درجات قرار إضافية للمرحلة المنتهية</span><input v-model.number="p.final_stage_extra" type="number" min="0" class="input w-24" /></div>
                <label class="toggle-row"><input v-model="p.carried_first" type="checkbox" class="toggle" /><span><b>المواد المحملة تاخذ القرار أولاً</b></span></label>
                <label class="toggle-row"><input v-model="p.no_fail_year" type="checkbox" class="toggle" /><span><b>سنة عدم رسوب</b><small>الطالب المعرّض للفصل يعيد السنة بدلاً من الفصل.</small></span></label>
                <div class="box">
                    <p class="box-title">ترتيب أولوية المواد عند منح القرار</p>
                    <ol class="space-y-1.5">
                        <li v-for="(k, i) in p.priorities" :key="k" class="flex items-center gap-2 rounded-lg bg-white px-3 py-2 ring-1 ring-slate-200">
                            <b class="grid h-6 w-6 place-items-center rounded-full bg-brand-50 text-xs text-brand-700">{{ i + 1 }}</b>
                            <span class="flex-1 text-sm">{{ priorityLabels[k] ?? k }}</span>
                            <button type="button" class="rounded p-1 text-slate-400 hover:bg-slate-100 disabled:opacity-30" :disabled="i === 0" title="للأعلى" @click="move(i, -1)"><ArrowUpIcon class="h-4 w-4" /></button>
                            <button type="button" class="rounded p-1 text-slate-400 hover:bg-slate-100 disabled:opacity-30" :disabled="i === p.priorities.length - 1" title="للأسفل" @click="move(i, 1)"><ArrowDownIcon class="h-4 w-4" /></button>
                        </li>
                    </ol>
                </div>
                <div class="box">
                    <p class="box-title">الامتحان التقويمي (الوزاري)</p>
                    <div class="field-row"><span>يرسب إذا درجة النهائي أقل من</span><div class="flex items-center gap-2"><input v-model.number="p.evaluative.fail_below" type="number" min="0" class="input w-20" /><small class="text-slate-500">من</small><input v-model.number="p.evaluative.out_of" type="number" min="1" class="input w-20" /></div></div>
                    <div class="field-row"><span>درجات المعالجة لكل مادة</span><input v-model.number="p.evaluative.treatment" type="number" min="0" class="input w-20" /></div>
                    <div class="field-row"><span>المعالجة محسوبة</span><select v-model="p.evaluative.treatment_scale" class="input w-44"><option value="weight">من 100 وتتناسب مع وزن النهائي</option><option value="mark">درجات مباشرة كما هي</option></select></div>
                    <div class="field-row"><span>معالجة إضافية للمرحلة المنتهية</span><input v-model.number="p.evaluative.final_stage_bonus" type="number" min="0" class="input w-20" /></div>
                </div>
            </template>

            <!-- Attendance -->
            <template v-else-if="type === 'attendance'">
                <label class="toggle-row"><input v-model="p.count_excused" type="checkbox" class="toggle" /><span><b>الغياب بعذر يُحتسب من نسبة الغياب</b></span></label>
                <div class="field-row"><span>كل تأخير يُحتسب</span><select v-model.number="p.late_as_absence" class="input w-44"><option v-for="o in lateOptions" :key="o.v" :value="o.v">{{ o.l }}</option></select></div>
                <div class="box">
                    <p class="box-title">الإنذارات</p>
                    <div v-for="(w, i) in p.warnings" :key="i" class="flex items-center gap-2 py-1">
                        <span class="text-sm text-slate-500">عند</span><input v-model.number="w.percent" type="number" min="1" max="100" class="input w-20" /><span class="text-sm text-slate-500">%</span>
                        <input v-model="w.name_ar" class="input flex-1" placeholder="اسم الإنذار" />
                        <button type="button" class="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600" title="حذف" @click="p.warnings.splice(i, 1)"><TrashIcon class="h-4 w-4" /></button>
                    </div>
                    <button type="button" class="btn-ghost btn-sm mt-1" @click="p.warnings.push({ percent: null, name_ar: '', name_en: '' })"><PlusIcon class="h-4 w-4" /> إضافة إنذار</button>
                </div>
                <label class="toggle-row"><input :checked="p.limit != null" type="checkbox" class="toggle" @change="optional(p, 'limit', $event.target.checked, 15)" /><span><b>حرمان عند تجاوز نسبة غياب</b><small>إذا أُطفئ، النظام ينذر فقط ولا يحرم أحداً.</small></span></label>
                <div v-if="p.limit != null" class="field-row"><span>نسبة الحرمان</span><div class="flex items-center gap-2"><input v-model.number="p.limit" type="number" min="1" max="100" class="input w-20" /><small class="text-slate-500">%</small></div></div>
            </template>
        </template>

        <p v-if="scaleProblem || attendanceProblem" class="flex items-center gap-1.5 rounded-lg bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800"><ExclamationTriangleIcon class="h-4 w-4 shrink-0" /> {{ scaleProblem ?? attendanceProblem }}</p>
        <p v-else-if="type === 'grade_scale'" class="flex items-center gap-1.5 text-xs font-semibold text-emerald-700"><CheckCircleIcon class="h-4 w-4" /> التقديرات تغطي 0 إلى 100 بدون فجوات.</p>
    </div>
</template>

<style scoped>
.option { @apply flex cursor-pointer flex-col gap-0.5 rounded-xl border border-slate-200 p-3 text-sm transition hover:border-brand-300; }
.option-on { @apply border-brand-500 bg-brand-50 ring-1 ring-brand-500; }
.field-row { @apply flex flex-wrap items-center justify-between gap-2 py-1 text-sm font-semibold text-slate-700; }
.toggle-row { @apply flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 p-3 text-sm; }
.toggle-row span { @apply flex flex-col gap-0.5; }
.toggle-row small { @apply text-xs font-normal text-slate-500; }
.toggle { @apply mt-0.5 h-5 w-5 rounded border-slate-300 text-brand-600 focus:ring-brand-500; }
.segmented { @apply flex rounded-lg bg-slate-100 p-0.5; }
.segmented button { @apply rounded-md px-3 py-1.5 text-sm font-bold text-slate-600 transition; }
.segmented button.on { @apply bg-white text-brand-700 shadow-sm; }
.stepper { @apply flex items-center gap-3; }
.stepper button { @apply grid h-8 w-8 place-items-center rounded-lg border border-slate-200 text-lg font-bold text-slate-600 hover:bg-slate-50 disabled:opacity-30; }
.box { @apply space-y-1 rounded-xl bg-slate-50 p-3; }
.mini { @apply mb-0.5 block text-[11px] font-semibold text-slate-500; }
.box-title { @apply mb-1 text-sm font-bold text-slate-800; }
</style>
