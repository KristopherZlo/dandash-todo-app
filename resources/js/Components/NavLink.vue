<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    href: {
        type: String,
        required: true,
    },
    active: {
        type: Boolean,
    },
});

const classes = computed(() =>
    props.active
        ? 'inline-flex items-center rounded-full border border-teal-200/80 bg-teal-50 px-4 py-2 text-sm font-semibold text-teal-800 shadow-sm transition'
        : 'inline-flex items-center rounded-full border border-transparent px-4 py-2 text-sm font-medium text-slate-600 transition hover:border-slate-300 hover:bg-white/70 hover:text-slate-900',
);
</script>

<template>
    <Link :href="href" :class="classes">
        <slot />
    </Link>
</template>
