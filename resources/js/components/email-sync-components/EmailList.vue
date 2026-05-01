<script setup lang="ts">
import { LoaderCircle, Search } from 'lucide-vue-next';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type {
    EmailRecord,
    InboxFilter,
} from '@/components/email-sync-components/types';
import EmailListItem from '@/components/email-sync-components/EmailListItem.vue';

const props = defineProps<{
    emails: EmailRecord[];
    emptyListMessage: string;
    hasMoreEmails: boolean;
    inboxFilter: InboxFilter;
    isLoadingMore: boolean;
    loadMoreButtonLabel: string;
    loadMoreError: string | null;
    searchTerm: string;
    selectedEmailId: number | null;
    totalStoredEmails: number;
}>();

const emit = defineEmits<{
    loadMore: [];
    select: [emailId: number];
    'update:inboxFilter': [value: InboxFilter];
    'update:searchTerm': [value: string];
}>();
</script>

<template>
    <aside class="flex min-h-0 flex-col border-r lg:overflow-hidden">
        <div class="border-b px-5 py-4">
            <div class="relative">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    :model-value="props.searchTerm"
                    class="h-10 rounded-2xl pl-10 text-sm"
                    type="search"
                    placeholder="Search sender, subject, body, or file"
                    @update:model-value="
                        emit('update:searchTerm', String($event))
                    "
                />
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    size="sm"
                    :variant="props.inboxFilter === 'all' ? 'default' : 'secondary'"
                    class="text-xs"
                    @click="emit('update:inboxFilter', 'all')"
                >
                    All
                </Button>
                <Button
                    type="button"
                    size="sm"
                    :variant="
                        props.inboxFilter === 'attachments'
                            ? 'default'
                            : 'secondary'
                    "
                    class="text-xs"
                    @click="emit('update:inboxFilter', 'attachments')"
                >
                    With files
                </Button>
            </div>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto p-3">
            <div
                v-if="props.emails.length === 0"
                class="rounded-2xl border border-dashed px-4 py-6 text-sm text-muted-foreground"
            >
                {{ props.emptyListMessage }}
            </div>

            <div v-else class="space-y-3">
                <EmailListItem
                    v-for="email in props.emails"
                    :key="email.id"
                    :email="email"
                    :query="props.searchTerm"
                    :selected="props.selectedEmailId === email.id"
                    @select="emit('select', $event)"
                />
            </div>

            <div class="mt-3">
                <Alert v-if="props.loadMoreError" variant="destructive" class="mb-3">
                    <AlertTitle>Load more failed</AlertTitle>
                    <AlertDescription>
                        {{ props.loadMoreError }}
                    </AlertDescription>
                </Alert>

                <Button
                    v-if="props.hasMoreEmails"
                    type="button"
                    variant="outline"
                    size="sm"
                    class="w-full text-xs"
                    :disabled="props.isLoadingMore"
                    @click="emit('loadMore')"
                >
                    <LoaderCircle
                        v-if="props.isLoadingMore"
                        class="mr-2 size-4 animate-spin"
                    />
                    {{ props.loadMoreButtonLabel }}
                </Button>

                <p
                    v-else-if="props.totalStoredEmails > 0"
                    class="px-1 text-xs text-muted-foreground"
                >
                    You are caught up with the email saved in this view.
                </p>
            </div>
        </div>
    </aside>
</template>
