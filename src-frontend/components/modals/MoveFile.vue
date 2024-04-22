<script setup lang="ts">
import { ref } from 'vue';
import { useApiStore } from '@/stores/api.ts';
import BaseModal from '@/components/modals/BaseModal.vue';

const apiStore = useApiStore();
const newDirectory = ref<string>('');

const emit = defineEmits([
  'close',
]);

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

const handleAction = (action: string): void => {
  switch (action) {
    case 'close':
      emit('close');
      break;
    case 'move':
      moveFile('asdasd');
      break;
  }
}

const moveFile = async (): Promise<void> => {
  await apiStore.moveFile(
    props.fileName,
    newDirectory.value
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
  handleAction('close');
}

const setNewDirectory = (directory: string): void => {
  newDirectory.value = directory
}
</script>

<template>
  <BaseModal header="Relocate file">
    <DropDown :data="apiStore.directories" class="min-h-[50px] min-w-[200px]" @input="setNewDirectory($event)" />
    <p class="py-2 px-3 text-sm text-center max-w-[300px]" label="Move to">You are about to relocate <br /> <b>{{
      fileName }}</b> <br /> to <b>{{ newDirectory }}</b> </p>
    <div class="flex w-full">
      <BaseButton @click="handleAction('close')"
        class="rounded-none w-full shadow-none bg-minizo-red hover:opacity-70 transition delay-75">
        Cancel
      </BaseButton>
      <BaseButton v-if="newDirectory" @click="handleAction('move')"
        class="rounded-none w-full shadow-none bg-minizo-green hover:opacity-70 transition delay-75">
        Proceed
      </BaseButton>
    </div>
  </BaseModal>
</template>