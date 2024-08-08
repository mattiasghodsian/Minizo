<script setup lang="ts">
import { useApiStore } from '@/stores/api.ts';
import AuthModal from '@/components/modals/AuthModal.vue';

const apiStore = useApiStore();
</script>

<template>
  <Teleport to="body">
    <AuthModal v-if="apiStore.auth && !apiStore.authStatus" />
  </Teleport>
</template>