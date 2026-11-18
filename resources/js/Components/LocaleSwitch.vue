<script setup>
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';
import { LanguageIcon } from '@heroicons/vue/24/outline';

// The translations are loaded with the page, so switching reloads it.
const page = usePage();
const other = () => (page.props.locale === 'en' ? 'ar' : 'en');
const toggle = async () => {
    await axios.post(route('locale'), { locale: other() });
    window.location.reload();
};
</script>

<template>
    <button type="button" class="flex items-center justify-center gap-1.5" :title="page.props.locale === 'en' ? 'العربية' : 'English'" @click="toggle">
        <LanguageIcon class="h-4 w-4" /> <span>{{ page.props.locale === 'en' ? 'عربي' : 'EN' }}</span>
    </button>
</template>
