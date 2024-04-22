<script setup lang="ts">
import { onMounted } from 'vue';
import { useApiStore } from '@/stores/api.ts';
import AuthModal from '@/components/modals/AuthModal.vue';

const apiStore = useApiStore();

onMounted(async (): Promise<void> => {
  await apiStore.getAbout();
});
</script>

<template>
  <Teleport to="body">
    <AuthModal v-if="apiStore.auth && !apiStore.authStatus" />
  </Teleport>
  <div class="w-full justify-center text-center text-sm">
    <a :href="apiStore.github" target="_blank">{{ apiStore.name }}</a>
    v{{ apiStore.version }}
    <span v-if="apiStore.environment == 'dev'"> dev-mode</span>
    <br />
    Made with <span class="text-minizo-red">♥️</span>
  </div>
</template>