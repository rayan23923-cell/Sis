<script setup>
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import { DevicePhoneMobileIcon, KeyIcon } from '@heroicons/vue/24/outline';

const recovery = ref(false);
const form = useForm({ code: '' });
const submit = () => form.post(route('two-factor.verify'), { onFinish: () => form.reset('code') });
const toggle = () => { recovery.value = !recovery.value; form.code = ''; form.clearErrors(); };
</script>

<template>
    <GuestLayout title="التحقق بخطوتين">
        <div class="grid h-12 w-12 place-items-center rounded-2xl bg-brand-50 text-brand-700">
            <component :is="recovery ? KeyIcon : DevicePhoneMobileIcon" class="h-6 w-6" />
        </div>
        <h1 class="mt-4 text-2xl font-extrabold text-slate-900">التحقق بخطوتين</h1>
        <p class="mt-1 text-sm text-slate-500">
            {{ recovery ? 'أدخل أحد رموز الاسترداد التي حفظتها عند التفعيل. كل رمز يُستخدم مرة واحدة.' : 'افتح تطبيق المصادقة في هاتفك وأدخل الرمز المكوّن من 6 أرقام.' }}
        </p>
        <form class="mt-8 space-y-5" @submit.prevent="submit">
            <div>
                <label class="label" for="code">{{ recovery ? 'رمز الاسترداد' : 'رمز التحقق' }}</label>
                <input id="code" v-model="form.code" dir="ltr" class="input py-3 text-center font-mono text-2xl tracking-[0.4em]" :class="recovery ? 'text-base tracking-widest' : ''"
                    :inputmode="recovery ? 'text' : 'numeric'" :maxlength="recovery ? 20 : 7" autocomplete="one-time-code" autofocus required
                    :placeholder="recovery ? 'xxxxx-xxxxx' : '000000'" />
                <InputError class="mt-1.5" :message="form.errors.code" />
            </div>
            <button class="btn-primary w-full py-3 text-base" :disabled="form.processing || !form.code">تحقق ودخول</button>
        </form>
        <div class="mt-6 flex items-center justify-between text-sm">
            <button type="button" class="font-semibold text-brand-700 hover:underline" @click="toggle">{{ recovery ? 'استخدام رمز التطبيق' : 'فقدت الهاتف؟ استخدم رمز استرداد' }}</button>
            <Link :href="route('login')" class="text-slate-500 hover:underline">رجوع</Link>
        </div>
    </GuestLayout>
</template>
