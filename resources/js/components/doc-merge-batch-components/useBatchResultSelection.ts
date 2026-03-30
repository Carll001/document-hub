import { computed, ref, watch, type Ref } from 'vue';
import type { BatchMergeHistoryRecord } from '@/components/doc-merge-batch-components/types';
import {
    mergeHistoryRecordKey,
    mergeHistorySearchText,
} from '@/components/doc-merge-batch-components/utils';

export function useBatchResultSelection(
    storedResults: Readonly<Ref<BatchMergeHistoryRecord[]>>,
) {
    const mergeHistorySearch = ref('');
    const selectedMergeHistoryKeys = ref<string[]>([]);

    const filteredMergeHistory = computed(() => {
        const query = mergeHistorySearch.value.trim().toLowerCase();

        if (query === '') {
            return storedResults.value;
        }

        return storedResults.value.filter((record) =>
            mergeHistorySearchText(record).includes(query),
        );
    });
    const selectedMergeHistorySet = computed(
        () => new Set(selectedMergeHistoryKeys.value),
    );
    const visibleMergeHistoryKeys = computed(() =>
        filteredMergeHistory.value.map((record) => mergeHistoryRecordKey(record)),
    );
    const visibleSelectedMergeHistoryCount = computed(
        () =>
            visibleMergeHistoryKeys.value.filter((key) =>
                selectedMergeHistorySet.value.has(key),
            ).length,
    );
    const selectAllMergeHistoryState = computed<boolean | 'indeterminate'>(() => {
        if (visibleSelectedMergeHistoryCount.value === 0) {
            return false;
        }

        if (
            visibleSelectedMergeHistoryCount.value ===
                visibleMergeHistoryKeys.value.length &&
            visibleMergeHistoryKeys.value.length > 0
        ) {
            return true;
        }

        return 'indeterminate';
    });

    watch(filteredMergeHistory, (mergeHistory) => {
        const visibleKeys = new Set(
            mergeHistory.map((record) => mergeHistoryRecordKey(record)),
        );

        selectedMergeHistoryKeys.value = selectedMergeHistoryKeys.value.filter(
            (key) => visibleKeys.has(key),
        );
    });

    function isMergeHistorySelected(record: BatchMergeHistoryRecord): boolean {
        return selectedMergeHistorySet.value.has(mergeHistoryRecordKey(record));
    }

    function toggleMergeHistorySelection(
        record: BatchMergeHistoryRecord,
        checked: boolean | 'indeterminate',
    ): void {
        const recordKey = mergeHistoryRecordKey(record);

        if (checked === true) {
            selectedMergeHistoryKeys.value = Array.from(
                new Set([...selectedMergeHistoryKeys.value, recordKey]),
            );

            return;
        }

        selectedMergeHistoryKeys.value = selectedMergeHistoryKeys.value.filter(
            (key) => key !== recordKey,
        );
    }

    function toggleAllVisibleMergeHistory(
        checked: boolean | 'indeterminate',
    ): void {
        if (checked === true) {
            selectedMergeHistoryKeys.value = Array.from(
                new Set([
                    ...selectedMergeHistoryKeys.value,
                    ...visibleMergeHistoryKeys.value,
                ]),
            );

            return;
        }

        selectedMergeHistoryKeys.value = selectedMergeHistoryKeys.value.filter(
            (key) => !visibleMergeHistoryKeys.value.includes(key),
        );
    }

    return {
        filteredMergeHistory,
        isMergeHistorySelected,
        mergeHistorySearch,
        selectedMergeHistoryKeys,
        selectedMergeHistorySet,
        selectAllMergeHistoryState,
        toggleAllVisibleMergeHistory,
        toggleMergeHistorySelection,
    };
}
