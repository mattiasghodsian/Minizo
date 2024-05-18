<script setup lang="ts">
import { ref } from 'vue';
import { useApiStore } from '@/stores/api.ts';
import FileActions from '@/components/FileActions.vue';
import FileMetaData from '@/components/modals/FileMetaData.vue';

const apiStore = useApiStore();
const fileMetaModal = ref<boolean>(false);

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
      <span class="flex w-3/5 md:w-4/5 cursor-pointer select-none" @click="getMetaData(index)">
        {{ file }}
      </span>
      <span class="flex w-2/5 md:w-1/5 items-center">
        <FileActions :index="index" :fileName="file" />
      </span>
    </div>
  </section>
</template>
