<script setup lang="ts">
import { ref, getCurrentInstance, onMounted } from 'vue';
import { useApiStore } from '@/stores/api.ts';
import Password from 'primevue/password';
import IconLogo from '../icons/IconLogo.vue';
import { useToast } from 'primevue/usetoast';

const toast = useToast();
const apiStore = useApiStore();
const username = ref<string>();
const password = ref<string>();

const { appContext } = getCurrentInstance()!;
const $cookies = appContext.config.globalProperties.$cookies;

const handler = async (): Promise<void> => {
  if (!username.value || username.value.length == 0) {
    toast.add({
      severity: 'error',
      summary: 'Error',
      detail: 'Please provide a username!',
      life: 3000
    });
    return;
  }

  if (!password.value || password.value.length == 0) {
    toast.add({
      severity: 'error',
      summary: 'Error',
      detail: 'Please provide a password!',
      life: 3000
    });
    return;
  }

  await apiStore.getAuthToken(username.value, password.value).then(res => {
    toast.add({
      severity: 'success',
      summary: 'Authenticated',
      detail: 'Authenticated successfully',
      life: 3000
    });
    $cookies.set('token', res.token);
  }).catch(err => {
    toast.add({
      severity: 'error',
      summary: 'Error',
      detail: err.data.message,
      life: 3000
    });
  });
}

const isAuth = (): boolean => {
  const backendIsAuth = apiStore.auth && !apiStore.authStatus;

  if (backendIsAuth == true){
    apiStore.testAuth().then(res => {
      return false;
    }).catch(err => {
      $cookies.remove('token');
      return true;
    });
  }

  return backendIsAuth;
}

</script>

<template>
  <Dialog :visible="isAuth()" modal :closable="false" class="bg-white"
    :showHeader="false" :style="{ width: '20rem' }" pt:root:class="!border-0 pt-4  overflow-hidden !bg-minizo-dark !text-white"
    pt:mask:class="backdrop-blur-sm">

    <div class="flex flex-col w-full gap-2">
      <div class="flex flex-col items-center justify-center">
        <IconLogo class="w-16 h-16"/>
        <h1 class="text-lg">Authenticate</h1>
      </div>
  
      <div class="flex flex-col">
        <label for="username" class="text-white text-xs">Username</label>
        <InputText v-model="username" id="username" placeholder="Username" />
      </div>
  
      <div class="flex flex-col w-full">
        <label for="password" class="text-white text-xs">Password</label>
        <Password v-model="password" v-on:keyup.enter="handler" id="password" :feedback="false" placeholder="Password" toggleMask  />
      </div>
  
      <div class="flex flex-col w-full">
        <Button type="button" label="Authenticate" severity="danger" class="!w-full" @click="handler"></Button>
      </div>
    </div>
  </Dialog>
</template>