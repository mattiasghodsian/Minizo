import express from 'express';
import path from 'path';
import * as fs from 'fs';
import { viewDirectory, deleteFile, relocateFile } from '../controllers/directoryController.js';
import { download , getMeta, downloadFile} from '../controllers/downloadController.js';

const backendRouter = express.Router();

backendRouter.get('/files/:directory', viewDirectory);
backendRouter.delete('/files/:directory', deleteFile);
backendRouter.post('/files/relocate/:directory', relocateFile);

backendRouter.post('/download', download);
backendRouter.get('/download/file', downloadFile);
backendRouter.get('/download/meta', getMeta);

backendRouter.get('/info', (req, res) => {
  const MUSIC_STORAGE = process.env.MUSIC_STORAGE;
  const directories = fs.readdirSync(MUSIC_STORAGE)
    .map(file => path.join(MUSIC_STORAGE, file))
    .filter(dir => fs.statSync(dir).isDirectory() && !path.basename(dir).startsWith('.'))
    .map(dir => path.basename(dir)) || [];

  res.send({
    'name': 'Minizo',
    'version': '0.1.0',
    'github': 'https://github.com/mattiasghodsian/Minizo',
    'environment': process.env.ENV,
    'musicStorage': MUSIC_STORAGE,
    'auth': !!(process.env.AUTH_USER && process.env.AUTH_PASS),
    'directories': directories
  });
});

backendRouter.get('/auth', (req, res) => {
  res.status(200).send({
    'message': 'OK'
  });
});

export { backendRouter };