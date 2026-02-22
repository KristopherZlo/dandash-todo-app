<script setup>
import { Check, RotateCcw, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const emit = defineEmits(['complete', 'remove', 'tap']);
const props = defineProps({
    isCompleted: {
        type: Boolean,
        default: false,
    },
});

const startX = ref(0);
const startY = ref(0);
const dragX = ref(0);
const dragging = ref(false);
const touchId = ref(null);
const pressStartedAt = ref(0);
const touchSwipeLocked = ref(false);

const gestureThreshold = 72;
const swipeActivationDistance = 8;
const maxSwipeAngleDeg = 35;

const cardStyle = computed(() => ({
    transform: `translateX(${dragX.value}px)`,
}));

function isDragHandleTarget(target) {
    return target instanceof Element && target.closest('.drag-handle');
}

function isInteractiveTarget(target) {
    return target instanceof Element
        && target.closest('button, input, textarea, select, a, label, [data-no-swipe]') !== null;
}

function beginDrag(clientX) {
    startX.value = clientX;
    dragX.value = 0;
    dragging.value = true;
    pressStartedAt.value = Date.now();
    touchSwipeLocked.value = false;
}

function updateDrag(clientX) {
    if (!dragging.value) {
        return;
    }

    const delta = clientX - startX.value;
    dragX.value = Math.max(-120, Math.min(120, delta));
}

function endDrag() {
    if (!dragging.value) {
        return;
    }

    const delta = dragX.value;
    const clickLike = Math.abs(delta) < 10 && Date.now() - pressStartedAt.value < 320;

    dragX.value = 0;
    dragging.value = false;
    touchId.value = null;
    touchSwipeLocked.value = false;

    if (delta >= gestureThreshold) {
        emit('complete');
        return;
    }

    if (delta <= -gestureThreshold) {
        emit('remove');
        return;
    }

    if (clickLike) {
        emit('tap');
    }
}

function cancelDrag() {
    dragX.value = 0;
    dragging.value = false;
    touchId.value = null;
    touchSwipeLocked.value = false;
}

function onMouseDown(event) {
    if (event.button !== 0) {
        return;
    }

    if (isInteractiveTarget(event.target)) {
        return;
    }

    if (isDragHandleTarget(event.target)) {
        return;
    }

    beginDrag(event.clientX);
}

function onMouseMove(event) {
    if (!dragging.value) {
        return;
    }

    if (event.buttons === 0) {
        endDrag();
        return;
    }

    updateDrag(event.clientX);
}

function onMouseUp() {
    endDrag();
}

function onTouchStart(event) {
    const touch = event.changedTouches[0];
    if (!touch) {
        return;
    }

    if (isInteractiveTarget(event.target)) {
        return;
    }

    if (isDragHandleTarget(event.target)) {
        return;
    }

    touchId.value = touch.identifier;
    startY.value = touch.clientY;
    beginDrag(touch.clientX);
}

function onTouchMove(event) {
    if (!dragging.value) {
        return;
    }

    const touch = Array.from(event.touches).find((item) => item.identifier === touchId.value);
    if (!touch) {
        return;
    }

    const deltaX = touch.clientX - startX.value;
    const deltaY = touch.clientY - startY.value;

    if (!touchSwipeLocked.value) {
        const travelDistance = Math.hypot(deltaX, deltaY);
        if (travelDistance < swipeActivationDistance) {
            return;
        }

        const absDeltaX = Math.abs(deltaX);
        const absDeltaY = Math.abs(deltaY);
        const angleDeg = Math.atan2(absDeltaY, Math.max(absDeltaX, 0.0001)) * (180 / Math.PI);
        if (angleDeg > maxSwipeAngleDeg) {
            cancelDrag();
            return;
        }

        touchSwipeLocked.value = true;
    }

    event.preventDefault();
    updateDrag(touch.clientX);
}

function onTouchEnd(event) {
    if (!dragging.value) {
        return;
    }

    const ended = Array.from(event.changedTouches).some((item) => item.identifier === touchId.value);

    if (ended) {
        endDrag();
    }
}
</script>

<template>
    <div class="swipe-item">
        <div class="swipe-item-backdrop" aria-hidden="true">
            <div class="swipe-side swipe-side-done">
                <Check v-if="!props.isCompleted" class="h-5 w-5" />
                <RotateCcw v-else class="h-5 w-5" />
            </div>
            <div class="swipe-side swipe-side-remove">
                <Trash2 class="h-5 w-5" />
            </div>
        </div>

        <div
            class="swipe-item-card"
            :style="cardStyle"
            @mousedown="onMouseDown"
            @mousemove="onMouseMove"
            @mouseup="onMouseUp"
            @mouseleave="onMouseUp"
            @touchstart.passive="onTouchStart"
            @touchmove="onTouchMove"
            @touchend="onTouchEnd"
            @touchcancel="cancelDrag"
        >
            <slot />
        </div>
    </div>
</template>

<style scoped>
.swipe-item {
    position: relative;
    overflow: hidden;
    border-radius: 16px;
}

.swipe-item-backdrop {
    position: absolute;
    inset: 0;
    display: flex;
}

.swipe-side {
    flex: 1;
    display: flex;
    align-items: center;
    color: color-mix(in srgb, var(--ink) 94%, transparent);
}

.swipe-side-done {
    justify-content: flex-start;
    padding-left: 18px;
    background: color-mix(in srgb, var(--ok) 24%, transparent);
}

.swipe-side-remove {
    justify-content: flex-end;
    padding-right: 18px;
    background: color-mix(in srgb, var(--danger) 24%, transparent);
}

.swipe-item-card {
    position: relative;
    border-radius: 16px;
    border: 1px solid var(--border-surface);
    background: var(--bg-surface);
    box-shadow: 0 8px 18px -16px rgb(0 0 0 / 35%);
    padding: 14px;
    cursor: pointer;
    user-select: none;
    -webkit-user-select: none;
    touch-action: pan-y;
    transition: transform 0.2s ease-out;
}
</style>
