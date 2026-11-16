<script setup>
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { duration, dateTime } from '../format';
import SegmentList from './SegmentList.vue';

const props = defineProps({
    generation: { type: Object, required: true },
    projects: { type: Array, default: () => [] },
});
defineEmits(['reuse']);

const showSegments = ref(false);
const running = computed(() => ['pending', 'processing'].includes(props.generation.status));
const percent = computed(() => {
    const { segments_done: done, segments_total: total } = props.generation;
    return total ? Math.round((done / total) * 100) : 0;
});

const remove = () => {
    const message = running.value ? 'Arrêter cette génération et la supprimer ?' : 'Supprimer cet audio ?';
    if (confirm(message)) {
        router.delete(`/generations/${props.generation.id}`, { preserveScroll: true });
    }
};

const projectName = computed(() => props.projects.find((p) => p.id === props.generation.project_id)?.name);
const move = (event) => router.patch(
    `/generations/${props.generation.id}`,
    { project_id: event.target.value === '' ? null : Number(event.target.value) },
    { preserveScroll: true },
);

const rename = () => {
    const title = prompt('Nom de l\'audio (il sert aussi de nom au fichier téléchargé) :', props.generation.title ?? '');
    if (title !== null) {
        router.patch(`/generations/${props.generation.id}`, { title }, { preserveScroll: true });
    }
};

const retry = () => router.post(`/generations/${props.generation.id}/retry`, {}, { preserveScroll: true });
</script>

<template>
    <article class="card p-4">
        <h3 v-if="generation.title" class="mb-1 font-medium">{{ generation.title }}</h3>
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-black/55">
            <span class="rounded-full bg-accent/10 px-2 py-0.5 font-medium text-accent">{{ generation.voice_name }}</span>
            <span class="uppercase">{{ generation.language }}</span>
            <span v-if="generation.duration_ms && generation.status === 'completed'">{{ duration(generation.duration_ms) }}</span>
            <span>{{ dateTime(generation.created_at) }}</span>
            <span v-if="generation.author">par {{ generation.author }}</span>
            <span v-if="projectName" class="rounded-full bg-black/5 px-2 py-0.5 text-black/70">{{ projectName }}</span>
        </div>

        <p class="mt-2 line-clamp-3 text-sm whitespace-pre-line">{{ generation.text }}</p>

        <div v-if="generation.status === 'completed'" class="mt-3 flex flex-wrap items-center gap-3">
            <audio :key="generation.audio_url" :src="generation.audio_url" controls preload="none" class="h-10 min-w-0 flex-1"></audio>
            <a :href="`${generation.audio_url}&download=mp3`" class="btn-ghost">Télécharger{{ generation.wav_url ? ' MP3' : '' }}</a>
            <a v-if="generation.wav_url" :href="generation.wav_url" class="btn-ghost">WAV</a>
        </div>

        <div v-else-if="generation.status === 'failed'" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
            <p>Échec : {{ generation.error }}</p>
            <div v-if="generation.segments_total" class="mt-2 flex flex-wrap items-center gap-3">
                <button class="btn bg-red-600 text-white hover:bg-red-700" @click="retry">Reprendre</button>
                <span class="text-red-700/80">{{ generation.segments_done }} passage(s) sur {{ generation.segments_total }} déjà prêts, ils ne seront pas refaits.</span>
            </div>
        </div>

        <div v-else class="mt-3" role="status">
            <div class="flex items-center gap-2 text-sm text-black/60">
                <span class="h-4 w-4 animate-spin rounded-full border-2 border-accent border-t-transparent"></span>
                <template v-if="generation.segments_total > 1">
                    {{ generation.segments_done < generation.segments_total
                        ? `${generation.segments_done} passage(s) prêts sur ${generation.segments_total}…`
                        : 'Assemblage de l\'audio…' }}
                </template>
                <template v-else>{{ generation.status === 'processing' ? 'Génération en cours…' : 'En file d\'attente…' }}</template>
            </div>
            <div v-if="generation.segments_total > 1" class="mt-2 h-1.5 overflow-hidden rounded-full bg-black/10">
                <div class="h-full rounded-full bg-accent transition-all" :style="{ width: `${percent}%` }"></div>
            </div>
        </div>

        <div class="mt-3 flex flex-wrap gap-4 text-xs">
            <button v-if="generation.segments_total" class="text-accent underline" :aria-expanded="showSegments" @click="showSegments = !showSegments">
                {{ showSegments ? 'Masquer les passages' : `Voir les ${generation.segments_total} passage(s)` }}
            </button>
            <button class="text-black/60 underline hover:text-black" @click="$emit('reuse', generation)">Réutiliser le texte</button>
            <button class="text-black/60 underline hover:text-black" @click="rename">{{ generation.title ? 'Renommer' : 'Nommer' }}</button>
            <label v-if="projects.length" class="flex items-center gap-1 text-black/60">
                Projet
                <select class="rounded border border-black/15 bg-white px-1 py-0.5" :value="generation.project_id ?? ''" @change="move">
                    <option value="">Aucun</option>
                    <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.name }}</option>
                </select>
            </label>
            <button class="text-red-600/80 underline hover:text-red-700" @click="remove">{{ running ? 'Arrêter et supprimer' : 'Supprimer' }}</button>
        </div>

        <SegmentList v-if="showSegments" :generation="generation" />
    </article>
</template>
