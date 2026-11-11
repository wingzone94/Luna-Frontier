/* Run from the Node repository with: node luna-products/tests/local-regression.cjs */
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const repoRoot = path.resolve(__dirname, '../..');
const pluginDir = path.resolve(__dirname, '..');

function getSkyalowVariablesCss() {
  return execFileSync(
    'git',
    ['show', 'luna-frontier-2.0-skyalow:src/styles/_variables.css'],
    { cwd: repoRoot, encoding: 'utf8' }
  );
}

function runPhpRenderTests() {
  const phpScript = `
    define('ABSPATH', ${JSON.stringify(repoRoot + '/')});
    $GLOBALS['mock_options'] = array(
      'luna_products_amazon_id' => 'luminous-22',
      'luna_products_rakuten_id' => '',
      'luminous_nexus_amazon_id' => 'nexus-fallback-22',
      'luminous_nexus_rakuten_id' => '',
      'luna_products_disclosure_amazon' => 'Amazonのアソシエイトとして、当メディアは適格販売により収入を得ています。',
      'luna_products_disclosure_rakuten' => '当メディアは、楽天アフィリエイト・プログラムの参加者です。',
    );
    function add_action($hook, $cb) {}
    function register_setting($group, $name, $args = array()) {}
    function add_options_page($page_title, $menu_title, $cap, $slug, $cb) {}
    function plugin_dir_url($file) { return 'http://cybernode.local/wp-content/plugins/luna-products/'; }
    function __( $text, $domain = 'default' ) { return $text; }
    function esc_html__( $text, $domain = 'default' ) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
    function esc_attr__( $text, $domain = 'default' ) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
    function wp_unique_id( $prefix = '' ) { static $id = 0; return $prefix . ( ++$id ); }
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

    // Case 3: Luna Products の Amazon ID が空の場合、luminous_nexus_amazon_id にフォールバックすることを確認
    $GLOBALS['mock_options']['luna_products_amazon_id'] = '';
    $GLOBALS['mock_options']['luminous_nexus_amazon_id'] = 'nexus-fallback-22';
    $case3 = luna_products_render_card(array(
      'title'     => 'Nexus フォールバック検証',
      'spec'      => '',
      'imageId'   => 0,
      'imageUrl'  => '',
      'asin'      => 'B0FALLBACK1',
      'checkedAt' => '',
      'stores'    => array(
        array(
          'preset'    => 'amazon',
          'label'     => '',
          'color'     => '',
          'url'       => '',
          'price'     => 1980,
          'listPrice' => 0,
          'saleNote'  => '',
          'official'  => false,
        ),
        array(
          'preset'    => 'rakuten',
          'label'     => '',
          'color'     => '',
          'url'       => 'https://item.rakuten.co.jp/shop/item-01/',
          'price'     => 2180,
          'listPrice' => 0,
          'saleNote'  => '',
          'official'  => false,
        ),
      ),
    ));

    // Case 4 (1.1.0): クーポンあり/なし混在・couponCode サニタイズ（タグ除去/空白除去/64文字切り詰め）・couponLabel 空時「クーポン」・Steam色
    $long_code = '  <b>' . str_repeat('ABCD1234', 10) . "</b>\r\n ";
    $case4 = luna_products_render_card(array(
      'title'     => 'クーポン＆Steam検証カード',
      'spec'      => '',
      'imageId'   => 0,
      'imageUrl'  => '',
      'asin'      => '',
      'checkedAt' => '',
      'stores'    => array(
        array(
          'preset'      => 'amazon',
          'label'       => '',
          'color'       => '',
          'url'         => 'https://www.amazon.co.jp/dp/B0COUPON01',
          'price'       => 2980,
          'listPrice'   => 0,
          'saleNote'    => '',
          'official'    => false,
          'couponCode'  => 'ANKER10',
          'couponLabel' => '10%OFF',
        ),
        array(
          'preset'      => 'rakuten',
          'label'       => '',
          'color'       => '',
          'url'         => 'https://item.rakuten.co.jp/shop/coupon-none/',
          'price'       => 3180,
          'listPrice'   => 0,
          'saleNote'    => '',
          'official'    => false,
          'couponCode'  => '',
          'couponLabel' => '500円OFF',
        ),
        array(
          'preset'      => 'steam',
          'label'       => '',
          'color'       => '',
          'url'         => 'https://store.steampowered.com/app/10/',
          'price'       => 1980,
          'listPrice'   => 0,
          'saleNote'    => '',
          'official'    => true,
          'couponCode'  => $long_code,
          'couponLabel' => '',
        ),
      ),
    ));

    // Case 5 (1.1.0 M2): 価格なし行のみのカードでは税込注記（.luna-product-card__note）が出ないこと
    $case5_no_price = luna_products_render_card(array(
      'title'     => '直販リンクのみカード（M2検証）',
      'spec'      => '',
      'imageId'   => 0,
      'imageUrl'  => '',
      'asin'      => '',
      'checkedAt' => '2026年11月12日',
      'stores'    => array(
        array(
          'preset'    => 'nintendo',
          'label'     => '',
          'color'     => '',
          'url'       => 'https://store-jp.nintendo.com/item/software/D70010000000001',
          'price'     => 0,
          'listPrice' => 0,
          'saleNote'  => '',
          'official'  => true,
        ),
      ),
    ));

    // Case 6 (1.1.0 M3): 価格のみ・URLなしの Amazon / 楽天行では has_amazon / has_rakuten が立たずディスクロージャーが出ないこと
    $case6_price_only = luna_products_render_card(array(
      'title'     => '価格のみ・URLなしカード（M3検証）',
      'spec'      => '',
      'imageId'   => 0,
      'imageUrl'  => '',
      'asin'      => '',
      'checkedAt' => '',
      'stores'    => array(
        array(
          'preset'    => 'amazon',
          'label'     => '',
          'color'     => '',
          'url'       => '',
          'price'     => 2500,
          'listPrice' => 0,
          'saleNote'  => '',
          'official'  => false,
        ),
        array(
          'preset'    => 'rakuten',
          'label'     => '',
          'color'     => '',
          'url'       => '',
          'price'     => 2600,
          'listPrice' => 0,
          'saleNote'  => '',
          'official'  => false,
        ),
      ),
    ));

    echo json_encode(array(
      'case1'   => $case1,
      'case2'   => $case2,
      'case3'   => $case3,
      'case4'   => $case4,
      'case5'   => $case5_no_price,
      'case6'   => $case6_price_only,
      'presets' => array_values(luna_products_store_presets()),
    ), JSON_UNESCAPED_UNICODE);
  `;

  const raw = execFileSync('php', ['-r', phpScript], { encoding: 'utf8' });
  return JSON.parse(raw);
}

function runCssVariableAudit(varsCss) {
  const definedVars = new Set(
    [...varsCss.matchAll(/(--[a-zA-Z0-9_-]+)\s*:/g)].map((m) => m[1])
  );
  assert.ok(
    definedVars.has('--md-sys-color-on-surface-variant'),
    'skyalow ブランチの _variables.css に --md-sys-color-on-surface-variant が定義されている'
  );

  for (const rel of ['assets/style.css', 'assets/editor.css']) {
    const css = fs.readFileSync(path.join(pluginDir, rel), 'utf8');
    assert.ok(
      !css.includes('color-mix('),
      `${rel} に color-mix の記述が残っていないこと`
    );
    const usedVars = new Set(
      [...css.matchAll(/var\(\s*(--[a-zA-Z0-9_-]+)/g)].map((m) => m[1])
    );
    assert.ok(
      usedVars.has('--md-sys-color-on-surface-variant'),
      `${rel} が --md-sys-color-on-surface-variant を実トークン参照していること`
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
  const { case1, case2, case3, case4, case5, case6, presets } = runPhpRenderTests();

  assert.equal(presets.length, 14, 'プリセットレジストリは全14種（Tier1:2 + Tier2:6 + Tier3:6）');
  const steamPreset = presets.find((p) => p.id === 'steam');
  assert.ok(steamPreset, 'Steam プリセットが存在する');
  assert.equal(steamPreset.color, '#171A21', 'M4: Steam プリセットの色が #171A21 である');
  assert.equal(steamPreset.brand_var, '', 'M4: Steam プリセットの brand_var が空である');

  // Case 1: 価格整形・OFFバッジ・最安チップ・rel属性・ディスクロージャー・上限4行・Luna Products ID 優先
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
    'Luna Products 側に Amazon ID がある場合はそちらが優先される'
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
  assert.ok(
    !case1.includes('luna-product-coupon__toggle'),
    '後方互換：couponCode 属性を持たない既存ブロックではクーポン UI が出ない'
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

  // Case 3: Nexus 設定値へのフォールバックと楽天 ID 未設定時の素の URL 出力
  assert.ok(
    case3.includes('https://www.amazon.co.jp/dp/B0FALLBACK1?tag=nexus-fallback-22'),
    'Luna Products の Amazon ID が空の場合、luminous_nexus_amazon_id へフォールバックする'
  );
  assert.ok(
    case3.includes('href="https://item.rakuten.co.jp/shop/item-01/"'),
    '楽天 ID が両方空の場合は素の URL がそのまま出力される'
  );

  // Case 4: クーポン機能（トグル・パネル・コード・サニタイズ・ラベル空フォールバック）
  assert.equal(
    (case4.match(/class="luna-product-coupon__toggle"/g) || []).length,
    2,
    'クーポンありの2行（Amazon・Steam）でのみトグルが出力され、空の楽天行では出ない'
  );
  assert.equal(
    (case4.match(/class="luna-product-coupon__panel"/g) || []).length,
    2,
    'クーポンありの2行でのみパネルが出力される'
  );
  assert.ok(
    case4.includes('>10%OFFクーポン<'),
    'couponLabel がある場合は「{label}クーポン」と表示される'
  );
  assert.ok(
    case4.includes('>クーポン<'),
    'couponLabel が空の場合は「クーポン」へフォールバックする'
  );
  assert.ok(
    case4.includes('value="ANKER10"'),
    'Amazon 行のクーポンコード ANKER10 が出力される'
  );
  const expected64 = 'ABCD1234'.repeat(8);
  assert.ok(
    case4.includes(`value="${expected64}"`) && !case4.includes('<b>'),
    'couponCode のタグ除去・空白改行除去・64文字切り詰めが機能する'
  );

  // Case 5 (M2): 価格なしのみのカードでは .luna-product-card__note が出ない
  assert.ok(
    !case5.includes('luna-product-card__note'),
    'M2: 価格なし行のみのカードでは税込注記が出力されない'
  );

  // Case 6 (M3): 価格のみ・URLなし行ではディスクロージャーが出ない（価格行自体は描画される）
  assert.ok(
    case6.includes('2,500円') && case6.includes('2,600円'),
    'M3: 価格のみ・URLなし行でも価格行自体は描画される'
  );
  assert.ok(
    !case6.includes('luna-product-card__disclosure'),
    'M3: 最終 URL が空の行では has_amazon / has_rakuten が立たずディスクロージャーが出ない'
  );

  // view.js の静的チェック（navigator.clipboard、execCommand フォールバック、1600ms 自動クローズ、aria-expanded 切替）
  const viewJs = fs.readFileSync(path.join(pluginDir, 'assets/view.js'), 'utf8');
  assert.ok(
    viewJs.includes('navigator.clipboard') && viewJs.includes('writeText'),
    'view.js に navigator.clipboard.writeText の第一手段が含まれる'
  );
  assert.ok(
    viewJs.includes('execCommand') && viewJs.includes('"copy"'),
    'view.js に document.execCommand("copy") のフォールバックが含まれる'
  );
  assert.ok(
    viewJs.includes('1600') && viewJs.includes('setTimeout'),
    'view.js に 1600ms の setTimeout 自動クローズ処理が含まれる'
  );
  assert.ok(
    viewJs.includes('aria-expanded') &&
      viewJs.includes('"true"') &&
      viewJs.includes('"false"'),
    'view.js に aria-expanded の true/false 切替が含まれる'
  );

  // 2. CSS 変数監査（luna-frontier-2.0-skyalow ブランチの src/styles/_variables.css を正として未定義変数ゼロを確認）
  const skyalowVarsCss = getSkyalowVariablesCss();
  runCssVariableAudit(skyalowVarsCss);

  // 3. Chromium によるデスクトップ（1280px）・モバイル（360px）レイアウト検証
  const pluginCss = fs.readFileSync(
    path.join(pluginDir, 'assets/style.css'),
    'utf8'
  );
  const htmlDoc = `<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>${skyalowVarsCss}\nbody { margin: 0; padding: 16px; background: var(--md-sys-color-surface); }\n${pluginCss}</style>
</head>
<body>
<main style="max-width: 864px; margin: 0 auto;">${case1}\n${case4}</main>
<script>${viewJs}</script>
</body>
</html>`;

  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    await page.setContent(htmlDoc, { waitUntil: 'domcontentloaded' });

    const desktopLayout = await page.evaluate(() => {
      const media = document.querySelector('.luna-product-card__media').getBoundingClientRect();
      const body = document.querySelector('.luna-product-card__body').getBoundingClientRect();
      const specColor = getComputedStyle(document.querySelector('.luna-product-card__spec')).color;
      const steamBg = getComputedStyle(document.querySelector('.luna-product-btn--steam')).backgroundColor;
      return {
        mediaWidth: Math.round(media.width),
        mediaHeight: Math.round(media.height),
        sideBySide: body.left >= media.right,
        overflow: document.documentElement.scrollWidth > window.innerWidth,
        specColor,
        steamBg,
      };
    });
    assert.ok(desktopLayout.sideBySide, 'デスクトップ幅（1280px）で左に画像・右に本文が横並びになる');
    assert.equal(desktopLayout.mediaWidth, desktopLayout.mediaHeight, '商品画像パネルが 1:1 の正方形');
    assert.equal(desktopLayout.overflow, false, 'デスクトップ幅で横はみ出しなし');
    assert.equal(desktopLayout.specColor, 'rgb(107, 90, 74)', 'skyalow の --md-sys-color-on-surface-variant (#6B5A4A) が適用される');
    assert.equal(desktopLayout.steamBg, 'rgb(23, 26, 33)', 'M4: Steam ボタン背景色が #171A21 (rgb(23, 26, 33)) である');

    await page.setViewportSize({ width: 360, height: 900 });
    // 360px 幅でクーポンパネルを開いた状態でも横はみ出しがないことを確認
    await page.locator('[data-coupon-toggle]').first().click();
    const mobileLayout = await page.evaluate(() => {
      const firstCard = document.querySelector('.luna-product-card');
      const media = firstCard.querySelector('.luna-product-card__media').getBoundingClientRect();
      const body = firstCard.querySelector('.luna-product-card__body').getBoundingClientRect();
      const rows = [...firstCard.querySelectorAll('.luna-product-store')].map((row) => {
        const info = row.querySelector('.luna-product-store__info').getBoundingClientRect();
        const action = row.querySelector('.luna-product-store__action').getBoundingClientRect();
        return {
          horizontal: action.left >= info.left && Math.abs(action.top - info.top) < info.height + 8,
          fitsInRow: action.right <= row.getBoundingClientRect().right + 1,
        };
      });
      const firstPanel = document.querySelector('[data-coupon-panel]');
      const firstToggle = document.querySelector('[data-coupon-toggle]');
      return {
        stacked: body.top >= media.bottom - 1,
        overflow: document.documentElement.scrollWidth > window.innerWidth,
        rows,
        panelVisible: !firstPanel.hidden && getComputedStyle(firstPanel).display !== 'none',
        ariaExpanded: firstToggle.getAttribute('aria-expanded'),
      };
    });
    assert.ok(mobileLayout.stacked, 'モバイル幅（360px）で画像と本文が縦積みになる');
    assert.equal(mobileLayout.overflow, false, 'モバイル幅（360px）・クーポン展開時でも横はみ出しなし');
    assert.equal(mobileLayout.panelVisible, true, 'トグルクリックでクーポンパネルが表示される');
    assert.equal(mobileLayout.ariaExpanded, 'true', 'トグルクリックで aria-expanded="true" になる');
    assert.ok(
      mobileLayout.rows.every((r) => r.horizontal && r.fitsInRow),
      'モバイル幅（360px）でも各ストア行の価格と CTA ボタンが横並びで潰れず収まる'
    );
  } finally {
    await browser.close();
  }

  console.log(
    'Luna Products 1.1.0: 価格整形・OFFバッジ・最安チップ・クーポン機能・M1〜M4・Nexusフォールバック・skyalow CSS変数監査・1280px/360pxレイアウト OK'
  );
})().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
