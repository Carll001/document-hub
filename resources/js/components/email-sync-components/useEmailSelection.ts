import { computed, ref, watch, type Ref } from 'vue';
import {
    buildBirReceiptClipboardText,
    parseBirReceiptEmailText,
} from '@/lib/bir-receipt';
import type {
    EmailRecord,
    InboxFilter,
} from '@/components/email-sync-components/types';
import {
    attachmentCountLabel,
    bodyLines,
    bodyTextForDisplay,
    emailSearchableText,
    visibleAttachments,
} from '@/components/email-sync-components/utils';

export function useEmailSelection(storedEmails: Ref<EmailRecord[]>) {
    const expandedBodyEmailIds = ref<number[]>([]);
    const searchTerm = ref('');
    const inboxFilter = ref<InboxFilter>('all');
    const selectedEmailId = ref<number | null>(storedEmails.value[0]?.id ?? null);

    const filteredEmails = computed(() => {
        const query = searchTerm.value.trim().toLowerCase();

        return storedEmails.value.filter((email) => {
            if (
                inboxFilter.value === 'attachments' &&
                visibleAttachments(email).length === 0
            ) {
                return false;
            }

            if (query === '') {
                return true;
            }

            return emailSearchableText(email).includes(query);
        });
    });

    watch(
        () => storedEmails.value.map((email) => email.id),
        (emailIds) => {
            expandedBodyEmailIds.value = expandedBodyEmailIds.value.filter((id) =>
                emailIds.includes(id),
            );
        },
        { immediate: true },
    );

    watch(
        () => filteredEmails.value.map((email) => email.id),
        (emailIds) => {
            if (
                selectedEmailId.value !== null &&
                emailIds.includes(selectedEmailId.value)
            ) {
                return;
            }

            selectedEmailId.value = emailIds[0] ?? null;
        },
        { immediate: true },
    );

    const selectedEmail = computed(
        () =>
            filteredEmails.value.find((email) => email.id === selectedEmailId.value) ??
            null,
    );
    const selectedEmailBodyLines = computed(() =>
        bodyLines(
            selectedEmail.value
                ? bodyTextForDisplay(
                      selectedEmail.value,
                      expandedBodyEmailIds.value.includes(selectedEmail.value.id),
                  )
                : null,
        ),
    );
    const selectedEmailAttachments = computed(() =>
        selectedEmail.value ? visibleAttachments(selectedEmail.value) : [],
    );
    const selectedEmailHtmlUrl = computed(() =>
        selectedEmail.value?.hasHtmlBody ? selectedEmail.value.htmlUrl : null,
    );
    const selectedEmailReceiptClipboardText = computed(() => {
        const details = parseBirReceiptEmailText(
            selectedEmail.value?.bodyText ?? selectedEmail.value?.bodyPreview ?? null,
        );

        return details ? buildBirReceiptClipboardText(details) : null;
    });
    const emptyListMessage = computed(() => {
        if (searchTerm.value.trim() !== '') {
            return 'No synced email matches your search yet.';
        }

        if (storedEmails.value.length === 0) {
            return 'No synced email yet. Sync your inbox to build the list.';
        }

        if (inboxFilter.value === 'attachments') {
            return 'No synced email with attachments in the currently loaded list.';
        }

        return 'No email available right now.';
    });
    const emptyDetailMessage = computed(() => {
        if (
            searchTerm.value.trim() !== '' ||
            inboxFilter.value === 'attachments'
        ) {
            return 'Choose a message from the filtered list once one appears.';
        }

        return 'Select a synced email to read it here.';
    });

    function setInboxFilter(filter: InboxFilter): void {
        inboxFilter.value = filter;
    }

    function selectEmail(emailId: number): void {
        selectedEmailId.value = emailId;
    }

    function isBodyExpanded(emailId: number): boolean {
        return expandedBodyEmailIds.value.includes(emailId);
    }

    function toggleBodyExpanded(emailId: number): void {
        if (isBodyExpanded(emailId)) {
            expandedBodyEmailIds.value = expandedBodyEmailIds.value.filter(
                (id) => id !== emailId,
            );

            return;
        }

        expandedBodyEmailIds.value = [...expandedBodyEmailIds.value, emailId];
    }

    return {
        attachmentCountLabel,
        emptyDetailMessage,
        emptyListMessage,
        filteredEmails,
        inboxFilter,
        isBodyExpanded,
        searchTerm,
        selectedEmail,
        selectedEmailAttachments,
        selectedEmailBodyLines,
        selectedEmailHtmlUrl,
        selectedEmailId,
        selectedEmailReceiptClipboardText,
        selectEmail,
        setInboxFilter,
        toggleBodyExpanded,
    };
}
