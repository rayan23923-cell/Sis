<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Badge from '@/Components/Badge.vue';
import { systems, policyStatus } from '@/lib/labels';
import { BuildingLibraryIcon, ChevronDownIcon, CalendarDaysIcon, BookOpenIcon, UsersIcon } from '@heroicons/vue/24/outline';

const props = defineProps({ colleges: Array, years: Array });
const openIds = ref(new Set(props.colleges.slice(0, 1).map((c) => c.id)));
const toggle = (id) => { const s = new Set(openIds.value); s.has(id) ? s.delete(id) : s.add(id); openIds.value = s; };
const termTone = { planning: 'slate', registration: 'sky', teaching: 'brand', exams: 'amber', closed: 'emerald' };
const termLabel = { planning: 'تخطيط', registration: 'تسجيل', teaching: 'تدريس', exams: 'امتحانات', closed: 'مغلق' };
const count = (c, key) => c.departments.reduce((s, d) => s + d.programs.reduce((x, p) => x + (key === 'students' ? p.students : 1), 0), 0);
</script>

<template>
    <AppLayout title="الهيكل والمناهج">
        <PageHeader title="الهيكل الأكاديمي والمناهج" subtitle="الكليات والأقسام والبرامج والمناهج المعتمدة، والتقويم الأكاديمي." />

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <section v-for="c in colleges" :key="c.id" class="card overflow-hidden">
                    <button class="flex w-full items-center gap-4 px-5 py-4 text-start transition hover:bg-slate-50" @click="toggle(c.id)">
                        <div class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-700"><BuildingLibraryIcon class="h-6 w-6" /></div>
                        <div class="min-w-0 flex-1">
                            <p class="font-extrabold text-slate-800">{{ c.name }}</p>
                            <p class="text-xs text-slate-500">{{ c.departments.length }} أقسام · {{ count(c, 'programs') }} برامج · {{ count(c, 'students') }} طالب</p>
                        </div>
                        <ChevronDownIcon class="h-5 w-5 text-slate-400 transition" :class="openIds.has(c.id) && 'rotate-180'" />
                    </button>
                    <div v-if="openIds.has(c.id)" class="divide-y divide-slate-100 border-t border-slate-100">
                        <div v-for="d in c.departments" :key="d.id" class="px-5 py-4">
                            <div class="mb-3 flex items-center justify-between">
                                <p class="font-bold text-slate-700">{{ d.name }} <span class="font-mono text-xs text-slate-400">{{ d.code }}</span></p>
                                <span class="flex items-center gap-1 text-xs text-slate-500"><BookOpenIcon class="h-4 w-4" /> {{ d.courses }} مادة</span>
                            </div>
                            <div v-for="p in d.programs" :key="p.id" class="mb-2 rounded-xl border border-slate-100 bg-slate-50/50 p-4 last:mb-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="font-bold text-slate-800">{{ p.name }}</p>
                                    <Badge tone="brand">{{ systems[p.system] }}</Badge>
                                    <Badge tone="slate">{{ p.years }} سنوات</Badge>
                                    <span class="mr-auto flex items-center gap-1 text-xs text-slate-500"><UsersIcon class="h-4 w-4" /> {{ p.students }}</span>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <Link v-for="v in p.curricula" :key="v.id" :href="route('curricula.show', v.id)" class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:border-brand-300 hover:text-brand-800">
                                        {{ v.name }} · إصدار {{ v.version }} · {{ v.courses }} مادة <Badge :map="policyStatus" :value="v.status" />
                                    </Link>
                                    <span v-if="!p.curricula.length" class="text-xs text-amber-700">لا يوجد منهج</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
            <aside>
                <section class="card">
                    <div class="card-header"><h2 class="card-title flex items-center gap-2"><CalendarDaysIcon class="h-5 w-5 text-brand-600" /> التقويم الأكاديمي</h2></div>
                    <div v-for="y in years" :key="y.id" class="border-b border-slate-100 p-5 last:border-0">
                        <div class="mb-3 flex items-center justify-between"><p class="ltr font-extrabold">{{ y.name }}</p><Badge v-if="y.current" tone="emerald" dot>الحالي</Badge></div>
                        <ul class="space-y-2">
                            <li v-for="t in y.terms" :key="t.id" class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 text-sm">
                                <div><p class="font-semibold">{{ t.name }}</p><p class="text-[11px] tabular-nums text-slate-400">{{ t.starts }} ← {{ t.ends }}</p></div>
                                <Badge :tone="termTone[t.status]">{{ termLabel[t.status] }}</Badge>
                            </li>
                        </ul>
                    </div>
                </section>
            </aside>
        </div>
    </AppLayout>
</template>
