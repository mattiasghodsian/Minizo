<script setup lang="ts">
import { ref } from 'vue';
import BaseModal from '@/components/modals/BaseModal.vue';

const emit = defineEmits([
  'close',
  'doAction'
]);

const handleAction = (action: string): void => {
  switch (action) {
    case 'close':
      emit('close');
      break;
    case 'doAction':
      emit('doAction');
      break;
  }
}
</script>

<template>
  <BaseModal header="Are you sure?">
    <p class="py-2 px-3 text-sm">Are you certain you wish to proceed with this action?</p>
    <div class="flex w-full">
      <BaseButton @click="handleAction('close')"
        class="rounded-none w-full shadow-none bg-minizo-red hover:opacity-70 transition delay-75">
        Cancel
      </BaseButton>
      <BaseButton @click="handleAction('doAction')"
        class="rounded-none w-full shadow-none bg-minizo-green hover:opacity-70 transition delay-75">
        Yes
      </BaseButton>
    </div>
  </BaseModal>
</template>