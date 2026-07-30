export type View =
    | 'dashboard'
    | 'customers'
    | 'products'
    | 'locations'
    | 'lots'
    | 'orders'
    | 'recipes'
    | 'production'
    | 'payments'
    | 'procurement'
    | 'finance'
    | 'alerts';

export type OrderStatus =
    | 'draft'
    | 'confirmed'
    | 'in_production'
    | 'ready'
    | 'delivered'
    | 'cancelled';

export interface OrderItemInput {
    product_id: number;
    quantity: string;
    unit_price: string;
}

export interface CreateOrderInput {
    customer_name: string;
    required_at?: string;
    items: OrderItemInput[];
}

export interface OrganizationMembership {
    id: number;
    name: string;
    pivot: { role: string };
}

export interface Branch {
    id: number;
    organization_id: number;
    name: string;
    active: boolean;
}

export interface SessionUser {
    id: number;
    name: string;
    email: string;
    organizations: OrganizationMembership[];
    branches: Branch[];
}

export interface Order {
    id: number;
    organization_id: number;
    customer_name: string;
    required_at?: string | null;
    status: OrderStatus;
    total: string;
    paid_total: string;
    items?: OrderItemInput[];
}

export interface Payment {
    id: number;
    order_id: number;
    amount: string;
    method: 'cash' | 'transfer' | 'mercadopago' | 'card';
    external_reference?: string;
    cash_session_id?: number | null;
}

export interface DashboardMetrics {
    orders_total: number;
    orders_today: number;
    overdue_orders: number;
    pending_production: number;
    outstanding_balance: string;
    open_alerts: number;
    critical_stock: number;
}

export interface DashboardAlert {
    id: number;
    event: string;
    action: string;
    severity: string;
    status: string;
}

export interface DashboardSummary {
    metrics: DashboardMetrics;
    recent_orders: Order[];
    open_alerts: DashboardAlert[];
}

export interface ApiEnvelope<T> {
    data: T;
}

export interface DataTableMeta {
    current_page: number;
    from: number | null;
    last_page: number;
    per_page: number;
    to: number | null;
    total: number;
}

export interface DataTableResponse<T> {
    data: T[];
    meta: DataTableMeta;
}

export interface DataTableQuery {
    page: number;
    per_page: number;
    search: string;
    sort: string;
    direction: 'asc' | 'desc';
    filters: Record<string, string>;
}

export interface DataTableColumn<T extends Record<string, unknown> = Record<string, unknown>> {
    key: string;
    label: string;
    sortable?: boolean;
    align?: 'start' | 'end';
    priority?: 'primary' | 'secondary';
    render?: (row: T) => string;
}

export interface DataTableFilter {
    key: string;
    label: string;
    options: Array<{ label: string; value: string }>;
}

export interface DataTableRowAction {
    key: string;
    label: string;
    tone?: 'default' | 'danger';
    disabled?: boolean;
}

export interface ApiProblem {
    message: string;
    status: number;
    errors: Record<string, string[]>;
}
