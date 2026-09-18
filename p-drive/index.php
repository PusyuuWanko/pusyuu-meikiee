<?php
/**
 * Pusyuu p-drive — メイキー(Meikee)上のユーザーストレージの「顔」
 *
 * Copyright (c) 2026 ISAMI ABE
 * SPDX-License-Identifier: MIT
 *
 * 配布条件はリポジトリ同梱の LICENSE を参照してください。
 * 保存の実体は p-meikiee/index.php の PDriveEngine にあります。
 * このファイルだけを持ち出しても、置き場所が無いので意味を成しません。
 */

/**
 * =====================================================================
 * p-drive - 全サービス共通のユーザーデータストレージAPI + 「顔」(個人用
 * ファイル管理画面)
 *
 * 【実装の中身は一切ここに持たない】暗号化・保存ロジック(pDriveEngine*)は
 * すべてp-meikiee/index.php側にある。ここは2つの役割だけを持つ:
 *
 *   1. ?api=<action> … 他サービスのサーバから叩かれる中継専用API(従来通り)。
 *      p-meikiee/index.php の ?api=p_drive_<action> へそのまま転送し、
 *      応答をそのまま返すだけ。認証は合言葉(api_secret)のみ。
 *
 *   2. それ以外(ブラウザからの直接アクセス) … ユーザー自身がログインして、
 *      自分のstorage_id配下の「files」(どのサービスにも属さない個人用
 *      ファイル)を閲覧・アップロード・ダウンロード・削除できるHTML画面。
 *      これがp-driveの「顔」。ここも実装(保存ロジック)は一切持たず、
 *      1で使っているのと**同じ汎用ハンドル**(p_drive_put/get/list/delete)を
 *      呼ぶだけ(専用のバックエンドAPIを新設しない)。
 *
 * ログインはp-memo/pipsと同じ「各サービスの入口へ直接アカウント連携コードを
 * 書き込む」方式(ACCOUNTS_INTEGRATION_SPEC.md パターンB/C)。p-drive自身が
 * 自分の$_SESSIONにaccountsトークンを持つ、p-memo等と対等な1つのサービスに
 * なる(これまでは中継専用でセッションを一切持たなかった)。
 * =====================================================================
 */

declare(strict_types=1);

// セッションCookie自体の属性をphp.ini任せにせず明示します。
// session_start()より前でしか効かないので、必ずこの位置に置くこと。
//
// 【SameSiteは必ずLax】Strictにすると、メイキィのログイン画面から戻ってきた
// トップレベル遷移にCookieが送られず、毎回「別のセッション」として扱われます。
// 往路で控えたstateごと消えるので照合が必ず外れ、ログインが永久に成立しません。
// php.ini任せにしていると、サーバ設定が変わっただけで黙ってこの状態になります。
//
// 【secureは実際にHTTPSで来ているかで決める】無条件にtrueにすると、HTTPで
// 配信されている環境ではブラウザがセッションCookieを一切保存できず、ログイン状態が
// 1リクエストも保ちません。逆に無条件にfalseだと、HTTPS環境で保護が1段落ちます。
// (メイキィ側 p-meikiee/index.php も同じ判定で揃えてあります。)
$pusyuuHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
  || (($_SERVER['SERVER_PORT'] ?? '') === '443')
  || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
session_set_cookie_params([
  'lifetime' => 0,
  'path'     => '/',
  'secure'   => $pusyuuHttps,
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_cache_limiter('nocache');
session_start();
// 古いInternet Explorer向けの互換宣言(p-meikiee/p-memoと同じもの。実際の
// プライバシーポリシーの内容を表すものではない)。
header('P3P: CP="CAO PSA OUR"');

$dir = "./";
$directry = $dir . "../main/pusyuusystem/scripts/php_scripts/panic_handler.php";
if (file_exists($directry)) {
  require_once($directry);
}

$directry = $dir . "../main/pusyuusystem/scripts/php_scripts/usage_tracker.php";
if (file_exists($directry)) {
  require_once($directry);
}
if (function_exists('pusyuuRecordProductUsage')) {
  pusyuuRecordProductUsage('p-drive');
}
$directry = $dir . "../main/pusyuusystem/scripts/php_scripts/notation.php";
if (file_exists($directry)) {
  require_once($directry);
}

// このエコシステムはサービスごとに独立したバーチャルホスト構成のため、
// ループバック接続は常にルートの/index.php宛にし、Hostヘッダーで実際の
// 振り分け先を指定する(p-memo/pips等と同じ慣習)。
// 定数名は共有スクリプト(main/pusyuusystem/scripts/php_scripts/meikiee_client.php)が
// 参照するものに揃えてある。名前を変えると読まれなくなるので変えないこと。
//
// 【この4つは共有スクリプトを読み込む「前」に定義すること】あちらは
// if (!defined(...)) で囲ってあるので、先に定義しておけばこちらの値が優先されます。
// 後ろに置くと、あちらの既定値が先に入ってしまい、下のタイムアウト10秒が効きません。
// p-driveだけ10秒にしているのは、大きなファイルの読み書きを中継するためです
// (他プロダクトは5秒)。
if (!defined('PUSYUU_ACCOUNTS_BASE_URL')) {
  define('PUSYUU_ACCOUNTS_BASE_URL', 'https://127.0.0.1/index.php');
}
if (!defined('PUSYUU_ACCOUNTS_HOST')) {
  define('PUSYUU_ACCOUNTS_HOST', 'p-meikiee.pusyuuwanko.com');
}
if (!defined('PUSYUU_ACCOUNTS_API_TIMEOUT')) {
  define('PUSYUU_ACCOUNTS_API_TIMEOUT', 10);
}
if (!defined('PUSYUU_ACCOUNTS_KEY_FILE_CANDIDATES')) {
  define('PUSYUU_ACCOUNTS_KEY_FILE_CANDIDATES', ['.pusyuuHiddenFiles/pips_account_key.php']);
}
// このp-drive自身の公開バーチャルホスト名(ログインの戻り先URL組み立て用)。
if (!defined('P_DRIVE_SELF_HOST')) {
  define('P_DRIVE_SELF_HOST', $_SERVER['HTTP_HOST'] ?? 'p-drive.pusyuuwanko.com');
}

// =====================================================================
// アカウント機能の実体は p-drive の中にはありません。共有スクリプトが提供する
// pusyuuAccount〜() へ頼み、p-drive はその答えを自分の言葉へ翻訳するだけです。
//
// 以前はここに、メイキィと話すクラスの全文(500行あまり)が埋め込まれていました。
// 同じものが6プロダクトに複製され「直したら他へ貼り替える」運用で揃えることに
// なっていましたが、実際には揃わず、toolboxの複製だけが1文字違っていたせいで
// toolboxのアカウント機能が全滅していました(2026-09-06)。持ち物が1つなら、
// そもそも食い違いようがありません。
//
// 【使うときは必ず PDriveAccount を通すこと】
// 本文から直接 pusyuuAccount〜() を呼ばないでください。共有スクリプトが無い環境では
// それらの関数自体が存在せず、「Call to undefined function」で致命的エラーになり、
// p-driveのページが1行も表示されなくなります。
//
// 読み込み方は上の panic_handler / usage_tracker / notation と同じです。
// 上へ探しには行きません。単独で動かすというのは「階層をずらす」ことではなく
// 「別の環境へ丸ごと持って行く」ことなので、その先には main/ ごと無いのが普通で、
// そのときはアカウント機能だけが畳まれた状態になります。
// (p-driveはファイル置き場そのものがメイキィの中にあるため、その状態では
//  マイファイル画面は空になります。それでも致命的エラーにはならず、
//  理由が画面に出ます。)
// =====================================================================
$directry = $dir . "../main/pusyuusystem/scripts/php_scripts/meikiee_client.php";
if (file_exists($directry)) {
  require_once($directry);
}

// ================================================================
// p-drive から見たアカウント機能(p-driveの「顔」)
//
// ここが持つのは「p-driveにとっての意味」だけです——自分のセッションのどのキーに
// トークンを入れるか、マイファイルの置き場をどう指すか、使えないときに何を返すか。
// ================================================================
class PDriveAccount {
  /** アカウント機能が今使えるかどうか。他のメソッドは全部これを先に見ます。 */
  public static function ready(): bool {
    if (function_exists('pusyuuAccountReady')) {
      $result = pusyuuAccountReady();
    } else {
      // 共通のアカウント連携ファイルが置かれていないサーバ。単体で動かすための道です。
      $result = false;
    }

    return $result;
  }

  /** 使えないときの理由(利用者にそのまま見せてよい1文)。 */
  public static function unavailableReason(): string {
    if (function_exists('pusyuuAccountUnavailableReason')) {
      $result = pusyuuAccountUnavailableReason();
    } else {
      $result = 'このサーバにはアカウント連携機能が設置されていないため、マイファイルはご利用いただけません。';
    }

    return $result;
  }

  /** 直近のログインが失敗していれば、その理由を1度だけ返します(読んだら消えます)。 */
  public static function takeSignInError(): string {
    if (function_exists('pusyuuAccountTakeSignInError')) {
      $result = pusyuuAccountTakeSignInError();
    } else {
      $result = '';
    }

    return $result;
  }

  /**
   * 「使えるなら $work を実行し、使えないなら $fallback を返す」。
   * 判定をここ1箇所に集めておけば、メソッドを足すときに書き忘れようがありません。
   * $fallback は引数なので先に評価されます。軽いものだけを渡してください。
   */
  private static function whenReady(callable $work, $fallback) {
    if (self::ready()) {
      $result = $work();
    } else {
      $result = $fallback;
    }

    return $result;
  }

  /** 使えないときに、保存系の関数が返す形。呼び出し側の分岐を増やさないため形を揃えます。 */
  private static function unavailable(): array {
    return ['ok' => false, 'error' => 'account_unavailable', 'message' => self::unavailableReason()];
  }

  /** p-drive自身のセッションが持っているトークン。未ログインなら空文字。 */
  public static function token(): string {
    return pDriveAccountsToken();
  }

  /**
   * トークンの持ち主。['ok'=>bool,'user'=>array,'rejected'=>bool,'message'=>string]。
   *
   * 【rejected を必ず見てください】「ok以外なら全部ログアウト」と書くと、メイキィが
   * 一時的に落ちただけで利用者のセッションが破棄され、復旧しても未ログインのままに
   * なります。捨ててよいのは「そのトークンはもう無効だ」と答えが返ったときだけです。
   */
  public static function sessionResult(): array {
    $token = self::token();

    if ($token === '') {
      // 未ログイン。rejected を false にしておくのが要点です。true にすると
      // 呼び出し側が「無効なトークンだった」と解釈してセッションを破棄します。
      $result = ['ok' => false, 'rejected' => false, 'message' => ''];
    } else {
      $result = self::whenReady(static function () use ($token) {
        return pusyuuAccountSelf($token);
      }, ['ok' => false, 'rejected' => false, 'message' => self::unavailableReason()]);
    }

    return $result;
  }

  /** ログイン画面へのリンク。使えないときは空文字(リンクを出さないでください)。 */
  public static function loginUrl(): string {
    return self::whenReady(static function () {
      return pusyuuAccountSignInUrl();
    }, '');
  }

  /** ログアウトの戻り先URL。使えないときは自分のトップへ戻します。 */
  public static function logoutUrl(): string {
    return self::whenReady(static function () {
      return pusyuuAccountSignOutUrl();
    }, './');
  }

  /** 手元のトークンを失効させます(画面遷移は伴いません)。 */
  public static function revokeToken(string $token): void {
    if (self::ready()) {
      pusyuuAccountRevokeToken($token);
    }
  }

  // ----------------------------------------------------------------
  // マイファイルの置き場
  //
  // 実体はメイキィの中にあります。$storageId はログイン時に受け取ったハッシュです。
  // $service には PDRIVE_FILES_SERVICE を渡してください(どのサービスにも属さない
  // 個人用ファイルの区画です)。
  //
  // 【ファイルは手元保存の受け皿へ落としません】ここが扱うのは利用者がアップロード
  // した実ファイルで、ブラウザのセッションに置ける量ではありません。中途半端に手元へ
  // 貯めると、保存できたつもりで消える方が、保存できないと分かるより有害です。
  // 使えないときは、はっきり失敗を返して理由を見せます。
  // ----------------------------------------------------------------

  public static function storageList(string $storageId, string $service): array {
    return self::whenReady(static function () use ($storageId, $service) {
      return pusyuuAccountStorageList($storageId, $service);
    }, self::unavailable());
  }

  public static function storageGet(string $storageId, string $service, string $key): array {
    return self::whenReady(static function () use ($storageId, $service, $key) {
      return pusyuuAccountStorageGet($storageId, $service, $key);
    }, self::unavailable());
  }

  public static function storagePut(string $storageId, string $service, string $key, string $value): array {
    return self::whenReady(static function () use ($storageId, $service, $key, $value) {
      return pusyuuAccountStoragePut($storageId, $service, $key, $value);
    }, self::unavailable());
  }

  public static function storageDelete(string $storageId, string $service, string $key): array {
    return self::whenReady(static function () use ($storageId, $service, $key) {
      return pusyuuAccountStorageDelete($storageId, $service, $key);
    }, self::unavailable());
  }

  public static function storageUsage(string $storageId): array {
    return self::whenReady(static function () use ($storageId) {
      return pusyuuAccountStorageUsage($storageId);
    }, self::unavailable());
  }

  public static function storageBreakdown(string $storageId): array {
    return self::whenReady(static function () use ($storageId) {
      return pusyuuAccountStorageBreakdown($storageId);
    }, self::unavailable());
  }

  public static function sweepOrphanedChunks(string $storageId, string $service, int $maxAgeSeconds): array {
    return self::whenReady(static function () use ($storageId, $service, $maxAgeSeconds) {
      return pusyuuAccountStorageSweepChunks($storageId, $service, $maxAgeSeconds);
    }, self::unavailable());
  }
}

// =====================================================================
// 【削除済み】?api=<action> の中継
//
// 以前ここには「他サービスから ?api=put 等で呼ばれたら、中身を見ずにメイキィの
// ?api=p_drive_<action> へ転送して、返事をそのまま返す」という取次口があった。
// 2つの理由で廃止した。
//
// 1. 保存の実体はメイキィの中にあり、p-driveは「マイファイル」画面(顔)だけを
//    担当する。実体側が既に同じ操作を公開しているので、各サービスはメイキィへ
//    直接話せばよく、この一段は同じHTTPの配管を二重に持つだけだった。
//
// 2. そして何より、この取次口は**認証が無かった**。呼び出し側が持ってきた合言葉を
//    検証しないまま、転送時にp-drive自身の正規の合言葉を付け直して送っていたため、
//    誰でも(インターネットから直接)全ユーザーの保存データを読み・書き・削除できた。
//    保存先の指定に使うstorage_idは、公開プロフィールのアバターURLに現れるため
//    秘密ではなく、事実上の無認証ゲートウェイになっていた。
//
// 【復活させないこと】どうしても中継が必要になった場合でも、転送前に呼び出し側の
// api_secretを必ず検証すること。検証せずに自分の合言葉を付け直す形は、
// 「自分の権限を誰にでも貸す」のと同じ。
// =====================================================================

// =====================================================================
// ここから下(ブラウザからの直接アクセス)が「顔」。
// =====================================================================

// ---------------------------------------------------------------------
// ログイン連携(p-memo/index.phpの無音SSOセクションと同じもの)。
// ---------------------------------------------------------------------

/** 本人確認(p-meikiee)に使うトークン。 */
function pDriveAccountsToken(): string {
  return isset($_SESSION['pusyuu_accounts_token']) && is_string($_SESSION['pusyuu_accounts_token'])
    ? $_SESSION['pusyuu_accounts_token']
    : '';
}

/** ログイン中ならuser配列(userid=storage_id/username/name等)、そうでなければnull。1リクエスト中は結果をキャッシュする。 */
function pDriveCurrentUser(): ?array {
  static $checked = false;
  static $user = null;

  // 2回目以降は $checked が true なので、この塊ごと素通りして控えをそのまま返します。
  if ($checked === false) {
    $checked = true;
    $token = pDriveAccountsToken();

    if ($token === '') {
      $user = null;
    } else {
      $res = PDriveAccount::sessionResult();

      if (!empty($res['ok'])) {
        $user = $res['user'];
      } else {
        // 【失敗しても、いつでも控えを捨ててよいわけではありません】
        // 以前ここは「ok以外なら問答無用でトークンを消す」と書かれていました。その結果、
        // メイキィが一時的に落ちているだけで利用者がログアウト扱いになり、復旧しても
        // 未ログインのまま、という状態になります。捨ててよいのは「そのトークンはもう
        // 無効だ」とメイキィが答えたときだけです。届かなかっただけのときは何もしません。
        if (!empty($res['rejected'])) {
          unset($_SESSION['pusyuu_accounts_token']);
        }
        $user = null;
      }
    }
  }

  return $user;
}

// ログインの往復。
//
// 手順そのものは全プロダクト共通なので、共有スクリプトに任せます。
// p-drive固有なのは次の1点だけで、それを silent の判定として渡しています。
//
//   ファイル配信中(?download=)は往復を始めない
//   (往復に入るとブラウザが別ページへ飛び、ダウンロードそのものが壊れるため)
//
// 【往復を自前で書き直さないこと】
// 以前ここには、共通クラスと同じ手順を丸ごと写して上の条件を足したものが
// 27行ほど並んでいました。当時は「共通クラスは全プロダクトで一字一句同じ」という
// 決まりだったため、条件をあちらへ入れることも、あちらを使うこともできなかった
// からです。しかしその結果、**引き渡しコードを交換する前の照合**が
// pips・p-drive・p-chat・p-reversi の4箇所に手書きで複製されていました。
// あの照合は、コードを盗んだ第三者が自分のブラウザでこのページを開くだけで、
// こちらが代わりに交換して盗んだ側へ被害者のトークンを渡してしまうのを防ぐ、
// 唯一の砦です。1箇所でも書き忘れれば穴が開き、しかも普段は正常に動くので
// 誰も気づけません。固有の事情は下の options で表現できるので、往復そのものは
// 必ず共有スクリプトへ通してください。
if (function_exists('pusyuuAccountHandleSignIn')) {
  pusyuuAccountHandleSignIn(
    static function (): bool {
      return pDriveAccountsToken() !== '';
    },
    static function (array $user, string $token): void {
      session_regenerate_id(true); // ログイン=権限昇格。セッション固定化を防ぐ
      $_SESSION['pusyuu_accounts_token'] = $token;
    },
    [
      'silent' => static function (): bool {
        if (isset($_GET['download'])) {
          // ダウンロード配信中は往復に入りません。入るとブラウザが別ページへ
          // 飛んでしまい、ダウンロードそのものが壊れます。
          $start = false;
        } else {
          // それ以外は既定と同じ「1ブラウザセッションにつき1回だけ」。
          $start = empty($_SESSION['pusyuu_sso_checked']);
        }

        return $start;
      },
    ]
  );
}

// ログインに失敗していたら、その理由を画面へ出すために受けておきます。
// 拾わないと、失敗は誰にも見えません(判定はリダイレクトの直前で終わり、その
// リクエストはそのまま消えるため)。読めるのは1度だけなので変数へ受けます。
$pDriveSignInError = PDriveAccount::takeSignInError();

if (!isset($_SESSION['pdrive_csrf'])) {
  $_SESSION['pdrive_csrf'] = bin2hex(random_bytes(32));
}
function pDriveCsrfIsValid(): bool {
  return isset($_POST['csrf']) && is_string($_POST['csrf'])
    && isset($_SESSION['pdrive_csrf'])
    && hash_equals($_SESSION['pdrive_csrf'], $_POST['csrf']);
}

// ---------------------------------------------------------------------
// マイファイル(service='files')。実装は持たず、p_drive_put/get/list/delete
// という他サービスと全く同じ汎用ハンドルだけを呼ぶ。保存形式(<id>.meta +
// <id>.c0..N)も、以前p-meikiee側に一時的に作った実装と同じ考え方。
// ---------------------------------------------------------------------

// メタ情報(小さなJSON)とチャンク本体(任意のバイナリ)は、同じ1つの
// serviceにまとめて置く('p-drive'という名前。p_drive_storage配下、他の
// 各サービスのフォルダ('p-meikiee'・'p-memo'等)と同じ階層に並ぶため、
// マイファイル機能ぶんの保存領域だとひと目でわかるようにこの名前にしている)。
// キーの命名で衝突は起きない(メタは素の$fileId、チャンクは
// pDriveFilesChunkKey()が付ける".cN"サフィックス付き)。
//
// 【以前は分けていた理由と、今は分けていない理由】p_drive_list/p_drive_getは
// p-meikiee側でJSON応答に組み立てて返す(pmeikieeApiRespond()がjson_encode()
// する)ため、応答に含める値は本来有効なUTF-8文字列である必要がある。以前は
// これに対処するため、バイナリを含みうるチャンク本体を一覧取得(list、
// service配下の全項目をまとめて返す)に巻き込まないよう、メタとチャンクを
// 別serviceに分けていた。現在はp-meikiee側のエンジン自体(pDriveEncodeValueForJson/
// pDriveEncodeItemForJson)が、値ごとに有効なUTF-8か自動判定し、不正なら
// base64化してencodingフィールドを付けて返すようになったため、この問題は
// エンジン側で構造的に解決済み(加えてこのファイル自身もチャンク保存時に
// base64化しているため、ここで送る値は常に有効なUTF-8でもある)。よって
// 分ける必然性が無くなり、保存領域を分散させないために1つにまとめている。
const PDRIVE_FILES_SERVICE = 'p-drive';
const PDRIVE_FILES_MAX_UPLOAD_BYTES = 1024 * 1024 * 1024; // 1GB(全チャンク合計)
const PDRIVE_FILES_CHUNK_FALLBACK_BYTES = 1 * 1024 * 1024;
const PDRIVE_FILES_CHUNK_SAFETY_MARGIN = 0.8;
const PDRIVE_FILES_CHUNK_MIN_BYTES = 256 * 1024;
const PDRIVE_FILES_MAX_CHUNKS = 4096;

/** php.iniの"8M"/"2G"のような表記をバイト数へ変換する(p-5secondと同じ実装)。 */
function phpIniBytes(string $val): int {
  $val = trim($val);

  if ($val === '') {
    $bytes = 0;
  } else {
    $unit = strtolower(substr($val, -1));
    $num = (int)$val;
    $bytes = match ($unit) {
      'g' => $num * 1024 * 1024 * 1024,
      'm' => $num * 1024 * 1024,
      'k' => $num * 1024,
      default => (int)$val,
    };
  }

  return $bytes;
}

/**
 * post_max_size/upload_max_filesizeのうち小さい方(=実際にアップロードを
 * 制限している値)を、その場でini_get()し直して返す。固定値を持たず、
 * 呼ばれるたびにこのサーバー自身の現在のphp.ini実測値を見る。
 */
function pDriveEffectiveUploadLimitBytes(): int {
  $postMax = phpIniBytes(ini_get('post_max_size') ?: '0');
  $uploadMax = phpIniBytes(ini_get('upload_max_filesize') ?: '0');
  $candidates = array_filter([$postMax, $uploadMax], static fn($v) => $v > 0);

  if (empty($candidates)) {
    // どちらも0や無制限だった場合。「制限が分からない」を0で表します。
    $limit = 0;
  } else {
    $limit = min($candidates);
  }

  return $limit;
}

/** このサーバー自身のphp.ini実測値から、1回のチャンクPOSTに使って安全なサイズを計算する(p-5secondのsafeLimit計算と同じ考え方)。 */
function pDriveFilesSafeChunkBytes(): int {
  $limitBytes = pDriveEffectiveUploadLimitBytes();

  if ($limitBytes <= 0) {
    // php.iniから制限が読めなかった場合。当て推量で大きくせず、確実に通る値を使います。
    $chunk = PDRIVE_FILES_CHUNK_FALLBACK_BYTES;
  } else {
    $chunk = max((int)floor($limitBytes * PDRIVE_FILES_CHUNK_SAFETY_MARGIN), PDRIVE_FILES_CHUNK_MIN_BYTES);
  }

  return $chunk;
}

function pDriveFilesChunkKey(string $fileId, int $index): string { return $fileId . '.c' . $index; }

function pDriveFilesList(string $storageId): array {
  // メタ情報とチャンク本体が同じserviceに同居しているため、ここではチャンク
  // ぶんのエントリも一緒に返ってくる(全ファイル分のチャンクが多い/大きいと
  // その復号コストも都度かかる、という軽いトレードオフはある)。ただしチャンクの
  // 値は生のバイナリをbase64化した文字列であり、JSONオブジェクトとしては
  // json_decode()がnullを返すため、下のis_array()チェックで自然に除外される
  // (メタ用のキーだけ拾い出す特別なフィルタは不要)。
  $res = PDriveAccount::storageList($storageId, PDRIVE_FILES_SERVICE);
  $files = [];

  if (!empty($res['ok'])) {
    foreach (($res['items'] ?? []) as $item) {
      $fileId = (string)($item['key'] ?? '');
      $record = ($fileId === '') ? null : json_decode((string)($item['value'] ?? ''), true);

      // チャンク本体はbase64の文字列なのでjson_decode()がnullを返し、ここで自然に外れます。
      if (is_array($record)) {
        $files[] = [
          'id'          => $fileId,
          'name'        => (string)($record['name'] ?? $fileId),
          'mime'        => (string)($record['mime'] ?? 'application/octet-stream'),
          'size'        => (int)($record['size'] ?? 0),
          'chunk_count' => max(1, (int)($record['chunk_count'] ?? 1)),
          'uploaded_at' => (int)($record['uploaded_at'] ?? 0),
        ];
      }
    }
    usort($files, static fn($a, $b) => $b['uploaded_at'] <=> $a['uploaded_at']);
  }

  return $files;
}

function pDriveFilesGetMeta(string $storageId, string $fileId): ?array {
  if ($fileId === '') {
    $res = ['ok' => false];
  } else {
    $res = PDriveAccount::storageGet($storageId, PDRIVE_FILES_SERVICE, $fileId);
  }

  $record = (empty($res['ok']) || empty($res['found']))
    ? null
    : json_decode((string)($res['value'] ?? ''), true);

  if (is_array($record)) {
    $meta = [
      'name'        => (string)($record['name'] ?? $fileId),
      'mime'        => (string)($record['mime'] ?? 'application/octet-stream'),
      'size'        => (int)($record['size'] ?? 0),
      'chunk_count' => max(1, (int)($record['chunk_count'] ?? 1)),
    ];
  } else {
    $meta = null;
  }

  return $meta;
}

/**
 * チャンクを順番に取得してその場でechoする(ファイル全体を一度にメモリへ
 * 載せない)。呼び出し前にヘッダーは送信済みであること。
 * 【なぜbase64復号を挟むか】p_drive_getの応答はp-meikiee側でJSON
 * (pmeikieeApiRespond()がjson_encode())に組み立てられて返ってくる。
 * JSONの文字列値は有効なUTF-8である必要があるが、ファイル本体(画像・動画等)
 * は当然UTF-8として不正なバイト列を含みうるため、生のバイト列のままJSONへ
 * 載せようとするとjson_encode()自体が失敗し、応答が空になってしまう
 * (実際にこれが原因で「アップロードはされるが一覧・ダウンロードに出ない」
 * 不具合が起きていた)。そのためチャンク本体は保存時にbase64化してから
 * put し(pDriveFilesChunkUpload()参照)、常に有効なASCII文字列としてJSONを
 * 安全に通過できるようにしている。ここではその逆(base64→元のバイト列)を行う。
 */
function pDriveFilesStreamDownload(string $storageId, string $fileId, array $meta): void {
  for ($i = 0; $i < $meta['chunk_count']; $i++) {
    $res = PDriveAccount::storageGet($storageId, PDRIVE_FILES_SERVICE, pDriveFilesChunkKey($fileId, $i));
    if (empty($res['ok']) || empty($res['found'])) { break; }
    $decoded = base64_decode((string)($res['value'] ?? ''), true);
    echo $decoded !== false ? $decoded : '';
    if (ob_get_level() > 0) { ob_flush(); }
    flush();
  }
}

function pDriveFilesDelete(string $storageId, string $fileId): array {
  if ($fileId === '') {
    $result = ['ok' => false, 'message' => '不正な指定です。'];
  } else {
    // メタが読めなかった場合は上限の番号まで舐めて消します。チャンクだけ取り残すと、
    // 一覧には出ないのに使用量だけ食い続けるゴミ(孤立チャンク)になるためです。
    $meta = pDriveFilesGetMeta($storageId, $fileId);
    $chunkCount = $meta['chunk_count'] ?? PDRIVE_FILES_MAX_CHUNKS;

    for ($i = 0; $i < $chunkCount; $i++) {
      PDriveAccount::storageDelete($storageId, PDRIVE_FILES_SERVICE, pDriveFilesChunkKey($fileId, $i));
    }
    $res = PDriveAccount::storageDelete($storageId, PDRIVE_FILES_SERVICE, $fileId);

    if (!empty($res['ok'])) {
      $result = ['ok' => true, 'message' => 'ファイルを削除しました。'];
    } else {
      $result = ['ok' => false, 'message' => '削除に失敗しました。'];
    }
  }

  return $result;
}

/** 自分の保存領域にあるファイルを全件削除する(1件ずつの削除を、既存のpDriveFilesDelete()で繰り返すだけ)。 */
function pDriveFilesDeleteAll(string $storageId): array {
  $files = pDriveFilesList($storageId);
  $deleted = 0;
  foreach ($files as $f) {
    $result = pDriveFilesDelete($storageId, $f['id']);
    if (!empty($result['ok'])) { $deleted++; }
  }
  return ['ok' => true, 'deleted' => $deleted, 'total' => count($files), 'message' => $deleted . '件のファイルを削除しました。'];
}

// =====================================================================
// 未完了アップロードの孤立チャンクの掃除。
//
// アップロード中(pDriveFilesChunkUpload()がチャンクを1件ずつput)にブラウザが
// 閉じられる・通信が切れる等で中断すると、pDriveFilesChunkFinish()(全チャンク
// 送信後にメタデータを書く処理)まで辿り着けず、pDriveFilesChunkAbort()
// (クライアント側JSが呼ぶ中断時の掃除)も実行されないまま、チャンク本体
// (<fileId>.c<番号>.enc)だけがp-drive上に残り続けることがある。メタデータ
// (<fileId>.enc)が無いためpDriveFilesList()には一切出てこず、削除するUIも
// 存在しないが、使用量(usage)には合算され続ける。実際にこの形で約293MBの
// 孤立チャンクが見つかった(2026-08-23)。
//
// <id>.c<番号>という分割アップロードの命名規則自体と、その判定・削除ロジックは
// storage_id単位の孤立掃除と同格の「全サービス共通の土台」としてp-meikiee側の
// engineに一本化した(pDriveEngineSweepOrphanedChunks()、
// ?api=p_drive_sweep_orphaned_chunks)。ここ(p-drive自身)が持つのは
// 「いつ実行するか」というオーケストレーションの責任だけ(account_exists()を
// p-chat/p-reversiが呼ぶだけで済むのと同じ構造)。
//
// 【状態ファイルを持たない理由】pDriveEngineSweepOrphanedChunks()自体が
// 「1人・1serviceフォルダだけをscandir()する軽い処理」であり、
// account.jsonlの読み込みもp_drive_storage全体の走査も行わない。コストは
// システム全体のアカウント数ではなく、呼び出した本人が持つファイル数だけに
// 比例するため、pmeikieeSweepOrphanedStorage()のような「1日1回」への制限
// (=状態を記録する場所が必要になる)は不要と判断した。最終実行日を記録する
// ための場所(ユーザー自身のp-drive領域・別service・p-meikiee側の共有領域の
// いずれも)を探す過程で、結局どの置き場も「本来無い方が良い持ち物」を
// 増やすだけだと分かったため、そもそも持たないことにした。呼ぶ側は毎リクエスト
// 無条件に呼べばよい。
// =====================================================================

// 何時間チャンクの更新が無ければ「アップロード中断」と判断してよいか。
// アップロード自体に数時間かかる回線も想定し、安全側に長めに取る。
const PDRIVE_ORPHAN_CHUNK_MAX_AGE_SECONDS = 24 * 60 * 60;

function pDriveFilesRunOrphanSweepIfNeeded(string $storageId): void {
  // 秒数はintで渡します。このファイルは declare(strict_types=1) なので、
  // 以前のように (string) を付けたままだとTypeErrorで即座に落ちます
  // (旧クライアントには型宣言が無く、文字列でも通っていました)。
  PDriveAccount::sweepOrphanedChunks($storageId, PDRIVE_FILES_SERVICE, PDRIVE_ORPHAN_CHUNK_MAX_AGE_SECONDS);
}

function pDriveFilesUploadSessionKey(): string { return 'pdrive_files_upload_in_progress'; }

function pDriveFilesChunkStart(string $storageId, string $name, string $mime, int $totalSize): array {
  if ($totalSize <= 0 || $totalSize > PDRIVE_FILES_MAX_UPLOAD_BYTES) {
    $result = ['ok' => false, 'message' => 'ファイルは' . round(PDRIVE_FILES_MAX_UPLOAD_BYTES / 1024 / 1024 / 1024, 1) . 'GBまでです。'];
  } else {
    $baseName = mb_substr(basename($name), 0, 200);
    $safeName = ($baseName === '') ? 'ファイル' : $baseName;
    $fileId = bin2hex(random_bytes(8));
    $chunkBytes = pDriveFilesSafeChunkBytes();

    $sessionKey = pDriveFilesUploadSessionKey();
    if (!isset($_SESSION[$sessionKey]) || !is_array($_SESSION[$sessionKey])) {
      $_SESSION[$sessionKey] = [];
    }
    // 同時進行は5件まで。古いものから捨てないと、中断されたアップロードの
    // 進行状況がセッションに溜まり続けます。
    if (count($_SESSION[$sessionKey]) >= 5) {
      array_shift($_SESSION[$sessionKey]);
    }

    $_SESSION[$sessionKey][$fileId] = [
      'name' => $safeName, 'mime' => $mime !== '' ? $mime : 'application/octet-stream',
      'total_size' => $totalSize, 'received_bytes' => 0, 'chunk_count' => 0,
      'chunk_size' => $chunkBytes, 'started_at' => time(),
    ];
    $result = ['ok' => true, 'file_id' => $fileId, 'chunk_size' => $chunkBytes];
  }

  return $result;
}

function pDriveFilesChunkUpload(string $storageId, string $fileId, int $chunkIndex, string $chunkData): array {
  $sessionKey = pDriveFilesUploadSessionKey();
  $progress = $_SESSION[$sessionKey][$fileId] ?? null;

  if ($progress === null) {
    $result = ['ok' => false, 'message' => 'アップロードの進行状況が見つかりません。最初からやり直してください。'];
  } else if ($chunkIndex < 0 || $chunkIndex >= PDRIVE_FILES_MAX_CHUNKS) {
    $result = ['ok' => false, 'message' => '不正なチャンク番号です。'];
  } else {
    $newTotal = $progress['received_bytes'] + strlen($chunkData);
    $allowedChunkBytes = (int)($progress['chunk_size'] ?? PDRIVE_FILES_CHUNK_FALLBACK_BYTES);

    if ($newTotal > $progress['total_size'] + $allowedChunkBytes) {
      $result = ['ok' => false, 'message' => '申告されたサイズを超えて送信されました。'];
    } else {
      // 保存前にbase64化する(pDriveFilesStreamDownload()のコメント参照)。
      // p_drive_put自体のPOST本文はhttp_build_query()でURLエンコードされる
      // ため生のバイト列でも壊れないが、後で読み出すp_drive_get/p_drive_listの
      // 応答はp-meikiee側でJSONに組み立てられて返るため、そちらが有効なUTF-8を
      // 要求する。書き込み時点でbase64化しておけば読み出し側を常に安全にできる。
      $res = PDriveAccount::storagePut($storageId, PDRIVE_FILES_SERVICE, pDriveFilesChunkKey($fileId, $chunkIndex), base64_encode($chunkData));

      if (!empty($res['ok'])) {
        $progress['received_bytes'] = $newTotal;
        $progress['chunk_count'] = max($progress['chunk_count'], $chunkIndex + 1);
        $_SESSION[$sessionKey][$fileId] = $progress;
        $result = ['ok' => true, 'received_bytes' => $newTotal];
      } else if (($res['error'] ?? '') === 'quota_exceeded') {
        // 容量超過だけは、利用者が自分で解決できるので理由をそのまま伝えます。
        $result = ['ok' => false, 'message' => '保存容量の上限に達しています。不要なファイルを削除してください。'];
      } else {
        $result = ['ok' => false, 'message' => 'アップロード中にエラーが発生しました。'];
      }
    }
  }

  return $result;
}

function pDriveFilesChunkFinish(string $storageId, string $fileId): array {
  $sessionKey = pDriveFilesUploadSessionKey();
  $progress = $_SESSION[$sessionKey][$fileId] ?? null;

  if ($progress === null) {
    $result = ['ok' => false, 'message' => 'アップロードの進行状況が見つかりません。最初からやり直してください。'];
  } else if ($progress['chunk_count'] === 0) {
    $result = ['ok' => false, 'message' => 'ファイルの中身が届いていません。'];
  } else {
    $record = [
      'name' => $progress['name'], 'mime' => $progress['mime'], 'size' => $progress['received_bytes'],
      'chunk_count' => $progress['chunk_count'], 'uploaded_at' => time(),
    ];
    $encoded = json_encode($record, JSON_UNESCAPED_UNICODE);
    $metaResult = $encoded !== false
      ? PDriveAccount::storagePut($storageId, PDRIVE_FILES_SERVICE, $fileId, $encoded)
      : ['ok' => false];

    // 成否にかかわらず進行状況は畳みます。残すと同じfileIdでやり直せてしまいます。
    unset($_SESSION[$sessionKey][$fileId]);

    if (!empty($metaResult['ok'])) {
      $result = ['ok' => true, 'message' => '「' . $progress['name'] . '」を保存しました。'];
    } else {
      $result = ['ok' => false, 'message' => '保存データの記録に失敗しました。'];
    }
  }

  return $result;
}

function pDriveFilesChunkAbort(string $storageId, string $fileId): array {
  $sessionKey = pDriveFilesUploadSessionKey();
  $progress = $_SESSION[$sessionKey][$fileId] ?? null;
  if ($progress !== null) {
    for ($i = 0; $i < $progress['chunk_count']; $i++) {
      PDriveAccount::storageDelete($storageId, PDRIVE_FILES_SERVICE, pDriveFilesChunkKey($fileId, $i));
    }
  }
  unset($_SESSION[$sessionKey][$fileId]);
  return ['ok' => true];
}

// ---------------------------------------------------------------------
// フォールバックアップロード(JavaScriptが無効、またはfetch/File/Blobに
// 対応していない古いブラウザ向け)。<form>のネイティブ送信をそのまま1回の
// POSTとして受け取り、ローカルの一時ファイルからストリームで読み出しながら
// 既存のチャンク保存関数(pDriveFilesChunkStart/Upload/Finish)へ流し込む。
// こうすることで、保存形式・一覧・ダウンロード・削除は分割アップロード時と
// 完全に同じものを再利用でき、かつファイル全体を一度にメモリへ載せない。
// ---------------------------------------------------------------------

/** $_FILES['file']を、multiple指定時の配列形式・単一選択時のスカラー形式のどちらでも同じ形へ正規化する。 */
function pDriveNormalizeUploadedFiles(array $filesEntry): array {
  $items = [];

  if (!isset($filesEntry['name'])) {
    // そもそもファイル欄が送られていない
    $items = [];
  } else if (!is_array($filesEntry['name'])) {
    // 単一選択(multiple指定なし)。PHPはこの形だと各項目がスカラーになります。
    $chosen = ((int)($filesEntry['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)
      || ((string)$filesEntry['name'] !== '');

    if ($chosen) {
      $items[] = [
        'name' => (string)$filesEntry['name'], 'type' => (string)($filesEntry['type'] ?? ''),
        'tmp_name' => (string)($filesEntry['tmp_name'] ?? ''), 'error' => (int)($filesEntry['error'] ?? UPLOAD_ERR_NO_FILE),
        'size' => (int)($filesEntry['size'] ?? 0),
      ];
    }
  } else {
    // multiple指定。各項目が同じ添字の配列になるので、1件ずつ組み直します。
    foreach ($filesEntry['name'] as $i => $name) {
      $chosen = ((int)($filesEntry['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)
        || ((string)$name !== '');

      if ($chosen) {
        $items[] = [
          'name' => (string)$name, 'type' => (string)($filesEntry['type'][$i] ?? ''),
          'tmp_name' => (string)($filesEntry['tmp_name'][$i] ?? ''), 'error' => (int)($filesEntry['error'][$i] ?? UPLOAD_ERR_NO_FILE),
          'size' => (int)($filesEntry['size'][$i] ?? 0),
        ];
      }
    }
  }

  return $items;
}

/** アップロード済み一時ファイル1件を、チャンクへ分けながら保存する(分割アップロードのフォールバック本体)。 */
function pDriveFilesDirectUploadFile(string $storageId, string $name, string $mime, string $tmpPath, int $size): array {
  // $failure は「途中でやめた理由」。null のままなら最後まで通ったという意味です。
  $failure = null;

  if (!is_uploaded_file($tmpPath)) {
    $failure = ['ok' => false, 'message' => 'ファイルの受信に失敗しました。'];
    $result = $failure;
  } else {
    // ファイルサイズの上限チェック自体はpDriveFilesChunkStart()が行う(分割
    // アップロード時と同じ検証・同じメッセージにするため、ここでは重複させない)。
    $startRes = pDriveFilesChunkStart($storageId, $name, $mime, $size);

    if (empty($startRes['ok'])) {
      $result = $startRes;
    } else {
      $fileId = (string)$startRes['file_id'];
      $chunkBytes = (int)$startRes['chunk_size'];
      $fp = @fopen($tmpPath, 'rb');

      if ($fp === false) {
        pDriveFilesChunkAbort($storageId, $fileId);
        $result = ['ok' => false, 'message' => 'アップロードされたファイルの読み込みに失敗しました。'];
      } else {
        $index = 0;
        $done = false;

        while ($failure === null && $done === false && !feof($fp)) {
          $chunk = fread($fp, $chunkBytes);

          if ($chunk === false) {
            $failure = ['ok' => false, 'message' => 'アップロードされたファイルの読み込みに失敗しました。'];
          } else if ($chunk === '') {
            $done = true;
          } else {
            $uploadRes = pDriveFilesChunkUpload($storageId, $fileId, $index, $chunk);
            if (empty($uploadRes['ok'])) {
              $failure = $uploadRes;
            } else {
              $index++;
            }
          }
        }

        fclose($fp);

        if ($failure === null) {
          $result = pDriveFilesChunkFinish($storageId, $fileId);
        } else {
          // 途中で失敗したら、送り終えたぶんのチャンクも消します。残すとメタデータの
          // 無い孤立チャンクになり、一覧に出ないのに使用量だけ食い続けます。
          pDriveFilesChunkAbort($storageId, $fileId);
          $result = $failure;
        }
      }
    }
  }

  return $result;
}

/** post_max_size超過検知(p-5second/index.php・p-meikiee/index.phpと同じロジック)。 */
function pDrivePostMaxSizeExceeded(): bool {
  $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
  return $contentLength > 0 && empty($_POST) && empty($_FILES);
}

/** 超過時のメッセージに使う、実際の上限(このサーバーの現在のphp.ini実測値)込みの文言。 */
function pDrivePostMaxSizeExceededMessage(): string {
  $limitBytes = pDriveEffectiveUploadLimitBytes();
  return 'サーバー側の設定(1回に送信できる最大サイズ' . ($limitBytes > 0 ? '、約' . pDriveFormatBytes($limitBytes) : '')
    . ')を超えているため、アップロードできませんでした。JavaScriptを有効にすると、このサーバーの設定に合わせて自動的に分割アップロードされます。';
}

function pDriveUploadPhpErrorMessage(int $errorCode): string {
  $message = match ($errorCode) {
    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => pDrivePostMaxSizeExceededMessage(),
    UPLOAD_ERR_PARTIAL => 'ファイルの送信が途中で中断されました。もう一度お試しください。',
    UPLOAD_ERR_NO_FILE => 'ファイルが選択されていません。',
    default => 'ファイルのアップロードに失敗しました。',
  };

  return $message;
}

// ---------------------------------------------------------------------
// ダウンロード配信(?download=<file_id>)。ログイン必須(本人の非公開データ)。
// ---------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' && isset($_GET['download'])) {
  $downloadUser = pDriveCurrentUser();
  if ($downloadUser === null) {
    // メイキィへ届かないときは loginUrl() が空文字を返します。空のままLocationへ
    // 渡すと自分自身へ飛び続けて無限ループになるので、その場合はトップへ戻します
    // (トップには理由が表示されます)。
    $downloadLoginUrl = PDriveAccount::loginUrl();
    if ($downloadLoginUrl === '') {
      $downloadLoginUrl = './';
    }
    header('Location: ' . $downloadLoginUrl);
    exit;
  }
  $storageId = (string)($downloadUser['userid'] ?? '');
  $fileId = (string)$_GET['download'];
  $meta = $storageId !== '' ? pDriveFilesGetMeta($storageId, $fileId) : null;
  if ($meta === null) {
    http_response_code(404);
    exit('指定されたファイルが見つかりません。');
  }
  header('Content-Type: ' . $meta['mime']);
  header('Content-Disposition: attachment; filename="' . rawurlencode($meta['name']) . '"');
  header('Content-Length: ' . $meta['size']);
  header('Cache-Control: private, no-store');
  pDriveFilesStreamDownload($storageId, $fileId, $meta);
  exit;
}

// ---------------------------------------------------------------------
// チャンクアップロードJSON API(pdrive_action=start/upload/finish/abort/delete)。
//
// 【X-Pdrive-Ajaxヘッダーで判定する理由】post_max_sizeを超えて送信された
// 場合、PHPは$_POST/$_FILESを丸ごと空にしてしまう(それ自体はここで検知
// したい対象)。そのため`isset($_POST['pdrive_action'])`だけを入口の条件に
// すると、まさに検知したいそのケースで$_POST自体が消え、この分岐に
// 入れずJSON応答を返せない(=フロント側は素のHTMLをJSONとしてparseしようと
// して失敗し、原因不明のエラーにしか見えない)という矛盾が起きる。
// ヘッダーはボディの解析結果に関係なく常に読めるため、JS側の全fetch呼び出し
// (postForm())にこの印を付け、それを入口条件に使うことでこの矛盾を避ける。
// ---------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && (isset($_POST['pdrive_action']) || ($_SERVER['HTTP_X_PDRIVE_AJAX'] ?? '') === '1')) {
  header('Content-Type: application/json; charset=utf-8');

  if (pDrivePostMaxSizeExceeded()) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'message' => pDrivePostMaxSizeExceededMessage()]);
    exit;
  }
  if (!isset($_POST['pdrive_action'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => '不明な操作です。']);
    exit;
  }
  $actionUser = pDriveCurrentUser();
  if ($actionUser === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'ログインが必要です。']);
    exit;
  }
  if (!pDriveCsrfIsValid()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '不正なリクエストです。ページを再読み込みしてください。']);
    exit;
  }
  $storageId = (string)($actionUser['userid'] ?? '');

  $pdriveAction = (string)$_POST['pdrive_action'];
  switch ($pdriveAction) {
    case 'start':
      $result = pDriveFilesChunkStart(
        $storageId,
        is_string($_POST['name'] ?? null) ? $_POST['name'] : 'ファイル',
        is_string($_POST['mime'] ?? null) ? $_POST['mime'] : '',
        (int)($_POST['total_size'] ?? 0)
      );
      break;
    case 'upload':
      $fileId = is_string($_POST['file_id'] ?? null) ? $_POST['file_id'] : '';
      $chunkIndex = (int)($_POST['chunk_index'] ?? -1);
      if (!isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
        $result = ['ok' => false, 'message' => pDriveUploadPhpErrorMessage((int)($_FILES['chunk']['error'] ?? UPLOAD_ERR_NO_FILE))];
        break;
      }
      $chunkData = file_get_contents($_FILES['chunk']['tmp_name']);
      if ($chunkData === false) {
        $result = ['ok' => false, 'message' => 'チャンクの読み込みに失敗しました。'];
        break;
      }
      $result = pDriveFilesChunkUpload($storageId, $fileId, $chunkIndex, $chunkData);
      break;
    case 'finish':
      $result = pDriveFilesChunkFinish($storageId, is_string($_POST['file_id'] ?? null) ? $_POST['file_id'] : '');
      break;
    case 'abort':
      $result = pDriveFilesChunkAbort($storageId, is_string($_POST['file_id'] ?? null) ? $_POST['file_id'] : '');
      break;
    case 'delete':
      $result = pDriveFilesDelete($storageId, is_string($_POST['file_id'] ?? null) ? $_POST['file_id'] : '');
      break;
    case 'delete_all':
      $result = pDriveFilesDeleteAll($storageId);
      break;
    default:
      $result = ['ok' => false, 'message' => '不明な操作です。'];
  }

  echo json_encode($result, JSON_UNESCAPED_UNICODE);
  exit;
}

// ---------------------------------------------------------------------
// ログアウト
// ---------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['pdrive_logout'])) {
  if (pDriveCsrfIsValid()) {
    $token = pDriveAccountsToken();
    if ($token !== '') { PDriveAccount::revokeToken($token); }
    unset($_SESSION['pusyuu_accounts_token']);
  }
  header('Location: ' . PDriveAccount::logoutUrl());
  exit;
}

// ---------------------------------------------------------------------
// アップロードのフォールバック(JavaScriptが無効、またはfetch/File/Blobに
// 対応していない古いブラウザ)。files-upload-formはJavaScriptが動けば
// pdrive_action(チャンクAPI)を使って自前でPOSTするため、ここへ来るのは
// スクリプトが動かず<form>がネイティブ送信された場合だけ(=JS側では
// 分割アップロードが行えない場合)。X-Pdrive-Ajaxヘッダーが無いことで
// 上のJSON APIブロックと区別している。
//
// post_max_sizeを超えて送信された場合はここでも$_POST/$_FILESが丸ごと
// 空になるため(pDrivePostMaxSizeExceeded()参照)、CSRFチェックより先に
// 検知する。そうしないと「原因も告げずに何も起きていないかのように
// ページが再表示されるだけ」になり、超過が起きたことにユーザーが
// 気づけない。
// ---------------------------------------------------------------------

/**
 * この関数に該当する送信でなければnull、該当すれば
 * ['message' => string, 'is_error' => bool] を返す。
 *
 * 答えは $result 1つに溜めて最後に1回だけ返す。途中で抜けないので、
 * 「どの条件のときに何が返るか」はこの1つのif/elseを上から読めば全部わかる。
 */
function pDriveHandleUploadFallback(): ?array {
  // この関数が扱う送信かどうか。1つでも当てはまれば自分の担当ではありません。
  $notMine = (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST')
    || isset($_POST['pdrive_action'])
    || isset($_POST['pdrive_logout'])
    || (($_SERVER['HTTP_X_PDRIVE_AJAX'] ?? '') === '1');

  if ($notMine) {
    $result = null;
  } else if (pDrivePostMaxSizeExceeded()) {
    // 【CSRFより先に見ること】post_max_sizeを超えるとPHPが$_POSTと$_FILESを
    // 丸ごと空にします。後に回すと「トークンが無い」という的外れな理由で弾かれ、
    // 利用者には「何も起きずにページが再表示されただけ」に見えます。
    $result = ['message' => pDrivePostMaxSizeExceededMessage(), 'is_error' => true];
  } else {
    $fallbackUser = pDriveCurrentUser();

    if ($fallbackUser === null || !isset($_FILES['file'])) {
      $result = null;
    } else if (!pDriveCsrfIsValid()) {
      $result = ['message' => '不正なリクエストです。ページを再読み込みしてもう一度お試しください。', 'is_error' => true];
    } else {
      $items = pDriveNormalizeUploadedFiles($_FILES['file']);

      if (empty($items)) {
        $result = ['message' => 'ファイルが選択されていません。', 'is_error' => true];
      } else {
        $storageId = (string)($fallbackUser['userid'] ?? '');
        $okNames = [];
        $errors = [];

        foreach ($items as $item) {
          if ($item['error'] !== UPLOAD_ERR_OK) {
            $errors[] = $item['name'] . ': ' . pDriveUploadPhpErrorMessage($item['error']);
          } else {
            $uploadResult = pDriveFilesDirectUploadFile($storageId, $item['name'], $item['type'], $item['tmp_name'], $item['size']);
            if (!empty($uploadResult['ok'])) {
              $okNames[] = $item['name'];
            } else {
              $errors[] = $item['name'] . ': ' . ($uploadResult['message'] ?? 'アップロードに失敗しました。');
            }
          }
        }

        if (empty($errors)) {
          $result = ['message' => count($okNames) . '件のファイルを保存しました。', 'is_error' => false];
        } else {
          $prefix = !empty($okNames)
            ? count($okNames) . '件は保存しましたが、一部のファイルをアップロードできませんでした。' . "\n"
            : '一部のファイルをアップロードできませんでした。' . "\n";
          $result = ['message' => $prefix . implode("\n", $errors), 'is_error' => true];
        }
      }
    }
  }

  return $result;
}

// ---------------------------------------------------------------------
// 削除のフォールバック(同じくJavaScript無効・fetch/File/Blob非対応向け)。
// 削除ボタンはこれまで<button type="button">+クリックイベントだけで
// 実装されており、JavaScriptが動かない環境では押しても何も起きなかった
// (フォームにすら入っていないため、そもそもPOST自体が飛ばない)。各ボタンを
// 本物の<form method="post">に包み、ここでそのPOSTを直接処理することで
// JavaScript無しでも削除できるようにする。
// ---------------------------------------------------------------------

function pDriveHandleDeleteFallback(): ?array {
  // この関数が扱う送信かどうか。1つでも当てはまれば自分の担当ではありません。
  $notMine = (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST')
    || (($_SERVER['HTTP_X_PDRIVE_AJAX'] ?? '') === '1')
    || (!isset($_POST['pdrive_delete_file']) && !isset($_POST['pdrive_delete_all']));

  if ($notMine) {
    $answer = null;
  } else {
    $fallbackUser = pDriveCurrentUser();

    if ($fallbackUser === null) {
      $answer = null;
    } else if (!pDriveCsrfIsValid()) {
      $answer = ['message' => '不正なリクエストです。ページを再読み込みしてもう一度お試しください。', 'is_error' => true];
    } else {
      $storageId = (string)($fallbackUser['userid'] ?? '');

      if (isset($_POST['pdrive_delete_all'])) {
        $deleted = pDriveFilesDeleteAll($storageId);
        $answer = ['message' => (string)($deleted['message'] ?? '削除しました。'), 'is_error' => empty($deleted['ok'])];
      } else {
        $fileId = is_string($_POST['file_id'] ?? null) ? $_POST['file_id'] : '';
        $deleted = pDriveFilesDelete($storageId, $fileId);
        $answer = ['message' => (string)($deleted['message'] ?? '削除に失敗しました。'), 'is_error' => empty($deleted['ok'])];
      }
    }
  }

  return $answer;
}

// アップロード・削除のどちらのフォールバックも、それぞれ関係する$_POSTの
// キーが無ければ即座にnullを返す(=互いのケースを誤って処理することはない)
// ため、この順で単純に先勝ちさせてよい。
$pdriveFallback = pDriveHandleUploadFallback() ?? pDriveHandleDeleteFallback();

// ---------------------------------------------------------------------
// HTML描画
// ---------------------------------------------------------------------

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function pDriveFormatBytes(int $bytes): string {
  if ($bytes >= 1024 * 1024 * 1024) {
    $text = round($bytes / 1024 / 1024 / 1024, 2) . 'GB';
  } else if ($bytes >= 1024 * 1024) {
    $text = round($bytes / 1024 / 1024, 1) . 'MB';
  } else if ($bytes >= 1024) {
    $text = round($bytes / 1024, 1) . 'KB';
  } else {
    $text = $bytes . 'B';
  }

  return $text;
}

/**
 * 他のプシューサービスへのリンク集(プロダクト一覧)。gir/p-memo/p-chat等と同じ
 * main/pusyuusystem/documents/futures.json を読み、自分自身は除外して描画する。
 */
function pDriveProductListHtml(): string {
  // 【__DIR__ を使わないこと】このファイルは入口(実行されるスクリプトそのもの)なので、
  // PHPがカレントディレクトリをこのフォルダにしてくれます。素の相対パスで足ります。
  // __DIR__ で自分の位置を調べて組み立てると、置き場所を前提にした書き方が増えます。
  $products = @file_get_contents('./../main/pusyuusystem/documents/futures.json');
  $data = ($products === false) ? null : json_decode($products, true);

  if ($products === false) {
    $html = '<p class="pd-note">プロダクト一覧を読み込めませんでした。</p>';
  } else if (!isset($data['links']) || !is_array($data['links'])) {
    $html = '<p class="pd-note">プロダクト一覧の解析に失敗しました。</p>';
  } else {
    $selfUrl = 'https://' . P_DRIVE_SELF_HOST;
    $rows = '';

    foreach ($data['links'] as $product) {
      $url = (string)($product['url'] ?? '');

      // 自分自身は一覧から外します(今見ているページへのリンクになるため)。
      if ($url !== '' && rtrim($url, '/') !== rtrim($selfUrl, '/')) {
        $title = h((string)($product['title'] ?? $url));
        $image = !empty($product['image']) ? (string)$product['image'] : 'https://pusyuuwanko.com/pusyuusystem/images/avater.jpg';
        $rows .= '
      <li class="pd-product-row">
        <img class="pd-product-icon" src="' . h($image) . '" alt="" loading="lazy" />
        <span class="pd-product-name">' . $title . '</span>
        <a class="pd-btn-ghost pd-btn" href="' . h($url) . '" target="_blank" rel="noopener noreferrer">開く</a>
      </li>
    ';
      }
    }

    if ($rows === '') {
      $html = '<p class="pd-note">現在、表示できるプロダクトはありません。</p>';
    } else {
      $html = '<ul class="pd-product-list">' . $rows . '</ul>';
    }
  }

  return $html;
}

$currentUser = pDriveCurrentUser();
$csrf = $_SESSION['pdrive_csrf'];
$productListHtml = pDriveProductListHtml();

ob_start();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex,nofollow" />
  <title>p-drive - マイファイル</title>
  <link rel="shortcut icon" href="https://pusyuuwanko.com/pusyuusystem/images/favicon.ico" />
   <!--
      *----------------------------------
      |  ThisPageVersion: 1.0.0       |
      |  © 2026 By ISAMI ABE          |
      |  License: MIT License         |
      |  PusyuuPDrive                 |
    ----------------------------------*
  -->
  <style>
    /* p-drive独自のデザイン(p-meikiee等のスタイルシートは読み込まない)。 */
    :root {
      --pd-bg: #0f172a;
      --pd-bg-grad: #1e293b;
      --pd-card: #ffffff;
      --pd-ink: #0f172a;
      --pd-muted: #64748b;
      --pd-accent: #0ea5a4;
      --pd-accent-dark: #0b8685;
      --pd-danger: #dc2626;
      --pd-border: #e2e8f0;
      --pd-track: #eef2f6;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      font-family: -apple-system, "Segoe UI", "Hiragino Kaku Gothic ProN", "Yu Gothic", sans-serif;
      background: linear-gradient(160deg, var(--pd-bg) 0%, var(--pd-bg-grad) 45%, #0f3d3c 100%);
      color: var(--pd-ink);
      padding: 2.5rem 1rem;
    }
    .pd-card {
      max-width: 720px;
      margin: 0 auto;
      background: var(--pd-card);
      border-radius: 16px;
      box-shadow: 0 20px 50px rgba(0,0,0,0.35);
      padding: 2rem;
    }
    .pd-brand { display:flex; align-items:center; gap:0.6rem; margin-bottom:0.4rem; }
    .pd-brand-mark {
      width: 34px; height: 34px; border-radius: 9px;
      background: linear-gradient(135deg, var(--pd-accent), var(--pd-accent-dark));
      display:flex; align-items:center; justify-content:center;
      color:#fff; font-weight:700; font-size:1rem;
    }
    .pd-brand h1 { font-size:1.4rem; margin:0; letter-spacing:0.02em; }
    .pd-lede { color: var(--pd-muted); font-size:0.92rem; margin: 0 0 1.6rem; line-height:1.6; }
    .pd-note { color: var(--pd-muted); font-size:0.85rem; line-height:1.6; }
    .pd-user { display:flex; align-items:center; justify-content:space-between; padding:0.9rem 1rem; background:var(--pd-track); border-radius:12px; margin-bottom:1.2rem; }
    .pd-user-name { font-weight:600; margin:0; }
    .pd-user-sub { margin:0; color:var(--pd-muted); font-size:0.85rem; }
    .pd-meter { height:10px; border-radius:999px; background:var(--pd-track); overflow:hidden; margin:0.4rem 0; display:flex; }
    .pd-meter-fill { height:100%; background:linear-gradient(90deg, var(--pd-accent), var(--pd-accent-dark)); border-radius:999px; transition:width .3s ease; }
    .pd-meter-fill-own { height:100%; background:linear-gradient(90deg, var(--pd-accent), var(--pd-accent-dark)); transition:width .3s ease; flex-shrink:0; }
    .pd-meter-fill-other { height:100%; background:var(--pd-muted); transition:width .3s ease; flex-shrink:0; }
    .pd-section { margin-top:2rem; }
    .pd-section h2 { font-size:1rem; margin:0 0 0.7rem; padding-bottom:0.5rem; border-bottom:1px solid var(--pd-border); color:var(--pd-ink); }
    .pd-upload-box { border:1.5px dashed var(--pd-border); border-radius:12px; padding:1.2rem; }
    .pd-file-input { display:block; width:100%; padding:0.6rem; border:1px solid var(--pd-border); border-radius:8px; margin-bottom:0.6rem; background:#fafafa; }
    .pd-btn { display:inline-flex; align-items:center; justify-content:center; padding:0.6rem 1.2rem; border-radius:9px; border:none; background:var(--pd-accent); color:#fff; font-weight:600; cursor:pointer; text-decoration:none; font-size:0.92rem; }
    .pd-btn:hover { background:var(--pd-accent-dark); }
    .pd-btn:disabled { opacity:0.5; cursor:not-allowed; }
    .pd-btn-ghost { background:transparent; color:var(--pd-muted); border:1px solid var(--pd-border); font-weight:500; }
    .pd-btn-ghost:hover { background:var(--pd-track); }
    .pd-btn-danger-sm { padding:0.35rem 0.7rem; font-size:0.8rem; border-radius:7px; border:1px solid #fecaca; background:#fff5f5; color:var(--pd-danger); font-weight:600; cursor:pointer; }
    .pd-btn-danger-sm:hover { background:#fee2e2; }
    .pd-file-list { list-style:none; margin:0; padding:0; }
    .pd-file-row { display:flex; align-items:center; gap:0.8rem; padding:0.7rem 0; border-bottom:1px solid var(--pd-border); }
    .pd-file-row:last-child { border-bottom:none; }
    .pd-file-name { flex:1; min-width:0; font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .pd-file-name a { color:var(--pd-ink); text-decoration:none; }
    .pd-file-name a:hover { color: var(--pd-accent-dark); text-decoration: underline; }
    .pd-file-meta { color:var(--pd-muted); font-size:0.8rem; white-space:nowrap; }
    .pd-error { color:var(--pd-danger); font-size:0.85rem; }
    .pd-empty { color:var(--pd-muted); font-size:0.9rem; padding:1rem 0; text-align:center; }
    .pd-product-list { list-style:none; margin:0; padding:0; }
    .pd-product-row { display:flex; align-items:center; gap:0.8rem; padding:0.6rem 0; border-bottom:1px solid var(--pd-border); }
    .pd-product-row:last-child { border-bottom:none; }
    .pd-product-icon { width:28px; height:28px; border-radius:7px; object-fit:cover; flex-shrink:0; background:var(--pd-track); }
    .pd-product-name { flex:1; min-width:0; font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .pd-footer { margin-top:2rem; padding-top:1.2rem; border-top:1px solid var(--pd-border); }
    .pd-footer h3 { font-size:0.85rem; margin:0 0 0.4rem; color:var(--pd-ink); }
    .pd-footer p, .pd-footer ul, .pd-footer li { font-size:0.78rem; color:var(--pd-muted); line-height:1.6; margin:0.3rem 0; padding:0; list-style:none; }
    .pd-footer a { color:var(--pd-accent-dark); }
    .pd-footer small { font-size:0.75rem; color:var(--pd-muted); }
  </style>
</head>
<body>
<div class="pd-card">
  <div class="pd-brand"><span class="pd-brand-mark">p·</span><h1>p-drive</h1></div>
  <p class="pd-lede">プシューメイキィのアカウントに紐づく、あなた専用の個人ファイル保存場所です。どのサービスにも属さないファイルをここへ直接置けます。</p>
  <p class="pd-lede">p-5second(ワンタイムファイル共有)のような、他人と共有する機能はあえて持たせていません。誰にも見せない、あなただけの保存場所であることに徹しているためです。共有機能を足せばもっと便利にはなりますが、その分「うっかり誰かに見えてしまう」余地も増えます。多少不便でも安全な方を選ぶ、というプシューサービスらしい設計です。</p>

<?php if ($pDriveSignInError !== ''): ?>
  <p class="pd-note">ログインできませんでした：<?= h($pDriveSignInError) ?></p>
<?php endif; ?>
<?php if ($currentUser === null && PDriveAccount::ready()): ?>
  <p class="pd-note">ご利用にはプシューメイキィへのログインが必要です。</p>
  <p><a class="pd-btn" href="<?= h(PDriveAccount::loginUrl()) ?>">ログイン</a></p>
<?php elseif ($currentUser === null): ?>
  <?php /* 押しても行き止まりになるリンクは出さず、理由だけを書きます。
           p-driveはファイルの実体がメイキィの中にあるため、連携できない間は
           マイファイルそのものが使えません。それを隠さず伝えます。
           ここはPHPが書き出すHTMLなので、JavaScriptが無くても読めます。 */ ?>
  <p class="pd-note">いまプシューメイキィと連携できないため、マイファイルはご利用いただけません。</p>
  <p class="pd-note"><?= h(PDriveAccount::unavailableReason()) ?></p>
<?php else:
  $storageId = (string)($currentUser['userid'] ?? '');
  pDriveFilesRunOrphanSweepIfNeeded($storageId);
  $usage = PDriveAccount::storageUsage($storageId);
  $usedBytes = !empty($usage['ok']) ? (int)($usage['bytes'] ?? 0) : 0;
  $maxBytes = !empty($usage['ok']) ? (int)($usage['max_bytes'] ?? 0) : 0;

  // 「全体の使用量」にはp-meikiee(アバター・設定)・p-memo等、この画面からは
  // 見えない他サービスぶんも合算されている(p-drive自身のstorage_idは
  // ユーザー1人あたり1つで、全serviceが同じ5GBの枠を共有するため)。ここで
  // 見えている「自分のファイル」の使用量だけを分けて表示しないと、
  // 「アップロードしたファイルは数件しかないのに容量がほぼ埋まっている」
  // ように見えて混乱を招く(p-meikiee自身のuserData使用量バーが、全体
  // (p-drive全体の5GB)とは別に自分のuserDataぶん(5MB)だけを分けて
  // 表示しているのと同じ理由)。
  $breakdown = PDriveAccount::storageBreakdown($storageId);
  $ownFilesBytes = 0;
  if (!empty($breakdown['ok'])) {
    foreach (($breakdown['services'] ?? []) as $svc) {
      if (($svc['service'] ?? '') === PDRIVE_FILES_SERVICE) { $ownFilesBytes = (int)($svc['bytes'] ?? 0); break; }
    }
  }
  $otherBytes = max(0, $usedBytes - $ownFilesBytes);
  $ownFilesPercent = $maxBytes > 0 ? min(100, ($ownFilesBytes / $maxBytes) * 100) : 0;
  $otherPercent = $maxBytes > 0 ? min(100 - $ownFilesPercent, ($otherBytes / $maxBytes) * 100) : 0;

  $files = pDriveFilesList($storageId);
  $pdriveEffectiveLimitBytes = pDriveEffectiveUploadLimitBytes();
  $pdriveEffectiveLimitLabel = $pdriveEffectiveLimitBytes > 0 ? pDriveFormatBytes($pdriveEffectiveLimitBytes) : '不明';
?>
  <div class="pd-user">
    <div>
      <p class="pd-user-name"><?= h((string)($currentUser['name'] ?? '')) ?></p>
      <p class="pd-user-sub">@<?= h((string)($currentUser['username'] ?? '')) ?></p>
    </div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
      <button type="submit" name="pdrive_logout" value="1" class="pd-btn-ghost pd-btn" style="background:transparent;">ログアウト</button>
    </form>
  </div>

  <p class="pd-note">自分のファイル: <?= h(pDriveFormatBytes($ownFilesBytes)) ?>(<?= count($files) ?>件)</p>
  <p class="pd-note">全体の保存容量(この画面に出てこない他サービスぶんを含む): <?= h(pDriveFormatBytes($usedBytes)) ?> / <?= h(pDriveFormatBytes($maxBytes)) ?></p>
  <div class="pd-meter" role="img" aria-label="保存容量、うち自分のファイル<?= h(pDriveFormatBytes($ownFilesBytes)) ?>・他サービス<?= h(pDriveFormatBytes($otherBytes)) ?>、合計<?= h(pDriveFormatBytes($usedBytes)) ?> / <?= h(pDriveFormatBytes($maxBytes)) ?> 使用中">
    <div class="pd-meter-fill-own" style="width: <?= h((string)$ownFilesPercent) ?>%;" title="自分のファイル"></div>
    <div class="pd-meter-fill-other" style="width: <?= h((string)$otherPercent) ?>%;" title="他サービス"></div>
  </div>
  <p class="pd-note" style="font-size:0.78rem;"><span style="color:var(--pd-accent);">■</span> 自分のファイル&nbsp;&nbsp;&nbsp;<span style="color:var(--pd-muted);">■</span> 他サービス(アカウント設定・メモ等)</p>

<?php if ($pdriveFallback !== null): ?>
  <p class="<?= !empty($pdriveFallback['is_error']) ? 'pd-error' : 'pd-note' ?>" style="white-space:pre-line;"><?= h((string)$pdriveFallback['message']) ?></p>
<?php endif; ?>

  <div class="pd-section">
    <h2>アップロード</h2>
    <div class="pd-upload-box">
      <form method="post" enctype="multipart/form-data" id="files-upload-form" data-csrf="<?= h($csrf) ?>">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
        <input class="pd-file-input" type="file" name="file" id="files-upload-input" multiple required />
        <p class="pd-note" id="files-upload-limit-note">複数選択可。JavaScriptが有効な場合、このサーバーの設定に合わせて自動的に分割アップロードします(1ファイルにつき最大1GB)。JavaScriptが無効な場合は、1回の送信につき約<?= h($pdriveEffectiveLimitLabel) ?>までのファイルのみアップロードできます(このサーバーの現在の設定値)。</p>
        <button type="submit" id="files-upload-submit" class="pd-btn">アップロード</button>
        <div id="files-upload-progress" style="display:none;margin-top:0.8rem;">
          <div class="pd-meter"><div class="pd-meter-fill" id="files-upload-progress-fill" style="width:0%;"></div></div>
          <p class="pd-note" id="files-upload-progress-text"></p>
          <button type="button" id="files-upload-cancel" class="pd-btn-danger-sm">キャンセル</button>
        </div>
        <p class="pd-error" id="files-upload-error" style="display:none;"></p>
        <noscript><p class="pd-note">JavaScriptが無効なため、1ファイルにつき約<?= h($pdriveEffectiveLimitLabel) ?>までの通常アップロードのみ行えます(分割アップロードにはJavaScriptが必要です)。</p></noscript>
      </form>
    </div>
  </div>

  <div class="pd-section">
    <div style="display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--pd-border);padding-bottom:0.5rem;margin-bottom:0.7rem;">
      <h2 style="border-bottom:none;padding-bottom:0;margin:0;">ファイル一覧</h2>
<?php if (!empty($files)): ?>
      <form method="post" style="display:inline;margin:0;">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
        <button type="submit" name="pdrive_delete_all" value="1" id="files-delete-all-btn" class="pd-btn-danger-sm" data-file-count="<?= count($files) ?>">全て削除</button>
      </form>
<?php endif; ?>
    </div>
<?php if (empty($files)): ?>
    <p class="pd-empty">まだファイルがありません。</p>
<?php else: ?>
    <ul class="pd-file-list">
<?php foreach ($files as $f): ?>
      <li class="pd-file-row">
        <span class="pd-file-name"><a href="?download=<?= urlencode($f['id']) ?>"><?= h($f['name']) ?></a></span>
        <span class="pd-file-meta"><?= h(pDriveFormatBytes($f['size'])) ?></span>
        <span class="pd-file-meta"><?= $f['uploaded_at'] > 0 ? h(date('Y-m-d H:i', $f['uploaded_at'])) : '' ?></span>
        <form method="post" style="display:inline;margin:0;">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
          <input type="hidden" name="file_id" value="<?= h($f['id']) ?>" />
          <button type="submit" name="pdrive_delete_file" value="1" class="pd-btn-danger-sm files-delete-btn" data-file-id="<?= h($f['id']) ?>" data-file-name="<?= h($f['name']) ?>">削除</button>
        </form>
      </li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
  </div>

  <script>
  (function () {
    if (typeof fetch !== 'function' || typeof File === 'undefined' || typeof Blob === 'undefined') { return; }
    var form = document.getElementById('files-upload-form');
    if (!form) { return; }
    var input = document.getElementById('files-upload-input');
    var submitBtn = document.getElementById('files-upload-submit');
    var cancelBtn = document.getElementById('files-upload-cancel');
    var progressWrap = document.getElementById('files-upload-progress');
    var progressFill = document.getElementById('files-upload-progress-fill');
    var progressText = document.getElementById('files-upload-progress-text');
    var errorBox = document.getElementById('files-upload-error');
    var csrf = form.getAttribute('data-csrf');
    var uploading = false;
    var currentFileId = null;
    var aborted = false;

    function showError(msg) { errorBox.textContent = msg; errorBox.style.display = ''; }
    function clearError() { errorBox.style.display = 'none'; errorBox.textContent = ''; }
    function formatBytes(n) {
      if (n >= 1024 * 1024 * 1024) { return (n / 1024 / 1024 / 1024).toFixed(2) + 'GB'; }
      if (n >= 1024 * 1024) { return (n / 1024 / 1024).toFixed(1) + 'MB'; }
      if (n >= 1024) { return (n / 1024).toFixed(1) + 'KB'; }
      return n + 'B';
    }
    function setProgress(fileIndex, fileCount, fileName, doneBytes, totalBytes) {
      var pct = totalBytes > 0 ? Math.min(100, Math.floor((doneBytes / totalBytes) * 100)) : 0;
      progressFill.style.width = pct + '%';
      progressText.textContent =
        'ファイル ' + fileIndex + '/' + fileCount + ' 「' + fileName + '」 — ' +
        pct + '% (' + formatBytes(doneBytes) + ' / ' + formatBytes(totalBytes) + ')';
    }
    function postForm(fields) {
      var body = new FormData();
      for (var k in fields) { if (Object.prototype.hasOwnProperty.call(fields, k)) { body.append(k, fields[k]); } }
      body.append('csrf', csrf);
      // X-Pdrive-Ajax: サーバー側がこのPOSTをJSON API呼び出しだと判別するための印。
      // post_max_sizeを超えて送信されるとPHPは$_POST自体を空にしてしまうため、
      // pdrive_actionフィールドの有無では判別できなくなる(HTTPヘッダーは
      // ボディの解析結果に関係なく常に読めるため、そちらで判別する)。
      return fetch('', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Pdrive-Ajax': '1' } })
        .then(function (res) { return res.json(); });
    }
    function resetUi() {
      uploading = false; currentFileId = null; aborted = false;
      submitBtn.disabled = false; input.disabled = false;
      progressWrap.style.display = 'none';
    }

    // 1ファイル分のstart→upload(...)→finishを行う。doneBytesBeforeは、
    // 複数ファイル一括アップロード中の「これより前のファイルぶんの合計」で、
    // 進捗バーをファイルまたぎで連続的に見せるために使う。
    function uploadOneFile(file, fileIndex, fileCount, doneBytesBefore, totalBytesAll) {
      setProgress(fileIndex, fileCount, file.name, doneBytesBefore, totalBytesAll);
      return postForm({ pdrive_action: 'start', name: file.name, mime: file.type || 'application/octet-stream', total_size: String(file.size) })
        .then(function (startRes) {
          if (!startRes.ok) { throw new Error(startRes.message || 'アップロードを開始できませんでした。'); }
          currentFileId = startRes.file_id;
          var size = startRes.chunk_size;
          var offset = 0, index = 0;
          function uploadNext() {
            if (aborted) { return Promise.resolve(); }
            if (offset >= file.size) { return Promise.resolve(); }
            var slice = file.slice(offset, offset + size);
            return postForm({ pdrive_action: 'upload', file_id: currentFileId, chunk_index: String(index), chunk: slice })
              .then(function (res) {
                if (!res.ok) { throw new Error(res.message || 'アップロード中にエラーが発生しました。'); }
                offset += slice.size; index += 1;
                setProgress(fileIndex, fileCount, file.name, doneBytesBefore + Math.min(offset, file.size), totalBytesAll);
                return uploadNext();
              });
          }
          return uploadNext().then(function () {
            if (aborted) { return null; }
            return postForm({ pdrive_action: 'finish', file_id: currentFileId });
          });
        })
        .then(function (finishRes) {
          currentFileId = null;
          if (aborted || finishRes === null) { return; }
          if (!finishRes.ok) { throw new Error(finishRes.message || '保存の確定に失敗しました。'); }
        });
    }

    form.addEventListener('submit', function (ev) {
      if (uploading) { ev.preventDefault(); return; }
      var files = input.files ? Array.prototype.slice.call(input.files) : [];
      if (files.length === 0) { return; }
      ev.preventDefault();
      clearError();
      uploading = true; aborted = false;
      submitBtn.disabled = true; input.disabled = true;
      progressWrap.style.display = '';

      var totalBytesAll = files.reduce(function (sum, f) { return sum + f.size; }, 0);
      var doneBytesBefore = 0;
      var failed = [];
      var uploadedAny = false;

      files.reduce(function (chain, file, i) {
        return chain.then(function () {
          if (aborted) { return; }
          return uploadOneFile(file, i + 1, files.length, doneBytesBefore, totalBytesAll)
            .then(function () { uploadedAny = true; })
            .catch(function (err) { failed.push(file.name + ': ' + (err.message || '失敗')); })
            .then(function () { doneBytesBefore += file.size; });
        });
      }, Promise.resolve())
        .then(function () {
          if (aborted) { resetUi(); return; }
          if (failed.length > 0) {
            window.alert('一部のファイルをアップロードできませんでした。\n' + failed.join('\n'));
          }
          if (uploadedAny) {
            progressText.textContent = '保存しました。一覧を更新しています…';
            window.location.reload();
          } else {
            resetUi();
          }
        });
    });

    cancelBtn.addEventListener('click', function () {
      if (!uploading) { return; }
      aborted = true;
      var fileId = currentFileId;
      clearError();
      if (fileId) { postForm({ pdrive_action: 'abort', file_id: fileId }).catch(function () {}); }
    });

    // 削除ボタンは(JavaScript無効時のフォールバックとして)実際の
    // <form method="post">のsubmitボタンになっている。JavaScriptが動く
    // 環境ではネイティブ送信(ページ全体の再読み込み)をpreventDefault()で
    // 止め、代わりに今まで通りfetchで即時反映する。
    var deleteButtons = document.querySelectorAll('.files-delete-btn');
    for (var i = 0; i < deleteButtons.length; i++) {
      deleteButtons[i].addEventListener('click', function (ev) {
        ev.preventDefault();
        var btn = this;
        var name = btn.getAttribute('data-file-name');
        if (!window.confirm('「' + name + '」を削除しますか?この操作は元に戻せません。')) { return; }
        btn.disabled = true;
        postForm({ pdrive_action: 'delete', file_id: btn.getAttribute('data-file-id') })
          .then(function (res) {
            if (!res.ok) { window.alert(res.message || '削除に失敗しました。'); btn.disabled = false; return; }
            window.location.reload();
          })
          .catch(function () { window.alert('削除に失敗しました。'); btn.disabled = false; });
      });
    }

    var deleteAllBtn = document.getElementById('files-delete-all-btn');
    if (deleteAllBtn) {
      deleteAllBtn.addEventListener('click', function (ev) {
        ev.preventDefault();
        var count = deleteAllBtn.getAttribute('data-file-count');
        if (!window.confirm('保存されている' + count + '件のファイルを全て削除しますか?この操作は元に戻せません。')) { return; }
        deleteAllBtn.disabled = true;
        postForm({ pdrive_action: 'delete_all' })
          .then(function (res) {
            if (!res.ok) { window.alert(res.message || '削除に失敗しました。'); deleteAllBtn.disabled = false; return; }
            window.location.reload();
          })
          .catch(function () { window.alert('削除に失敗しました。'); deleteAllBtn.disabled = false; });
      });
    }
  })();
  </script>
<?php endif; ?>

  <div class="pd-section">
    <h2>他のプシューサービス</h2>
    <?= $productListHtml ?>
  </div>

  <div class="pd-footer">
    <?= function_exists('notationDisp') ? notationDisp('notation') : '' ?>
    <?= function_exists('notationDisp') ? notationDisp('copyright') : '' ?>
  </div>
</div>
</body>
</html>
<?php
echo ob_get_clean();
