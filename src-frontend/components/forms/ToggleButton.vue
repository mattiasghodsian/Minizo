<script setup lang="ts">
import { ref } from 'vue';
import ToolTip from '@/components/ToolTip.vue';
const toggle = ref<boolean>(false);

defineProps({
  tooltip: {
    type: String,
    default: null
  },
});

const emit = defineEmits([
  'input'
]);

const selectedText = (): string => {
  switch (toggle.value) {
    case true:
      return 'Yes';
    default:
      return 'No';
  }
}

const handler = (): void => {
  toggle.value = !toggle.value;
  emit('input', toggle.value);
}
</script>

<template>
  <div class="flex justify-center items-center gap-2">
    <ToolTip v-if="tooltip" class="shadow -mr-4 border-2 border-minizo-base rounded-full" :description="tooltip"></ToolTip>
    <div class="flex flex-col">
      <BaseButton :class="{ 'bg-minizo-green': toggle, 'bg-minizo-red': !toggle }" @click="handler">
        {{ selectedText() }}
      </BaseButton>
    </div>
  </div>
</template>