import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [
    vue(),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src-frontend', import.meta.url))
    }
  },
  optimizeDeps: {
    exclude: ['node_modules', 'dist', 'src-backend', 'src-frontend/assets/styles/fonts']
  }
  // build: {
  //   assetsInlineLimit: 0, // Ensure assets are always emitted to the output directory
  // },
  // assetsInclude: [
  //   '**/*.ttf',
  //   '**/*.woff',
  //   '**/*.woff2',
  // ],
})
