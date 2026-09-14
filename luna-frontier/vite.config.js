import { defineConfig } from 'vite';
import { resolve } from 'path';

// Luna Frontier 2.0 / SkyAlow の子テーマ専用ビルド。
// 親テーマ（node）の vite.config.js とは entry / outDir を完全に分離し、
// 親の assets/ を一切書き換えない。
export default defineConfig({
  root: __dirname,
  base: './',
  build: {
    manifest: true,
    outDir: resolve(__dirname, 'assets'),
    emptyOutDir: false,
    rollupOptions: {
      input: {
        luna: resolve(__dirname, 'src/luna.js'),
        'luna-style': resolve(__dirname, 'src/styles/luna.css'),
        'luna-spotlight': resolve(__dirname, 'src/styles/luna-spotlight.css'),
        'luna-archive': resolve(__dirname, 'src/styles/luna-archive.css')
      },
      output: {
        // ハッシュを付けず、PHP 側で filemtime によるキャッシュバスティングを行う。
        entryFileNames: 'js/[name].js',
        chunkFileNames: 'js/[name].js',
        assetFileNames: (assetInfo) => {
          if (assetInfo.name && assetInfo.name.endsWith('.css')) {
            return 'css/[name].[ext]';
          }
          return '[ext]/[name].[ext]';
        }
      }
    }
  }
});
