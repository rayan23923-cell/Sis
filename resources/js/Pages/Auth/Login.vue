<script setup>
import { useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';

defineProps({ canResetPassword: Boolean, status: String });
const form = useForm({ email: '', password: '', remember: false });
const submit = () => form.post(route('login'), { onFinish: () => form.reset('password') });
</script>

<template>
    <GuestLayout title="تسجيل الدخول">
        <h1 class="text-2xl font-extrabold text-slate-900">تسجيل الدخول</h1>
        <p class="mt-1 text-sm text-slate-500">ادخل بحسابك الجامعي. الحسابات يُنشئها مدير النظام.</p>
        <div v-if="status" class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">{{ status }}</div>
        <form class="mt-8 space-y-5" @submit.prevent="submit">
            <div>
                <label class="label" for="email">البريد الإلكتروني</label>
                <input id="email" v-model="form.email" type="email" dir="ltr" class="input py-2.5" required autofocus autocomplete="username" />
                <InputError class="mt-1.5" :message="form.errors.email" />
            </div>
            <div>
                <label class="label" for="password">كلمة المرور</label>
                <input id="password" v-model="form.password" type="password" dir="ltr" class="input py-2.5" required autocomplete="current-password" />
                <InputError class="mt-1.5" :message="form.errors.password" />
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-600"><input v-model="form.remember" type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" /> تذكرني على هذا الجهاز</label>
            <button class="btn-primary w-full py-3 text-base" :disabled="form.processing">دخول</button>
        </form>
    </GuestLayout>
</template>
