<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useApiStore } from '@/stores/api.ts';
import ErrorMessage from '@/components/ErrorMessage.vue';
import FileList from '@/components/FileList.vue';

const apiStore = useApiStore();
const directoryName = ref<string>('');
const nonMusic = ref<boolean>(false);
const error = ref<string>('');

const updateProperty = async (propertyName: string, value: any): Promise<void> => {
  switch (propertyName) {
    case 'directoryName':
      directoryName.value = value;
      break;
    case 'nonMusic':
      nonMusic.value = value;
      break;
  }

  await apiStore.viewDirectory(
    directoryName.value,
    nonMusic.value
  ).then(response => {
    apiStore.currentViewDIrectory = directoryName.value;
  }).catch(err => {
    error.value = err.data.message;
  });
}

onMounted(async (): Promise<void> => {
  if (!apiStore.musicStorage){
    await apiStore.getAbout();
  }
});
</script>

<template>
  <section class="flex flex-col justify-center items-center gap-2">
    <ErrorMessage v-if="error" :message="error" class="rounded-full" />
    <div class="flex w-full max-w-[450px] gap-2">
      <DropDown :data="apiStore.directories" tooltip="View directory" @input="updateProperty('directoryName', $event)" />
      <ToggleButton tooltip="View none audio files" @input="updateProperty('nonMusic', $event)" />
    </div>
  </section>
  <FileList />
</template>
