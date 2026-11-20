<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import LocaleSwitch from '@/Components/LocaleSwitch.vue';
import { BookOpenIcon, ShieldCheckIcon, ClipboardDocumentCheckIcon, AcademicCapIcon } from '@heroicons/vue/24/outline';

defineProps({ title: { type: String, default: '' } });
const page = usePage();
</script>

<template>
    <Head :title="title" />
    <div class="flex min-h-screen bg-white">
        <div class="flex flex-1 flex-col justify-center px-6 py-12 sm:px-12 lg:flex-none lg:basis-[520px]">
            <div class="mx-auto w-full max-w-sm">
                <LocaleSwitch class="mb-6 ms-auto rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-500 hover:bg-slate-100" />
                <div class="mb-10 flex items-center gap-3">
                    <div class="grid h-12 w-12 place-items-center rounded-full bg-brand-500 text-white"><BookOpenIcon class="h-6 w-6" /></div>
                    <div><p class="text-lg font-bold text-slate-900">{{ page.props.app?.name }}</p><p class="text-sm text-slate-500">{{ page.props.app?.university }}</p></div>
                </div>
                <slot />
            </div>
        </div>
        <div class="relative hidden flex-1 overflow-hidden border-s border-slate-200 bg-slate-50 lg:block">
            <div class="relative flex h-full flex-col justify-center p-16 text-slate-900">
                <h2 class="max-w-md text-3xl font-bold leading-snug">سجل أكاديمي واحد، دقيق، وقابل للتدقيق.</h2>
                <p class="mt-4 max-w-md text-slate-500">من القبول إلى التخرج: الدرجات تُحتسب بقواعد مؤرخة ومعتمدة، والنتيجة المقفلة لا تتغير إلا بأمر إداري.</p>
                <ul class="mt-10 space-y-4 text-sm text-slate-700">
                    <li class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-500 text-white"><AcademicCapIcon class="h-5 w-5" /></span> ملف واحد لكل طالب مهما تغير قسمه</li>
                    <li class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-500 text-white"><ClipboardDocumentCheckIcon class="h-5 w-5" /></span> إدخال الدرجات ومصادقة اللجنة والقفل في مسار واضح</li>
                    <li class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-500 text-white"><ShieldCheckIcon class="h-5 w-5" /></span> كل تغيير مسجل: من، ومتى، ولماذا</li>
                </ul>
            </div>
        </div>
    </div>
</template>
