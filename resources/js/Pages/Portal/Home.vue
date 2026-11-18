<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import Badge from '@/Components/Badge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import InputError from '@/Components/InputError.vue';
import { outcome, studentStatus } from '@/lib/labels';
import { AcademicCapIcon, ArrowDownTrayIcon, BookOpenIcon, ChartBarIcon, ClockIcon, ExclamationTriangleIcon, KeyIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ student: Object, current: Array, years: Array, official: Boolean, history: Array });
const tab = ref('current');
const lastGpa = computed(() => props.years.find((y) => y.gpa !== null)?.gpa ?? null);
const totalCredits = computed(() => props.years.reduce((s, y) => s + y.credits, 0));
const announced = computed(() => props.current.filter((c) => c.result).length);

const pw = useForm({ current_password: '', password: '', password_confirmation: '' });
const savePw = () => pw.put(route('password.update'), { preserveScroll: true, onSuccess: () => pw.reset(), onFinish: () => pw.reset('current_password', 'password', 'password_confirmation') });
</script>

<template>
    <PortalLayout title="بوابة الطالب">
        <!-- identity -->
        <section class="card overflow-hidden">
            <div class="flex flex-wrap items-center gap-4 rtl:bg-gradient-to-l ltr:bg-gradient-to-r from-brand-50 to-white p-5 sm:p-6">
                <div class="grid h-14 w-14 place-items-center rounded-2xl bg-brand-700 text-white"><AcademicCapIcon class="h-8 w-8" /></div>
                <div class="min-w-0 flex-1">
                    <h1 class="text-xl font-extrabold text-slate-900 sm:text-2xl">{{ student.name }}</h1>
                    <p class="mt-1 text-sm text-slate-500">{{ student.college }} · {{ student.department }}</p>
                    <p class="text-sm text-slate-500">{{ student.program }} · المرحلة {{ student.stage }} · نظام {{ student.system }}</p>
                </div>
                <div class="text-end">
                    <p class="text-xs text-slate-400">الرقم الجامعي</p>
                    <p class="ltr font-mono text-lg font-extrabold text-brand-800">{{ student.number }}</p>
                    <Badge :map="studentStatus" :value="student.status" dot class="mt-1" />
                </div>
            </div>
            <div class="grid grid-cols-2 divide-x rtl:divide-x-reverse divide-slate-100 border-t border-slate-100 sm:grid-cols-4">
                <div class="p-4"><p class="text-xs text-slate-500">آخر معدل سنوي</p><p class="text-xl font-extrabold tabular-nums">{{ lastGpa ?? '—' }}</p></div>
                <div class="p-4"><p class="text-xs text-slate-500">الوحدات المكتسبة</p><p class="text-xl font-extrabold tabular-nums">{{ totalCredits }}</p></div>
                <div class="p-4"><p class="text-xs text-slate-500">مواد هذا العام</p><p class="text-xl font-extrabold tabular-nums">{{ current.length }}</p></div>
                <div class="p-4"><p class="text-xs text-slate-500">نتائج معلنة</p><p class="text-xl font-extrabold tabular-nums text-emerald-600">{{ announced }} / {{ current.length }}</p></div>
            </div>
        </section>

        <div v-if="!official" class="mt-4 flex items-start gap-2 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <ExclamationTriangleIcon class="mt-0.5 h-5 w-5 shrink-0" /> بعض النتائج محسوبة بسياسات مؤقتة غير رسمية، ولا يُعتدّ بها رسمياً حتى اعتماد الأنظمة.
        </div>

        <div class="mt-6 flex flex-wrap items-center gap-2">
            <div class="flex gap-1 rounded-xl bg-slate-100 p-1 text-sm font-semibold">
                <button v-for="[k, l, icon] in [['current', 'مواد هذا العام', BookOpenIcon], ['results', 'النتائج السابقة', ChartBarIcon], ['history', 'السجل', ClockIcon], ['account', 'الحساب', KeyIcon]]" :key="k"
                    class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 transition" :class="tab === k ? 'bg-white text-brand-800 shadow-sm' : 'text-slate-500 hover:text-slate-700'" @click="tab = k">
                    <component :is="icon" class="h-4 w-4" /> {{ l }}
                </button>
            </div>
            <a v-if="student.status === 'graduated'" :href="route('students.graduation', student.id)" target="_blank" class="btn-secondary ms-auto">وثيقة التخرج PDF</a>
            <a :href="route('students.transcript', student.id)" target="_blank" class="btn-secondary" :class="student.status !== 'graduated' && 'ms-auto'"><ArrowDownTrayIcon class="h-4 w-4" /> كشف الدرجات PDF</a>
        </div>

        <!-- current year -->
        <section v-if="tab === 'current'" class="card mt-4">
            <EmptyState v-if="!current.length" title="لا توجد مواد مسجلة لهذا العام" />
            <ul v-else class="divide-y divide-slate-100">
                <li v-for="c in current" :key="c.code" class="flex flex-wrap items-center gap-3 px-5 py-3.5">
                    <div class="min-w-0 flex-1">
                        <p class="font-bold text-slate-800">{{ c.name }} <Badge v-if="c.carried" tone="amber">محمّلة</Badge></p>
                        <p class="text-xs text-slate-500"><span class="ltr font-mono">{{ c.code }}</span> · {{ c.term }} · {{ c.credits }} وحدة<span v-if="c.teacher"> · {{ c.teacher }}</span></p>
                    </div>
                    <template v-if="c.result">
                        <div class="text-end"><p class="text-lg font-extrabold tabular-nums">{{ c.result.mark }}</p><p class="text-xs text-slate-500">{{ c.result.grade }}</p></div>
                        <Badge :map="outcome" :value="c.result.outcome" />
                    </template>
                    <span v-else class="text-xs text-slate-400">لم تُعلن بعد</span>
                </li>
            </ul>
        </section>

        <!-- past years -->
        <template v-if="tab === 'results'">
            <EmptyState v-if="!years.length" class="card mt-4" title="لا توجد نتائج معلنة بعد" />
            <section v-for="y in years" :key="y.id" class="card mt-4">
                <div class="card-header flex flex-wrap items-center gap-2">
                    <h2 class="card-title">العام <span class="ltr">{{ y.name }}</span></h2>
                    <Badge v-if="y.decision" tone="brand">{{ y.decision }}</Badge>
                    <span class="ms-auto text-sm text-slate-500">المعدل: <b class="text-slate-800">{{ y.gpa ?? '—' }}</b> · الوحدات: <b class="text-slate-800">{{ y.credits }}</b></span>
                </div>
                <div class="overflow-x-auto">
                    <table class="table-base">
                        <thead><tr><th>المادة</th><th>الفصل</th><th>الوحدات</th><th>الدرجة</th><th>التقدير</th><th>الدور</th><th>النتيجة</th></tr></thead>
                        <tbody>
                            <tr v-for="r in y.rows" :key="r.code">
                                <td><p class="font-semibold">{{ r.name }}</p><p class="ltr text-start font-mono text-xs text-slate-400">{{ r.code }}</p></td>
                                <td>{{ r.term }}</td><td class="tabular-nums">{{ r.credits }}</td>
                                <td class="font-bold tabular-nums">{{ r.mark ?? '—' }}</td><td>{{ r.grade ?? '—' }}</td><td>{{ r.round }}</td>
                                <td><Badge v-if="r.outcome_code" :map="outcome" :value="r.outcome_code" /><span v-else class="text-xs text-slate-400">لم تُعلن</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>

        <!-- status history -->
        <section v-if="tab === 'history'" class="card mt-4">
            <EmptyState v-if="!history.length" title="لا يوجد سجل" />
            <ol v-else class="divide-y divide-slate-100">
                <li v-for="(h, i) in history" :key="i" class="flex flex-wrap items-center gap-3 px-5 py-3 text-sm">
                    <span class="font-bold text-slate-800">{{ h.to }}</span>
                    <span class="ltr tabular-nums text-slate-500">{{ h.on }}</span>
                    <span v-if="h.order" class="text-slate-500">{{ h.order }}</span>
                    <span v-if="h.notes" class="text-slate-400">{{ h.notes }}</span>
                </li>
            </ol>
        </section>

        <!-- account -->
        <form v-if="tab === 'account'" class="card mt-4 max-w-md p-6" @submit.prevent="savePw">
            <h2 class="card-title">تغيير كلمة المرور</h2>
            <p class="mt-1 text-sm text-slate-500">تدخل البوابة برقمك الجامعي <span class="ltr font-mono">{{ student.number }}</span>.</p>
            <div class="mt-5 space-y-4">
                <div><label class="label">كلمة المرور الحالية</label><input v-model="pw.current_password" type="password" dir="ltr" class="input" required autocomplete="current-password" /><InputError :message="pw.errors.current_password" class="mt-1" /></div>
                <div><label class="label">كلمة المرور الجديدة</label><input v-model="pw.password" type="password" dir="ltr" class="input" required autocomplete="new-password" /><InputError :message="pw.errors.password" class="mt-1" /></div>
                <div><label class="label">تأكيد كلمة المرور</label><input v-model="pw.password_confirmation" type="password" dir="ltr" class="input" required autocomplete="new-password" /></div>
            </div>
            <button class="btn-primary mt-5" :disabled="pw.processing">حفظ</button>
            <p v-if="pw.recentlySuccessful" class="mt-2 text-sm text-emerald-600">تم الحفظ.</p>
        </form>
    </PortalLayout>
</template>
