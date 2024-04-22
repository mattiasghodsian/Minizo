import express from 'express';
import path from 'path';
import { createServer } from 'vite';

const env = process.env.ENV || 'dev';
const frontendRouter = express.Router();

// Serve Vite in development mode
if (env == 'dev') {
    (async () => {
        const vite = await createServer({
            server: { middlewareMode: 'html' }
        });

        const server = express();
        server.use(vite.middlewares);

        frontendRouter.use(server);
    })();
} else {
    // Serve static files in production mode
    const frontendDistPath = path.resolve('dist');
    frontendRouter.use(express.static(frontendDistPath));
    frontendRouter.get('/', (req, res) => {
        res.sendFile(path.resolve(frontendDistPath, 'index.html'));
    });
}

export { frontendRouter };
