<script setup>
// The library's answer for a student (library connector, «براءة الذمة»). Compact: one chip.
import Badge from '@/Components/Badge.vue';
import { num } from '@/lib/labels';

defineProps({ status: { type: Object, default: null }, compact: { type: Boolean, default: false } });
</script>

<template>
    <template v-if="status">
        <Badge v-if="!status.known" tone="slate" :title="'لم تصل بيانات المكتبة بعد'">المكتبة: غير معروف</Badge>
        <Badge v-else-if="status.clear" tone="emerald" dot :title="`آخر مزامنة ${status.at}`">المكتبة: بريء الذمة</Badge>
        <span v-else class="inline-flex flex-wrap items-center gap-1" :title="`آخر مزامنة ${status.at}`">
            <Badge tone="rose" dot>المكتبة: عليه ذمة</Badge>
            <span v-if="!compact" class="text-xs text-rose-700">
                <template v-if="status.loans">{{ status.loans }} كتاب غير مُرجع<template v-if="status.overdue"> ({{ status.overdue }} متأخر)</template></template>
                <template v-if="status.loans && status.fines"> · </template>
                <template v-if="status.fines">غرامة {{ num(status.fines) }} دينار</template>
            </span>
        </span>
    </template>
</template>
