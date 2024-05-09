<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useApiStore } from '@/stores/api.ts';
import BaseModal from '@/components/modals/BaseModal.vue';

const apiStore = useApiStore();
const imageSrc = ref<string>();

const emit = defineEmits([
  'close',
]);

const getTrackName = (): string => {
  const fileName = apiStore.fileMetaData.file ?? null;
  if (fileName) {
    return fileName.substring(fileName.lastIndexOf('/') + 1, fileName.lastIndexOf('.'));
  }
  return 'Could not find file name';
}

const handleAction = (action: string): void => {
  switch (action) {
    case 'close':
      emit('close');
      break;
  }
}

const converImage = (): Promise<string> => {
  const imgData = apiStore.fileMetaData.common.picture[0];
  return new Promise((resolve, reject) => {
    try {
      const uint8Array = new Uint8Array(imgData.data.data);
      const blob = new Blob([uint8Array], { type: imgData.format });
      const reader = new FileReader();
      reader.onload = () => {
        resolve(reader.result as string);
      };
      reader.readAsDataURL(blob);
    } catch (error) {
      reject(error);
    }
  });
}

onMounted(async (): Promise<void> => {
  converImage().then(res => {
    console.log(res);
    imageSrc.value = res;
  })
  .catch(error => {
    console.error('Error getting cover image:', error);
  });
});
</script>

<template>
  <BaseModal :header="getTrackName()">
    <div class="grid grid-cols-3 gap-2 px-2 py-2 w-full max-h-[350px] border-t overflow-y-scroll overflow-x-auto">
      <div v-for="(field, index) in apiStore.fileMetaData.common" class="flex flex-col" key="index">
        <label class="font-bold capitalize">{{ index }}</label>
        <span v-if="index !== 'picture'">{{ field }}</span>
        <span v-else><img :src="imageSrc" /></span>
      </div>

      <div v-for="(field, index) in apiStore.fileMetaData.tags" class="flex flex-col" key="index">
        <label class="font-bold capitalize text-wrap">{{ field.id }}</label>
        <span v-if="field.id !== 'APIC' && field.id !== 'UFID' && field.id !== 'METADATA_BLOCK_PICTURE'">{{ field.value }}</span>
        <span v-else>Blob hidden</span>
      </div>

      <div v-for="(field, index) in apiStore.fileMetaData.format" class="flex flex-col" key="index">
        <label class="font-bold capitalize">{{ index }}</label>
        <span>{{ field }}</span>
      </div>
    </div>

    <div class="flex w-full">
      <BaseButton @click="handleAction('close')"
        class="rounded-none w-full shadow-none bg-minizo-red hover:opacity-70 transition delay-75">
        Close
      </BaseButton>
    </div>
  </BaseModal>
</template>