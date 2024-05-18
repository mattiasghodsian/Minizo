<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useApiStore } from '@/stores/api.ts';
import BaseModal from '@/components/modals/BaseModal.vue';
import IconLoading from '@/components/icons/IconLoading.vue';
import ErrorMessage from '@/components/ErrorMessage.vue';

const apiStore = useApiStore();
const artist = ref<string>('');
const track = ref<string>('');
const results = ref<[]>([]);
const toggleSettings = ref<boolean>(false);
const releaseId = ref<string>('');
const newFileName = ref<string>();
const errorMessage = ref<string>();
const isLoading = ref<boolean>(false);
const settings = ref<{}>({
  artist: false,
  track: false,
  release: false,
  country: false,
  label: false,
  score: false,
  type: false,
  renameFile: false
});

const emit = defineEmits([
  'close',
]);

const props = defineProps({
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
    case 'search':
      search();
      break;
    case 'settings':
      toggleSettings.value = !toggleSettings.value;
      break;
  }
}

const updateProperty = (propertyName: string, value: any): void => {
  switch (propertyName) {
    case 'artist':
      artist.value = value;
      break;
    case 'track':
      track.value = value;
      break;
    case 'settingsArtist':
      settings.value.artist = value;
      break;
    case 'settingsTrack':
      settings.value.track = value;
      break;
    case 'settingsRelease':
      settings.value.release = value;
      break;
    case 'settingsCountry':
      settings.value.country = value;
      break;
    case 'settingsLabel':
      settings.value.label = value;
      break;
    case 'settingsScore':
      settings.value.score = value;
      break;
    case 'settingsType':
      settings.value.type = value;
      break;
    case 'settingsRenameFile':
      settings.value.renameFile = value;
      break;
  }
}

const splitFileName = (fileName: string): [] => {
  const extensionIndex = fileName.lastIndexOf('.');
  const nameWithoutExtension = fileName.substring(0, extensionIndex);
  return nameWithoutExtension.split('-').map(part => part.trim()) as [];
}

const search = async (): Promise<void> => {
  await apiStore.metaSearch(
    artist.value,
    track.value
  ).then(response => {
    results.value = response.releases;
  }).catch(err => {
    console.log(err.data.message);
  });
}

const setReleaseId = (artist: [], track: string, id: string) => {
  errorMessage.value = "";
  newFileName.value = "";
  if (settings.value.renameFile && artist && track){
    const artists = artist.map(a => `${a.name}${a.joinphrase ? ` ${a.joinphrase}` : ''}`);
    newFileName.value = `${artists.join(' ')} - ${track}`;
  }
  releaseId.value = id;
}

const writeMetaData = async (): Promise<void> => {
  isLoading.value = true;
  await apiStore.postFileMeta(
    props.fileName,
    releaseId.value,
    newFileName.value
  ).then(() => {
    emit('close');
  }).catch(err => {
    errorMessage.value = err.data.message;
  }).finally(() => {
    isLoading.value = false;
  });
}

onMounted(async (): Promise<void> => {
  const fileParts = splitFileName(props.fileName);
  artist.value = fileParts[0] ?? '';
  track.value = fileParts[1] ?? '';
});
</script>

<template>
  <BaseModal header="Search metadata" headerSmall="MusicBrainz">

    <ErrorMessage v-if="errorMessage" :message="errorMessage" />

    <div v-if="toggleSettings" class="grid grid-cols-3 bg-minizo-base w-full px-2 py-2 gap-2 items-center justify-center">
      <div class="flex items-center gap-1 justify-between">
        <label class="text-white text-xs">Toggle Artist field: </label>
        <ToggleButton @input="updateProperty('settingsArtist', $event)" />
      </div>
      <div class="flex items-center gap-1 justify-between">
        <label class="text-white text-xs">Toggle Track field: </label>
        <ToggleButton @input="updateProperty('settingsTrack', $event)" />
      </div>
      <div class="flex items-center gap-1 justify-between">
        <label class="text-white text-xs">Toggle Release field: </label>
        <ToggleButton @input="updateProperty('settingsRelease', $event)" />
      </div>
      <div class="flex items-center gap-1 justify-between">
        <label class="text-white text-xs">Toggle Country field: </label>
        <ToggleButton @input="updateProperty('settingsCountry', $event)" />
      </div>
      <div class="flex items-center gap-1 justify-between">
        <label class="text-white text-xs">Toggle Label field: </label>
        <ToggleButton @input="updateProperty('settingsLabel', $event)" />
      </div>
      <div class="flex items-center gap-1 justify-between">
        <label class="text-white text-xs">Toggle Score field: </label>
        <ToggleButton @input="updateProperty('settingsScore', $event)" />
      </div>
      <div class="flex items-center gap-1 justify-between">
        <label class="text-white text-xs">Toggle Type field: </label>
        <ToggleButton @input="updateProperty('settingsType', $event)" />
      </div>
      <div class="flex items-center gap-1 justify-between">
        <label class="text-white text-xs">Rename file on write: </label>
        <ToggleButton @input="updateProperty('settingsRenameFile', $event)" />
      </div>
    </div>

    <div class="flex w-full gap-2 border-t border-b">
      <TextInput class="w-1/2" :placeholder="artist" @input="updateProperty('artist', $event)" />
      <TextInput class="w-1/2" :placeholder="track" @input="updateProperty('track', $event)" />
    </div>

    <div class="w-full max-h-[300px] overflow-y-scroll overflow-x-auto">
      <table class="table-auto w-full text-sm">
        <thead class="border-b">
          <tr>
            <th class="py-1 px-3 text-left" v-if="!settings.artist">Artist</th>
            <th class="py-1 px-3 text-left" v-if="!settings.track">Track</th>
            <th class="py-1 px-3 text-left" v-if="!settings.release">Release</th>
            <th class="py-1 px-3 text-left" v-if="!settings.country">Country</th>
            <th class="py-1 px-3 text-left" v-if="!settings.label">Label</th>
            <th class="py-1 px-3 text-left" v-if="!settings.type">Type</th>
            <th class="py-1 px-3 text-left" v-if="!settings.score">Score</th>
          </tr>
        </thead>
        <tbody class="">
          <tr 
            class="cursor-pointer hover:bg-minizo-base hover:text-white"
            v-for="(result, index) in results"
            :key="index"
            :data-id="result.id"
            @click="setReleaseId(result['artist-credit'], result.title, result.id)"
            :class="{'bg-minizo-base text-white': releaseId === result.id}"
            >
            <td class="py-1 px-3 whitespace-nowrap" v-if="!settings.artist">
              <span v-for="(artist, aIndex) in result['artist-credit']" :key="aIndex">
                {{ artist.name }} {{ artist.joinphrase }}
              </span>
            </td>
            <td class="py-1 px-3 whitespace-nowrap" v-if="!settings.track">{{ result.title }}</td>
            <td class="py-1 px-3 whitespace-nowrap" v-if="!settings.release">{{ result.date }}</td>
            <td class="py-1 px-3 whitespace-nowrap" v-if="!settings.country">{{ result.country }}</td>
            <td class="py-1 px-3 whitespace-nowrap" v-if="!settings.label">{{ result['label-info'][0].label.name }}</td>
            <td class="py-1 px-3 whitespace-nowrap" v-if="!settings.type">{{ result['release-group']['primary-type'] }}</td>
            <td class="py-1 px-3 whitespace-nowrap" v-if="!settings.score">{{ result.score }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="flex w-full">
      <BaseButton @click="handleAction('close')"
        class="rounded-none w-full shadow-none bg-minizo-red hover:opacity-70 transition delay-75">
        Close
      </BaseButton>
      <BaseButton @click="handleAction('settings')"
        class="rounded-none w-full flex flex-row items-center gap-2 shadow-none justify-center bg-minizo-base hover:opacity-70 transition delay-75">
        Settings
      </BaseButton>
      <BaseButton @click="handleAction('search')"
        class="rounded-none w-full shadow-none bg-minizo-green hover:opacity-70 transition delay-75">
        Search
      </BaseButton>
      <BaseButton v-if="releaseId" @click="writeMetaData()"
        class="rounded-none w-full shadow-none bg-minizo-base hover:opacity-70 transition delay-75">
        <IconLoading v-if="isLoading" class="w-[18px] h-[18px] mx-auto animate-spin" />
        <span v-else>Write</span>
      </BaseButton>
    </div>
  </BaseModal>
</template>