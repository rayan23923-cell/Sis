<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import FlashToast from '@/Components/FlashToast.vue';
import NotificationBell from '@/Components/NotificationBell.vue';
import LocaleSwitch from '@/Components/LocaleSwitch.vue';
import { ArrowRightOnRectangleIcon, BookOpenIcon, LockClosedIcon } from '@heroicons/vue/24/outline';

defineProps({ title: { type: String, default: '' } });
const page = usePage();
</script>

<template>
    <Head :title="title" />
    <div class="min-h-screen bg-slate-50">
        <header class="sticky top-0 z-30 border-b border-slate-200 bg-white text-slate-900">
            <div class="mx-auto flex h-14 max-w-5xl items-center gap-3 px-4 sm:px-6">
                <div class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-500 text-white"><BookOpenIcon class="h-5 w-5" /></div>
                <div class="min-w-0">
                    <p class="truncate font-bold leading-tight">بوابة الطالب</p>
                    <p class="truncate text-xs leading-tight text-slate-500">{{ page.props.app.university }}</p>
                </div>
                <div class="ms-auto flex shrink-0 items-center gap-1 text-sm sm:gap-3">
                    <span class="hidden text-slate-500 sm:inline">{{ page.props.auth.user?.name }}</span>
                    <NotificationBell />
                    <Link :href="route('security.show')" class="flex items-center gap-1.5 rounded-lg px-2 py-1.5 text-slate-500 hover:bg-slate-100 hover:text-brand-700 sm:px-3" title="الأمان"><LockClosedIcon class="h-4 w-4" /><span class="hidden sm:inline">الأمان</span></Link>
                    <LocaleSwitch class="rounded-lg px-2 py-1.5 sm:px-3 text-slate-500 hover:bg-slate-100 hover:text-brand-700" />
                    <Link :href="route('logout')" method="post" as="button" class="flex items-center gap-1.5 rounded-lg px-2 py-1.5 text-slate-500 hover:bg-slate-100 hover:text-brand-700 sm:px-3" title="خروج">
                        <ArrowRightOnRectangleIcon class="h-4 w-4" /><span class="hidden sm:inline">خروج</span>
                    </Link>
                </div>
            </div>
        </header>
        <main class="mx-auto max-w-5xl px-4 py-6 sm:px-6 sm:py-8">
            <slot />
        </main>
        <FlashToast />
    </div>
</template>
