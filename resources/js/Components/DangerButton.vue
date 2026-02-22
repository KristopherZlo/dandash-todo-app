<script setup>
defineProps({
    type: {
        type: String,
        default: 'button',
    },
});
</script>

<template>
    <button
        :type="type"
        class="app-button-danger app-pressable"
    >
        <slot />
    </button>
</template>
