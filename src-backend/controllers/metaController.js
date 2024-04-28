import * as path from 'path';
import { execa } from 'execa';
import * as fs from 'fs';
import {logger} from '../helpers/loggerHelper.js';
import { hasEnvArgs, isValidFormat, handleDownloadError, handleMetaError } from '../helpers/bitwiseHelper.js';
import { checkDirectoryExists, checkFileExists } from '../helpers/bitwiseHelper.js';
import { musicbrainzSearch, musicbrainzRelease } from '../helpers/axiosHelper.js';

export const getFileMeta = (req, res) => {

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


  return res.status(200).send({
    status: 200,
    message: 'response here'
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
    const response = await musicbrainzSearch(artist, track);
    return res.status(200).send({
      status: 200,
      message: response
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
      message: mbzResponse
    });
  } catch (err) {
    return res.status(500).send({
      status: 500,
      message: err
    });
  }
};