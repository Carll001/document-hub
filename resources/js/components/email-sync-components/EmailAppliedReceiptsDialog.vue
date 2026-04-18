<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { EmailRecord } from '@/components/email-sync-components/types';
import { emailMatchStatusLabel } from '@/components/email-sync-components/utils';

const props = defineProps<{
    emails: EmailRecord[];
    open: boolean;
    pageUrl: string;
    pagination: {
        currentPage: number;
        lastPage: number;
        perPage: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    formTypeFilter: string;
    accountFilterIds: number[];
    unmatchedPage: number;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const paginationControls = ref<HTMLElement | null>(null);

function visitPage(page: number): void {
    if (page === props.pagination.currentPage) {
        return;
    }

    router.get(
        props.pageUrl,
        {
            page: props.unmatchedPage,
            appliedPage: page,
            formType: props.formTypeFilter || undefined,
            accountIds: props.accountFilterIds.length > 0 ? props.accountFilterIds : undefined,
        },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['appliedEmails', 'appliedPagination', 'receiptCounts', 'flash', 'filters'],
        },
    );
}
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-5xl">
            <DialogHeader class="space-y-2">
                <DialogTitle>Applied receipts</DialogTitle>
                <DialogDescription>
                    These BIR receipt emails were already auto-applied to their
                    matching 1702-EX rows.
                </DialogDescription>
            </DialogHeader>

            <div class="overflow-hidden rounded-2xl border bg-background">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>ACCOUNT</TableHead>
                            <TableHead>TIN</TableHead>
                            <TableHead>FILE NAME</TableHead>
                            <TableHead>FORM TYPE</TableHead>
                            <TableHead>DATE RECEIVED</TableHead>
                            <TableHead>TIME RECEIVED</TableHead>
                            <TableHead>MATCH STATUS</TableHead>
                        </TableRow>
                    </TableHeader>

                    <TableBody>
                        <template v-if="props.emails.length > 0">
                            <TableRow
                                v-for="email in props.emails"
                                :key="email.id"
                            >
                                <TableCell class="text-sm text-muted-foreground">
                                    <div class="space-y-1">
                                        <p class="font-medium text-foreground">
                                            {{ email.accountLabel }}
                                        </p>
                                        <p v-if="email.accountEmail">
                                            {{ email.accountEmail }}
                                        </p>
                                    </div>
                                </TableCell>
                                <TableCell class="font-medium text-foreground">
                                    {{ email.matchedTin || '-' }}
                                </TableCell>
                                <TableCell
                                    class="max-w-[26rem] truncate text-sm text-foreground"
                                    :title="
                                        email.parsedBirReceiptDetails.fileName ||
                                        undefined
                                    "
                                >
                                    {{
                                        email.parsedBirReceiptDetails.fileName ||
                                        '-'
                                    }}
                                </TableCell>
                                <TableCell class="text-sm text-muted-foreground">
                                    {{
                                        email.parsedBirReceiptDetails.formType ||
                                        'Not detected'
                                    }}
                                </TableCell>
                                <TableCell class="text-sm text-muted-foreground">
                                    {{
                                        email.parsedBirReceiptDetails.dateReceived ||
                                        '-'
                                    }}
                                </TableCell>
                                <TableCell class="text-sm text-muted-foreground">
                                    {{
                                        email.parsedBirReceiptDetails.timeReceived ||
                                        '-'
                                    }}
                                </TableCell>
                                <TableCell>
                                    <Badge
                                        variant="secondary"
                                        class="rounded-full"
                                    >
                                        {{
                                            emailMatchStatusLabel(
                                                email.matchStatus,
                                            )
                                        }}
                                    </Badge>
                                </TableCell>
                            </TableRow>
                        </template>

                        <TableEmpty v-else :colspan="7">
                            No applied BIR receipts yet.
                        </TableEmpty>
                    </TableBody>
                </Table>
            </div>

            <div
                v-if="props.pagination.lastPage > 1"
                ref="paginationControls"
                class="mt-4 flex items-center justify-between gap-3 border-t pt-3"
            >
                <p class="text-xs text-muted-foreground">
                    Showing
                    {{ props.pagination.from ?? 0 }}
                    to
                    {{ props.pagination.to ?? 0 }}
                    of
                    {{ props.pagination.total }}
                    applied receipts.
                </p>

                <div class="flex items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="props.pagination.currentPage <= 1"
                        @click="visitPage(props.pagination.currentPage - 1)"
                    >
                        Previous
                    </Button>
                    <span class="text-sm">Page {{ props.pagination.currentPage }} / {{ props.pagination.lastPage }}</span>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="
                            props.pagination.currentPage >=
                            props.pagination.lastPage
                        "
                        @click="visitPage(props.pagination.currentPage + 1)"
                    >
                        Next
                    </Button>
                </div>
            </div>

            <DialogFooter>
                <Button
                    type="button"
                    variant="secondary"
                    @click="emit('update:open', false)"
                >
                    Close
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
