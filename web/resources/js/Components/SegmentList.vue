<script setup>
import { Link, router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { api } from '../api';

const props = defineProps({ generation: { type: Object, required: true } });

const segments = ref(null);
const error = ref('');
const editing = ref(null);
const draft = ref('');
const sending = ref(null);
let timer = null;

const base = `/generations/${props.generation.id}/segments`;

async function load() {
    try {
        segments.value = (await api(base)).segments;
        error.value = '';
    } catch (e) {
        error.value = e.message;
    }
}

function schedule() {
    clearInterval(timer);
    if (['pending', 'processing'].includes(props.generation.status)) {
        timer = setInterval(load, 3000);
    }
}

onMounted(() => { load(); schedule(); });
onUnmounted(() => clearInterval(timer));
// La génération vient de changer d'état (terminée, relancée…) : on resynchronise
watch(() => [props.generation.status, props.generation.segments_done], () => { load(); schedule(); });

function edit(segment) {
    editing.value = segment.id;
    draft.value = segment.text;
}

async function regenerate(segment, text) {
    sending.value = segment.id;
    try {
        segments.value = (await api(`${base}/${segment.id}/regenerate`, { method: 'POST', body: { text } })).segments;
        editing.value = null;
        error.value = '';
        router.reload({ only: ['generations'] });
    } catch (e) {
        error.value = e.message;
    } finally {
        sending.value = null;
    }
}
</script>

<template>
    <div class="mt-3 border-t border-black/10 pt-3">
        <p class="text-xs text-black/55">
            Un passage mal prononcé ? Régénérez-le seul, ou corrigez son texte. Pour un mot qui revient souvent, ajoutez-le
            plutôt au <Link href="/pronunciations" class="underline">dictionnaire de prononciation</Link>, puis régénérez le passage.
            L'audio complet est réassemblé ensuite.
        </p>
        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="segments === null && !error" class="mt-2 text-sm text-black/50">Chargement…</p>

        <ol class="mt-2 divide-y divide-black/5">
            <li v-for="segment in segments" :key="segment.id" class="flex gap-3 py-3">
                <span class="w-6 shrink-0 pt-0.5 text-right text-xs text-black/40">{{ segment.position + 1 }}</span>
                <div class="min-w-0 flex-1">
                    <template v-if="editing === segment.id">
                        <label :for="`segment-${segment.id}`" class="sr-only">Texte du passage</label>
                        <textarea :id="`segment-${segment.id}`" v-model="draft" rows="3" maxlength="1000" class="field"></textarea>
                        <div class="mt-2 flex gap-2">
                            <button class="btn-primary" :disabled="sending === segment.id || !draft.trim()" @click="regenerate(segment, draft)">
                                Régénérer avec ce texte
                            </button>
                            <button class="btn-ghost" @click="editing = null">Annuler</button>
                        </div>
                    </template>
                    <template v-else>
                        <p class="text-sm">{{ segment.text }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2">
                            <audio v-if="segment.status === 'completed'" :src="segment.audio_url" controls preload="none" class="h-9 w-64 max-w-full"></audio>
                            <span v-else-if="segment.status === 'failed'" class="text-sm text-red-700">Échec{{ segment.error ? ` : ${segment.error}` : '' }}</span>
                            <span v-else class="flex items-center gap-2 text-sm text-black/60" role="status">
                                <span v-if="segment.status === 'processing'" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-accent border-t-transparent"></span>
                                {{ segment.status === 'processing' ? 'Génération…' : 'En attente' }}
                            </span>
                            <template v-if="segment.status === 'completed' || segment.status === 'failed'">
                                <button class="text-xs text-accent underline" :disabled="sending === segment.id" @click="regenerate(segment, segment.text)">Régénérer</button>
                                <button class="text-xs text-black/60 underline hover:text-black" @click="edit(segment)">Modifier le texte</button>
                            </template>
                        </div>
                    </template>
                </div>
            </li>
        </ol>
    </div>
</template>
