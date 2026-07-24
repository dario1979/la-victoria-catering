export type View =
    | 'dashboard'
    | 'customers'
    | 'products'
    | 'lots'
    | 'orders'
    | 'production'
    | 'payments'
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
}

export interface ApiEnvelope<T> {
    data: T;
}

export interface ApiProblem {
    message: string;
    status: number;
    errors: Record<string, string[]>;
}
