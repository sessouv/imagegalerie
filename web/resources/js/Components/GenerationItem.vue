<script setup>
import { router } from '@inertiajs/vue3';
import { duration, dateTime } from '../format';

const props = defineProps({ generation: { type: Object, required: true } });
defineEmits(['reuse']);

const remove = () => {
    if (confirm('Supprimer cet audio ?')) {
        router.delete(`/generations/${props.generation.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <article class="card p-4">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-black/55">
            <span class="rounded-full bg-accent/10 px-2 py-0.5 font-medium text-accent">{{ generation.voice_name }}</span>
            <span class="uppercase">{{ generation.language }}</span>
            <span v-if="generation.duration_ms">{{ duration(generation.duration_ms) }}</span>
            <span>{{ dateTime(generation.created_at) }}</span>
            <span v-if="generation.author">par {{ generation.author }}</span>
        </div>

        <p class="mt-2 line-clamp-3 text-sm whitespace-pre-line">{{ generation.text }}</p>

        <div v-if="generation.status === 'completed'" class="mt-3 flex flex-wrap items-center gap-3">
            <audio :src="generation.audio_url" controls preload="none" class="h-10 min-w-0 flex-1"></audio>
            <a :href="`${generation.audio_url}?download=1`" class="btn-ghost">Télécharger</a>
        </div>
        <div v-else-if="generation.status === 'failed'" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
            Échec : {{ generation.error }}
        </div>
        <div v-else class="mt-3 flex items-center gap-2 text-sm text-black/60" role="status">
            <span class="h-4 w-4 animate-spin rounded-full border-2 border-accent border-t-transparent"></span>
            {{ generation.status === 'processing' ? 'Génération en cours…' : 'En file d\'attente…' }}
        </div>

        <div class="mt-3 flex gap-4 text-xs">
            <button class="text-black/60 underline hover:text-black" @click="$emit('reuse', generation)">Réutiliser le texte</button>
            <button class="text-red-600/80 underline hover:text-red-700" @click="remove">Supprimer</button>
        </div>
    </article>
</template>
