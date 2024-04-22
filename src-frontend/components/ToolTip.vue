<script setup lang="ts">
import { ref } from 'vue';
import IconInfo from '@/components/icons/IconInfo.vue';

const toggle = ref<boolean>(false);

defineProps({
  description: {
    type: String,
    default: null,
  }
});

const handler = (): void => {
  toggle.value = !toggle.value;
}
</script>

<template>
  <div class="relative">
    <IconInfo class="w-[19px] h-[19px] cursor-pointer" @mouseenter="handler" @mouseleave="handler" />
    <div v-if="toggle" class="absolute  z-10 left-4 -top-0.5 whitespace-nowrap bg-black text-xs px-3 py-1 rounded-full left-[calc(100% + 10px)] top-0">
      {{ description }}
    </div>
  </div>
</template>