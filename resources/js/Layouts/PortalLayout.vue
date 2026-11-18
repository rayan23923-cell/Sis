<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import FlashToast from '@/Components/FlashToast.vue';
import LocaleSwitch from '@/Components/LocaleSwitch.vue';
import { ArrowRightOnRectangleIcon, BookOpenIcon } from '@heroicons/vue/24/outline';

defineProps({ title: { type: String, default: '' } });
const page = usePage();
</script>

<template>
    <Head :title="title" />
    <div class="min-h-screen bg-slate-50">
        <header class="bg-brand-950 text-white">
            <div class="mx-auto flex max-w-5xl items-center gap-3 px-4 py-4 sm:px-6">
                <div class="grid h-10 w-10 place-items-center rounded-xl bg-gradient-to-br from-brand-400 to-emerald-400 shadow-lg shadow-brand-900/40"><BookOpenIcon class="h-6 w-6" /></div>
                <div class="min-w-0">
                    <p class="truncate font-extrabold">بوابة الطالب</p>
                    <p class="truncate text-xs text-brand-300">{{ page.props.app.university }}</p>
                </div>
                <div class="ms-auto flex items-center gap-3 text-sm">
                    <span class="hidden text-brand-200 sm:inline">{{ page.props.auth.user?.name }}</span>
                    <LocaleSwitch class="rounded-lg px-3 py-1.5 text-brand-200 hover:bg-white/10 hover:text-white" />
                    <Link :href="route('logout')" method="post" as="button" class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-brand-200 hover:bg-white/10 hover:text-white">
                        <ArrowRightOnRectangleIcon class="h-4 w-4" /> خروج
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
