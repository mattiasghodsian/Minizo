<script setup lang="ts">
import { ref } from 'vue';
import { useApiStore } from '@/stores/api.ts';
import ErrorMessage from '@/components/ErrorMessage.vue';
import SuccessMessage from '@/components/SuccessMessage.vue';
import IconLoading from '@/components/icons/IconLoading.vue';

const apiStore = useApiStore();
const videoUrl = ref<string>('');
const formatType = ref<string>('');
const metaData = ref<boolean>(false);
const saveTo = ref<string>('');
const error = ref<string>('');
const success = ref<string>('');
const isLoading = ref<boolean>(false);

const updateProperty = (propertyName: string, value: any): void => {
  switch (propertyName) {
    case 'videoUrl':
      videoUrl.value = value;
      break;
    case 'metaData':
      metaData.value = value;
      break;
    case 'formatType':
      formatType.value = value;
      break;
    case 'saveTo':
      saveTo.value = value;
      break;
  }

  error.value = '';
  success.value = '';
}

const handler = async (): Promise<void> => {
  if (!videoUrl.value || videoUrl.value.length == 0) {
    error.value = "Please provide a video url!";
    return;
  }

  if (!formatType.value || formatType.value.length == 0) {
    error.value = "Please provide a format type!";
    return;
  }

  if (!saveTo.value || saveTo.value.length == 0) {
    error.value = "Please provide a save directory!";
    return;
  }

  isLoading.value = true;

  await apiStore.grabTrack(
    videoUrl.value,
    formatType.value,
    metaData.value,
    saveTo.value
  ).then(response => {
    success.value = `${response.name} saved to ${response.path}.`
  }).catch(err => {
    error.value = err.data.message;
  }).finally(() => {
    isLoading.value = false;
  });
}

</script>

<template>
  <section class="flex flex-col justify-center items-center gap-2">
    <ErrorMessage v-if="error" :message="error" class="rounded-full" />
    <SuccessMessage v-if="success" :message="success" class="rounded-full" />
    <div class="flex w-full max-w-[380px] md:max-w-[450px]">
      <TextInput class="w-full rounded-full" placeholder="https://music.youtube.com/watch?v=xxxxxxxxxxx"
        @input="updateProperty('videoUrl', $event)" />
      <BaseButton class="-ml-9 px-5 bg-minizo-red" @click="handler()">
        <IconLoading v-if="isLoading" class="w-[18px] h-[18px] animate-spin" />
        <span v-else>Grab</span>
      </BaseButton>
    </div>
    <div class="flex flex-col md:flex-row w-full max-w-[380px] md:max-w-none gap-2">
      <DropDown :data="['flac', 'best', 'aac', 'mp3', 'm4a', 'opus', 'vorbis']" tooltip="Convert format"
        @input="updateProperty('formatType', $event)"  class="order-1" />
      <ToggleButton tooltip="Write metadata to file" @input="updateProperty('metaData', $event)" class="order-3 md:order-2" />
      <DropDown :data="apiStore.directories" tooltip="Save directory" @input="updateProperty('saveTo', $event)" class="order-2 md:order-3" />
    </div>
  </section>
</template>