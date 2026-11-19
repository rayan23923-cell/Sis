<script setup>
import LocaleSwitch from '@/Components/LocaleSwitch.vue';
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import FlashToast from '@/Components/FlashToast.vue';
import NotificationBell from '@/Components/NotificationBell.vue';
import {
    AcademicCapIcon, BanknotesIcon, TableCellsIcon, ChartBarIcon, ArrowRightOnRectangleIcon, Bars3Icon, BookOpenIcon, BuildingLibraryIcon,
    ClipboardDocumentCheckIcon, DocumentTextIcon, ExclamationTriangleIcon, HomeIcon, ScaleIcon,
    ShieldCheckIcon, UserCircleIcon, CalendarDaysIcon, InboxArrowDownIcon, FingerPrintIcon, LockClosedIcon, UsersIcon, XMarkIcon, ArrowTrendingUpIcon, StarIcon, PuzzlePieceIcon,
} from '@heroicons/vue/24/outline';

defineProps({ title: { type: String, default: '' } });

const page = usePage();
const user = computed(() => page.props.auth.user);
const can = computed(() => page.props.can ?? {});
const open = ref(false);

const nav = computed(() => [
    { section: 'الرئيسية' },
    { label: 'لوحة المتابعة', route: 'dashboard', match: 'dashboard', icon: HomeIcon, show: true },
    { section: 'الشؤون الأكاديمية' },
    { label: 'الطلبة', route: 'students.index', match: 'students.*', icon: AcademicCapIcon, show: can.value.students },
    { label: 'المواد والدرجات', route: 'offerings.index', match: 'offerings.*', icon: ClipboardDocumentCheckIcon, show: true },
    { label: 'الجدول الأسبوعي', route: 'timetable.index', match: ['timetable.*', 'rooms.*'], icon: CalendarDaysIcon, show: true },
    { label: 'نتائج نهاية السنة', route: 'progression.index', match: 'progression.*', icon: ArrowTrendingUpIcon, show: can.value.progression },
    { label: 'الماستر شيت', route: 'master-sheet.show', match: 'master-sheet.*', icon: TableCellsIcon, show: can.value.progression },
    { label: 'التقارير والإحصاءات', route: 'reports.index', match: 'reports.*', icon: ChartBarIcon, show: can.value.reports },
    { label: 'الهيكل والمناهج', route: 'structure.index', match: ['structure.*', 'curricula.*'], icon: BuildingLibraryIcon, show: true },
    { section: 'الشؤون المالية' },
    { label: 'الرسوم والأقساط', route: 'finance.index', match: 'finance.*', icon: BanknotesIcon, show: can.value.finance },
    { section: 'الحوكمة' },
    { label: 'السياسات الأكاديمية', route: 'policies.index', match: 'policies.*', icon: ScaleIcon, show: can.value.policies },
    { label: 'تقييم التدريس', route: 'evaluations.index', match: 'evaluations.*', icon: StarIcon, show: true },
    { label: 'طلبات الوثائق', route: 'requests.index', match: 'requests.*', icon: InboxArrowDownIcon, show: can.value.requests, badge: page.props.pendingRequests },
    { label: 'الأوامر الإدارية', route: 'orders.index', match: 'orders.*', icon: DocumentTextIcon, show: can.value.orders },
    { label: 'المستخدمون والأدوار', route: 'users.index', match: 'users.*', icon: UsersIcon, show: can.value.users },
    { label: 'الموصلات', route: 'connectors.index', match: 'connectors.*', icon: PuzzlePieceIcon, show: can.value.connectors },
    { label: 'سجل التدقيق', route: 'audit.index', match: 'audit.*', icon: ShieldCheckIcon, show: can.value.audit },
    { label: 'سجل الدخول', route: 'logins.index', match: 'logins.*', icon: FingerPrintIcon, show: can.value.audit },
].filter((i) => i.section || i.show).filter((i, idx, arr) => !i.section || (arr[idx + 1] && !arr[idx + 1].section)));

const active = (m) => [].concat(m).some((x) => route().current(x));
const initials = computed(() => (user.value?.name ?? '').split(' ').slice(0, 2).map((w) => w[0]).join(''));
</script>

<template>
    <Head :title="title" />
    <FlashToast />
    <div class="min-h-screen bg-slate-50">
        <!-- Sidebar -->
        <div v-if="open" class="fixed inset-0 z-40 bg-slate-900/40 backdrop-blur-sm lg:hidden" @click="open = false" />
        <aside class="fixed inset-y-0 start-0 z-50 flex w-72 flex-col bg-brand-950 text-brand-100 transition-transform lg:!translate-x-0"
            :class="open ? 'translate-x-0' : 'rtl:translate-x-full ltr:-translate-x-full'">
            <div class="flex h-16 items-center gap-3 border-b border-white/10 px-5">
                <div class="grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br from-brand-400 to-emerald-400 text-white shadow-lg shadow-brand-900/40">
                    <BookOpenIcon class="h-6 w-6" />
                </div>
                <div class="min-w-0 leading-tight">
                    <p class="truncate text-sm font-extrabold text-white">{{ page.props.app.name }}</p>
                    <p class="truncate text-xs text-brand-300">{{ page.props.app.university }}</p>
                </div>
                <button class="ms-auto rounded-lg p-1 text-brand-300 hover:bg-white/10 lg:hidden" @click="open = false"><XMarkIcon class="h-5 w-5" /></button>
            </div>
            <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
                <template v-for="(item, i) in nav" :key="i">
                    <p v-if="item.section" class="px-3 pb-1 pt-4 text-[11px] font-bold uppercase tracking-wider text-brand-400 first:pt-0">{{ item.section }}</p>
                    <Link v-else :href="route(item.route)" @click="open = false"
                        class="group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition"
                        :class="active(item.match) ? 'bg-white/10 text-white shadow-inner' : 'text-brand-200 hover:bg-white/5 hover:text-white'">
                        <component :is="item.icon" class="h-5 w-5 shrink-0" :class="active(item.match) ? 'text-emerald-300' : 'text-brand-400 group-hover:text-brand-200'" />
                        {{ item.label }}
                        <span v-if="item.badge" class="ms-auto rounded-full bg-amber-400 px-2 py-0.5 text-[11px] font-bold text-brand-950">{{ item.badge }}</span>
                    </Link>
                </template>
            </nav>
            <div class="border-t border-white/10 p-3">
                <div class="flex items-center gap-3 rounded-xl px-2 py-2">
                    <div class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-700 text-sm font-bold text-white">{{ initials }}</div>
                    <div class="min-w-0 flex-1 leading-tight">
                        <p class="truncate text-sm font-bold text-white">{{ user.name }}</p>
                        <p class="truncate text-xs text-brand-300">{{ user.roles.join('، ') || 'بدون دور' }}</p>
                    </div>
                </div>
                <div class="mt-1 grid grid-cols-4 gap-1">
                    <Link :href="route('profile.edit')" class="flex items-center justify-center gap-1 rounded-lg px-1 py-2 text-xs font-semibold text-brand-200 hover:bg-white/5 hover:text-white">
                        <UserCircleIcon class="h-4 w-4" /> حسابي
                    </Link>
                    <Link :href="route('security.show')" class="flex items-center justify-center gap-1 rounded-lg px-1 py-2 text-xs font-semibold hover:bg-white/5 hover:text-white" :class="route().current('security.*') ? 'text-white' : 'text-brand-200'">
                        <LockClosedIcon class="h-4 w-4" /> الأمان
                    </Link>
                    <Link :href="route('logout')" method="post" as="button" class="flex items-center justify-center gap-1.5 rounded-lg px-2 py-2 text-xs font-semibold text-brand-200 hover:bg-white/5 hover:text-white">
                        <ArrowRightOnRectangleIcon class="h-4 w-4" /> خروج
                    </Link>
                    <LocaleSwitch class="rounded-lg px-2 py-2 text-xs font-semibold text-brand-200 hover:bg-white/5 hover:text-white" />
                </div>
            </div>
        </aside>

        <div class="lg:ps-72">
            <header class="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-slate-200/70 bg-white/80 px-4 backdrop-blur sm:px-6 lg:px-8">
                <button class="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden" @click="open = true"><Bars3Icon class="h-6 w-6" /></button>
                <p class="font-extrabold text-slate-800 lg:hidden">{{ page.props.app.name }}</p>
                <p class="hidden truncate text-sm font-semibold text-slate-500 lg:block">{{ title }}</p>
                <div class="ms-auto flex items-center gap-1">
                    <NotificationBell />
                </div>
            </header>
            <div v-if="page.props.unofficialPolicies" class="border-b border-amber-200 bg-amber-50">
                <div class="mx-auto flex max-w-7xl items-center gap-2 px-4 py-2 text-xs font-semibold text-amber-900 sm:px-6 lg:px-8">
                    <ExclamationTriangleIcon class="h-4 w-4 shrink-0" />
                    <span class="sm:hidden">سياسات مؤقتة غير رسمية</span>
                    <span class="hidden sm:inline">بعض السياسات الأكاديمية مؤقتة وغير رسمية بانتظار النص المعتمد من الجامعة. النتائج المحسوبة بها تظهر بعلامة «غير رسمية».</span>
                    <Link :href="route('policies.index')" class="ms-auto shrink-0 underline underline-offset-2">التفاصيل</Link>
                </div>
            </div>
            <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                <slot />
            </main>
        </div>
    </div>
</template>
