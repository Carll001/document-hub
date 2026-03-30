import { computed, ref, type Ref } from 'vue';
import type {
    PageFolderDisplayItem,
    PageFolderUploadItem,
} from '@/components/doc-merge-batch-components/types';
import { bulkOutputPreview } from '@/components/doc-merge-batch-components/utils';

type DirectoryCapableFile = File & {
    webkitRelativePath?: string;
};

type BatchPageFolderFormBridge = {
    clearErrors: (field: 'pageFolders') => void;
    setError: (field: 'pageFolders', value: string) => void;
};

export function useBatchPageFolders(
    bulkFolderForm: BatchPageFolderFormBridge,
    outputPrefix: Ref<string>,
) {
    const selectedPageFolders = ref<PageFolderUploadItem[]>([]);
    let pageFolderSequence = 0;

    const duplicatePageFolderNumbers = computed(() => {
        const duplicateNumbers = new Set<number>();
        const counts = new Map<number, number>();

        for (const pageFolder of selectedPageFolders.value) {
            if (pageFolder.number === null) {
                continue;
            }

            const nextCount = (counts.get(pageFolder.number) ?? 0) + 1;

            counts.set(pageFolder.number, nextCount);

            if (nextCount > 1) {
                duplicateNumbers.add(pageFolder.number);
            }
        }

        return duplicateNumbers;
    });
    const pageFoldersForDisplay = computed<PageFolderDisplayItem[]>(() =>
        [...selectedPageFolders.value]
            .map((pageFolder) => ({
                ...pageFolder,
                issueMessage: pageFolderIssueMessage(pageFolder),
            }))
            .sort(comparePageFolderItems),
    );
    const validSelectedPageFolderCount = computed(
        () =>
            pageFoldersForDisplay.value.filter(
                (pageFolder) => pageFolder.issueMessage === null,
            ).length,
    );
    const bulkFolderOutputPreview = computed(() =>
        bulkOutputPreview(outputPrefix.value),
    );
    const bulkFolderInlineError = computed(
        () =>
            pageFoldersForDisplay.value.find(
                (pageFolder) => pageFolder.issueMessage !== null,
            )?.issueMessage ?? null,
    );
    const bulkFolderClientError = computed(() => {
        if (bulkFolderInlineError.value) {
            return bulkFolderInlineError.value;
        }

        if (selectedPageFolders.value.length < 2) {
            return 'Add at least two page folders like PAGE 1 and PAGE 2.';
        }

        if (validSelectedPageFolderCount.value < 2) {
            return 'Add at least two valid page folders like PAGE 1 and PAGE 2.';
        }

        return null;
    });

    function nextPageFolderKey(): string {
        pageFolderSequence += 1;

        return `page-folder-${pageFolderSequence}`;
    }

    function isPdfFile(file: File): boolean {
        return (
            file.type === 'application/pdf' ||
            file.name.toLowerCase().endsWith('.pdf')
        );
    }

    function pageFolderNumberFromName(name: string): number | null {
        const match = name.trim().match(/(\d+)$/);

        if (!match) {
            return null;
        }

        const pageNumber = Number.parseInt(match[1] ?? '', 10);

        return Number.isFinite(pageNumber) && pageNumber > 0 ? pageNumber : null;
    }

    function pageFolderIssueMessage(
        pageFolder: PageFolderUploadItem,
    ): string | null {
        if (pageFolder.number === null) {
            return `Folder ${pageFolder.name} must end with a positive number like PAGE 1.`;
        }

        if (duplicatePageFolderNumbers.value.has(pageFolder.number)) {
            return `Page number ${pageFolder.number} is already selected by another folder.`;
        }

        if (pageFolder.hasNestedEntries) {
            return `Folder ${pageFolder.name} contains nested folders. Only direct PDF files are allowed.`;
        }

        if (pageFolder.hasInvalidFiles) {
            return `Folder ${pageFolder.name} contains non-PDF files. Only direct PDF files are allowed.`;
        }

        if (pageFolder.files.length === 0) {
            return `Folder ${pageFolder.name} must contain at least one direct PDF file.`;
        }

        return null;
    }

    function comparePageFolderItems(
        left: Pick<PageFolderUploadItem, 'number' | 'name'>,
        right: Pick<PageFolderUploadItem, 'number' | 'name'>,
    ): number {
        const leftNumber = left.number ?? Number.POSITIVE_INFINITY;
        const rightNumber = right.number ?? Number.POSITIVE_INFINITY;

        if (leftNumber !== rightNumber) {
            return leftNumber - rightNumber;
        }

        const leftName = left.name.toLowerCase();
        const rightName = right.name.toLowerCase();

        if (leftName !== rightName) {
            return leftName.localeCompare(rightName);
        }

        return left.name.localeCompare(right.name);
    }

    function fileRelativePath(file: File): string {
        const relativePath = (file as DirectoryCapableFile).webkitRelativePath;

        if (typeof relativePath === 'string' && relativePath.trim() !== '') {
            return relativePath.replaceAll('\\', '/');
        }

        return file.name;
    }

    function fileRelativePathSegments(file: File): string[] {
        return fileRelativePath(file)
            .split('/')
            .filter((segment) => segment !== '');
    }

    function looksLikeContainerFolderSelection(files: File[]): boolean {
        const segmentedPaths = files
            .map(fileRelativePathSegments)
            .filter((segments) => segments.length > 0);

        if (segmentedPaths.length === 0) {
            return false;
        }

        const rootSegments = Array.from(
            new Set(segmentedPaths.map(([rootSegment = '']) => rootSegment)),
        ).filter((segment) => segment !== '');

        if (rootSegments.length !== 1) {
            return false;
        }

        const hasDirectRootFiles = segmentedPaths.some(
            (segments) => segments.length === 2,
        );

        if (hasDirectRootFiles) {
            return false;
        }

        const childFolderNames = Array.from(
            new Set(
                segmentedPaths
                    .filter((segments) => segments.length >= 3)
                    .map((segments) => segments[1] ?? ''),
            ),
        ).filter((segment) => segment !== '');

        return (
            childFolderNames.length > 0 &&
            childFolderNames.every(
                (folderName) => pageFolderNumberFromName(folderName) !== null,
            )
        );
    }

    function parseContainerFolderSelection(files: File[]): PageFolderUploadItem[] {
        const rootSegments = Array.from(
            new Set(
                files.map((file) => {
                    const [rootSegment = ''] = fileRelativePath(file).split('/');

                    return rootSegment;
                }),
            ),
        ).filter((segment) => segment !== '');

        if (rootSegments.length !== 1) {
            bulkFolderForm.setError(
                'pageFolders',
                'Choose one container folder at a time.',
            );

            return [];
        }

        const pageFolderMap = new Map<string, PageFolderUploadItem>();

        for (const file of files) {
            const segments = fileRelativePathSegments(file);

            if (segments.length < 3) {
                bulkFolderForm.setError(
                    'pageFolders',
                    'The container folder must contain only page folders.',
                );

                return [];
            }

            const pageFolderName = segments[1] ?? '';

            if (!pageFolderMap.has(pageFolderName)) {
                pageFolderMap.set(pageFolderName, {
                    key: nextPageFolderKey(),
                    name: pageFolderName,
                    number: pageFolderNumberFromName(pageFolderName),
                    files: [],
                    hasNestedEntries: false,
                    hasInvalidFiles: false,
                });
            }

            const pageFolder = pageFolderMap.get(pageFolderName);

            if (!pageFolder) {
                continue;
            }

            if (segments.length > 3) {
                pageFolder.hasNestedEntries = true;

                continue;
            }

            if (!isPdfFile(file)) {
                pageFolder.hasInvalidFiles = true;

                continue;
            }

            pageFolder.files.push(file);
        }

        return Array.from(pageFolderMap.values());
    }

    function parsePageFolderSelection(files: File[]): PageFolderUploadItem[] {
        if (looksLikeContainerFolderSelection(files)) {
            return parseContainerFolderSelection(files);
        }

        const pageFolderMap = new Map<string, PageFolderUploadItem>();

        for (const file of files) {
            const segments = fileRelativePathSegments(file);
            const [pageFolderName = ''] = segments;

            if (pageFolderName === '') {
                continue;
            }

            if (!pageFolderMap.has(pageFolderName)) {
                pageFolderMap.set(pageFolderName, {
                    key: nextPageFolderKey(),
                    name: pageFolderName,
                    number: pageFolderNumberFromName(pageFolderName),
                    files: [],
                    hasNestedEntries: false,
                    hasInvalidFiles: false,
                });
            }

            const pageFolder = pageFolderMap.get(pageFolderName);

            if (!pageFolder) {
                continue;
            }

            if (segments.length > 2) {
                pageFolder.hasNestedEntries = true;

                continue;
            }

            if (!isPdfFile(file)) {
                pageFolder.hasInvalidFiles = true;

                continue;
            }

            pageFolder.files.push(file);
        }

        const pageFolders = Array.from(pageFolderMap.values());

        if (pageFolders.length === 0) {
            bulkFolderForm.setError(
                'pageFolders',
                'Choose one or more page folders to add.',
            );
        }

        return pageFolders;
    }

    function handlePageFolderSelection(files: File[]): void {
        if (files.length === 0) {
            return;
        }

        const parsedPageFolders = parsePageFolderSelection(files);

        if (parsedPageFolders.length === 0) {
            return;
        }

        selectedPageFolders.value = [
            ...selectedPageFolders.value,
            ...parsedPageFolders,
        ];
        bulkFolderForm.clearErrors('pageFolders');
    }

    function handlePageFolderContainerSelection(files: File[]): void {
        if (files.length === 0) {
            return;
        }

        const parsedPageFolders = parseContainerFolderSelection(files);

        if (parsedPageFolders.length === 0) {
            return;
        }

        selectedPageFolders.value = [
            ...selectedPageFolders.value,
            ...parsedPageFolders,
        ];
        bulkFolderForm.clearErrors('pageFolders');
    }

    function removeSelectedPageFolder(pageFolderKey: string): void {
        selectedPageFolders.value = selectedPageFolders.value.filter(
            (pageFolder) => pageFolder.key !== pageFolderKey,
        );
        bulkFolderForm.clearErrors('pageFolders');
    }

    function resetPageFolders(): void {
        selectedPageFolders.value = [];
    }

    return {
        bulkFolderClientError,
        bulkFolderInlineError,
        bulkFolderOutputPreview,
        handlePageFolderContainerSelection,
        handlePageFolderSelection,
        pageFoldersForDisplay,
        removeSelectedPageFolder,
        resetPageFolders,
        selectedPageFolders,
        validSelectedPageFolderCount,
    };
}
