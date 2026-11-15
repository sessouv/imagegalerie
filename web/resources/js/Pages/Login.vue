<script setup>
import { Head, useForm } from '@inertiajs/vue3';

defineOptions({ layout: null });

const form = useForm({ email: '', password: '', remember: true });
const submit = () => form.post('/login', { onFinish: () => form.reset('password') });
</script>

<template>
    <Head title="Connexion" />
    <div class="flex min-h-screen items-center justify-center px-4">
        <form class="card w-full max-w-sm p-8" @submit.prevent="submit">
            <h1 class="text-2xl font-semibold tracking-tight">Timbre</h1>
            <p class="mt-1 mb-6 text-sm text-black/60">Synthèse et clonage de voix</p>

            <label class="label" for="email">Adresse e-mail</label>
            <input id="email" v-model="form.email" type="email" class="field" autocomplete="username" required autofocus />
            <p v-if="form.errors.email" class="error">{{ form.errors.email }}</p>

            <label class="label mt-4" for="password">Mot de passe</label>
            <input id="password" v-model="form.password" type="password" class="field" autocomplete="current-password" required />

            <label class="mt-4 flex items-center gap-2 text-sm">
                <input v-model="form.remember" type="checkbox" class="accent-accent" /> Rester connecté
            </label>

            <button class="btn-primary mt-6 w-full" :disabled="form.processing">Se connecter</button>
        </form>
    </div>
</template>
