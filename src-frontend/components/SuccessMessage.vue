<script setup lang="ts">
import IconInfo from '@/components/icons/IconInfo.vue';
defineProps({
  message: {
    type: String,
    default: '',
    required: false
  }
});
</script>

<template>
  <div class="flex w-full p-1.5 justify-center items-center flex-row text-xs gap-2 bg-minizo-green text-white font-bold">
    <IconInfo class="w-[18px] h-[18px]" />
    <span>{{ message }}</span>
  </div>
</template>