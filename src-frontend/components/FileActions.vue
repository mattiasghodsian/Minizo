<script setup lang="ts">
import { ref } from 'vue';
import { useApiStore } from '@/stores/api.ts';
import IconDownload from '@/components/icons/IconDownload.vue';
import IconYouTube from '@/components/icons/IconYouTube.vue';
import IconTrashcan from '@/components/icons/IconTrashcan.vue';
import IconFolder from '@/components/icons/IconFolder.vue';
import YesNoModal from '@/components/modals/YesNoModal.vue';
import MoveFile from '@/components/modals/MoveFile.vue';

const apiStore = useApiStore();
const deleteModal = ref<boolean>(false);
const moveModal = ref<boolean>(false);

const props = defineProps({
  index: {
    type: Number,
    required: true
  },
  fileName: {
    type: String,
    required: true
  }
});

const openYoTube = (): void => {
  const trackName = props.fileName.replace(/\.[^/.]+$/, "");
  const query = trackName.replace(/\s+/g, '+');
  const url = `https://music.youtube.com/search?q=${query}`;
  window.open(url, '_blank');
}

const deleteFile = async (): Promise<void> => {
  await apiStore.deleteFile(
    props.fileName,
  ).then(response => {
    const indexToDelete = props.index;
    let files = apiStore.fileList;
    if (indexToDelete >= 0 && indexToDelete < files.length) {
      files.splice(indexToDelete, 1);
      apiStore.fileList = files;
    }
  }).catch(err => {
    console.log(err.data.message);
  });
  deleteModal.value = false;
}

const toggleDeleteModal = (): void => {
  deleteModal.value = !deleteModal.value;
}

const downloadFile = async (): Promise<void> => {
  await apiStore.downloadFile(props.fileName, apiStore.currentViewDIrectory);
}

const toggleMoveModal = (): void => {
  moveModal.value = !moveModal.value;
}
</script>

<template>
  <Teleport to="body">
    <YesNoModal v-if="deleteModal" @close="toggleDeleteModal()" @doAction="deleteFile()" />
    <MoveFile v-if="moveModal" :fileName="fileName" :index="index" @close="toggleMoveModal()" />
  </Teleport>
  <ul class="flex justify-center gap-2">
    <li class="flex justify-center align-middle">
      <IconDownload class="w-[16px] cursor-pointer" @click="downloadFile()" />
    </li>
    <li class="flex justify-center align-middle">
      <IconYouTube class="w-[25px] cursor-pointer" @click="openYoTube()" />
    </li>
    <li class="flex justify-center align-middle">
      <IconFolder class="w-[18px] cursor-pointer" @click="toggleMoveModal()" />
    </li>
    <li class="flex justify-center align-middle">
      <IconTrashcan class="w-[15px] cursor-pointer" @click="toggleDeleteModal()" />
    </li>
  </ul>
</template>
