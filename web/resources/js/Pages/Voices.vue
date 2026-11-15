<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onUnmounted, ref } from 'vue';
import { dateTime } from '../format';

const props = defineProps({ voices: Array, languages: Object, maxSampleMb: Number });

const form = useForm({ name: '', description: '', language: 'fr', sample: null, consent: false });

const fileInput = ref(null);
const previewUrl = ref(null);
const sampleSeconds = ref(null);
const recording = ref(false);
const elapsed = ref(0);
const micError = ref('');
let recorder = null;
let stream = null;
let ticker = null;

const scripts = {
    fr: 'Bonjour, je m\'appelle Camille et j\'enregistre cet échantillon pour créer ma voix. Aujourd\'hui, le ciel est dégagé, un léger vent souffle sur la ville et les terrasses se remplissent peu à peu. Est-ce que tu as déjà goûté ce café ? Franchement, il est excellent !',
    en: 'Hello, my name is Alex and I am recording this sample to create my voice. Today the sky is clear, a light breeze drifts across the city, and the cafés are slowly filling up. Have you tried this coffee yet? Honestly, it is excellent!',
};

const tooShort = computed(() => sampleSeconds.value !== null && sampleSeconds.value < 5);

function setSample(file) {
    form.sample = file;
    form.clearErrors('sample');
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = file ? URL.createObjectURL(file) : null;
    sampleSeconds.value = null;
}

function onMetadata(event) {
    // Les enregistrements du navigateur n'annoncent pas toujours leur durée
    const value = event.target.duration;
    sampleSeconds.value = Number.isFinite(value) ? value : (elapsed.value || null);
}

async function startRecording() {
    micError.value = '';
    try {
        stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch {
        micError.value = 'Accès au micro refusé ou indisponible. Importez plutôt un fichier.';
        return;
    }
    const chunks = [];
    recorder = new MediaRecorder(stream);
    recorder.ondataavailable = (e) => e.data.size && chunks.push(e.data);
    recorder.onstop = () => {
        const type = recorder.mimeType || 'audio/webm';
        const ext = type.includes('mp4') ? 'm4a' : type.includes('ogg') ? 'ogg' : 'webm';
        setSample(new File(chunks, `enregistrement.${ext}`, { type }));
        stopStream();
    };
    recorder.start();
    recording.value = true;
    elapsed.value = 0;
    ticker = setInterval(() => {
        elapsed.value += 1;
        if (elapsed.value >= 30) stopRecording();
    }, 1000);
}

function stopRecording() {
    clearInterval(ticker);
    recording.value = false;
    if (recorder?.state === 'recording') recorder.stop();
}

function stopStream() {
    stream?.getTracks().forEach((track) => track.stop());
    stream = null;
}

onUnmounted(() => {
    clearInterval(ticker);
    stopStream();
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
});

const submit = () => form.post('/voices', {
    preserveScroll: true,
    onSuccess: () => {
        form.reset();
        setSample(null);
        if (fileInput.value) fileInput.value.value = '';
    },
});

const remove = (voice) => {
    if (confirm(`Supprimer la voix « ${voice.name} » ? Les audios déjà générés sont conservés.`)) {
        router.delete(`/voices/${voice.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Voix" />

    <h1 class="text-2xl font-semibold tracking-tight">Voix</h1>
    <p class="mt-1 text-sm text-black/60">Clonez une voix à partir d'un court échantillon audio, puis utilisez-la dans la synthèse.</p>

    <div class="mt-6 grid gap-6 lg:grid-cols-[24rem_1fr]">
        <form class="card h-fit space-y-4 p-5" @submit.prevent="submit">
            <h2 class="text-lg font-semibold">Cloner une voix</h2>

            <div>
                <label class="label" for="name">Nom</label>
                <input id="name" v-model="form.name" class="field" maxlength="80" required />
                <p v-if="form.errors.name" class="error">{{ form.errors.name }}</p>
            </div>

            <div>
                <label class="label" for="vlanguage">Langue parlée dans l'échantillon</label>
                <select id="vlanguage" v-model="form.language" class="field">
                    <option v-for="(label, code) in languages" :key="code" :value="code">{{ label }}</option>
                </select>
            </div>

            <div>
                <label class="label" for="description">Description (facultatif)</label>
                <input id="description" v-model="form.description" class="field" maxlength="500" placeholder="Ex. voix grave, ton calme" />
            </div>

            <div>
                <span class="label">Échantillon audio</span>
                <p class="mb-2 text-xs text-black/55">
                    10 à 20 secondes d'une seule personne, sans musique ni bruit de fond. {{ maxSampleMb }} Mo maximum.
                </p>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="btn-ghost" :disabled="recording" @click="fileInput.click()">Importer un fichier</button>
                    <button v-if="!recording" type="button" class="btn-ghost" @click="startRecording">Enregistrer au micro</button>
                    <button v-else type="button" class="btn bg-red-600 text-white hover:bg-red-700" @click="stopRecording">
                        Arrêter ({{ elapsed }} s)
                    </button>
                </div>
                <input ref="fileInput" type="file" accept="audio/*,.m4a,.webm" class="hidden" @change="setSample($event.target.files[0] ?? null)" />

                <div v-if="recording" class="mt-3 rounded-lg bg-black/5 p-3 text-sm">
                    <div class="mb-1 text-xs font-medium text-black/55">Texte à lire à voix haute</div>
                    {{ scripts[form.language] }}
                </div>

                <audio v-if="previewUrl" :src="previewUrl" controls class="mt-3 h-10 w-full" @loadedmetadata="onMetadata"></audio>
                <p v-if="tooShort" class="mt-1 text-sm text-amber-700">
                    Échantillon de {{ Math.round(sampleSeconds) }} s : le clonage sera plus fidèle avec au moins 10 secondes.
                </p>
                <p v-if="micError" class="error">{{ micError }}</p>
                <p v-if="form.errors.sample" class="error">{{ form.errors.sample }}</p>
            </div>

            <label class="flex items-start gap-2 text-sm">
                <input v-model="form.consent" type="checkbox" class="mt-0.5 accent-accent" />
                <span>Je confirme qu'il s'agit de ma voix, ou que la personne enregistrée m'a donné son accord pour la cloner.</span>
            </label>
            <p v-if="form.errors.consent" class="error">{{ form.errors.consent }}</p>

            <button class="btn-primary w-full" :disabled="form.processing || recording || !form.sample || !form.consent">
                {{ form.processing ? 'Envoi…' : 'Ajouter la voix' }}
            </button>
        </form>

        <section>
            <h2 class="text-lg font-semibold">Bibliothèque ({{ voices.length }})</h2>
            <p v-if="!voices.length" class="mt-3 text-sm text-black/50">Aucune voix clonée pour le moment.</p>
            <div class="mt-3 grid gap-3 xl:grid-cols-2">
                <article v-for="voice in voices" :key="voice.id" class="card p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 class="truncate font-medium">{{ voice.name }}</h3>
                            <p class="text-xs text-black/55">
                                {{ languages[voice.language] }} · ajoutée le {{ dateTime(voice.created_at) }}
                                <template v-if="voice.author"> par {{ voice.author }}</template>
                            </p>
                        </div>
                        <button class="text-xs text-red-600/80 underline hover:text-red-700" @click="remove(voice)">Supprimer</button>
                    </div>
                    <p v-if="voice.description" class="mt-2 text-sm text-black/70">{{ voice.description }}</p>
                    <audio :src="voice.sample_url" controls preload="none" class="mt-3 h-10 w-full"></audio>
                </article>
            </div>
        </section>
    </div>
</template>
