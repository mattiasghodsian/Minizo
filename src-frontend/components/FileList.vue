<script setup lang="ts">
import { ref } from 'vue';
import { useApiStore } from '@/stores/api.ts';
import FileActions from '@/components/FileActions.vue';

const apiStore = useApiStore();

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
</script>

<template>
  <section class="flex flex-col w-full max-h-[500px] md:max-h-[400px] overflow-x-auto">
    <div v-for="(file, index) in apiStore.fileList" :key="index"
      class="flex hover:bg-minizo-red hover:text-white text-sm md:text-md px-2 py-1 gap-2 just border-b border-minizo-red pt-2">
      <span class="w-3/4">
        {{ splitFileName(file)[0] }} - {{ splitFileName(file)[1] }}
      </span>
      <span class="w-0.5/4">
        {{ getFileExtension(file) }}
      </span>
      <span class="w-1/4">
        <FileActions :index="index" :fileName="file" />
      </span>
    </div>
  </section>
</template>
