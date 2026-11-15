<script setup>
import { Link, usePage, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
const flash = computed(() => page.props.flash?.success);

const links = [
    { href: '/', label: 'Synthèse vocale' },
    { href: '/voices', label: 'Voix' },
    { href: '/generations', label: 'Historique' },
];

const isActive = (href) => (href === '/' ? page.url === '/' : page.url.startsWith(href));
</script>

<template>
    <div class="min-h-screen md:flex">
        <aside class="flex shrink-0 flex-col bg-ink text-white md:min-h-screen md:w-60">
            <div class="flex items-center gap-2 px-5 py-5">
                <svg viewBox="0 0 24 24" class="h-6 w-6 text-accent" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true">
                    <path d="M4 10v4M8 6v12M12 3v18M16 7v10M20 10v4" />
                </svg>
                <span class="text-lg font-semibold tracking-tight">Timbre</span>
            </div>
            <nav class="flex gap-1 overflow-x-auto px-3 pb-3 md:flex-col md:pb-0">
                <Link
                    v-for="link in links"
                    :key="link.href"
                    :href="link.href"
                    class="rounded-lg px-3 py-2 text-sm whitespace-nowrap transition"
                    :class="isActive(link.href) ? 'bg-white/15 font-medium' : 'text-white/70 hover:bg-white/10 hover:text-white'"
                >
                    {{ link.label }}
                </Link>
            </nav>
            <div class="mt-auto hidden border-t border-white/10 px-5 py-4 text-sm md:block">
                <div class="truncate text-white/80">{{ user?.name }}</div>
                <button class="mt-1 text-white/50 hover:text-white" @click="router.post('/logout')">Se déconnecter</button>
            </div>
        </aside>

        <main class="min-w-0 flex-1 px-4 py-6 md:px-10 md:py-8">
            <div v-if="flash" class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
                {{ flash }}
            </div>
            <slot />
            <button class="mt-8 text-sm text-black/50 underline md:hidden" @click="router.post('/logout')">Se déconnecter</button>
        </main>
    </div>
</template>
