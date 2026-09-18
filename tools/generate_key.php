<?php
/**
 * アカウント暗号鍵(pips_account_key.php)を生成する、初回セットアップ専用のCLIスクリプト。
 *
 * 【なぜこれが要るのか】
 * p-drive側の鍵(p_drive_storage_key.php)は、無ければ本体が自分で作ります
 * (PDriveEngine::loadOrCreateStorageKey())。しかしアカウント本体の鍵だけは
 * 自動生成されません。MeikieeSecureStore::key() は鍵が見つからなければ
 * error_log を残して null を返すだけで、その結果アカウントが1件も読めません。
 *
 * これは設計ミスではなく安全側の判断です。アカウント台帳の鍵を「無ければ作る」に
 * すると、鍵ファイルを見失ったサーバーが黙って新しい鍵を作り、既存の
 * account.jsonl を復号できないまま「アカウントが0件」として平常運転を始めて
 * しまいます。利用者から見れば全員のアカウントが消えたのと同じで、しかも
 * エラーは出ません。だから本番の起動経路では絶対に鍵を作らせず、
 * 「人間が一度だけ明示的に作る」この入口に限定しています。
 *
 * 【使い方】
 *   php tools/generate_key.php <鍵を置くディレクトリ>
 *
 * 例(推奨の配置。DocumentRoot の外側であることが重要):
 *   php tools/generate_key.php /var/www/.pusyuuHiddenFiles
 *
 * 【ファイル名を変えないこと】
 * p-meikiee/index.php の KEY_FILE_CANDIDATES が
 * '.pusyuuHiddenFiles/pips_account_key.php' を探します。"pips_" は、この仕組みが
 * PIPS というサービスから育った名残です。名前として据わりは悪いのですが、
 * 改名すると既存の account.jsonl を積み上げてきた稼働環境が鍵を見失うため、
 * 互換性のためにそのままにしています。
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  fwrite(STDERR, "このスクリプトはコマンドラインからのみ実行できます。\n");
  exit(1);
}

$dir = $argv[1] ?? '';
if ($dir === '') {
  fwrite(STDERR, "使い方: php tools/generate_key.php <鍵を置くディレクトリ>\n");
  fwrite(STDERR, "例:     php tools/generate_key.php /var/www/.pusyuuHiddenFiles\n");
  exit(1);
}

if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
  fwrite(STDERR, "ディレクトリを作成できませんでした: {$dir}\n");
  exit(1);
}

$path = rtrim($dir, "/\\") . '/pips_account_key.php';

// 【上書きしないこと】既存の鍵を上書きすると、その鍵で暗号化された
// account.jsonl が二度と読めなくなります。失敗として止めます。
if (file_exists($path)) {
  fwrite(STDERR, "既に鍵が存在します。上書きしません: {$path}\n");
  fwrite(STDERR, "作り直したい場合は、既存の account.jsonl が読めなくなることを理解した上で\n");
  fwrite(STDERR, "手動で退避・削除してから再実行してください。\n");
  exit(1);
}

$raw = random_bytes(32); // AES-256-GCM の鍵長。32バイト以外は本体が弾きます。
$php = "<?php\nreturn '" . base64_encode($raw) . "';\n";

if (@file_put_contents($path, $php) === false) {
  fwrite(STDERR, "鍵を書き込めませんでした: {$path}\n");
  exit(1);
}
@chmod($path, 0600);

echo "鍵を作成しました: {$path}\n";
echo "\n";
echo "次に確認してください:\n";
echo "  1. このディレクトリが Web から配信されない場所にあること\n";
echo "     (DocumentRoot の外側。念のため Require all denied の .htaccess も置く)\n";
echo "  2. このファイルをバックアップしたこと\n";
echo "     失うと、既存の全アカウントが復号できなくなり復旧手段はありません\n";
echo "  3. p-meikiee/ から見て最大8階層上まで遡れば見つかる位置にあること\n";
echo "     (MeikieeSecureStore::findUpward() が上へ辿って探します)\n";
