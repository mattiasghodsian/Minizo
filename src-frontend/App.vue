<script setup lang="ts">
import { RouterView } from 'vue-router';
import NavBar from '@/components/NavBar.vue';
import TheFooter from '@/components/TheFooter.vue';
</script>

<template>
  <main class="flex flex-col justify-center items-center gap-4">
    <NavBar />
    <RouterView />
    <TheFooter />
  </main>
</template>