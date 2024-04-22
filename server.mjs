import dotenv from 'dotenv';
import express from 'express';
import basicAuth from 'express-basic-auth';
import { backendRouter } from './src-backend/routes/routes-backend.js';
import { frontendRouter } from './src-backend/routes/routes-frontend.js';
import { hasEnvArgs } from './src-backend/helpers/bitwiseHelper.js';

dotenv.config();

const app = express();
const port = process.env.PORT || 3000;
const usr = process.env.AUTH_USER;
const pass = process.env.AUTH_PASS;

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

const apiAuthMiddleware = (req, res, next) => {
  if (usr && pass && req.originalUrl !== "/api/info") {
    basicAuth({
      users: { [usr]: pass },
      unauthorizedResponse: function getUnauthorizedResponse(req) {
        return req.auth
          ? { status: 401, message: 'Credentials rejected' }
          : { status: 401, message: 'No credentials provided' };
      }
    })(req, res, next);
  } else {
    next();
  }
};

app.use(express.json());
app.use('/api', apiAuthMiddleware, backendRouter);
app.use('/', frontendRouter);

app.listen(port, () => {
  console.log(`Minizo Server listening at http://localhost:${port}`);
});