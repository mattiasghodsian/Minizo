<script setup lang="ts">
import IconWarning from '@/components/icons/IconWarning.vue';
defineProps({
  message: {
    type: String,
    default: '',
    required: false
  }
});
</script>

<template>
  <div class="flex w-full p-1.5 justify-center items-center flex-row text-xs gap-2 bg-minizo-red text-white font-bold">
    <IconWarning class="w-[18px] h-[18px]" />
    <span>{{ message }}</span>
  </div>
</template>