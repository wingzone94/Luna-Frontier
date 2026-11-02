import { defineConfig } from 'vite';
import { resolve } from 'path';

// 成果物は dist/ に出す（ソースと同階層に混ぜない）。
// PHP 側は inc/theme-setup.php の node_resolve_vite_build() で
// dist/.vite/manifest.json → assets/.vite/manifest.json の順に解決する。
// assets/ には images/, fonts/, pwa/, 手動配置の blocks.js と、配布 ZIP 単体でも
// 動くように残して置く旧ハッシュバンドル（1世代分のフォールバック）があるため、
// assets を outDir にする運用には戻さない（emptyOutDir は dist を離れると発火しない）。
export default defineConfig({
  base: './',
  build: {
    manifest: true,
    outDir: 'dist',
    emptyOutDir: true,
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
            if (id.includes('node_modules/gsap')) {
              return 'gsap-lib';
            }
            return 'vendor';
          }
        }
      }
    }
  }
});
