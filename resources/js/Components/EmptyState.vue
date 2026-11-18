<script setup>
defineProps({ title: String, text: String, icon: [Object, Function] });
</script>

<template>
    <div class="flex flex-col items-center justify-center px-6 py-14 text-center">
        <div v-if="icon" class="mb-3 grid h-14 w-14 place-items-center rounded-2xl bg-slate-100 text-slate-400">
            <component :is="icon" class="h-7 w-7" />
        </div>
        <p class="font-bold text-slate-700">{{ title }}</p>
        <p v-if="text" class="mt-1 max-w-md text-sm text-slate-500">{{ text }}</p>
        <div class="mt-4"><slot /></div>
    </div>
</template>
