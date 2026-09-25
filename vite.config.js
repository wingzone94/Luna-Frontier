import { defineConfig } from 'vite';
import { resolve } from 'path';
import { readdirSync, unlinkSync } from 'fs';

// assets/ には images/ fonts/ pwa/ や手動配置の blocks.js があるため
// emptyOutDir は使えない。代わりにハッシュ付きバンドル（name.hash.js の
// 2ドット形式）だけをビルド開始時に削除する。
const cleanHashedBundles = () => ({
  name: 'clean-hashed-bundles',
  buildStart() {
    const dir = resolve(__dirname, 'assets/js');
    for (const f of readdirSync(dir)) {
      if (/^.+\..+\.js$/.test(f)) unlinkSync(resolve(dir, f));
    }
  }
});

export default defineConfig({
  base: './',
  plugins: [cleanHashedBundles()],
  build: {
    manifest: true,
    outDir: 'assets',
    emptyOutDir: false,
    rollupOptions: {
      input: {
        main: resolve(__dirname, 'src/main.js'),
        style: resolve(__dirname, 'src/styles/style.css'),
        editor: resolve(__dirname, 'src/editor.js'),
        luna: resolve(__dirname, 'src/luna/luna.js'),
        'luna-style': resolve(__dirname, 'src/luna/styles/luna.css'),
        'luna-spotlight': resolve(__dirname, 'src/luna/styles/luna-spotlight.css'),
        'luna-archive': resolve(__dirname, 'src/luna/styles/luna-archive.css')
      },
      output: {
        entryFileNames: (chunkInfo) => (
          chunkInfo.name && String(chunkInfo.name).startsWith('luna')
            ? 'js/[name].js'
            : 'js/[name].[hash].js'
        ),
        chunkFileNames: (chunkInfo) => (
          chunkInfo.name && String(chunkInfo.name).startsWith('luna')
            ? 'js/[name].js'
            : 'js/[name].[hash].js'
        ),
        assetFileNames: (assetInfo) => {
          if (assetInfo.name.endsWith('.css')) {
            return 'css/[name].[ext]';
          }
          return '[ext]/[name].[ext]';
        },
        manualChunks: (id) => {
          if (id.includes('node_modules')) {
            if (
              id.includes('node_modules/colorthief')
              || id.includes('node_modules/@lokesh.dhakar')
              || id.includes('node_modules/ndarray')
              || id.includes('node_modules/cwise-compiler')
              || id.includes('node_modules/uniq')
              || id.includes('node_modules/iota-array')
            ) {
              return 'color-tools';
            }
            return 'vendor';
          }
        }
      }
    }
  }
});
