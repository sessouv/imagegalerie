<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';
import { duration, dateTime } from '../format';

const props = defineProps({ transcriptions: Array, languages: Object, maxMb: Number });

const form = useForm({ file: null, language: null });
const fileInput = ref(null);
const open = ref(new Set());
const copied = ref(null);

const submit = () => form.post('/transcriptions', {
    preserveScroll: true,
    onSuccess: () => {
        form.reset();
        if (fileInput.value) fileInput.value.value = '';
    },
});

// Rafraîchit la liste tant qu'une transcription est en cours
const busy = computed(() => props.transcriptions.some((t) => ['pending', 'processing'].includes(t.status)));
let timer = null;
watch(busy, (value) => {
    clearInterval(timer);
    if (value) timer = setInterval(() => router.reload({ only: ['transcriptions'] }), 3000);
}, { immediate: true });
onUnmounted(() => clearInterval(timer));

// Un résultat qui vient d'arriver s'affiche déplié
watch(() => props.transcriptions.filter((t) => t.status === 'completed').map((t) => t.id), (now, before) => {
    now.filter((id) => !before.includes(id)).forEach((id) => open.value.add(id));
});

const toggle = (id) => (open.value.has(id) ? open.value.delete(id) : open.value.add(id));

async function copy(transcription) {
    try {
        await navigator.clipboard.writeText(transcription.text);
        copied.value = transcription.id;
        setTimeout(() => { copied.value = null; }, 2000);
    } catch {
        alert('Copie impossible : sélectionnez le texte à la main.');
    }
}

function toStudio(transcription) {
    sessionStorage.setItem('timbre:draft', JSON.stringify({
        text: transcription.text,
        language: props.languages[transcription.language] ? transcription.language : 'fr',
        voice_id: null,
        settings: {},
    }));
    router.visit('/');
}

const retry = (t) => router.post(`/transcriptions/${t.id}/retry`, {}, { preserveScroll: true });
const remove = (t) => {
    if (confirm(`Supprimer la transcription de « ${t.name} » ?`)) {
        router.delete(`/transcriptions/${t.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Transcription" />

    <h1 class="text-2xl font-semibold tracking-tight">Transcription</h1>
    <p class="mt-1 text-sm text-black/60">Transformez un fichier audio ou vidéo en texte et en sous-titres.</p>

    <form class="card mt-6 flex max-w-3xl flex-wrap items-end gap-4 p-5" @submit.prevent="submit">
        <div class="min-w-0 flex-1">
            <label class="label" for="file">Fichier audio ou vidéo</label>
            <input
                id="file"
                ref="fileInput"
                type="file"
                accept="audio/*,video/*"
                class="block w-full text-sm file:mr-3 file:rounded-lg file:border file:border-black/10 file:bg-white file:px-3 file:py-2 file:text-sm file:font-medium hover:file:bg-black/5"
                @change="form.file = $event.target.files[0] ?? null"
            />
            <p class="mt-1 text-xs text-black/55">{{ maxMb }} Mo maximum.</p>
        </div>
        <div>
            <label class="label" for="tlanguage">Langue parlée</label>
            <select id="tlanguage" v-model="form.language" class="field">
                <option :value="null">Détection automatique</option>
                <option v-for="(label, code) in languages" :key="code" :value="code">{{ label }}</option>
            </select>
        </div>
        <button class="btn-primary" :disabled="form.processing || !form.file">
            {{ form.processing ? `Envoi… ${form.progress?.percentage ?? 0} %` : 'Transcrire' }}
        </button>
        <p v-if="form.errors.file" class="error w-full">{{ form.errors.file }}</p>
    </form>

    <p v-if="!transcriptions.length" class="mt-6 text-sm text-black/50">Aucune transcription pour le moment.</p>

    <div class="mt-6 space-y-3">
        <article v-for="t in transcriptions" :key="t.id" class="card p-4">
            <h2 class="font-medium break-words">{{ t.name }}</h2>
            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-black/55">
                <span v-if="t.language" class="uppercase">{{ t.language }}</span>
                <span v-if="t.duration_ms">{{ duration(t.duration_ms) }}</span>
                <span>{{ dateTime(t.created_at) }}</span>
                <span v-if="t.author">par {{ t.author }}</span>
            </div>

            <div v-if="t.status === 'failed'" class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                <p>Échec : {{ t.error }}</p>
                <button class="btn mt-2 bg-red-600 text-white hover:bg-red-700" @click="retry(t)">Relancer</button>
            </div>

            <div v-else-if="t.status !== 'completed'" class="mt-3" role="status">
                <div class="flex items-center gap-2 text-sm text-black/60">
                    <span class="h-4 w-4 animate-spin rounded-full border-2 border-accent border-t-transparent"></span>
                    {{ t.status === 'processing' ? `Transcription en cours… ${t.progress} %` : 'En file d\'attente…' }}
                </div>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-black/10">
                    <div class="h-full rounded-full bg-accent transition-all" :style="{ width: `${t.progress}%` }"></div>
                </div>
            </div>

            <template v-else>
                <p v-if="!t.text" class="mt-3 text-sm text-black/55">Aucune parole détectée dans ce fichier.</p>
                <p v-else class="mt-3 text-sm whitespace-pre-line" :class="open.has(t.id) ? '' : 'line-clamp-3'">{{ t.text }}</p>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <button v-if="t.text" class="btn-ghost" @click="copy(t)">{{ copied === t.id ? 'Copié' : 'Copier le texte' }}</button>
                    <a v-if="t.text" :href="`/transcriptions/${t.id}/download/txt`" class="btn-ghost">Texte (.txt)</a>
                    <a v-if="t.text" :href="`/transcriptions/${t.id}/download/srt`" class="btn-ghost">Sous-titres (.srt)</a>
                    <button v-if="t.text" class="btn-ghost" @click="toStudio(t)">Envoyer vers la synthèse</button>
                </div>
            </template>

            <div class="mt-3 flex gap-4 text-xs">
                <button v-if="t.status === 'completed' && t.text" class="text-accent underline" :aria-expanded="open.has(t.id)" @click="toggle(t.id)">
                    {{ open.has(t.id) ? 'Réduire le texte' : 'Afficher tout le texte' }}
                </button>
                <button class="text-red-600/80 underline hover:text-red-700" @click="remove(t)">Supprimer</button>
            </div>
        </article>
    </div>
</template>
