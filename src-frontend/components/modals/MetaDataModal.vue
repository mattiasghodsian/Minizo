<script setup lang="ts">
import { ref } from 'vue';
import { flatten } from 'flat'
import { useApiStore } from '@/stores/api.ts';
import Stepper from 'primevue/stepper';
import StepPanels from 'primevue/steppanels';
import StepPanel from 'primevue/steppanel';
import { useToast } from 'primevue/usetoast';

const toast = useToast();
const apiStore = useApiStore();
const artist = ref<string>();
const track = ref<string>();
const musicBrainzResults = ref<[]>([]);
const currentStep = ref(1);
const selectedMetaData = ref<Object>({});
const renameFile = ref<boolean>(false);

const props = defineProps({
  show: {
    type: Boolean,
    default: false
  },
  track: {
    type: Object,
    default: {
      file: 'Minizo - minizo.flac',
      name: 'Minizo - minizo',
      type: 'flac',
    }
  },
});

const emit = defineEmits([
  'close',
]);

const activateCallback = async (step: number): Promise<void> => {
  if (step == 2) {
    await apiStore.metaSearch(
      artist.value,
      track.value
    ).then(response => {
      musicBrainzResults.value = response.releases;
    }).catch(err => {
      console.log(err.data.message);
    });
  }
  currentStep.value = step;
};

const trackName = (): string => {
  let trackname = "Minizo - Minizo"

  if (props.track.hasOwnProperty('name')) {
    trackname = props.track.name;
  }

  const parts = trackname.split(" - ");
  artist.value = parts[0];
  track.value = parts[1];
  return trackname;
}

const writeMetaData = async (): Promise<void> => {
  let newFileName = "";
  if (renameFile.value === true){
    const artists = selectedMetaData.value['artist-credit'].map(a => `${a.name}${a.joinphrase ? `${a.joinphrase}` : ''}`);
    newFileName = `${artists.join(' ')} - ${selectedMetaData.value.title}`;
  }

  await apiStore.postFileMeta(
    props.track.file,
    selectedMetaData.value['release-group']['id'],
    newFileName
  ).then(() => {
    toast.add({
      severity: 'success',
      summary: 'Meta data',
      detail: "Metadata successfully written.",
      life: 3000
    });
  }).catch(err => {
    toast.add({
      severity: 'error',
      summary: 'Error',
      detail: err.data.message,
      life: 3000
    });
  });
}

const closeDialog = () => {
  currentStep.value = 1;
  emit('close');
} 

</script>

<template>
  <Dialog :visible="show" modal :header="'Search for ' + trackName()" @update:visible="closeDialog" :closable="true" class="bg-white"
    :style="{ 'max-width': '60rem' }" pt:root:class="!border-0 overflow-hidden !bg-minizo-dark !text-white" pt:header:class="!py-2"  pt:content:class="!px-1 !pb-3" 
    pt:mask:class="backdrop-blur-sm">
    <Stepper v-model:value="currentStep">
      <StepPanels :pt="{ root: { class: '!p-0' } }">

        <StepPanel :value="1" :pt="{ root: { class: '!bg-transparent' } }">
          <div class="flex gap-2 flex-col px-4">
            <div class="flex gap-2">
              <div class="flex flex-col w-full">
                <label for="artist" class="text-white text-xs">Artist</label>
                <InputText v-model="artist" id="artist" placeholder="Artist" />
              </div>
              <div class="flex flex-col w-full">
                <label for="track" class="text-white text-xs">Track</label>
                <InputText v-model="track" id="track" placeholder="track" />
              </div>
            </div>
            <div class="flex gap-2">
              <div class="flex items-center justify-center md:justify-normal gap-2 text-white">
                <label>Rename file:</label>
                <ToggleSwitch v-model="renameFile" />
                <span class="bg-gray-500 px-1 rounded text-minizo-dark">`${artist} ${joinphrase} ${artist} - ${track}`</span>
              </div>
            </div>
          </div>
          <div class="flex pt-2 px-2.5 justify-end">
            <Button label="Search" severity="success" @click="activateCallback(2)" />
          </div>
        </StepPanel>

        <StepPanel :value="2" :pt="{ root: { class: '!bg-transparent' } }">
          <DataTable v-model:selection="selectedMetaData" :value="musicBrainzResults" selectionMode="single" dataKey="id" class="max-h-96 overflow-y-auto overflow-x-auto">
            <Column selectionMode="single" headerStyle="width: 3rem"></Column>
            <Column field="title" header="Title" footer="Title" sortable></Column>
            <Column field="artist" header="Artist" sortable>
              <template #body="slotProps">
                <span v-for="(artist, aIndex) in slotProps.data['artist-credit']" :key="aIndex">
                  {{ artist.name }} {{ artist.joinphrase }}
                </span>
              </template>
            </Column>
            <Column field="release-group.title" header="Release group" footer="Release group" sortable></Column>
            <Column field="status" header="Status" footer="Status" sortable></Column>
            <Column field="score" header="Score" footer="Score" sortable></Column>
          </DataTable>
      
          <div class="flex pt-2 px-2.5 justify-between">
            <Button label="Back" unstyled class="text-white font-bold p-2.5" @click="activateCallback(1)" />
            <Button label="Next" severity="success" @click="activateCallback(3)" />
          </div>
        </StepPanel>
  
        <StepPanel :value="3" :pt="{ root: { class: '!bg-transparent' } }">
          <div class="flex flex-col h-96 overflow-y-auto">
            <div class="grid grid-cols-2 gap-4">
              <div v-for="(data, index) in flatten(selectedMetaData)" :key="index" class="flex flex-col p-2">
                <span class="uppercase text-gray-500 border-b border-gray-700">{{ index }}</span>
                <span class="text-white">{{ data }}</span>
              </div>
            </div>
          </div>
          <div class="flex justify-between pt-2 px-2.5">
            <Button label="Back" unstyled class="text-white font-bold p-2.5" @click="activateCallback(2)" />
            <Button label="Write data" severity="success" @click="writeMetaData()" />
          </div>
        </StepPanel>

      </StepPanels>
    </Stepper>
  </Dialog>
</template>