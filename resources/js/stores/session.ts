import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { api, configureApi, HttpError } from '../api';
import type { Branch, SessionUser } from '../types';

export const useSessionStore = defineStore('session', () => {
    const user = ref<SessionUser | null>(null);
    const organizationId = ref<number | null>(null);
    const branchId = ref<number | null>(null);
    const authenticated = computed(() => user.value !== null);
    const name = computed(() => user.value?.name ?? '');
    const branches = computed<Branch[]>(() => user.value?.branches.filter(
        (branch) => branch.organization_id === organizationId.value && branch.active,
    ) ?? []);
    const branch = computed(() => branches.value.find((item) => item.id === branchId.value)?.name ?? '');

    function selectTenant(organization: number, selectedBranch: number) {
        const allowedOrganization = user.value?.organizations.some((item) => item.id === organization);
        const allowedBranch = user.value?.branches.some(
            (item) => item.id === selectedBranch && item.organization_id === organization && item.active,
        );
        if (!allowedOrganization || !allowedBranch) throw new Error('Tenant no autorizado.');
        organizationId.value = organization;
        branchId.value = selectedBranch;
        configureApi({ organizationId: organization, branchId: selectedBranch });
    }

    function acceptUser(current: SessionUser) {
        user.value = current;
        const organization = current.organizations[0]?.id;
        const selectedBranch = current.branches.find((item) => item.organization_id === organization && item.active)?.id;
        if (organization && selectedBranch) selectTenant(organization, selectedBranch);
    }

    async function login(email: string, password: string) {
        await api.csrf();
        acceptUser(await api.login(email, password));
    }

    async function recover() {
        try {
            await api.csrf();
            acceptUser(await api.me());
        } catch (error) {
            if (error instanceof HttpError && error.status === 401) {
                user.value = null;
                return;
            }
            throw error;
        }
    }

    async function logout() {
        await api.logout();
        user.value = null;
        organizationId.value = null;
        branchId.value = null;
        configureApi({ organizationId: null, branchId: null });
    }

    return {
        user, name, organizationId, branchId, branch, branches, authenticated,
        login, recover, logout, selectTenant,
    };
});
