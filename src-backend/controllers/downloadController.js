import * as path from 'path';
import { execa } from 'execa';
import * as fs from 'fs';
import { isValidFormat, handleDownloadError } from '../helpers/bitwiseHelper.js';
import { checkDirectoryExists, checkFileExists } from '../helpers/bitwiseHelper.js';

export const download = (req, res) => {

  const storage = process.env.MUSIC_STORAGE || null;
  const url = req.query.url || null;
  const meta = req.query.meta || "false";
  const saveTo = req.query.saveto || null;
  const format = req.query.format || "flac";

  if (!isValidFormat(format)) {
    return res.status(400).send({
      status: 400,
      message: 'Invalid format specified.'
    });
  }

  if (url === null || saveTo === null) {
    return res.status(400).send({
      status: 400,
      message: 'url & saveto must be provided.'
    });
  }

  const savePath = path.join(storage, saveTo);

  let ytdlArgs = [
    '-x',
    `--audio-format`,
    format,
    '--audio-quality',
    '0',
    '-o',
    `${savePath}/%(artist)s - %(title)s.%(ext)s`,
    '--force-overwrites'
  ];

  if (format === "mp3" || format === "mp4") {
    ytdlArgs.push('--embed-thumbnail');
  }

  if (meta === "true") {
    ytdlArgs.push(`--add-metadata`);
  }

  // add url as last arg.
  ytdlArgs.push(url);

  execa(process.env.YOUTUBE_DL_PATH, ytdlArgs)
    .then(({ stdout }) => {
      let fileName = "";
      const pathRegex = /\[ExtractAudio] Destination: (.+)/;
      const pathMatch = stdout.match(pathRegex);
      const path = pathMatch ? pathMatch[1] : null;

      if (path) {
        fileName = path.split('/').pop();
      }

      res.send({
        status: 200,
        name: fileName || null,
        type: format,
        path: savePath,
      });
    })
    .catch((error) => {
      console.log(stdout)
      handleDownloadError(error, res);
    });
};

export const downloadFile = (req, res) => {

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

  try {
    res.setHeader('Content-disposition', 'attachment; filename=' + path.basename(filePath));
    res.setHeader('Content-type', 'application/octet-stream');

    const fileStream = fs.createReadStream(filePath);
    fileStream.on('error', (err) => {
      res.status(500).send({ status: 500, message: 'Error reading file.' });
    });

    fileStream.pipe(res);
  } catch (err) {
    res.status(500).send({ status: 500, message: 'Internal server error.' });
  }
};