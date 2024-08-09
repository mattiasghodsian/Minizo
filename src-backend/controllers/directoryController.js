import * as fs from 'fs';
import * as path from 'path';
import { logger } from '../helpers/loggerHelper.js';
import { checkDirectoryExists, checkFileExists, handleFileError } from '../helpers/bitwiseHelper.js';

export const viewDirectory = (req, res) => {
  const musicExtensions = process.env.MUSIC_EXTENSIONS.split(',');
  const MUSIC_STORAGE = process.env.MUSIC_STORAGE || null;
  const directoryPath = path.join(MUSIC_STORAGE, req.params.directory);

  checkDirectoryExists(directoryPath, res);

  fs.promises.readdir(directoryPath)
    .then((files) => {
      const nonmusic = req.query.nonmusic || 'false';
      if (nonmusic && nonmusic.toLowerCase() != 'true') {
        files = files.filter(file => {
          const extension = path.extname(file).toLowerCase();
          return musicExtensions.includes(extension);
        });
      }

      res.send(files.map(function (file) {
        const lastDotIndex = file.lastIndexOf('.');
        const name = file.slice(0, lastDotIndex);
        const type = file.slice(lastDotIndex + 1);

        return {
          file: file,
          name: name,
          type: type
        };
      }));
    })
    .catch((err) => {
      handleFileError(err, '', res, 'Error reading directory.');
    });
};

export const deleteFile = (req, res) => {
  const MUSIC_STORAGE = process.env.MUSIC_STORAGE || null;
  const directoryPath = path.join(MUSIC_STORAGE, req.params.directory);
  const fileName = req.query.filename || null;
  const filePath = path.join(directoryPath, fileName);

  checkDirectoryExists(directoryPath, res);
  checkFileExists(filePath, fileName, res);

  fs.unlink(filePath, (err) => {
    if (err) {
      handleFileError(err, fileName, res, 'Error deleting file.');
    } else {
      res.status(200).send({
        status: 200,
        message: `File ${fileName} deleted successfully.`
      });
    }
  });
};

export const relocateFile = (req, res) => {
  const MUSIC_STORAGE = process.env.MUSIC_STORAGE || null;
  const directoryPath = path.join(MUSIC_STORAGE, req.params.directory);
  const fileName = req.query.filename || null;
  const directoryName = req.query.directoryname || null;
  const relocatePath = path.join(MUSIC_STORAGE, directoryName);

  checkDirectoryExists(directoryPath, res);
  checkDirectoryExists(relocatePath, res);
  checkFileExists(path.join(directoryPath, fileName), fileName, res);

  const filePath = path.join(directoryPath, fileName);
  const newFilePath = path.join(relocatePath, path.basename(filePath));

  fs.rename(filePath, newFilePath, (err) => {
    if (err) {
      handleFileError(err, fileName, res, `Error moving ${fileName}.`);
    } else {
      res.status(200).send({
        status: 200,
        message: 'File moved successfully.'
      });
    }
  });
};