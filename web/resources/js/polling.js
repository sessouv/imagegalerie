import { router } from '@inertiajs/vue3';
import { computed, onUnmounted, watch } from 'vue';

const running = (generation) => ['pending', 'processing'].includes(generation.status);

// Rafraîchit la liste tant qu'au moins un audio est en cours de génération
export function useGenerationPolling(list) {
    const busy = computed(() => list().some(running));
    let timer = null;

    watch(busy, (value) => {
        clearInterval(timer);
        if (value) {
            timer = setInterval(() => router.reload({ only: ['generations'] }), 3000);
        }
    }, { immediate: true });

    onUnmounted(() => clearInterval(timer));
}
