/* Run from the Node repository with: node luna-products/tests/local-regression.cjs */
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const repoRoot = path.resolve(__dirname, '../..');
const pluginDir = path.resolve(__dirname, '..');

function runPhpRenderTests() {
  const phpScript = `
    define('ABSPATH', ${JSON.stringify(repoRoot + '/')});
    $GLOBALS['mock_options'] = array(
      'luna_products_amazon_id' => 'luminous-22',
      'luna_products_rakuten_id' => '',
      'luna_products_disclosure_amazon' => 'Amazonのアソシエイトとして、当メディアは適格販売により収入を得ています。',
      'luna_products_disclosure_rakuten' => '当メディアは、楽天アフィリエイト・プログラムの参加者です。',
    );
    function add_action($hook, $cb) {}
    function register_setting($group, $name, $args = array()) {}
    function add_options_page($page_title, $menu_title, $cap, $slug, $cb) {}
    function plugin_dir_url($file) { return 'http://cybernode.local/wp-content/plugins/luna-products/'; }
    function __( $text, $domain = 'default' ) { return $text; }
    function esc_html__( $text, $domain = 'default' ) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
    function esc_html( $text ) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }
    function esc_attr( $text ) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }
    function esc_textarea( $text ) { return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'); }
    function esc_url( $url ) { return htmlspecialchars(trim((string)$url), ENT_QUOTES, 'UTF-8'); }
    function wp_strip_all_tags( $text ) { return strip_tags((string)$text); }
    function get_option( $name, $default = false ) {
      return array_key_exists($name, $GLOBALS['mock_options']) ? $GLOBALS['mock_options'][$name] : $default;
    }

    require_once ${JSON.stringify(path.join(pluginDir, 'luna-products.php'))};

    $case1 = luna_products_render_card(array(
      'title'     => 'Anker Nano Charger (45W)',
      'spec'      => '45W・Display・スイングプラグ／ホワイト',
      'imageId'   => 0,
      'imageUrl'  => 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="240" height="240"><rect width="240" height="240" fill="%23ffffff"/><circle cx="120" cy="120" r="70" fill="%2300a9e0"/></svg>',
      'asin'      => 'B0EXAMPLE1',
      'checkedAt' => '2026年11月11日',
      'stores'    => array(
        array(
          'preset'    => 'amazon',
          'label'     => '',
          'color'     => '',
          'url'       => '',
          'price'     => 2790,
          'listPrice' => 3990,
          'saleNote'  => '11月7日まで',
          'official'  => false,
        ),
        array(
          'preset'    => 'rakuten',
          'label'     => '',
          'color'     => '',
          'url'       => 'https://item.rakuten.co.jp/anker/a2692/',
          'price'     => 3990,
          'listPrice' => 0,
          'saleNote'  => '',
          'official'  => false,
        ),
        array(
          'preset'    => 'anker',
          'label'     => '',
          'color'     => '',
          'url'       => 'https://www.ankerjapan.com/products/a2692',
          'price'     => 3990,
          'listPrice' => 0,
          'saleNote'  => '公式ストア特典あり',
          'official'  => true,
        ),
        array(
          'preset'    => 'custom',
          'label'     => 'e☆イヤホン',
          'color'     => '#FF5500',
          'url'       => 'https://www.e-earphone.jp/products/detail/12345/',
          'price'     => 3800,
          'listPrice' => 0,
          'saleNote'  => '',
          'official'  => false,
        ),
        array(
          'preset'    => 'yodobashi',
          'label'     => '',
          'color'     => '',
          'url'       => 'https://www.yodobashi.com/product/10000000/',
          'price'     => 3990,
          'listPrice' => 0,
          'saleNote'  => '',
          'official'  => false,
        ),
      ),
    ));

    $case2 = luna_products_render_card(array(
      'title'     => '同額最安・空行除外・直販リンクのみ検証',
      'spec'      => 'Switch パッケージ版／ダウンロード版',
      'imageId'   => 0,
      'imageUrl'  => '',
      'asin'      => '',
      'checkedAt' => '',
      'stores'    => array(
        array(
          'preset'    => 'nintendo',
          'label'     => '',
          'color'     => '',
          'url'       => 'https://store-jp.nintendo.com/item/software/D70010000000001',
          'price'     => 0,
          'listPrice' => 0,
          'saleNote'  => '公式マイニンテンドーストア',
          'official'  => true,
        ),
        array(
          'preset'    => 'amazon',
          'label'     => '',
          'color'     => '',
          'url'       => 'https://www.amazon.co.jp/dp/B0TIEPRICE',
          'price'     => 710,
          'listPrice' => 1000,
          'saleNote'  => '',
          'official'  => false,
        ),
        array(
          'preset'    => 'yodobashi',
          'label'     => '',
          'color'     => '',
          'url'       => 'https://www.yodobashi.com/product/20000000/',
          'price'     => 710,
          'listPrice' => 0,
          'saleNote'  => '',
          'official'  => false,
        ),
        array(
          'preset'    => 'rakuten',
          'label'     => '',
          'color'     => '',
          'url'       => '',
          'price'     => 0,
          'listPrice' => 0,
          'saleNote'  => '',
          'official'  => false,
        ),
      ),
    ));

    echo json_encode(array(
      'case1'   => $case1,
      'case2'   => $case2,
      'presets' => array_values(luna_products_store_presets()),
    ), JSON_UNESCAPED_UNICODE);
  `;

  const raw = execFileSync('php', ['-r', phpScript], { encoding: 'utf8' });
  return JSON.parse(raw);
}

function runCssVariableAudit() {
  const varsCss = fs.readFileSync(
    path.join(repoRoot, 'src/styles/_variables.css'),
    'utf8'
  );
  const definedVars = new Set(
    [...varsCss.matchAll(/(--[a-zA-Z0-9_-]+)\s*:/g)].map((m) => m[1])
  );

  for (const rel of ['assets/style.css', 'assets/editor.css']) {
    const css = fs.readFileSync(path.join(pluginDir, rel), 'utf8');
    const usedVars = new Set(
      [...css.matchAll(/var\(\s*(--[a-zA-Z0-9_-]+)/g)].map((m) => m[1])
    );
    const missing = [...usedVars].filter((v) => !definedVars.has(v));
    assert.deepEqual(
      missing,
      [],
      `${rel} に未定義の CSS 変数参照があります: ${missing.join(', ')}`
    );
  }
}

(async () => {
  // 1. PHP 描画とプリセット検証
  const { case1, case2, presets } = runPhpRenderTests();

  assert.equal(presets.length, 14, 'プリセットレジストリは全14種（Tier1:2 + Tier2:6 + Tier3:6）');

  // Case 1: 価格整形・OFFバッジ・最安チップ・rel属性・ディスクロージャー・上限4行
  assert.ok(case1.includes('2,790円'), '価格が3桁カンマ＋円で整形される');
  assert.ok(case1.includes('定価3,990円'), '定価が3桁カンマ＋円で整形される');
  assert.ok(case1.includes('30% OFF'), 'OFF率が (1 - 2790/3990) の切り捨てで 30% OFF になる');
  assert.equal(
    (case1.match(/luna-product-badge--cheapest/g) || []).length,
    1,
    '最安チップが1つだけ出力される'
  );
  assert.ok(
    case1.includes('luna-product-store--amazon luna-product-store--cheapest'),
    '最小価格の Amazon 行に最安クラスが付く'
  );
  assert.ok(
    case1.includes('luna-product-badge--official'),
    '直販行の公式チップが出力される'
  );
  assert.ok(
    case1.includes('https://www.amazon.co.jp/dp/B0EXAMPLE1?tag=luminous-22'),
    'ASIN とトラッキング ID から Amazon URL が構築される'
  );
  const relMatches = case1.match(/rel="sponsored noopener noreferrer"/g) || [];
  assert.equal(relMatches.length, 4, '全4行の外部リンクに rel="sponsored noopener noreferrer" が付く');
  const targetMatches = case1.match(/target="_blank"/g) || [];
  assert.equal(targetMatches.length, 4, '全4行の外部リンクに target="_blank" が付く');
  assert.ok(
    !case1.includes('google.com/s2/favicons'),
    '外部 favicon 取得サービスを使用しない'
  );
  assert.ok(
    !case1.includes('yodobashi'),
    '5行目は描画されず最大4行制限が守られる'
  );
  assert.ok(
    case1.includes('2026年11月11日 時点の価格') &&
      case1.includes('価格・セール期間は表示時点のものです') &&
      case1.includes('税込'),
    'カード内注記に税込・確認日・表示時点の注記が含まれる'
  );
  assert.ok(
    case1.includes('data-network="amazon"') &&
      case1.includes('Amazonのアソシエイトとして、当メディアは適格販売により収入を得ています。'),
    'Amazon ディスクロージャーが出力される'
  );
  assert.ok(
    case1.includes('data-network="rakuten"') &&
      case1.includes('当メディアは、楽天アフィリエイト・プログラムの参加者です。'),
    '楽天ディスクロージャーが出力される'
  );

  // Case 2: 同額時の最安チップ先頭のみ・浮動小数点切り捨て・空行除外・単一ネットワーク開示
  assert.equal(
    (case2.match(/luna-product-badge--cheapest/g) || []).length,
    1,
    '同額最小価格が複数ある場合も最安チップは先頭の1行のみ'
  );
  assert.ok(
    case2.includes('luna-product-store--amazon luna-product-store--cheapest'),
    '同額時は先頭の価格入力行（Amazon）のみ最安になる'
  );
  assert.ok(
    case2.includes('29% OFF'),
    '(1 - 710/1000) の切り捨てが浮動小数点誤差なく 29% OFF になる'
  );
  assert.ok(
    case2.includes('My Nintendo Storeで見る') && !case2.includes('>0円<'),
    '価格なし（URLのみ）の直販行は価格を表示せず CTA のみ描画する'
  );
  assert.ok(
    !case2.includes('楽天市場で見る') && !case2.includes('data-network="rakuten"'),
    'URL・価格とも空の楽天行は描画されず、楽天ディスクロージャーも出ない'
  );
  assert.ok(
    case2.includes('data-network="amazon"'),
    '使用された Amazon のディスクロージャーのみ出力される'
  );

  // 2. CSS 変数監査（未定義変数ゼロの確認）
  runCssVariableAudit();

  // 3. Chromium によるデスクトップ（1280px）・モバイル（360px）レイアウト検証
  const varsCss = fs.readFileSync(
    path.join(repoRoot, 'src/styles/_variables.css'),
    'utf8'
  );
  const pluginCss = fs.readFileSync(
    path.join(pluginDir, 'assets/style.css'),
    'utf8'
  );
  const htmlDoc = `<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>${varsCss}\nbody { margin: 0; padding: 16px; background: var(--md-sys-color-surface); }\n${pluginCss}</style>
</head>
<body>
<main style="max-width: 864px; margin: 0 auto;">${case1}</main>
</body>
</html>`;

  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    await page.setContent(htmlDoc, { waitUntil: 'domcontentloaded' });

    const desktopLayout = await page.evaluate(() => {
      const media = document.querySelector('.luna-product-card__media').getBoundingClientRect();
      const body = document.querySelector('.luna-product-card__body').getBoundingClientRect();
      return {
        mediaWidth: Math.round(media.width),
        mediaHeight: Math.round(media.height),
        sideBySide: body.left >= media.right,
        overflow: document.documentElement.scrollWidth > window.innerWidth,
      };
    });
    assert.ok(desktopLayout.sideBySide, 'デスクトップ幅（1280px）で左に画像・右に本文が横並びになる');
    assert.equal(desktopLayout.mediaWidth, desktopLayout.mediaHeight, '商品画像パネルが 1:1 の正方形');
    assert.equal(desktopLayout.overflow, false, 'デスクトップ幅で横はみ出しなし');

    await page.setViewportSize({ width: 360, height: 800 });
    const mobileLayout = await page.evaluate(() => {
      const media = document.querySelector('.luna-product-card__media').getBoundingClientRect();
      const body = document.querySelector('.luna-product-card__body').getBoundingClientRect();
      const rows = [...document.querySelectorAll('.luna-product-store')].map((row) => {
        const info = row.querySelector('.luna-product-store__info').getBoundingClientRect();
        const action = row.querySelector('.luna-product-store__action').getBoundingClientRect();
        return {
          horizontal: action.left >= info.left && Math.abs(action.top - info.top) < info.height + 8,
          fitsInRow: action.right <= row.getBoundingClientRect().right + 1,
        };
      });
      return {
        stacked: body.top >= media.bottom - 1,
        overflow: document.documentElement.scrollWidth > window.innerWidth,
        rows,
      };
    });
    assert.ok(mobileLayout.stacked, 'モバイル幅（360px）で画像と本文が縦積みになる');
    assert.equal(mobileLayout.overflow, false, 'モバイル幅（360px）で横はみ出しなし');
    assert.ok(
      mobileLayout.rows.every((r) => r.horizontal && r.fitsInRow),
      'モバイル幅（360px）でも各ストア行の価格と CTA ボタンが横並びで潰れず収まる'
    );
  } finally {
    await browser.close();
  }

  console.log(
    'Luna Products: 価格整形・OFFバッジ・最安チップ・rel属性・ディスクロージャー・CSS変数監査・1280px/360pxレイアウト OK'
  );
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
