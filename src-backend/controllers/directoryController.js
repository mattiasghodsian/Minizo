import * as fs from 'fs';
import * as path from 'path';
import { checkDirectoryExists, checkFileExists, handleFileError } from '../helpers/bitwiseHelper.js';
import * as mm from 'music-metadata';

export const viewDirectory = async (req, res) => {
  try {
    const musicExtensions = process.env.MUSIC_EXTENSIONS.split(',');
    const MUSIC_STORAGE = process.env.MUSIC_STORAGE || null;
    const directoryPath = path.join(MUSIC_STORAGE, req.params.directory);

    checkDirectoryExists(directoryPath, res);

    let files = await fs.promises.readdir(directoryPath);

    if (req.query.nonmusic?.toLowerCase() !== 'true') {
      files = files.filter(file => musicExtensions.includes(path.extname(file).toLowerCase()));
    }

    const fileDetails = await Promise.all(
      files.map(async file => {
        const filePath = path.join(directoryPath, file);
        const extension = path.extname(file).toLowerCase();
        const name = path.basename(file, extension);
        const type = extension.slice(1);

        let bitrate = null;
        let sampleRate = null;

        // Extract metadata only for music files
        if (musicExtensions.includes(extension)) {
          try {
            const metadata = await mm.parseFile(filePath);
            bitrate = Math.round(metadata.format.bitrate / 1000) || null;
            sampleRate = Math.round(metadata.format.sampleRate) || null;
          } catch (error) {
            console.error(`Error reading metadata for file ${file}: ${error.message}`);
          }
        }

        return {
          file,
          name,
          type,
          bitrate,
          sampleRate
        };
      })
    );

    res.status(200).send(fileDetails);
  } catch (error) {
    handleFileError(error, '', res, 'Error reading directory.');
  }
}

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