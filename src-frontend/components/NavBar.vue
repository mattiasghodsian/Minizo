<script setup lang="ts">
import { RouterLink, useRoute } from 'vue-router';
import TheLogo from '@/components/TheLogo.vue';

const route = useRoute();

const isCurrentPage = (slug: string): boolean => {
  return (route.path == slug);
};
</script>

<template>
  <header class="flex flex-col items-center gap-4">
    <TheLogo class="max-h-[150px] md:max-h-[200px]" />
    <nav>
      <ul class="flex gap-4">
        <li>{</li>
        <li>
          <RouterLink to="/" class="hover:font-bold transition-all delay-75"
            :class="{ 'font-bold': isCurrentPage('/') }">Grab track</RouterLink>
        </li>
        <li>
          <RouterLink to="/manager" class="hover:font-bold transition-all delay-75"
            :class="{ 'font-bold': isCurrentPage('/manager') }">Manager</RouterLink>
        </li>
        <li>}</li>
      </ul>
    </nav>
  </header>
</template>