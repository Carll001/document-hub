import type { FormType as SharedFormType } from '@/lib/form-field-aliases';

export type FlashState = {
    success: string | null;
    error: string | null;
};

export type SortDirection = 'asc' | 'desc';

export type FormType = SharedFormType;

export type UnifiedItem = {
    id: number;
    row_number: number;
    company: string;
    tin?: string | null;
    status: string;
    row_data: Record<string, string>;
    docx_available: boolean;
    pdf_available: boolean;
    signature_applied: boolean;
    signature_applied_at: string | null;
    error_message: string | null;
    error_details?: Record<string, unknown> | null;
    source_excel_name: string;
    template_name: string;
    created_at: string | null;
    updated_at: string | null;
};

export type PaginatedResponse<T> = {
    current_page: number;
    data: T[];
    last_page: number;
    per_page: number;
    total: number;
};

export type CompletedExportState = {
    status: 'queued' | 'processing' | 'cancelling' | 'failed' | 'ready' | null;
    error: string | null;
    itemCount: number | null;
    downloadUrl: string | null;
    expiresAt?: string | null;
    batchId?: string | null;
};

export type SignatureAnchor = 'top_left' | 'top_right' | 'bottom_left' | 'bottom_right' | 'center';
export type SignaturePlacementMode = 'fixed' | 'text_anchor';

export type SignatureLayout = {
    anchor: SignatureAnchor;
    placement_mode: SignaturePlacementMode;
    anchor_text: string;
    offset_x: number;
    offset_y: number;
    width: number;
    height: number;
};

export type SignatureSettings = {
    president: {
        page2: SignatureLayout;
        page3: SignatureLayout;
    };
    getor: {
        page4: SignatureLayout;
        page8: SignatureLayout;
        preview_url: string;
    };
};

export type TemplateEntry = {
    id: number;
    year: number | null;
    template_name: string;
};

export type TemplateMappingPayload = {
    default_template: TemplateEntry | null;
    year_templates: TemplateEntry[];
};
