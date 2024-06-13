import * as path from 'path';
import { execa } from 'execa';
import * as fs from 'fs';
import {logger} from '../helpers/loggerHelper.js';
import { hasEnvArgs, isValidFormat, handleDownloadError, handleMetaError, handleWriteMetaError, justLogg } from '../helpers/bitwiseHelper.js';
import { checkDirectoryExists, checkFileExists } from '../helpers/bitwiseHelper.js';
import { musicbrainzSearch, musicbrainzRelease } from '../helpers/axiosHelper.js';
import * as mm from 'music-metadata';

export const getFileMeta = async (req, res) => {

  const storage = process.env.MUSIC_STORAGE || null;
  const directory = req.query.directoryname || null;
  const fileName = req.query.filename || null;

  if (directory === null) {
    return res.status(400).send({
      status: 400,
      message: 'directory must be provided.'
    });
  }

  if (fileName === null) {
    return res.status(400).send({
      status: 400,
      message: 'filename must be provided.'
    });
  }

  const directoryPath = path.join(storage, directory);
  const filePath      = path.join(directoryPath, fileName);

  checkDirectoryExists(directoryPath, res);
  checkFileExists(filePath, fileName, res);

  await mm.parseFile(filePath)
  .then(metadata => {
    console.log(metadata);
    return res.status(200).send({
      status: 200,
      file: filePath,
      format: metadata.format ?? null,
      tags: metadata.native[metadata.format.tagTypes[0]] ?? null,
      common: metadata.common
    });
  })
  .catch(error => {
    console.error(error.message);
  });

};

export const search = async (req, res) => {

  const mbToken = process.env.MUSICBRAINZ_TOKEN || null;
  const track   = req.query.track || null;
  const artist  = req.query.artist || null;

  if (mbToken === null) {
    return res.status(400).send({
      status: 400,
      message: 'musicbrainz token missing in .env.'
    });
  }

  if (track === null || artist === null) {
    return res.status(400).send({
      status: 400,
      message: 'track and artist must be provided.'
    });
  }

  try {
    const mbzResponse = await musicbrainzSearch(artist, track);
    return res.status(200).send({
      status: 200,
      ...mbzResponse
    });
  } catch (err) {
    return res.status(500).send({
      status: 500,
      message: err
    });
  }
};

export const getRelease = async (req, res) => {

  const mbToken = process.env.MUSICBRAINZ_TOKEN || null;
  const id      = req.query.id || null;

  if (mbToken === null) {
    return res.status(400).send({
      status: 400,
      message: 'musicbrainz token missing in .env.'
    });
  }

  if (id === null) {
    return res.status(400).send({
      status: 400,
      message: 'id must be provided.'
    });
  }

  try {
    const mbzResponse = await musicbrainzRelease(id);
    return res.status(200).send({
      status: 200,
      ...mbzResponse
    });
  } catch (err) {
    return res.status(500).send({
      status: 500,
      message: err
    });
  }
};

export const writeMetaToFile = async (req, res) => {

  const beetsDB = process.env.BEETS_DB || null;
  const storage = process.env.MUSIC_STORAGE || null;
  const directory = req.query.directoryname || null;
  const fileName = req.query.filename || null;
  const releaseID = req.query.releaseid || null;
  const renameTo = req.query.rename || null;

  if (directory === null) {
    return res.status(400).send({
      status: 400,
      message: 'directory must be provided.'
    });
  }

  if (fileName === null && releaseID === null) {
    return res.status(400).send({
      status: 400,
      message: 'filename and releaseid must be provided.'
    });
  }

  const directoryPath = path.join(storage, directory);
  const filePath      = path.join(directoryPath, fileName);
  const fileExtension = path.extname(filePath);

  checkDirectoryExists(directoryPath, res);
  checkFileExists(filePath, fileName, res);

  // Remove beets db file to avoid issues
  fs.unlink(beetsDB);
  console.log(beetsDB);

  try {
    const beetProcess = execa("beet",
      ['import', '--write', '-S', releaseID, filePath],
      { stdio: ['pipe', 'pipe', 'inherit'] }
    );

    // Send 'A' to stdin after a short delay to ensure process is ready
    setTimeout(() => {
      if (beetProcess.stdin) {
        beetProcess.stdin.write('A\n');
        beetProcess.stdin.end();
      } else {
        handleWriteMetaError('Failed to send input: stdin is not available.', res);
      }
    }, 500);

    const { stderr } = await beetProcess;

    if (typeof stderr === 'undefined'){
      if (renameTo){
        let newFileName = renameTo + fileExtension;
        let newFilePath = path.join(directoryPath, newFileName);
        fs.rename(filePath, newFilePath, (err) => {
          if (err) justLogg(err);
        });
      }

      res.send({
        status: 200,
        message: `Meta import completed: ${filePath}`
      });
    }else {
      handleWriteMetaError(stderr, res);
    }
  } catch (error) {
    handleWriteMetaError(error, res);
  }
};