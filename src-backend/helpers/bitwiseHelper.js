import * as fs from 'fs';
import * as path from 'path';
import { logger } from '../helpers/loggerHelper.js';

export function hasEnvArgs() {
  return !!(process.env.MUSIC_STORAGE && process.env.FFMPEG_PATH && process.env.YOUTUBE_DL_PATH && process.env.MUSIC_EXTENSIONS);
}

export function isValidFormat(format) {
  const validFormats = ["best", "aac", "flac", "mp3", "m4a", "opus", "vorbis"];
  return validFormats.includes(format);
}

export const checkDirectoryExists = (directoryPath, res) => {
  if (!fs.existsSync(directoryPath)) {
    return res.status(404).send({
      status: 404,
      message: 'Directory not found.'
    });
  }
};

export const checkFileExists = (filePath, fileName, res) => {
  if (!fs.existsSync(filePath)) {
    return res.status(400).send({
      status: 400,
      message: `File ${fileName} not found.`
    });
  }
};

export const handleFileError = (err, fileName, res, errorMessage) => {
  logger.error(err.message);
  return res.status(500).send({
    status: 500,
    message: errorMessage || `Error processing ${fileName}.`
  });
};

export const handleDownloadError = (error, res) => {
  logger.error(error.message);
  res.status(500).send({ message: 'Download Failed.', data: error.message });
};

export const handleWriteMetaError = (error, res) => {
  logger.error(error.message);
  res.status(500).send({ message: 'Write meta Failed.', data: error.message });
};

export const handleMetaError = (error, res) => {
  logger.error(error.message);
  res.status(500).send({ message: 'Data fetching failed.', data: error.message });
};