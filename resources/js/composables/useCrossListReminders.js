import { computed } from 'vue';
import { buildCrossListReminders } from '@/modules/dashboard/listState';

export function useCrossListReminders(options = {}) {
    const {
        listOptions,
        selectedListId,
    } = options;

    const crossListReminders = computed(() => buildCrossListReminders(
        listOptions?.value,
        selectedListId?.value,
    ));

    const hasCrossListReminders = computed(() => crossListReminders.value.length > 0);

    return {
        crossListReminders,
        hasCrossListReminders,
    };
}
