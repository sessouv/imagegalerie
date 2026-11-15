<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import GenerationItem from '../Components/GenerationItem.vue';

defineProps({ generations: Object });

const reuse = (generation) => {
    sessionStorage.setItem('timbre:draft', JSON.stringify(generation));
    router.visit('/');
};
</script>

<template>
    <Head title="Historique" />

    <h1 class="text-2xl font-semibold tracking-tight">Historique</h1>
    <p class="mt-1 text-sm text-black/60">{{ generations.total }} audio(s) générés par l'équipe.</p>

    <p v-if="!generations.data.length" class="mt-6 text-sm text-black/50">Aucun audio pour le moment.</p>
    <div class="mt-6 space-y-3">
        <GenerationItem v-for="generation in generations.data" :key="generation.id" :generation="generation" @reuse="reuse" />
    </div>

    <nav v-if="generations.prev_page_url || generations.next_page_url" class="mt-6 flex items-center gap-3 text-sm">
        <Link v-if="generations.prev_page_url" :href="generations.prev_page_url" class="btn-ghost">Précédent</Link>
        <span class="text-black/50">Page {{ generations.current_page }} / {{ generations.last_page }}</span>
        <Link v-if="generations.next_page_url" :href="generations.next_page_url" class="btn-ghost">Suivant</Link>
    </nav>
</template>
