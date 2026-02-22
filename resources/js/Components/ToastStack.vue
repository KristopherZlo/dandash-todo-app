<script setup>
defineProps({
    toasts: {
        type: Array,
        default: () => [],
    },
    handlePointerDown: {
        type: Function,
        default: null,
    },
    handlePointerMove: {
        type: Function,
        default: null,
    },
    handlePointerUp: {
        type: Function,
        default: null,
    },
    handlePointerCancel: {
        type: Function,
        default: null,
    },
});

function toastClassByType(type) {
    if (type === 'error') {
        return 'border-[#ee5c81]/60 bg-[#221f22]/96 text-[#ee5c81]';
    }

    if (type === 'info' || type === 'warning') {
        return 'border-[#dfbe5a]/60 bg-[#221f22]/96 text-[#dfbe5a]';
    }

    return 'border-[#a5d774]/55 bg-[#221f22]/96 text-[#a5d774]';
}
</script>

<template>
    <TransitionGroup
        name="toast"
        tag="div"
        class="pointer-events-none fixed bottom-[90px] left-1/2 z-[240] flex w-[calc(100%-20px)] max-w-md -translate-x-1/2 flex-col gap-2"
    >
        <div
            v-for="toast in toasts"
            :key="`toast-${toast.id}`"
            class="toast-card pointer-events-auto select-none rounded-2xl border px-3 py-2 text-sm shadow-xl backdrop-blur"
            :class="toastClassByType(toast.type)"
            :style="{ transform: `translateX(${toast.deltaX || 0}px)` }"
            @pointerdown="handlePointerDown?.(toast.id, $event)"
            @pointermove="handlePointerMove?.(toast.id, $event)"
            @pointerup="handlePointerUp?.(toast.id)"
            @pointercancel="handlePointerCancel?.(toast.id)"
            @pointerleave="handlePointerUp?.(toast.id)"
        >
            <p class="truncate">{{ toast.message }}</p>
        </div>
    </TransitionGroup>
</template>

<style scoped>
.toast-enter-active,
.toast-leave-active {
    transition: opacity 0.2s ease, transform 0.2s ease;
}

.toast-enter-from,
.toast-leave-to {
    opacity: 0;
    transform: translateY(8px) scale(0.98);
}

.toast-move {
    transition: transform 0.2s ease;
}

.toast-card {
    touch-action: pan-y;
}

@media (prefers-reduced-motion: reduce) {
    .toast-enter-active,
    .toast-leave-active,
    .toast-move {
        transition-duration: 0.01ms;
    }
}
</style>
