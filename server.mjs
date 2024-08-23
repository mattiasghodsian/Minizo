import dotenv from 'dotenv';
import express from 'express';
import { backendRouter } from './src-backend/routes/routes-backend.js';
import { frontendRouter } from './src-backend/routes/routes-frontend.js';
import { hasEnvArgs } from './src-backend/helpers/bitwiseHelper.js';
import { verifyToken } from './src-backend/controllers/authController.js';

dotenv.config();

const app = express();
const port = process.env.PORT || 3000;

// Middleware to check if environment arguments are present
app.use((req, res, next) => {
  if (!hasEnvArgs()) {
    return res.status(400).send({
      status: 400,
      message: 'Missing env args.'
    });
  }
  next();
});

app.use(express.json());
app.use('/api', verifyToken, backendRouter);
app.use('/', frontendRouter);

app.listen(port, () => {
  console.log(`Minizo Server listening at http://localhost:${port}`);
});