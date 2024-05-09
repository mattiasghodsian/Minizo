<script setup lang="ts">
import { ref } from 'vue';
import { useApiStore } from '@/stores/api.ts';
import FileActions from '@/components/FileActions.vue';
import FileMetaData from '@/components/modals/FileMetaData.vue';

const apiStore = useApiStore();
const fileMetaModal = ref<boolean>(false);

const getFileExtension = (fileName: string): string => {
  const parts = fileName.split('.');
  const extension = parts[parts.length - 1];
  return extension.toLowerCase();
}

const splitFileName = (fileName: string): [] => {
  const extensionIndex = fileName.lastIndexOf('.');
  const nameWithoutExtension = fileName.substring(0, extensionIndex);
  return nameWithoutExtension.split('-').map(part => part.trim()) as [];
}

const getMetaData = async (index: number): Promise<void|boolean> => {
  const files = apiStore.fileList as [];
  const file = files[index] ?? null;

  if (!file){
    console.log('could not find index in filelist');
    return false;
  }

  await apiStore.getFileMeta(file).then(res => {
    apiStore.fileMetaData = res;
    fileMetaModal.value = !fileMetaModal.value;
  }).catch(err => {
    console.log(err);
  });
}
</script>

<template>
  <FileMetaData v-if="fileMetaModal" @close="fileMetaModal = !fileMetaModal" />
  <section class="flex flex-col w-full max-h-[500px] md:max-h-[400px] overflow-x-auto">
    <div v-for="(file, index) in apiStore.fileList" :key="index"
      class="flex hover:bg-minizo-red hover:text-white text-sm md:text-md px-2 py-1 gap-2 just border-b border-minizo-red pt-2">
      <span class="w-4/5 cursor-pointer select-none" @click="getMetaData(index)">
        {{ splitFileName(file)[0] }} - {{ splitFileName(file)[1] }}
      </span>
      <span class="w-0.5/5">
        {{ getFileExtension(file) }}
      </span>
      <span class="w-1/5">
        <FileActions :index="index" :fileName="file" />
      </span>
    </div>
  </section>
</template>
