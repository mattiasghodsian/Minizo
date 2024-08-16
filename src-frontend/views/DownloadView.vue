<script setup lang="ts">
import { ref } from 'vue';
import { useApiStore } from '@/stores/api.ts';
import { useToast } from 'primevue/usetoast';
import IconDownload from '@/components/icons/IconDownload.vue';

const toast = useToast();
const apiStore = useApiStore();

const videoUrl = ref<string>('');
const formatType = ref<string>('');
const metaData = ref<boolean>(true);
const saveTo = ref<string>('');
const loading = ref(false);

const download = async (): Promise<void> => {
  if (!videoUrl.value || videoUrl.value.length == 0) {
    toast.add({
      severity: 'error',
      summary: 'Error',
      detail: 'Please provide a video url!',
      life: 3000 
    });
    return;
  }

  if (!formatType.value || formatType.value.length == 0) {
    toast.add({
      severity: 'error',
      summary: 'Error',
      detail: 'Please provide a format type!',
      life: 3000 
    });
    return;
  }

  if (!saveTo.value || saveTo.value.length == 0) {
    toast.add({
      severity: 'error',
      summary: 'Error',
      detail: 'Please provide a save directory!',
      life: 3000 
    });
    return;
  }

  loading.value = true;

  await apiStore.grabTrack(
    videoUrl.value,
    formatType.value,
    metaData.value,
    saveTo.value
  ).then(response => {
    toast.add({ 
      severity: 'success',
      summary: 'Download success',
      detail: `${response.name} saved to ${response.path}.`,
      life: 3000 
    });
  }).catch(err => {
    toast.add({
      severity: 'error',
      summary: 'Error',
      detail: err.data.message,
      life: 3000 
    });
  }).finally(() => {
    loading.value = false;
  });
}

</script>

<template>

  <section class="flex flex-col gap-8">
    <div class="flex flex-row gap-2 items-center">
      <IconDownload class="w-5 h-5 fill-gray-400 group-hover:fill-white" />
      <h1 class="text-xl text-white">Download</h1>
    </div>
    <div class="flex gap-2 flex-col justify-center">
      <div class="bg-gray-700 p-1 rounded-full overflow-hidden relative z-10">
        <InputText v-model="videoUrl" class="w-full px-4 md:px-8 h-10 md:h-16 rounded-full" unstyled
          placeholder="https://music.youtube.com/watch?v=xxxxxxxxxxx" />
        <Button type="button" label="Download" severity="danger" iconPos="right" class="!absolute top-1 right-1 h-10 md:h-16 !rounded-full" :loading="loading" @click="download()"/>
      </div>
      <div class="flex flex-col md:flex-row gap-2 justify-between bg-gray-700 px-3 pt-4 pb-2 mx-10 text-sm -top-5 rounded-xl overflow-hidden relative">
        <div class="flex md:w-2/6 lg:w-full items-center justify-center md:justify-normal gap-2 text-white">
          <label>Import source meta</label>
          <ToggleSwitch v-model="metaData" />
        </div>
        <div class="flex md:w-2/6 lg:w-full">
          <Select v-model="formatType" :options="['flac', 'best', 'aac', 'mp3', 'm4a', 'opus', 'vorbis']" checkmark
        :highlightOnSelect="false" placeholder="Format" class="w-full" />
        </div>
        <div class="flex md:w-2/6 lg:w-full">
          <Select v-model="saveTo" :options="apiStore.directories" checkmark :highlightOnSelect="false"
          placeholder="Directory" class="w-full" />
        </div>
      </div>
    </div>
  </section>

  <section class="text-xs w-full text-center text-gray-400">
    <p class="px-8 md:px-20 py-2">
      Downloading copyrighted content without authorization is illegal. This project is for educational purposes only. Ensure you have the right to download and use the content.
    </p>
  </section>

</template>