<script setup lang="ts">
import { ref } from 'vue';

const selectedValue = ref<string | number>();

defineProps({
  placeholder: {
    type: String,
    required: true
  },
  type: {
    type: String,
    default: 'text'
  }
});

const emit = defineEmits([
  'input'
]);

const handler = (): void => {
  emit('input', selectedValue.value );
}
</script>

<template>
  <input
  :type="type" 
  class="border-none text-minizo-base focus:ring-0"
  :placeholder="placeholder"
  v-model="selectedValue"
  @change="handler"
  >
</template>