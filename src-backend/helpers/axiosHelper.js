import axios from 'axios';

const baseUrl = 'https://musicbrainz.org/ws/2';

let defaultConfig = {
  maxBodyLength: Infinity,
  headers: { 
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'Authorization': `Bearer ${process.env.MUSICBRAINZ_TOKEN}`
  },
};

export async function musicbrainzSearch(artist, track) {
  try {
    const response = await axios.request({
      ...defaultConfig,
      method: 'get',
      url: `${baseUrl}/release?query=track:${track} AND artist:${artist}&fmt=json`
    });
    return response.data;
  } catch (error) {
    throw error.response;
  }
}

export async function musicbrainzRelease(releaseId) {
  try {
    const response = await axios.request({
      ...defaultConfig,
      method: 'get',
      url: `${baseUrl}/release/${releaseId}?fmt=json`
    });
    return response.data;
  } catch (error) {
    throw error.response;
  }
}