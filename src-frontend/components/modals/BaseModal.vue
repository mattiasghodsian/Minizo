<script setup lang="ts">
defineProps({
  header: {
    type: String,
    default: null,
  },
  headerSmall: {
    type: String,
    default: null,
  },
});
</script>

<template>
  <div class="fixed inset-0 flex min-w-[200px] items-center justify-center z-50 bg-black bg-opacity-60">
    <div class="flex flex-col items-center bg-white text-minizo-base max-w-prose rounded-lg shadow-md overflow-hidden">
      <div class="p-2 relative">
        <small v-if="headerSmall" class="absolute text-[9px] top-1.5 right-1">{{ headerSmall }}</small>
        <h1 class="font-bold text-xl">{{ header }}</h1>
      </div>
      <slot></slot>
    </div>
  </div>
</template>