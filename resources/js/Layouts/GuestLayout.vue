<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import { BookOpenIcon, ShieldCheckIcon, ClipboardDocumentCheckIcon, AcademicCapIcon } from '@heroicons/vue/24/outline';

defineProps({ title: { type: String, default: '' } });
const page = usePage();
</script>

<template>
    <Head :title="title" />
    <div class="flex min-h-screen bg-white">
        <div class="flex flex-1 flex-col justify-center px-6 py-12 sm:px-12 lg:flex-none lg:basis-[520px]">
            <div class="mx-auto w-full max-w-sm">
                <div class="mb-10 flex items-center gap-3">
                    <div class="grid h-12 w-12 place-items-center rounded-2xl bg-gradient-to-br from-brand-600 to-emerald-500 text-white shadow-lg shadow-brand-200"><BookOpenIcon class="h-7 w-7" /></div>
                    <div><p class="text-lg font-extrabold text-slate-900">{{ page.props.app?.name }}</p><p class="text-sm text-slate-500">{{ page.props.app?.university }}</p></div>
                </div>
                <slot />
            </div>
        </div>
        <div class="relative hidden flex-1 overflow-hidden bg-brand-950 lg:block">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_30%_20%,rgba(70,140,192,.35),transparent_45%),radial-gradient(circle_at_80%_80%,rgba(16,185,129,.25),transparent_40%)]" />
            <div class="relative flex h-full flex-col justify-center p-16 text-white">
                <h2 class="max-w-md text-4xl font-extrabold leading-tight">سجل أكاديمي واحد، دقيق، وقابل للتدقيق.</h2>
                <p class="mt-4 max-w-md text-brand-200">من القبول إلى التخرج: الدرجات تُحتسب بقواعد مؤرخة ومعتمدة، والنتيجة المقفلة لا تتغير إلا بأمر إداري.</p>
                <ul class="mt-10 space-y-4 text-sm">
                    <li class="flex items-center gap-3"><span class="grid h-9 w-9 place-items-center rounded-xl bg-white/10"><AcademicCapIcon class="h-5 w-5" /></span> ملف واحد لكل طالب مهما تغير قسمه</li>
                    <li class="flex items-center gap-3"><span class="grid h-9 w-9 place-items-center rounded-xl bg-white/10"><ClipboardDocumentCheckIcon class="h-5 w-5" /></span> إدخال الدرجات ومصادقة اللجنة والقفل في مسار واضح</li>
                    <li class="flex items-center gap-3"><span class="grid h-9 w-9 place-items-center rounded-xl bg-white/10"><ShieldCheckIcon class="h-5 w-5" /></span> كل تغيير مسجل: من، ومتى، ولماذا</li>
                </ul>
            </div>
        </div>
    </div>
</template>
