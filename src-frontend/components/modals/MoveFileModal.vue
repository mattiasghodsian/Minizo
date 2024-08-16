<script setup lang="ts">
import { ref } from 'vue';
import { useApiStore } from '@/stores/api.ts';
import { useToast } from 'primevue/usetoast';

const toast = useToast();
const apiStore = useApiStore();
const saveTo = ref<string>('');

const props = defineProps({
  show: {
    type: Boolean,
    default: false
  },
  track: {
    type: Object,
    default: {
      file: 'Minizo - minizo.flac',
      name: 'Minizo - minizo',
      type: 'flac',
    }
  },
});

const emit = defineEmits([
  'close',
]);

const closeDialog = () => {
  emit('close');
} 

const moveFile = async (): Promise<void> => {
  await apiStore.moveFile(
    props.track.file,
    saveTo.value
  ).then(response => {
    toast.add({
      severity: 'success',
      summary: 'Success',
      detail: `${props.track.file} moved to ${saveTo.value}`,
      life: 3000
    });
  }).catch(err => {
    toast.add({
      severity: 'error',
      summary: 'Error',
      detail: err.data.message,
      life: 3000
    });
  });
  emit('close');
}

</script>

<template>
  <Dialog :visible="show" modal :header="'Move file'" @update:visible="closeDialog" :closable="true" class="bg-white"
    :style="{ 'max-width': '60rem' }" pt:root:class="!border-0 overflow-hidden !bg-minizo-dark !text-white" pt:header:class="!py-2"  pt:content:class="!px-1 !pb-3" 
    pt:mask:class="backdrop-blur-sm">

    <div class="flex gap-2 flex-col px-4 mt-2">
        <div class="flex flex-col w-full">
          <Select v-model="saveTo" :options="apiStore.directories" checkmark :highlightOnSelect="false"
          placeholder="Directory" class="w-full" />
        </div>
        <div class="flex flex-col w-full">
          <p>You are about to relocate <br> {{ track.file }}</p>
        </div>
    </div>
    <div class="flex pt-2 px-2.5 justify-center">
      <Button label="Move" severity="success" @click="moveFile" />
    </div>
   
  </Dialog>
</template>