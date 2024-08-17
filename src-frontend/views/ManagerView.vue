<script setup lang="ts">
import { ref, onMounted, watch } from "vue";
import { useRoute } from 'vue-router';
import { useApiStore } from '@/stores/api.ts';
import IconFolder from '@/components/icons/IconFolder.vue';
import { useToast } from 'primevue/usetoast';
import SplitButton from 'primevue/splitbutton';
import MetaDataModal from '@/components/modals/MetaDataModal.vue';
import ViewDataModal from '@/components/modals/ViewMetaDataModal.vue';
import MoveFileModal from '@/components/modals/MoveFileModal.vue';

const toast = useToast();
const apiStore = useApiStore();
const route = useRoute();
const viewAllFiles = ref<boolean>(false);
const selectedTrack = ref<Object>();
const showMetaDataModal = ref<boolean>(false);
const showViewMetaDataModal = ref<boolean>(false);
const showMoveFileModal = ref<boolean>(false);

const actionList = [
  {
    label: 'Delete',
    keyCode: 68, // D
    command: async () => {
      if (selectedTrack.value && selectedTrack.value.hasOwnProperty('name')) {
        await apiStore.deleteFile(
          selectedTrack.value.file,
        ).then(async response => {
          toast.add({
            severity: 'success',
            summary: 'Success',
            detail: `${selectedTrack.value.file} deleted`,
            life: 3000
          });
          await getFiles();
        }).catch(err => {
          toast.add({
            severity: 'error',
            summary: 'Error',
            detail: err.data.message,
            life: 3000
          });
        });
      } else {
        toast.add({
          severity: 'error',
          summary: 'Error',
          detail: "Please choose a track.",
          life: 3000
        });
      }
    }
  },
  {
    label: 'Download',
    keyCode: 72, // H
    command: async () => {
      if (selectedTrack.value && selectedTrack.value.hasOwnProperty('name')) {
        await apiStore.downloadFile(
          selectedTrack.value.file,
          route.params.directory
        );
      } else {
        toast.add({
          severity: 'error',
          summary: 'Error',
          detail: "Please choose a track.",
          life: 3000
        });
      }
    }
  },
  {
    label: 'Write meta',
    keyCode: 87, // W
    command: () => {
      if (selectedTrack.value && selectedTrack.value.hasOwnProperty('name')) {
        showMetaDataModal.value = !showMetaDataModal.value;
      } else {
        toast.add({
          severity: 'error',
          summary: 'Error',
          detail: "Please choose a track.",
          life: 3000
        });
      }
    }
  },
  {
    label: 'View meta',
    keyCode: 86, // V
    command: () => {
      if (selectedTrack.value && selectedTrack.value.hasOwnProperty('name')) {
        showViewMetaDataModal.value = !showViewMetaDataModal.value;
      } else {
        toast.add({
          severity: 'error',
          summary: 'Error',
          detail: "Please choose a track.",
          life: 3000
        });
      }
    }
  },
  {
    label: 'Move file',
    keyCode: 77, // M
    command: () => {
      if (selectedTrack.value && selectedTrack.value.hasOwnProperty('name')) {
        showMoveFileModal.value = !showMoveFileModal.value;
      } else {
        toast.add({
          severity: 'error',
          summary: 'Error',
          detail: "Please choose a track.",
          life: 3000
        });
      }
    }
  },
  {
    label: 'YouTube',
    keyCode: 89, // Y
    command: () => {
      if (selectedTrack.value && selectedTrack.value.hasOwnProperty('name')) {
        const trackName = selectedTrack.value.name.replace(/\.[^/.]+$/, "");
        const query = trackName.replace(/\s+/g, '+');
        const url = `https://music.youtube.com/search?q=${query}`;
        window.open(url, '_blank');
      } else {
        toast.add({
          severity: 'error',
          summary: 'Error',
          detail: "Please choose a track.",
          life: 3000
        });
      }
    }
  },
];

const getFiles = async (): Promise<void> => {
  await apiStore.viewDirectory(
    route.params.directory,
    viewAllFiles.value
  ).then(response => {
    apiStore.currentViewDIrectory = route.params.directory;
  }).catch(err => {
    toast.add({
      severity: 'error',
      summary: 'Error',
      detail: err.data.message,
      life: 3000
    });
  });
}

watch(
  () => route.params.directory,
   async (newValue, oldValue) => {
    await getFiles();
  }
);

const closeMoveFileModal = async (): Promise<void> => {
  showMoveFileModal.value = false;
  await getFiles();
}

const triggerAction = (keyCode: number) => {
  const action = actionList.find(action => action.keyCode === keyCode);
  if (action && typeof action.command === 'function') {
    action.command();
  }
};

onMounted(async (): Promise<void> => {
  await getFiles();
  window.addEventListener('keydown', (e) => {
    if (e.keyCode === 114 || (e.ctrlKey && actionList.some(action => action.keyCode === e.keyCode))) {
      triggerAction(e.keyCode);
    }
  });
});
</script>

<template>
  <MetaDataModal :show="showMetaDataModal" :track="selectedTrack ?? {}" @close="showMetaDataModal = false" />
  <ViewDataModal :show="showViewMetaDataModal" :track="selectedTrack ?? {}" @close="showViewMetaDataModal = false" />
  <MoveFileModal :show="showMoveFileModal" :track="selectedTrack ?? {}" @close="closeMoveFileModal" />

  <section class="flex flex-col gap-8 mb-4 mt-[30px] bg-minizo-dark z-10 w-6">
    <div class="flex md:w-[calc(100%-18.5rem)] top-[61px] z-10 bg-minizo-dark py-2 justify-between fixed">
      <div class="flex flex-row gap-2 items-center">
        <IconFolder class="w-5 h-5 fill-gray-400 group-hover:fill-white" />
        <h1 class="text-xl text-white">{{ route.params.directory }}</h1>
      </div>
      <div class="flex items-center justify-center md:justify-normal gap-2 text-white">
        <div class="flex items-center gap-2">
          <label class="text-sm">View all files</label>
          <ToggleSwitch v-model="viewAllFiles" @change="getFiles" />
        </div>
        <SplitButton :model="actionList" severity="danger">
          <span class="flex items-center font-bold">
            <span>Actions</span>
          </span>
        </SplitButton>
      </div>
    </div>
  </section>

  <section class="flex flex-col gap-8">
    <DataTable v-model:selection="selectedTrack" :value="apiStore.fileList" selectionMode="single" dataKey="name">
      <Column selectionMode="single" headerStyle="width: 3rem"></Column>
      <Column field="name" header="Name" footer="Name" sortable></Column>
      <Column field="type" header="Type" footer="Type" sortable></Column>
    </DataTable>
  </section>
</template>