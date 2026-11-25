import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * ¿Quien mira puede ver dinero? (permiso money.view). El rol semiadmin no lo
 * tiene: autoriza lo humano sin ver sueldos ni montos (Luis 2026-11-25). El
 * servidor ya no le manda los montos; esto solo esconde los recuadros vacíos.
 */
export function useCanSeeMoney() {
    const page = usePage();

    return computed(() => (page.props.auth?.permissions ?? []).includes('money.view'));
}
