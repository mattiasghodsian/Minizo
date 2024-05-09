import { defineStore } from 'pinia';
import axios from 'axios';

let defaultConfig = {
  maxBodyLength: Infinity,
  headers: { 
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  },
};

export const useApiStore = defineStore('api', {
  state: () => ({
    name: 'Minizo',
    version: '',
    github: '',
    environment: '',
    musicStorage: '',
    auth: false,
    username: '',
    password: '',
    authStatus: false,
    directories: [],
    currentViewDIrectory: '',
    fileList: {},
    fileMetaData: []
  }),
  actions: {
    async getAbout() {
      return await axios.request({
        ...defaultConfig,
        method: 'get',
        url: `api/info`
      }).then((response) => {
        this.name = response.data.name;
        this.version = response.data.version;
        this.github = response.data.github;
        this.environment = response.data.environment;
        this.musicStorage = response.data.musicStorage;
        this.auth = response.data.auth;
        this.directories = response.data.directories;
      }).catch((error) => {
        throw error.response;
      });
    },
    async testAuth() {
      return await axios.request({
        ...defaultConfig,
        method: 'get',
        url: `api/auth`,
        auth: {
          username: this.username,
          password: this.password
        }
      }).then((response) => {
        this.authStatus = true;
        return response.data;
      }).catch((error) => {
        throw error.response;
      });
    },
    async grabTrack(videoUrl: string, formatType: string, metaData: boolean, saveTo: string) {
      let axiosConfig = {
        ...defaultConfig,
        method: 'post',
        url: `api/download`,
        params: {
          url: videoUrl,
          format: formatType,
          meta: metaData,
          saveto: saveTo
        }
      }

      if (this.auth && this.authStatus){
        axiosConfig = {
          ...axiosConfig,
          auth: {
            username: this.username,
            password: this.password
          }
        };
      }

      return await axios.request(axiosConfig)
      .then((response) => {
        return response.data;
      }).catch((error) => {
        throw error.response;
      });
    },
    async viewDirectory(directoryName: string, nonAudio: boolean) {
      let axiosConfig = {
        ...defaultConfig,
        method: 'get',
        url: `api/files/${directoryName}`,
        params: {
          nonmusic: nonAudio,
        }
      }

      if (this.auth && this.authStatus){
        axiosConfig = {
          ...axiosConfig,
          auth: {
            username: this.username,
            password: this.password
          }
        };
      }

      return await axios.request(axiosConfig)
      .then((response) => {
        this.fileList = response.data;
        return response.data;
      }).catch((error) => {
        throw error.response;
      });
    },
    async deleteFile(fileName: string) {
      let axiosConfig = {
        ...defaultConfig,
        method: 'delete',
        url: `api/files/${this.currentViewDIrectory}`,
        params: {
          filename: fileName,
        }
      }

      if (this.auth && this.authStatus){
        axiosConfig = {
          ...axiosConfig,
          auth: {
            username: this.username,
            password: this.password
          }
        };
      }

      return await axios.request(axiosConfig)
      .then((response) => {
        return response.data;
      }).catch((error) => {
        throw error.response;
      });
    },
    async moveFile(fileName: string, directoryName: string) {
      let axiosConfig = {
        ...defaultConfig,
        method: 'post',
        url: `api/files/relocate/${this.currentViewDIrectory}`,
        params: {
          filename: fileName,
          directoryname: directoryName
        }
      }

      if (this.auth && this.authStatus){
        axiosConfig = {
          ...axiosConfig,
          auth: {
            username: this.username,
            password: this.password
          }
        };
      }

      return await axios.request(axiosConfig)
      .then((response) => {
        return response.data;
      }).catch((error) => {
        throw error.response;
      });
    },
    async downloadFile(fileName: string, directoryName: string) {
      let axiosConfig = {
        ...defaultConfig,
        method: 'get',
        url: `api/download/file`,
        params: {
          filename: fileName,
          directoryname: directoryName
        },
        responseType: 'blob'
      };
    
      if (this.auth && this.authStatus){
        axiosConfig = {
          ...axiosConfig,
          auth: {
            username: this.username,
            password: this.password
          }
        };
      }
    
      try {
        const response = await axios.request(axiosConfig);
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', fileName);
        document.body.appendChild(link);
        link.click();
        window.URL.revokeObjectURL(url);
        return response.data;
      } catch (error) {
        throw error.response;
      }
    },
    async metaSearch(artist: string, track: string) {
      let axiosConfig = {
        ...defaultConfig,
        method: 'get',
        url: `api/meta/search`,
        params: {
          artist: artist,
          track: track,
        }
      }

      if (this.auth && this.authStatus){
        axiosConfig = {
          ...axiosConfig,
          auth: {
            username: this.username,
            password: this.password
          }
        };
      }

      return await axios.request(axiosConfig)
      .then((response) => {
        return response.data;
      }).catch((error) => {
        throw error.response;
      });
    },
    async getFileMeta(file: string){
      let axiosConfig = {
        ...defaultConfig,
        method: 'get',
        url: `api/meta/file`,
        params: {
          directoryname: this.currentViewDIrectory,
          filename: file,
        }
      }

      if (this.auth && this.authStatus){
        axiosConfig = {
          ...axiosConfig,
          auth: {
            username: this.username,
            password: this.password
          }
        };
      }

      return await axios.request(axiosConfig)
      .then((response) => {
        return response.data;
      }).catch((error) => {
        throw error.response;
      });
    }
  },
  getters: {
  },
});