<script setup>
import { useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import InputError from '@/Components/InputError.vue';
import { LockClosedIcon } from '@heroicons/vue/24/outline';

const form = useForm({ current_password: '', password: '', password_confirmation: '', first: true });
const submit = () => form.put(route('password.update'), { onFinish: () => form.reset('current_password', 'password', 'password_confirmation') });
</script>

<template>
    <PortalLayout title="تعيين كلمة مرور جديدة">
        <form class="card mx-auto max-w-md p-6" @submit.prevent="submit">
            <div class="flex items-center gap-3">
                <span class="grid h-10 w-10 place-items-center rounded-xl bg-brand-50 text-brand-700"><LockClosedIcon class="h-5 w-5" /></span>
                <div>
                    <h1 class="text-lg font-extrabold text-slate-900">تعيين كلمة مرور جديدة</h1>
                    <p class="text-sm text-slate-500">دخلت بكلمة مرور مؤقتة. اختر كلمة مرور خاصة بك للمتابعة.</p>
                </div>
            </div>
            <div class="mt-6 space-y-4">
                <div><label class="label">كلمة المرور المؤقتة</label><input v-model="form.current_password" type="password" dir="ltr" class="input" required autocomplete="current-password" /><InputError :message="form.errors.current_password" class="mt-1" /></div>
                <div><label class="label">كلمة المرور الجديدة</label><input v-model="form.password" type="password" dir="ltr" class="input" required autocomplete="new-password" /><InputError :message="form.errors.password" class="mt-1" /></div>
                <div><label class="label">تأكيد كلمة المرور الجديدة</label><input v-model="form.password_confirmation" type="password" dir="ltr" class="input" required autocomplete="new-password" /></div>
            </div>
            <button class="btn-primary mt-6 w-full justify-center" :disabled="form.processing">حفظ ومتابعة</button>
        </form>
    </PortalLayout>
</template>
