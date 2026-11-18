<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';

defineProps({ mustVerifyEmail: Boolean, status: String });
</script>

<template>
    <AppLayout title="حسابي">
        <PageHeader title="حسابي" subtitle="بياناتك وكلمة المرور." />
        <div class="grid max-w-3xl gap-6">
            <div class="card p-6"><UpdateProfileInformationForm :must-verify-email="mustVerifyEmail" :status="status" class="max-w-xl" /></div>
            <div class="card p-6"><UpdatePasswordForm class="max-w-xl" /></div>
        </div>
    </AppLayout>
</template>
