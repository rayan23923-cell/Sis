<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import WeekGrid from '@/Components/WeekGrid.vue';
import StatCard from '@/Components/StatCard.vue';
import Badge from '@/Components/Badge.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { offeringStatus, studentStatus, outcome, roundName, num } from '@/lib/labels';
import { AcademicCapIcon, BuildingLibraryIcon, ClipboardDocumentCheckIcon, CheckBadgeIcon, InboxIcon, ArrowLeftIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ year: String, terms: Array, stats: Object, myCourses: Array, toApprove: Array, statusLabels: Object, myTimetable: { type: Array, default: () => [] }, days: Object });
const page = usePage();
const hour = new Date().getHours();
const greeting = hour < 12 ? 'صباح الخير' : 'مساء الخير';

const pipeline = computed(() => ['open', 'submitted', 'approved', 'locked'].map((k) => ({
    key: k, ...offeringStatus[k], count: props.stats.byStatus[k] ?? 0,
})));
const pipelineTotal = computed(() => Math.max(1, pipeline.value.reduce((s, p) => s + p.count, 0)));
const barColor = { sky: 'bg-sky-500', amber: 'bg-amber-500', violet: 'bg-violet-500', emerald: 'bg-emerald-500' };

const outcomes = computed(() => {
    const o = props.stats.outcomes ?? {};
    const total = Object.values(o).reduce((s, v) => s + v, 0);
    return { total, rows: ['pass', 'resit', 'fail'].map((k) => ({ key: k, ...outcome[k], count: o[k] ?? 0, pct: total ? Math.round(((o[k] ?? 0) / total) * 100) : 0 })) };
});
const studentRows = computed(() => Object.entries(props.stats.byStudentStatus ?? {}).map(([k, v]) => ({ key: k, count: v })).sort((a, b) => b.count - a.count));
</script>

<template>
    <AppLayout title="لوحة المتابعة">
        <div class="mb-6 overflow-hidden rounded-3xl rtl:bg-gradient-to-l ltr:bg-gradient-to-r from-brand-800 via-brand-900 to-brand-950 p-6 text-white shadow-lg sm:p-8">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm font-medium text-brand-200">{{ greeting }}،</p>
                    <h1 class="mt-1 text-2xl font-extrabold sm:text-3xl">{{ page.props.auth.user.name }}</h1>
                    <p class="mt-2 text-sm text-brand-200">العام الدراسي <span class="ltr">{{ year ?? '—' }}</span>
                        <span v-for="t in terms" :key="t.id" class="ms-2 inline-flex items-center gap-1 rounded-full bg-white/10 px-2.5 py-0.5 text-xs font-semibold text-white">{{ t.name_ar }}</span>
                    </p>
                </div>
                <div class="flex gap-2">
                    <Link v-if="page.props.can.admit" :href="route('students.create')" class="btn bg-white text-brand-900 hover:bg-brand-50">قبول طالب جديد</Link>
                    <Link :href="route('offerings.index')" class="btn bg-white/10 text-white ring-1 ring-white/20 hover:bg-white/20">المواد والدرجات</Link>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            <StatCard label="الطلبة المستمرون" :value="num(stats.active)" :hint="`من أصل ${num(stats.students)} طالب`" :icon="AcademicCapIcon" />
            <StatCard label="الشعب الدراسية" :value="num(stats.offerings)" hint="في العام الحالي" :icon="ClipboardDocumentCheckIcon" tone="violet" />
            <StatCard label="بانتظار اللجنة" :value="num(stats.byStatus.submitted ?? 0)" hint="شعب مرفوعة للمصادقة" :icon="InboxIcon" tone="amber" />
            <StatCard label="الكليات" :value="num(stats.colleges)" hint="ضمن صلاحياتك" :icon="BuildingLibraryIcon" tone="emerald" />
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <section v-if="myCourses.length" class="card">
                    <div class="card-header"><h2 class="card-title">موادي</h2><Link :href="route('offerings.index', { mine: 1 })" class="text-sm font-semibold text-brand-700 hover:underline">الكل</Link></div>
                    <ul class="divide-y divide-slate-100">
                        <li v-for="o in myCourses" :key="o.id">
                            <Link :href="route('offerings.show', o.id)" class="flex items-center gap-4 px-5 py-3.5 transition hover:bg-slate-50">
                                <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-xs font-extrabold text-brand-700">{{ o.code.slice(-3) }}</div>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-bold text-slate-800">{{ o.name }}</p>
                                    <p class="text-xs text-slate-500">{{ o.code }} · شعبة {{ o.section }} · {{ o.students }} طالب · {{ roundName(o.round) }}</p>
                                </div>
                                <Badge :map="offeringStatus" :value="o.status" dot />
                                <ArrowLeftIcon class="h-4 w-4 text-slate-400" />
                            </Link>
                        </li>
                    </ul>
                </section>

                <section v-if="toApprove.length" class="card">
                    <div class="card-header"><h2 class="card-title">بانتظار قرارك</h2><Badge tone="amber">{{ toApprove.length }}</Badge></div>
                    <ul class="divide-y divide-slate-100">
                        <li v-for="o in toApprove" :key="o.id">
                            <Link :href="route('offerings.show', o.id)" class="flex items-center gap-4 px-5 py-3.5 transition hover:bg-slate-50">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-bold text-slate-800">{{ o.name }} <span class="text-xs font-medium text-slate-400">{{ o.code }}</span></p>
                                    <p class="text-xs text-slate-500">{{ o.teacher ?? 'بدون تدريسي' }} · {{ o.students }} طالب</p>
                                </div>
                                <Badge :map="offeringStatus" :value="o.status" dot />
                            </Link>
                        </li>
                    </ul>
                </section>

                <section class="card">
                    <div class="card-header"><h2 class="card-title">مراحل إنجاز الدرجات</h2></div>
                    <div class="p-5">
                        <div class="flex h-3 overflow-hidden rounded-full bg-slate-100">
                            <div v-for="p in pipeline" :key="p.key" :class="barColor[p.tone]" :style="{ width: (p.count / pipelineTotal) * 100 + '%' }" />
                        </div>
                        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <Link v-for="p in pipeline" :key="p.key" :href="route('offerings.index', { status: p.key })" class="rounded-xl border border-slate-100 p-3 transition hover:border-slate-200 hover:bg-slate-50">
                                <div class="flex items-center gap-2 text-xs font-semibold text-slate-500"><span class="h-2 w-2 rounded-full" :class="barColor[p.tone]" />{{ p.label }}</div>
                                <p class="mt-1 text-xl font-extrabold tabular-nums text-slate-800">{{ p.count }}</p>
                            </Link>
                        </div>
                    </div>
                </section>
            </div>

            <div class="space-y-6">
                <section class="card">
                    <div class="card-header"><h2 class="card-title">نتائج مقفلة</h2><CheckBadgeIcon class="h-5 w-5 text-emerald-600" /></div>
                    <div class="p-5">
                        <EmptyState v-if="!outcomes.total" title="لا توجد نتائج مقفلة بعد" />
                        <div v-else class="space-y-3">
                            <div v-for="o in outcomes.rows" :key="o.key">
                                <div class="mb-1 flex justify-between text-sm"><span class="font-semibold text-slate-700">{{ o.label }}</span><span class="tabular-nums text-slate-500">{{ o.count }} · {{ o.pct }}%</span></div>
                                <div class="h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full" :class="{ emerald: 'bg-emerald-500', amber: 'bg-amber-500', rose: 'bg-rose-500' }[o.tone]" :style="{ width: o.pct + '%' }" /></div>
                            </div>
                        </div>
                    </div>
                </section>
                <section class="card">
                    <div class="card-header"><h2 class="card-title">حالات الطلبة</h2></div>
                    <ul class="divide-y divide-slate-100">
                        <li v-for="r in studentRows" :key="r.key" class="flex items-center justify-between px-5 py-2.5 text-sm">
                            <Badge :map="studentStatus" :value="r.key" dot />
                            <span class="font-bold tabular-nums text-slate-700">{{ num(r.count) }}</span>
                        </li>
                        <li v-if="!studentRows.length" class="px-5 py-6 text-center text-sm text-slate-500">لا يوجد طلبة</li>
                    </ul>
                </section>
            </div>
        </div>
        <section v-if="myTimetable.length" class="card mt-6 p-2 sm:p-4">
            <div class="mb-3 flex items-center justify-between px-2"><h2 class="font-extrabold text-slate-800">جدولي الأسبوعي</h2><a :href="route('timetable.mine')" class="btn-secondary">طباعة</a></div>
            <WeekGrid :slots="myTimetable" :days="days" />
        </section>
    </AppLayout>
</template>
