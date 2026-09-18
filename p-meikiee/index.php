<?php
/**
 * Pusyuu メイキー (Meikee) — データベースを使わないアカウント基盤 / SSOプロバイダ
 *
 * Copyright (c) 2021-2026 ISAMI ABE
 * SPDX-License-Identifier: MIT
 *
 * 配布条件はリポジトリ同梱の LICENSE を参照してください。
 * この表記を残したまま、自由に利用・改変・再配布できます。
 *
 * (このファイル末尾、</head>の直前にも同じ内容のカードがHTMLコメントとして
 *  入っています。あちらは配信されるページのソースに出るためのもの、ここは
 *  ソースファイルそのものに対する表記です。両方とも消さないでください。)
 */

/**
 * =====================================================================
 * Pusyuu共通メイキィサービス (index.php) - 単一ファイル構成
 *
 * メイキィの作成・編集・削除・ログイン・ログアウト、フォロー/フォロワー、
 * お気に入り(likes)を扱う唯一の入口です。以前は lib/store.php・api/index.php・
 * index.php の3ファイルに分かれていましたが、入口をむやみに増やすと
 * (a) それぞれ個別にループバック制限やCSRF対策を作り込む必要が出て漏れが起きやすい
 * (b) どのファイルが何を許可しているか把握しづらくなる
 * という理由から、このファイル1つに統合しました。
 *
 * 動作モードは「クエリパラメータ」で切り替えます。
 *
 *   ?api=<action>   … 他サービスのサーバから叩く内部API(JSON応答)。
 *                      暗号化キーから導出した合言葉(api_secret)が無いと弾かれます。
 *                      例: ./index.php?api=session (POSTで token/api_secret)
 *   ?account=<form> … 人間がブラウザで触る画面(作成/編集/削除/ログイン)。
 *                      通常のセッション+CSRFトークンで保護します。
 *
 * 【他サービスからの連携について】
 * アカウントの作成・ログイン・編集・削除を行う画面は、このサービスにしかありません。
 * 他サービスは自前のフォームを一切持たず、?account=login&return_to=<戻り先URL>
 * (ログイン済みかどうかを黙って確かめたいだけなら silent=1 も付ける)でこの画面へ
 * ユーザーを誘導します。ログイン成功後、戻り先URLへ使い切りの一時コード
 * (?pusyuu_code=...)付きでリダイレクトするので、戻り先サービスはそのコードを
 * ?api=exchange_code へサーバ間通信で渡し、本物のログイントークンと交換してください。
 *
 * したがって ?api= に残っているのは「既にログインした後」に使う読み書き
 * (session・me・profile・data_系・p_drive_系)と、管理パネル専用(admin_系)だけです。
 * 認証そのものを行うAPI(create/login/edit/delete)は、SSOへ完全移行した際に
 * 削除しました。復活させないでください(理由はpmeikieeDispatchApi()内の
 * 【削除済み: create / login / edit / delete】コメント参照)。
 *
 * 呼び出し用のPHP関数群は、各サービスの入口ファイル(index.php)へ直接書き込む
 * 方針にしています(サービスごとの入口を1つにまとめ、こちら側のディレクトリ構成が
 * 変わっても他サービスが影響を受けないようにするためです)。
 * 実装例は pips/index.php・p-memo/index.php、詳しい手順は
 * ACCOUNTS_INTEGRATION_SPEC.md を参照してください。
 *
 * account.jsonl(暗号化済み)はPIPS時代のものをそのまま移行してきています。
 * 書式(base64(iv(12byte) . tag(16byte) . cipher) / AES-256-GCM)を変えると
 * 積み上げたメイキィデータが読めなくなるため、絶対に変更しないでください。
 * =====================================================================
 */

declare(strict_types=1);

// セッションCookie自体の属性をphp.ini任せにせず明示する(REMEMBER_COOKIEと同じ
// 水準に揃える)。session_start()より前でしか効かないので、必ずこの位置に置くこと。
//
// SameSiteは必ずLax。Strictにすると、他サービス(pips等)からのトップレベル遷移で
// 戻ってきた無音SSO(?account=login&silent=1)にCookieが送られず、常に「未ログイン」
// と判定されてSSO全体が壊れる。
//
// secureは「今そのリクエストが実際にHTTPSで来ているか」で決める。無条件にtrueへ
// すると、HTTPで配信されている環境ではブラウザがセッションCookieを一切保存できず、
// ログイン状態が1リクエストも保たない=全サービスのSSOが黙って動かなくなる。
// (HTTPSで来ているときは今まで通りSecureが付く。)
$pmeikieeHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
  || (($_SERVER['SERVER_PORT'] ?? '') === '443')
  || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
session_set_cookie_params([
  'lifetime' => 0,
  'path'     => '/',
  'secure'   => $pmeikieeHttps,
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_cache_limiter('nocache');
session_start();

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
  pusyuuRecordProductUsage('p-meikiee');
}

// 暗号化ヘルパーは共有スクリプトへ依存させず、このファイル自身が持ちます
// (下の PDriveEngine::aesGcmEncrypt() 等)。以前は
// main/pusyuusystem/scripts/php_scripts/pusyuu_storage_crypto.php をrequireし、
// 見つからなければdie()していましたが、それだと共有部分の置き場所が変わったり
// p-meikieeフォルダ単体を持ち出したりしただけでサービスが起動しなくなります。
// この方針の理由は PDriveEngine::aesGcmEncrypt() 手前のコメントを参照。

// ================================================================
// 設定
// ================================================================

// メイキィ本体(account.jsonl)。PIPS時代と同じ「assetsの下」という置き場所の
// 慣習を踏襲しつつ、ここ(accounts)を唯一の正データにします。
const ACCOUNT_FILE = __DIR__ . '/assets/posts/account.jsonl';

// 暗号化キー。Web公開ディレクトリよりさらに手前の非公開層に置かれている、
// PIPS時代からの鍵ファイルをそのまま共用します(鍵を分けるとaccount.jsonlが
// 復号できなくなるため、絶対に新しい鍵に切り替えないでください)。
const KEY_FILE_CANDIDATES = [
  '.pusyuuHiddenFiles/pips_account_key.php',
];

// ログイントークンの有効期限(秒)。アクセスのたびにスライド式で延長されます。
const SESSION_TTL = 60 * 60 * 24 * 30; // 30日

// 「ログイン状態を保持する」用の長期Cookie。PHPのセッションCookie(既定の寿命)が
// 切れてもログイン状態を復元できるようにするためのもので、accounts_token自体を
// そのまま保持する(専用の別ストレージを持たず、既存のsessions.jsonl/SESSION_TTLの
// スライド式失効・一括失効(パスワード変更時等)処理をそのまま使い回すため)。
// Cookie自体の寿命はSESSION_TTLに合わせておく(それより長くしてもトークン自体が
// その頃には切れているため意味が無い)。ログインフォームとメイキィ編集画面の
// チェックボックスでユーザーが選んだ場合のみ発行する(既定では発行しない)。
const REMEMBER_COOKIE = 'pmeikiee_remember_token';
const REMEMBER_COOKIE_TTL = SESSION_TTL;

// SSO用の一時コード(authorization code)の有効期限。使い切り・短命にします。
const HANDOFF_CODE_TTL = 60; // 60秒

// oppai管理パネルがサポート対応のために発行する「一時ログインリンク」の有効期限。
// パスワードを検証しない代わりに、寿命を短く・使い切りにして安全性を確保します。
const ADMIN_TEMP_LOGIN_TTL = 60 * 15; // 15分

// oppai管理パネルの「休眠アカウント」表示のしきい値、かつ
// pmeikieeSweepStaleAccounts()による自動削除のしきい値でもあります。
// last_loginが記録されている(=過去に実際にログインした実績がある)アカウントに
// 限って適用します。last_loginが一度も記録されていないアカウント(移行前の
// 既存アカウント等、本当に古いのか判定できないもの)は「誤判定で巻き込む」
// リスクを避けるため、この自動削除の対象からは除外し、admin_list_accountsの
// never_seen一覧から管理者が個別に判断する運用のままにします。
const DORMANT_ACCOUNT_DAYS = 365;

// 作成されたのに一度もログインされないまま放置されたメイキィ(=誰も使って
// いない空の登録)を自動削除するまでの猶予日数。created_atフィールド導入後に
// 作られたアカウントにのみ適用します(created_atが無い=導入前の既存アカウントは
// 「作成日時が不明」なだけで「使われていない」とは限らないため対象外です)。
const NEW_ACCOUNT_INACTIVITY_DAYS = 7;

// ログイン失敗の連続許容回数と、その計測時間(秒)。
const LOGIN_MAX_FAILURES = 10;
const LOGIN_FAILURE_WINDOW = 60 * 15; // 15分

// ログイン診断トレースの目印ファイル名。中身と読み方は MeikieeLoginTrace の
// 手前のコメントを参照してください。
//
// 【2026-09-15に、コードを書き換えるスイッチから目印ファイルへ変えました】
// 以前は const LOGIN_TRACE_ENABLED = true; という定数で、ONにするにも
// OFFにするにもこのファイルを編集する必要がありました。そのため
//   ・調べたい時に「どのファイルのどの行か」を思い出すところから始まる
//   ・共有スクリプト側(meikiee_client.php)のSSOトレースは目印ファイル方式で、
//     2つの記録を取るのに切り替え方が2通りあった
//   ・戻し忘れても気づく機会が無い(実際、trueのまま残っていました)
// という3つを抱えていました。
//
// 今はこの1つの目印ファイルで、メイキィ側のログイン診断と、各プロダクト側の
// SSO往復トレースの**両方**が同時にONになります。管理画面(oppai)の
// 「メイキィ ログイン記録」から押すのが一番簡単で、サーバへ入れる人は
// 従来どおり空ファイルを置く/消すでも構いません。名前を変えるときは
// meikiee_client.php の TRACE_FLAG_NAME と必ず揃えてください。片方だけ変えると、
// 「ONにしたのに片方の記録だけ増えない」という分かりにくい形で食い違います。
const LOGIN_TRACE_FLAG_NAME = 'pusyuu_sso_trace_on';

// トレースファイルの上限(バイト)。超えたら古い方から半分捨てます。
// 消し忘れたまま放置されても、非公開層を埋め尽くさないようにするためです。
const LOGIN_TRACE_MAX_BYTES = 1024 * 1024; // 1MB

// リカバリコード(パスワード再設定用メールが存在しないための自己復旧手段)関連の設定。
// 発行は5本・使い捨て(使ったら削除)。総当たり対策は通常ログインの失敗回数制限
// (LOGIN_MAX_FAILURES)よりも厳しくする(RECOVERY_MAX_ATTEMPTS)。
const RECOVERY_CODE_COUNT = 5;
const RECOVERY_CODE_GROUP_COUNT = 5; // ハイフン区切りのグループ数
const RECOVERY_CODE_GROUP_LENGTH = 5; // 1グループあたりの文字数
const RECOVERY_CODE_CONSENT_VERSION = 'v1'; // 同意画面の文言を変えたらこの値を上げる
const RECOVERY_MAX_ATTEMPTS = 3;
const RECOVERY_ATTEMPT_WINDOW = 60 * 15; // 15分

// リカバリコードに使う文字集合(Crockford's Base32相当)。英単語の羅列は
// 日本語話者にとって読み書きの助けにならない(単語として認識・省略記憶しにくい)ため
// 採用せず、完全にランダムな文字列にしている。0/O・1/I/L・U/Vのような誤読しやすい
// 文字は除外済み(手書きでの転記時の誤読対策)。
const RECOVERY_CODE_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

// ?api=... の認証は、暗号化キーから導出した合言葉(api_secret)の一致だけで判定します。
// 以前はREMOTE_ADDRがループバック(127.0.0.1)かどうかも見ていましたが、Docker上で
// 動かす構成だとコンテナ間・ホスト⇄コンテナ間の通信がNAT経由になり、内部からの
// 正当な呼び出しでもREMOTE_ADDRがブリッジのゲートウェイIPやLAN側のIPに化けてしまい
// 信用できないため廃止しました。合言葉は各サービス側(pips/index.php・
// p-memo/index.php)のpusyuuAccountsApiSecret()が同じ暗号化キーファイルから
// 自動で導出して送るので、キー自体を各サービスへ個別に配布する必要はありません。

// SSOリダイレクト先の許可リスト(オープンリダイレクト対策)。
// return_to の「ホスト名」がここに含まれない場合は危険なので無視し、
// 通常のこのサイト自身へのログインとして扱います。
// 新しいサービスと連携するときは、そのサービスのホスト名をここに追加してください。
const ALLOWED_RETURN_HOSTS = [
  'p-memo.pusyuuwanko.com',
  'pips.pusyuuwanko.com',
  'toolbox.pusyuuwanko.com',
  'p-reversi.pusyuuwanko.com',
  'p-chat.pusyuuwanko.com',
  'p-drive.pusyuuwanko.com', // p-drive自身が「顔」(マイファイル画面)を持つようになったため追加。
];

// パスワードポリシー: 8文字以上、英字と数字を両方含み、さらに大文字と小文字も
// 両方含むこと。上限は、bcrypt(PASSWORD_DEFAULT)が72バイトを超える部分を
// 静かに切り捨てて無視する挙動に合わせて設けている(切り捨てにより「入力した
// パスワードの一部しか実際には効いていない」という気付きにくい不具合を防ぐため)。
const PASSWORD_MIN_LENGTH = 8;
const PASSWORD_MAX_LENGTH = 72;

// ================================================================
// プロフィール(自己紹介・アバター画像)
// ================================================================

// 他サービス(pipsなど)からリンクされる、accounts自身のバーチャルホスト名。
// avatar_urlを絶対URLで組み立てるためだけに使います。ドメインが変わっても
// 追従できるよう、実際のリクエストのHostヘッダから取得する(constは実行時の
// 値を持てないためdefine()にしている)。
if (!defined('PMEIKIEE_SELF_HOST')) {
  define('PMEIKIEE_SELF_HOST', $_SERVER['HTTP_HOST'] ?? 'p-meikiee.pusyuuwanko.com');
}

const BIO_MAX_LENGTH = 300;

const AVATAR_MAX_UPLOAD_BYTES = 2 * 1024 * 1024; // アップロード時点の生ファイルサイズ上限(2MB)
const AVATAR_SIZE = 320; // 保存後の一辺のピクセル数(正方形にセンタークロップ)
// 未設定時・ファイルが見つからない場合のフォールバック。main/pips/p-memoが
// 既定アバターとして使っているものと同じ画像です。
const DEFAULT_AVATAR_URL = 'https://pusyuuwanko.com/pusyuusystem/images/avater.jpg';

// ================================================================
// ================================================================
// 暗号化された保存の土台(MeikieeSecureStore)
//
// 鍵の在り処を突き止め、AES-256-GCMで暗号化し、暗号化されたJSONLを安全に
// 読み書きする——ここまでが1つの塊です。account.jsonl も sessions.jsonl も
// handoff_codes.jsonl も、すべてこの層の上に乗っています。
//
// 【なぜクラスにまとめたか】これらは「鍵」という同じ状態を共有しています。
// 鍵の場所が変われば全部の読み書きが変わり、暗号方式を変えれば全部が変わります。
// 一緒に動くものが離れて置かれていると、片方だけ直したときに気づけません。
//
// 【グローバル関数として残したもの】
// このクラスの外(台帳・認証・保守・API等、6区分)から呼ばれるものは、呼び出し側を
// 一斉に書き換える利益が無いのでグローバルの薄い入口として残し、中身をここへ委ねます。
// 呼び出し箇所を数えたうえでの判断です(2026-09-07時点):
//   accountsHiddenStorageDir … 6区分から利用
//   accountsTransactJsonl    … 認証・検索整形から計9箇所
//   accountsEncryptionKey    … APIから4箇所 ほか
//
// 【ここから出したもの(移動済み・2026-09-07)】
// accountsSessionFile / accountsCodeFile / accountsAdminTempLoginFile /
// accountsAttemptFile の4つは、長らくこの節に並んでいましたが、実際には
// 「どのファイルに保存するか」を1行返すだけで、暗号化とは何の関係もありませんでした。
// 置き場所だけが理由でここに集まっていた状態です。現在は持ち主の側に移してあり、
// それぞれのクラスのprivateメソッドになっています:
//   sessions.jsonl / handoff_codes.jsonl / admin_temp_logins.jsonl
//                                    → MeikieeAuth の中(このファイル内で検索)
//   login_attempts.json              → MeikieeLoginThrottle の中
// 保存先を1つ増やしたくなった時も、ここには戻さず、そのデータを扱うクラスの中に
// 置いてください。ここへ集めると「暗号化の節なのに暗号化と無関係の物が並ぶ」状態が
// また育ちます。
// ================================================================

final class MeikieeSecureStore {
  /**
   * $start から上へ辿りながら、$relatives のいずれかが実在する最初の場所を返します。
   * 鍵ファイルを探すために使いますが、探索そのものは汎用なので他からも呼べます。
   */
  public static function findUpward(array $relatives, string $start, int $maxUp = 8): ?string {
    $dir = $start;
    $found = null;
    $atRoot = false;

    // 上限($maxUp段)は、見つからないときに延々と登り続けないための歯止めです。
    for ($i = 0; $i <= $maxUp && $found === null && $atRoot === false; $i++) {
      foreach ($relatives as $relative) {
        $candidate = $dir . '/' . $relative;
        if (file_exists($candidate)) {
          $found = $candidate;
          break;
        }
      }

      if ($found === null) {
        $parent = dirname($dir);
        if ($parent === $dir) {
          $atRoot = true; // ルートまで来た
        } else {
          $dir = $parent;
        }
      }
    }

    return $found;
  }

  /**
   * 暗号化キーファイル(.pusyuuHiddenFiles/pips_account_key.php)が置かれているディレクトリ、
   * すなわち「Web公開ディレクトリの外側にある非公開層」そのものを返します。
   * セッショントークン等の付随データも、この下にしまうことで.htaccessに頼らず保護します。
   */
  public static function hiddenDir(): ?string {
    $keyFile = self::findUpward(KEY_FILE_CANDIDATES, __DIR__);

    if ($keyFile === null) {
      $dir = null;
    } else {
      $dir = dirname($keyFile);
    }

    return $dir;
  }

  /** 付随データ(トークン等)を保存するディレクトリ。無ければ作成します。 */
  public static function storageDir(): ?string {
    $hidden = self::hiddenDir();
    $dir = ($hidden === null) ? null : $hidden . '/accounts_storage';

    if ($dir === null) {
      $result = null;
    } else if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
      // mkdir が失敗しても、他のリクエストが同時に作っていれば is_dir は真になります。
      error_log('[Accounts] 非公開ストレージディレクトリを作成できませんでした。dir=' . $dir);
      $result = null;
    } else {
      $result = $dir;
    }

    return $result;
  }

  /**
   * 非公開層の中のファイル名を組み立てます。非公開層が使えないときは一時ディレクトリへ
   * 逃がします(保存できないよりは、再起動で消える場所にでも置けた方がましなため)。
   *
   * 各区分が「自分の保存先」を1行で名乗れるようにするための共通部品です。
   * ここに個別のファイル名を並べないこと。並べ始めると、この層が全機能の保存先一覧に
   * なってしまい、どの機能がどのファイルを持っているのかが散らばります。
   */
  public static function pathIn(string $fileName): string {
    return (self::storageDir() ?? sys_get_temp_dir()) . '/' . $fileName;
  }

  /** 暗号化キー(生32バイト)。見つからない・壊れている場合はnull。 */
  public static function key(): ?string {
    static $loaded = false;
    static $key = null;

    // 2回目以降は $loaded が true なので、この塊ごと素通りして答えを使い回します。
    if ($loaded === false) {
      $loaded = true;
      $keyFile = self::findUpward(KEY_FILE_CANDIDATES, __DIR__);
      $encoded = ($keyFile === null) ? null : require $keyFile;
      $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;

      // 【どの段階で躓いたのかを必ず残すこと】黙って null を返すと、
      // 全アカウントが読めない理由が誰にも分からなくなります。
      if ($keyFile === null) {
        error_log('[Accounts] 暗号化キーファイルが見つかりません。');
        $key = null;
      } else if (!is_string($encoded)) {
        error_log('[Accounts] 暗号化キーファイルの形式が不正です。file=' . $keyFile);
        $key = null;
      } else if ($decoded === false || strlen($decoded) !== 32) {
        error_log('[Accounts] 暗号化キーの長さが不正です。AES-256-GCMには32byteのキーが必要です。');
        $key = null;
      } else {
        $key = $decoded;
      }
    }

    return $key;
  }

  public static function encryptText(string $plaintext): ?string {
    $key = self::key();
    $iv  = random_bytes(12);
    $tag = '';
    $cipher = ($key === null)
      ? false
      : openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

    if ($key === null) {
      // key() が既に理由を error_log へ残しているので、ここでは重ねません。
      $encoded = null;
    } else if ($cipher === false) {
      error_log('[Accounts] データの暗号化に失敗しました。');
      $encoded = null;
    } else {
      // 【この並び順(base64( IV + TAG + 暗号文 ))を変えないこと】
      // 変えると account.jsonl が丸ごと読めなくなります。
      $encoded = base64_encode($iv . $tag . $cipher);
    }

    return $encoded;
  }

  public static function decryptText(string $encoded): ?string {
    $key = self::key();
    $raw = ($key === null) ? false : base64_decode($encoded, true);

    // 28バイト未満は、IVとTAGだけでも足りない = そもそもこの形式ではありません。
    if ($raw === false || strlen($raw) < 28) {
      $plain = null;
    } else {
      $iv     = substr($raw, 0, 12);
      $tag    = substr($raw, 12, 16);
      $cipher = substr($raw, 28);
      $opened = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
      $plain = ($opened === false) ? null : $opened;
    }

    return $plain;
  }

  // ----------------------------------------------------------------
  // 暗号化JSONLの読み書き
  // (account.jsonl / sessions.jsonl / handoff_codes.jsonl が同じ形で乗ります)
  // ----------------------------------------------------------------

  public static function readJsonl(string $file): array {
    $raw = file_exists($file) ? file_get_contents($file) : false;
    $empty = ($raw === false || trim($raw) === '');
    $plain = $empty ? null : self::decryptText(trim($raw));
    $rows = [];

    // 【復号できなかったことを黙って空配列で返さないこと】ここが空を返すと、
    // 呼び出し側には「アカウントが1件も無い」と区別が付きません。
    if (!$empty && $plain === null) {
      error_log('[Accounts] データの復号に失敗しました。file=' . $file);
    }

    if ($plain !== null) {
      foreach (preg_split('/\r?\n/', $plain) as $line) {
        $line = trim($line);
        if ($line !== '') {
          $row = json_decode($line, true);
          if (is_array($row)) { $rows[] = $row; }
        }
      }
    }

    return $rows;
  }

  /**
   * 【privateにしている理由】書き込みは必ず transactJsonl() を通してください。
   * ロックを取らずに直接書くと、同時に走った別のリクエストの変更を丸ごと踏み潰します。
   * 呼び出し箇所を数えたところ、この関数を外から呼んでいる場所は1つもありませんでした。
   * 外に開いておく理由が無く、開いておくと「うっかりロック無しで書く」道が残ります。
   */
  private static function writeJsonl(string $file, array $rows): bool {
    $lines = [];
    foreach ($rows as $row) {
      $lines[] = json_encode($row, JSON_UNESCAPED_UNICODE);
    }

    $encrypted = self::encryptText(implode("\n", $lines));
    $dir = dirname($file);
    // mkdir が失敗しても、他のリクエストが同時に作っていれば is_dir は真になります。
    $noDir = (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir));
    $tmpFile = $dir . '/.' . uniqid('acc_', true) . '.tmp';
    $fp = ($encrypted === null || $noDir) ? false : fopen($tmpFile, 'w');

    if ($encrypted === null) {
      // 【暗号化できないまま書かないこと】平文で書くとアカウント情報が素で残ります。
      error_log('[Accounts] 暗号化に失敗したため書き込みを中止しました。file=' . $file);
      $ok = false;

    } else if ($noDir) {
      error_log('[Accounts] 保存先ディレクトリを作成できませんでした。dir=' . $dir);
      $ok = false;

    } else if ($fp === false) {
      error_log('[Accounts] 一時ファイルの作成に失敗しました。file=' . $tmpFile);
      $ok = false;

    } else {
      flock($fp, LOCK_EX);
      fwrite($fp, $encrypted);
      fflush($fp);
      flock($fp, LOCK_UN);
      fclose($fp);

      // いったん別名で書いてから rename します。途中で落ちても、読み手が
      // 半分だけ書かれたファイルを掴むことがありません(rename は不可分)。
      if (!rename($tmpFile, $file)) {
        error_log('[Accounts] リネームに失敗しました。tmp=' . $tmpFile . ' target=' . $file);
        @unlink($tmpFile);
        $ok = false;
      } else {
        $ok = true;
      }
    }

    return $ok;
  }

  /**
   * ロックファイルを取ってから読み込み→変更→書き込みを行います。
   * $mutator は array& $rows を受け取り、保存が必要なら true を返します。
   * 戻り値: true=保存した / false=保存不要(中断) / null=エラー
   */
  public static function transactJsonl(string $file, callable $mutator) {
    $lockFile = $file . '.lock';
    $dir = dirname($lockFile);
    // mkdir が失敗しても、他のリクエストが同時に作っていれば is_dir は真になります。
    $noDir = (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir));
    $lockHandle = $noDir ? false : fopen($lockFile, 'c');

    if (!$noDir && $lockHandle === false) {
      error_log('[Accounts] ロックファイルを開けませんでした。file=' . $lockFile);
    }

    if ($lockHandle === false) {
      // 鍵をかけられないまま書くと、同時に走った別の要求の変更を丸ごと踏み潰します。
      $status = null;
    } else {
      flock($lockHandle, LOCK_EX);
      $rows = self::readJsonl($file);
      $shouldSave = $mutator($rows);

      // 戻り値は3値です。false=保存不要(中断)、true=保存した、null=エラー。
      if (!$shouldSave) {
        $status = false;
      } else {
        $status = self::writeJsonl($file, $rows) ? true : null;
      }

      flock($lockHandle, LOCK_UN);
      fclose($lockHandle);
    }

    return $status;
  }
}

// ----------------------------------------------------------------
// 上のクラスへの薄い入口(グローバル関数)
//
// 6つの区分から呼ばれているものだけを残しています。呼び出し側を一斉に書き換える
// 利益が無いので、名前はそのままにして中身だけをクラスへ委ねました。
// 新しく書くコードでは MeikieeSecureStore::… を直接呼んでください。
// ----------------------------------------------------------------

function accountsFindUpward(array $relatives, string $start, int $maxUp = 8): ?string {
  return MeikieeSecureStore::findUpward($relatives, $start, $maxUp);
}

function accountsHiddenDir(): ?string {
  return MeikieeSecureStore::hiddenDir();
}

function accountsHiddenStorageDir(): ?string {
  return MeikieeSecureStore::storageDir();
}

function accountsEncryptionKey(): ?string {
  return MeikieeSecureStore::key();
}

function accountsEncryptText(string $plaintext): ?string {
  return MeikieeSecureStore::encryptText($plaintext);
}

function accountsDecryptText(string $encoded): ?string {
  return MeikieeSecureStore::decryptText($encoded);
}

function accountsReadEncryptedJsonl(string $file): array {
  return MeikieeSecureStore::readJsonl($file);
}

function accountsTransactJsonl(string $file, callable $mutator) {
  return MeikieeSecureStore::transactJsonl($file, $mutator);
}

// ================================================================
// アカウント台帳(MeikieeAccounts)
//
// account.jsonl に触れるのはここだけです。全アカウントの読み出し、ユーザー名や
// メールでの検索、書き換えのトランザクション、そして「外部へ見せてよい形」への
// 整形が、すべてこの1つの塊に入っています。
//
// 【なぜクラスにまとめたか】これらは長らくファイルの2箇所に分かれて置かれて
// いました(参照・検索の節と、その400行ほど下の検索の節)。同じ台帳を扱う
// 仲間なのに離れていると、「どこから台帳を読んでいるのか」を追うのに
// ファイル全体を見渡すことになります。1箇所にまとめれば、台帳への入口が
// ここしかないことが一目で分かります。
//
// 【ほとんどが public なのは意図的です】
// 呼び出し箇所を数えたところ、readAll は6箇所、transaction は9箇所、
// findById は9箇所、publicUser は11箇所から使われていました(2026-09-07時点)。
// 台帳は「全機能が参照する土台」なので、これは正常な形です。逆に言えば、
// ここに手を入れると影響範囲が広いということでもあります。
//
// 【外から呼ばれないもの】computeStorageId だけが内部専用です。
// storage_id は sha256(生id) で、生idを外へ出さないための仕組みそのものなので、
// 外から気軽に計算できる必要はありません。
// ================================================================

final class MeikieeAccounts {

  public static function readAll(): array {
    return array_map([self::class, 'normalizeUser'], accountsReadEncryptedJsonl(ACCOUNT_FILE));
  }

  /**
   * 実在するアカウントのstorage_id一覧(p_drive_put等、書き込み時にuseridの
   * 妥当性を検証するために使う)。1リクエスト内で複数回呼ばれてもaccount.jsonlの
   * 再読込・再復号をしないよう、リクエスト単位でメモ化する。
   */
  public static function validStorageIds(): array {
    static $ids = null;
    if ($ids === null) {
      $ids = [];
      foreach (self::readAll() as $user) {
        $sid = (string)($user['storage_id'] ?? '');
        if ($sid !== '') { $ids[$sid] = true; }
      }
    }
    return $ids;
  }

  /**
   * ストレージ用の不変id("storage_id")。p-drive・アバター・p-memo等の他サービスに
   * 渡すuseridは、必ずこの値(=sha256(生id))で統一する。生id自体は
   * pmeikieeCreate()で発行された瞬間から一切変更されない(idを書き換える編集
   * 機能は存在しない)ため、この値も同様に口座の生涯を通じて不変であることが
   * 保証される。
   *
   * 【どちらを使うかの判断基準】account.jsonlの中(self::findIndexById()等、
   * このファイルの内部処理)だけが生idを扱ってよい。セッション・画面・フォームの
   * hidden・他サービスへの応答・ログなど、このファイルの外へ出る値は例外なく
   * こちら(storage_id)。詳しい理由と、比較ロジックを書くときの指針は
   * pmeikieeUiEstablishSession()のコメントを参照。
   */
  private static function computeStorageId(string $rawId): string {
    return hash('sha256', $rawId);
  }

  /** account.jsonlの各レコードが "userData" キー・"storage_id" キーを持つことを保証します。 */
  public static function normalizeUser(array $user): array {
    if (!isset($user['userData']) || !is_array($user['userData'])) {
      $user['userData'] = [];
    }

    // storage_idの整合性保証。account.jsonlに保存済みの値が、本来あるべき値
    // (sha256(生id))と食い違っていたら、そもそもp-drive上のデータ全部が
    // 迷子になる致命的な不整合なので、ここで必ず検知してログに残す(通常運用では
    // 絶対に起こらないはずの状態: storage_idはpmeikieeCreate()以降一切書き換え
    // られないため)。
    $expectedStorageId = self::computeStorageId((string)($user['id'] ?? ''));
    if (!isset($user['storage_id']) || $user['storage_id'] === '') {
      // storage_idフィールド導入前に作成された既存アカウント向けの後方互換。
      // account.jsonl自体はここでは書き換えない(1件の書き込みでも全件再暗号化
      // されるコストが大きいため)。値は生idから毎回決定的に再計算できるので、
      // メモリ上で補うだけで十分。
      $user['storage_id'] = $expectedStorageId;
    } elseif (!hash_equals($expectedStorageId, (string)$user['storage_id'])) {
      error_log('[p-meikiee] storage_id不整合を検知しました。account.jsonlのstorage_idが生idから期待される値と一致しません。userid=' . $expectedStorageId . ' stored=' . $user['storage_id']);
      // 不整合時は「p-driveの実データが実際に置かれている可能性が高い方」より、
      // 常に生idから再計算した値を正とする(storage_idは本来生idの純粋な関数で
      // あるべきで、記録された値の方が壊れているとみなす)。
      $user['storage_id'] = $expectedStorageId;
    }

    return $user;
  }

  public static function transaction(callable $mutator) {
    return accountsTransactJsonl(ACCOUNT_FILE, $mutator);
  }

  public static function findIndexByUsername(array $accounts, string $username): ?int {
    $found = null;

    foreach ($accounts as $i => $user) {
      if (isset($user['username']) && $user['username'] === $username) {
        $found = $i;
        break;
      }
    }

    return $found;
  }

  /**
   * 同じメールアドレスのメイキィを探します。
   * メールアドレスは大文字小文字を区別せず比較します(ドメイン部はRFC上そもそも
   * 区別されず、ローカル部を区別する運用も実際にはほぼ無いため。「Foo@example.com」と
   * 「foo@example.com」を別アカウントとして作れてしまうと重複禁止の意味が無くなります)。
   * $exceptIndexを渡すと、その位置のアカウントは重複判定から除外します
   * (編集時に「自分自身の今のアドレス」を重複扱いしないため)。
   */
  public static function findIndexByEmail(array $accounts, string $email, ?int $exceptIndex = null): ?int {
    // 大文字小文字を区別せずに比べます。区別すると「Foo@」と「foo@」を
    // 別アカウントとして作れてしまい、重複禁止の意味が無くなります。
    $needle = mb_strtolower(trim($email));
    $found = null;

    if ($needle !== '') {
      foreach ($accounts as $i => $user) {
        // $exceptIndex は「自分自身の今のアドレス」を重複扱いしないための除外です。
        if ($i !== $exceptIndex && mb_strtolower(trim((string)($user['email'] ?? ''))) === $needle) {
          $found = $i;
          break;
        }
      }
    }

    return $found;
  }

  public static function findIndexById(array $accounts, string $id): ?int {
    $found = null;

    foreach ($accounts as $i => $user) {
      if (isset($user['id']) && $user['id'] === $id) {
        $found = $i;
        break;
      }
    }

    return $found;
  }

  public static function findByUsername(string $username): ?array {
    $accounts = self::readAll();
    $idx = self::findIndexByUsername($accounts, $username);

    return $idx === null ? null : $accounts[$idx];
  }

  public static function findById(string $id): ?array {
    $accounts = self::readAll();
    $idx = self::findIndexById($accounts, $id);

    return $idx === null ? null : $accounts[$idx];
  }

  /** avatar_url組み立て・アップロード保存先の計算に使う、メイキィごとの固定ファイル名。 */
  /**
   * avatarが未設定ならデフォルト画像のURLを返します。画像データそのものは
   * account.jsonlとは別の、p_drive_storage/<storage_id>/p-meikiee/avatar上の
   * 専用項目に持つため(pDriveEnginePut()参照。p-drive自身の鍵で暗号化)、
   * account.jsonl自体は誰かのアバター変更で重くならず、ディスク上には平文の
   * 画像ファイルも残さない(?avatar=<hash>で都度復号して配信する。下記の
   * 早期GET分岐、および pmeikieeAvatarSave() 参照)。ハッシュ自体はstorage_id
   * (self::computeStorageId()と同じ計算=sha256(id))と同一のため、これを
   * 公開しても他サービスに既に渡しているuseridと同じ情報量にしかならない。
   * 更新の度にブラウザ/CDNへ古い画像がキャッシュされたまま出ないよう、
   * 保存時刻をクエリに付ける。
   */
  public static function avatarUrl(array $user): string {
    $storageId = (string)($user['storage_id'] ?? '');

    if ($storageId === '' || empty($user['avatar_updated'])) {
      $url = DEFAULT_AVATAR_URL;
    } else {
      // 保存時刻をクエリに付けます。付けないと、更新しても古い画像が
      // ブラウザやCDNのキャッシュから出続けます。
      $updated = (int)$user['avatar_updated'];
      $url = 'https://' . PMEIKIEE_SELF_HOST . '/index.php?avatar=' . $storageId . '&v=' . $updated;
    }

    return $url;
  }

  /**
   * 呼び出し側(他サービス)へ返してよい範囲だけを抜き出します。
   * password(ハッシュ)は絶対に外に出しません。idも生値は出さず、sha256ハッシュにします。
   *
   * bio・avatar_urlは(email等と違い)公開プロフィール情報として扱うため、
   * $includeOwnDataに関わらず常に含めます(profile/session/login/edit/me、すべてに乗ります)。
   *
   * $includeOwnData を true にすると、本人にだけ返す情報(email・userData丸ごと)も含めます。
   * userDataの中身(フォロー中一覧やお気に入りなど)が何を意味するかはaccounts側では
   * 関知しません。各サービスが自分のservice名+key名で読み書きしたものが、そのまま
   * 返ってくるだけです。following等に生idを入れてしまわないのは、呼び出し側
   * (pipsなど)の責務です(pipsは他ユーザーの生idを知り得ないため、実質的に守られます)。
   *
   * PIPS本体は受け取ったPOSTを htmlspecialchars() してから account.jsonl に保存しているため、
   * 名前やユーザー名に & " < > ' が含まれていると "&amp;" のような形で入っています。
   * ここで元に戻しておかないと、呼び出し側が改めてエスケープしたときに二重エスケープになります。
   */
  public static function publicUser(array $user, bool $includeOwnData = false): array {
    $decode = static fn($value) => htmlspecialchars_decode((string)$value, ENT_QUOTES);
    $name = $decode($user['name'] ?? '');
    $out = [
      'userid'     => (string)($user['storage_id'] ?? self::computeStorageId((string)($user['id'] ?? ''))),
      'username'   => $decode($user['username'] ?? ''),
      'name'       => $name !== '' ? $name : '名無し',
      'bio'        => $decode($user['bio'] ?? ''),
      'avatar_url' => self::avatarUrl($user),
    ];

    if ($includeOwnData) {
      $out['email'] = $decode($user['email'] ?? '');
      $out['userData'] = pmeikieeResolveUserData($user);
    }

    return $out;
  }
}

// ----------------------------------------------------------------
// 上のクラスへの薄い入口(グローバル関数)
//
// 台帳は全機能の土台で、呼び出し側が非常に多いため、名前はそのままにして
// 中身だけをクラスへ委ねました。新しく書くコードでは MeikieeAccounts::… を
// 直接呼んでください。
//
// pmeikieeNormalizeUser は、以前 array_map('pmeikieeNormalizeUser', …) と
// **文字列で**呼ばれていました。クラスへ移す際にその1行は
// array_map([self::class, 'normalizeUser'], …) へ直してあるので、今この入口に
// 依存しているのは外部の呼び出しだけです。それでも残しているのは、同じ書き方が
// 将来また現れたときに静かに壊れないようにするためです。
// ----------------------------------------------------------------

function pmeikieeReadAll(): array {
  return MeikieeAccounts::readAll();
}

function pmeikieeValidStorageIds(): array {
  return MeikieeAccounts::validStorageIds();
}

function pmeikieeNormalizeUser(array $user): array {
  return MeikieeAccounts::normalizeUser($user);
}

function pmeikieeTransaction(callable $mutator) {
  return MeikieeAccounts::transaction($mutator);
}

function pmeikieeFindIndexByUsername(array $accounts, string $username): ?int {
  return MeikieeAccounts::findIndexByUsername($accounts, $username);
}

// 第3引数$exceptIndexを省いてはいけない。編集時(MeikieeAccountLifecycle::edit())は
// 「自分自身を除いて同じアドレスが居るか」を訊いており、除外先を渡せないと自分の
// 今のアドレスに自分で引っかかって、名前やbioだけを直した編集まで
// 「そのメールアドレスは既に使われています。」で必ず失敗する。
// PHPはユーザー定義関数に余分な引数を渡してもエラーにせず黙って捨てるため、
// ここで引数を落としても呼び出し側は何も言わずに壊れる。
function pmeikieeFindIndexByEmail(array $accounts, string $email, ?int $exceptIndex = null): ?int {
  return MeikieeAccounts::findIndexByEmail($accounts, $email, $exceptIndex);
}

function pmeikieeFindIndexById(array $accounts, string $id): ?int {
  return MeikieeAccounts::findIndexById($accounts, $id);
}

function pmeikieeFindByUsername(string $username): ?array {
  return MeikieeAccounts::findByUsername($username);
}

function pmeikieeFindById(string $id): ?array {
  return MeikieeAccounts::findById($id);
}

function pmeikieeAvatarUrl(array $user): string {
  return MeikieeAccounts::avatarUrl($user);
}

function pmeikieePublicUser(array $user, bool $includeOwnData = false): array {
  return MeikieeAccounts::publicUser($user, $includeOwnData);
}

// =====================================================================
// p-drive(全サービス共通のユーザーデータストレージ)。
//
// あえてp-meikieeとは別ファイル・別プロセスにせず、この index.php 1つに
// 実装を統合しています(このファイル冒頭のコメント「以前は lib/store.php・
// api/index.php・index.php の3ファイルに分かれていましたが…このファイル1つに
// 統合しました」という設計方針と同じ理由です)。理由は2つ:
//
//   1. アカウント削除処理(pmeikieeDelete()/pmeikieeAdminDelete())が、この
//      後段のuserData等の削除(pDriveEngineDeleteUser())を、ネットワーク越しの
//      API呼び出しではなく同一プロセス内の直接の関数呼び出しとして行える。
//      「p-driveが別プロジェクトとして落ちていた場合に削除が反映されない」
//      というクラスの不具合が構造上発生しない(呼び出しに失敗する経路が無い)。
//   2. 以前、実体を"p_drive_engine.php"という別ファイルに切り出したことがあるが、
//      「index.php以外のPHPファイルが何らかの理由で(誤操作・デプロイmiss等で)
//      消えると、それをrequireしているindex.php自体が起動不能になる」という
//      単一障害点を生んでしまうことが分かった。1ファイルに統合していれば
//      この種の事故は起こり得ない。
//
// p-drive/index.php(公開向けのURL)自体はこのファイルへの薄い中継(HTTP転送)
// のみを行う、実体を一切持たないファイルです。p-chat・p-memo等、p-meikiee以外の
// 他サービスから見た公開API契約(P_DRIVE_INTEGRATION_SPEC.md)は変わりません。
// 認証は既存の pmeikieeDispatchApi() の合言葉検証(pusyuu_accounts_api)を
// そのまま使い回します(専用の別の合言葉は持ちません)。
//
// 命名規則: "pDrive"/"P_DRIVE_" で始まるシンボルはこの節専用です。
// =====================================================================

const P_DRIVE_STORAGE_ROOT = __DIR__ . '/p_drive_storage';
const P_DRIVE_STORAGE_KEY_FILE_NAME = 'p_drive_storage_key.php';

// unlink/rmdir は他プロセスの一時的なファイルアクセス(flockを取っている最中等)
// と重なると失敗することがあるため、削除系操作は「本当に消えたか」を都度
// clearstatcache()で確認しながら数回リトライしてから確定的に諦める(以前は
// unlink/rmdirの戻り値すら見ておらず、この手の一時的失敗はおろか恒久的な
// 失敗すら検知できていなかった)。
const P_DRIVE_DELETE_MAX_ATTEMPTS = 5;
const P_DRIVE_DELETE_RETRY_DELAY_US = 100000; // 100ms

// 1ユーザーあたりの合計保存容量の上限(全service合算)。p-drive自体はp-5secondの
// ような期限付き共有ではなく、ここに置いたデータは(そのユーザーが使う限り)
// 恒久的に残るプライベートストレージという性質のため、MB単位ではなくGB単位を
// 既定にしている。技術的な上限があってMB単位にしていたわけではなく、初期値を
// 保守的にしていただけ。個々のサービス側でさらに厳しい自前の上限(例: 下の
// USER_DATA_TOTAL_MAX_BYTES)を持つのは引き続き推奨(1サービスの暴走が同じ
// ユーザーの他サービスぶんを圧迫しないため)。
const P_DRIVE_USER_TOTAL_MAX_BYTES = 5 * 1024 * 1024 * 1024; // 5GB
const P_DRIVE_VALUE_MAX_BYTES = 20 * 1024 * 1024; // 20MB(1項目あたり)

// ================================================================
// p-drive ストレージ本体(PDriveEngine)
//
// 利用者のファイルを実際に置いているのはここです。p-drive.pusyuuwanko.com は
// 「マイファイル」画面(顔)だけを持ち、実体はこのメイキィの中にあります。
//
// 【なぜクラスにまとめたか】29個の関数が、鍵・保存先ディレクトリ・ロックという
// 同じ状態を共有しています。保存形式を変えれば全部が変わり、ディレクトリの
// 決め方を変えても全部が変わる。離れて置かれていると、片方だけ直したときに
// 気づけません。
//
// 【外から呼ばれないものはprivateにしました】
// 呼び出し箇所を数えたうえでの判断です(2026-09-07時点)。暗号化・パスの組み立て・
// ロック・検証つき削除といった内部の道具は、このクラスの外から使う理由がありません。
// privateにしておけば、「うっかりロックを取らずに書く」「安全確認を飛ばして消す」
// といった近道が最初から存在しなくなります。
//
// 【保存形式を変えないこと】base64( IV 12バイト + TAG 16バイト + 暗号文 )。
// 変えると、既に p_drive_storage 配下にある .enc ファイルが一切読めなくなります。
// ================================================================

final class PDriveEngine {
  /**
   * p-drive上のファイルを暗号化する3つのヘルパー。
   *
   * 【なぜ共有スクリプトを使わず自前で持つか】以前は
   * main/pusyuusystem/scripts/php_scripts/pusyuu_storage_crypto.php をrequireしていたが、
   * 共有する意味が実は無かった: この形式で書かれたファイルを読むのは、それを書いた
   * 本人(=このp-meikiee、鍵はp_drive_storage_key.php)だけで、他プロダクトは自分専用の
   * 鍵で自分のファイルを読み書きしている。つまりプロダクト間で形式を揃えても、
   * 互いのファイルを読む場面が存在しない。一方で共有していると、共有スクリプトが
   * 無い環境ではサービスごと起動不能になる(実際にdie()していた)。
   *
   * 【変更してはいけないこと】保存形式は base64( IV 12バイト + TAG 16バイト + 暗号文 )。
   * これを変えると、既にp_drive_storage配下に積み上がっている.encファイルが
   * 一切読めなくなる。アルゴリズム・バイト順・base64化のどれも変えないこと。
   * 同じ形式の実装がこのファイル内の accountsEncryptText()/accountsDecryptText()
   * にもあるが、あちらはaccount.jsonl用で鍵が違う(統合しないこと)。
   */
  private static function aesGcmEncrypt(string $plaintext, string $rawKey): ?string {
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $rawKey, OPENSSL_RAW_DATA, $iv, $tag);

    if ($cipher === false) {
      $encoded = null;
    } else {
      // 【この並び順(base64( IV + TAG + 暗号文 ))を変えないこと】
      // 変えると p_drive_storage 配下の .enc ファイルが一切読めなくなります。
      $encoded = base64_encode($iv . $tag . $cipher);
    }

    return $encoded;
  }

  private static function aesGcmDecrypt(string $encoded, string $rawKey): ?string {
    $raw = base64_decode($encoded, true);

    // 28バイト未満は、IVとTAGだけでも足りない = そもそもこの形式ではありません。
    if ($raw === false || strlen($raw) < 28) {
      $plain = null;
    } else {
      $iv     = substr($raw, 0, 12);
      $tag    = substr($raw, 12, 16);
      $cipher = substr($raw, 28);
      $opened = openssl_decrypt($cipher, 'aes-256-gcm', $rawKey, OPENSSL_RAW_DATA, $iv, $tag);
      $plain = ($opened === false) ? null : $opened;
    }

    return $plain;
  }

  /** 鍵ファイルが有れば読み込み、無ければ生成して保存する。戻り値は生バイト列(32byte)。 */
  private static function loadOrCreateStorageKey(string $keyFilePath): ?string {
    if (is_file($keyFilePath)) {
      $encoded = require $keyFilePath;
      $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;
      // 長さが違う鍵をそのまま使うと、暗号化はできても復号だけ失敗します。ここで弾きます。
      $key = ($decoded !== false && strlen($decoded) === 32) ? $decoded : null;
    } else {
      $raw = random_bytes(32);
      $php = "<?php\nreturn '" . base64_encode($raw) . "';\n";

      if (@file_put_contents($keyFilePath, $php) === false) {
        // 【保存できなかったら鍵を使わないこと】使ってしまうと、次のリクエストで
        // 別の鍵が生成され、今書いたデータが二度と読めなくなります。
        $key = null;
      } else {
        @chmod($keyFilePath, 0600);
        $key = $raw;
      }
    }

    return $key;
  }

  /** p-drive自身のデータ暗号化キー(account.jsonlの鍵とは別物。既存のaccountsHiddenDir()を再利用)。 */
  public static function storageKey(): ?string {
    $hidden = accountsHiddenDir();

    if ($hidden === null) {
      $key = null;
    } else {
      $key = self::loadOrCreateStorageKey($hidden . '/' . P_DRIVE_STORAGE_KEY_FILE_NAME);
    }

    return $key;
  }

  /**
   * userid/service/keyは、そのままディレクトリ・ファイル名の構成要素として使うため、
   * 経路混入(path traversal)を防ぐホワイトリスト検証を必ず通す。
   * "."/".."そのものは英数字・_・-・.のみの正規表現には合致してしまうため、
   * 完全一致で個別に弾く。
   */
  private static function isSafeSegment(string $s): bool {
    // 【"."と".."を個別に弾くこと】英数字・_・-・. だけの正規表現には
    // "." も ".." も合致してしまうので、正規表現だけでは経路を遡られます。
    if ($s === '' || $s === '.' || $s === '..' || strlen($s) > 128) {
      $safe = false;
    } else {
      $safe = (preg_match('/^[A-Za-z0-9_.-]+$/', $s) === 1);
    }

    return $safe;
  }

  /**
   * 【重要・機密情報の取り扱い】p-driveのuseridを直接ディレクトリ名として
   * 使ってはいけない。account.jsonlの生のid(pmeikieeCreate()がbin2hex(random_bytes(16))
   * で発行する、必ず32文字の16進数)を、絶対にそのままp_drive_storage配下の
   * ディレクトリ名にしないこと。P_DRIVE_STORAGE_ROOT(このファイル冒頭で定義)は
   * p-meikiee/p_drive_storageという**Web公開ディレクトリの内側**にあるため
   * (accounts_storage/はWeb公開ディレクトリの外側だが、こちらは違う)、
   * ディレクトリ名という"メタデータ"のレベルで生idが露出すると「idの生値は
   * 一切外に出さない」というこのエコシステム全体の大前提(ACCOUNTS_INTEGRATION_SPEC.md
   * 4節)が崩れる。必ず`$user['storage_id']`(=sha256(生id)。MeikieeAccounts::computeStorageId()
   * 参照)を使うこと。
   *
   * かつては「生idが渡されてもここで自動検知してhash化し、旧ディレクトリが
   * あれば都度移行する」という互換レイヤ(pDriveCanonicalUserId()/
   * pDriveMigrateLegacyRawIdIfNeeded())をここに置いていたが、これは「新旧2つの
   * 保存場所を読み書きのたびに気にし続ける」恒久的なフォールバックそのものであり、
   * 複雑さに見合わないと判断して撤去した。旧データ(生idディレクトリに残っていた
   * pips/toolbox等のuserData、account.jsonlの鍵で別ツリーに保存されていた旧アバター)は
   * 2026-08-23に一回限りの移行スクリプトで一括統合済み。以後は呼び出し側
   * (pmeikieeUserDataGet/Set等・pDriveEnginePut等)が常に`storage_id`だけを
   * 渡す、という前提を素直に信頼する。
   */
  public static function userDir(string $userid): string {
    return P_DRIVE_STORAGE_ROOT . '/' . $userid;
  }

  /**
   * 【重要】$service(各サービスがpDriveApi()呼び出しに渡す固定のservice名)は、
   * storage_idと同じく「一度決めたら不変の定数」として扱うこと。リネームしない。
   * 理由: <storage_id>/<service>/という物理フォルダ名として直接使われるため、
   * serviceの文字列をコード側で書き換えるだけでは、旧service名のフォルダの
   * 中身は新service名からは一切見えなくなる(使用量(self::engineUsage())には
   * 合算され続けるので消えたようには見えないが、一覧・取得はできない)。実際に
   * 一度この形で孤立が発生している。「未知のフォルダ名=孤立とみなして削除」も
   * 採用しない(正規サービスが単にservice名を変えただけのケースと区別がつかず、
   * 現用データを誤って消しかねないため)。改名したくなったら新しいservice名を
   * 追加するのではなく、self::engineStorageBreakdown()で内訳を見て気づけるように
   * しておき、人間の判断で個別に移行すること。
   */
  private static function serviceDir(string $userid, string $service): string {
    return self::userDir($userid) . '/' . $service;
  }

  private static function itemFile(string $userid, string $service, string $key): string {
    return self::serviceDir($userid, $service) . '/' . $key . '.enc';
  }

  /** 指定userid配下(全service横断)の合計バイト数(暗号化後のファイルサイズの合計)。 */
  private static function userTotalBytes(string $userid): int {
    $dir = self::userDir($userid);
    $total = 0;

    // フォルダがまだ無ければ0バイト、で正解です。
    if (is_dir($dir)) {
      $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
      foreach ($it as $file) {
        if ($file->isFile()) { $total += $file->getSize(); }
      }
    }

    return $total;
  }

  /**
   * 1ファイルの削除を確認付きでリトライする。「unlink()がtrueを返したか」では
   * なく「その後ファイルが実際に消えているか」で判定する(ネットワーク共有上
   * ではPHPのstatキャッシュが古い状態を返すことがあるため、確認の直前で毎回
   * clearstatcache()する)。
   */
  private static function deleteFileVerified(string $path): bool {
    // 【「unlink()がtrueを返したか」で判断しないこと】ネットワーク共有では
    // PHPのstatキャッシュが古い状態を返すので、確認の直前で毎回 clearstatcache() し、
    // 「その後ファイルが実際に消えているか」だけを見ます。
    $gone = false;

    for ($attempt = 0; $attempt < P_DRIVE_DELETE_MAX_ATTEMPTS && $gone === false; $attempt++) {
      clearstatcache(true, $path);

      if (!file_exists($path) && !is_link($path)) {
        $gone = true;
      } else {
        @unlink($path);
        clearstatcache(true, $path);

        if (!file_exists($path) && !is_link($path)) {
          $gone = true;
        } else if ($attempt < P_DRIVE_DELETE_MAX_ATTEMPTS - 1) {
          usleep(P_DRIVE_DELETE_RETRY_DELAY_US);
        }
      }
    }

    if ($gone === false) {
      clearstatcache(true, $path);
      $gone = !file_exists($path) && !is_link($path);
    }

    return $gone;
  }

  /** 空になったディレクトリの削除を確認付きでリトライする(self::deleteFileVerified()と同じ理由)。 */
  private static function deleteDirVerified(string $dir): bool {
    $gone = false;

    for ($attempt = 0; $attempt < P_DRIVE_DELETE_MAX_ATTEMPTS && $gone === false; $attempt++) {
      clearstatcache(true, $dir);

      if (!is_dir($dir)) {
        $gone = true;
      } else {
        @rmdir($dir);
        clearstatcache(true, $dir);

        if (!is_dir($dir)) {
          $gone = true;
        } else if ($attempt < P_DRIVE_DELETE_MAX_ATTEMPTS - 1) {
          usleep(P_DRIVE_DELETE_RETRY_DELAY_US);
        }
      }
    }

    if ($gone === false) {
      clearstatcache(true, $dir);
      $gone = !is_dir($dir);
    }

    return $gone;
  }

  /**
   * ディレクトリを中身ごと再帰的に削除する(delete/delete_service/delete_userの実体)。
   *
   * 【なぜ戻り値を持つようにしたか】以前はvoidで、中のunlink()/rmdir()の成否を
   * 一切見ておらず、1件でも削除に失敗しても呼び出し元(self::engineDeleteUser()等)
   * は常にok:trueを返していた。これはアカウント削除処理(MeikieeAccountLifecycle::cleanupExternalData())
   * が「付随データを全部消せたことを確認してからaccount.jsonlを消す」という
   * 安全設計の前提そのものを壊す(確認しているつもりが、確認になっていなかった)。
   * 実際にこの経路で、account.jsonlからは削除済みなのにp_drive_storage配下の
   * ファイルだけが取り残される事例が確認されたため、戻り値で成否を正しく
   * 伝播するように修正した。
   *
   * 戻り値: 最終的にディレクトリが実際に存在しなくなっていればtrue(元から
   * 存在しなかった場合もtrue=冪等)。1件でも消しきれなかった場合はfalse。
   */
  public static function rrmdir(string $dir): bool {
    clearstatcache(true, $dir);
    $entries = is_dir($dir) ? @scandir($dir) : null;

    if (!is_dir($dir)) {
      // 元から存在しなかった場合も成功として扱います(冪等)。
      $removed = true;

    } else if ($entries === false) {
      // 中身を列挙できなかった(権限等)。存在の有無で最終判定する。
      clearstatcache(true, $dir);
      $removed = !is_dir($dir);

    } else {
      $allOk = true;

      foreach ($entries as $item) {
        if ($item !== '.' && $item !== '..') {
          $path = $dir . '/' . $item;

          if (is_dir($path) && !is_link($path)) {
            if (!self::rrmdir($path)) { $allOk = false; }
          } else {
            if (!self::deleteFileVerified($path)) { $allOk = false; }
          }
        }
      }

      // 中身を消しきれていない場合、このディレクトリ自体のrmdir()は失敗するだけ
      // なので試みない(失敗の原因が中身の削除失敗であることをそのまま伝える)。
      $removed = $allOk ? self::deleteDirVerified($dir) : false;
    }

    return $removed;
  }

  /**
   * (userid, service)単位で読み込み→変更→書き込みを直列化する。$fnは戻り値を
   * そのまま返す(この関数自体はロック取得可否だけを見る。取得できなければnull)。
   *
   * 【この関数はフォルダを作る、という副作用を忘れないこと】ロックの実体は
   * <storage_id>/<service>/.lock なので、ロックを取るにはまずそのフォルダが
   * 要る。つまり「何も書かない操作」でもここを通せばフォルダが生まれる。
   * 呼び出す前に「本当に書く(または本当に消す物がある)のか」を確かめること。
   * 実際、self::engineDelete()が無条件にここを通していた頃は、存在しないキーを
   * 消すだけで空フォルダと0バイトの.lockが残っていた。
   *
   * 【残った空フォルダを後から掃除しないこと】.lockを消して片付けたくなるが、
   * やってはいけない。誰かがそのファイルを開いてロックを保持している最中に
   * unlink()すると、次に来た要求は「新しく作られた別のファイル」でロックを取る。
   * 両者が同時に書けてしまい、直列化そのものが静かに壊れる(しかも同時アクセスが
   * 重なった時にしか起きないので、壊れていることに気づけない)。空フォルダ自体は
   * 無害で、アカウント削除時に self::engineDeleteUser() が丸ごと消す。放置が正解。
   */
  private static function withLock(string $userid, string $service, callable $fn) {
    $dir = self::serviceDir($userid, $service);
    // mkdir が失敗しても、他のリクエストが同時に作っていれば is_dir は真になります。
    $noDir = (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir));
    $fp = $noDir ? false : fopen($dir . '/.lock', 'c');

    if ($fp === false) {
      // 鍵をかけられないまま書くと、同時に走った別の要求の変更を踏み潰します。
      $result = null;
    } else {
      flock($fp, LOCK_EX);
      $result = $fn();
      flock($fp, LOCK_UN);
      fclose($fp);
    }

    return $result;
  }

  public static function enginePut(string $userid, string $service, string $key, string $value): array {
    if (!self::isSafeSegment($userid) || !self::isSafeSegment($service) || !self::isSafeSegment($key)) {
      return ['ok' => false, 'error' => 'invalid_input', 'message' => 'userid/service/keyの形式が不正です(英数字・_・-・.のみ、128文字以内)。'];
    }
    if (strlen($value) > P_DRIVE_VALUE_MAX_BYTES) {
      return ['ok' => false, 'error' => 'too_large', 'message' => '1項目あたり' . round(P_DRIVE_VALUE_MAX_BYTES / 1024) . 'KBまでです。'];
    }
    $storageKey = self::storageKey();
    if ($storageKey === null) {
      return ['ok' => false, 'error' => 'server_misconfigured', 'message' => '暗号化キーを用意できませんでした。'];
    }

    $result = self::withLock($userid, $service, function () use ($userid, $service, $key, $value, $storageKey): array {
      $file = self::itemFile($userid, $service, $key);
      $existingSize = is_file($file) ? (int)filesize($file) : 0;
      $newTotal = self::userTotalBytes($userid) - $existingSize + strlen($value);
      if ($newTotal > P_DRIVE_USER_TOTAL_MAX_BYTES) {
        return ['ok' => false, 'error' => 'quota_exceeded'];
      }
      $encoded = self::aesGcmEncrypt($value, $storageKey);
      if ($encoded === null) { return ['ok' => false, 'error' => 'encrypt_failed']; }
      $dir = dirname($file);
      $tmp = $dir . '/.' . bin2hex(random_bytes(6)) . '.tmp';
      if (@file_put_contents($tmp, $encoded) === false) { return ['ok' => false, 'error' => 'write_failed']; }
      if (!@rename($tmp, $file)) { @unlink($tmp); return ['ok' => false, 'error' => 'write_failed']; }
      return ['ok' => true];
    });

    if ($result === null) {
      return ['ok' => false, 'error' => 'storage_error', 'message' => 'ロックを取得できませんでした。'];
    }
    if (empty($result['ok'])) {
      if (($result['error'] ?? '') === 'quota_exceeded') {
        return ['ok' => false, 'error' => 'quota_exceeded', 'message' => '保存容量の上限(' . round(P_DRIVE_USER_TOTAL_MAX_BYTES / 1024 / 1024, 1) . 'MB)に達しています。'];
      }
      return ['ok' => false, 'error' => 'storage_error', 'message' => '保存に失敗しました。'];
    }
    return ['ok' => true];
  }

  public static function engineGet(string $userid, string $service, string $key): array {
    if (!self::isSafeSegment($userid) || !self::isSafeSegment($service) || !self::isSafeSegment($key)) {
      return ['ok' => false, 'error' => 'invalid_input', 'message' => 'userid/service/keyの形式が不正です。'];
    }
    $file = self::itemFile($userid, $service, $key);
    if (!is_file($file)) { return ['ok' => true, 'found' => false]; }

    $raw = file_get_contents($file);
    $storageKey = self::storageKey();
    $plain = ($raw !== false && $storageKey !== null) ? self::aesGcmDecrypt(trim($raw), $storageKey) : null;
    if ($plain === null) {
      error_log("[p-drive] get: 復号に失敗しました。userid={$userid} service={$service} key={$key}");
      return ['ok' => true, 'found' => false];
    }
    return ['ok' => true, 'found' => true, 'value' => $plain];
  }

  /**
   * (userid, service)配下の全項目を復号して返す。p-memoのメモ一覧のように
   * 「そのサービスが自分のuseridぶんを全部列挙したい」場合に使う
   * (get()をキー数ぶん繰り返すより1回で済む)。
   * 戻り値: ['ok'=>true, 'items'=>[['key'=>string,'value'=>string], ...]]
   */
  public static function engineList(string $userid, string $service): array {
    if (!self::isSafeSegment($userid) || !self::isSafeSegment($service)) {
      return ['ok' => false, 'error' => 'invalid_input', 'message' => 'userid/serviceの形式が不正です。'];
    }
    $dir = self::serviceDir($userid, $service);
    if (!is_dir($dir)) { return ['ok' => true, 'items' => []]; }

    $storageKey = self::storageKey();
    $items = [];
    foreach (scandir($dir) as $entry) {
      if (substr($entry, -4) !== '.enc') { continue; } // .lock等はここで自然に除外される
      $key = substr($entry, 0, -4);
      $raw = file_get_contents($dir . '/' . $entry);
      $plain = ($raw !== false && $storageKey !== null) ? self::aesGcmDecrypt(trim($raw), $storageKey) : null;
      if ($plain === null) {
        error_log("[p-drive] list: 復号に失敗しました。userid={$userid} service={$service} key={$key}");
        continue;
      }
      $items[] = ['key' => $key, 'value' => $plain];
    }
    return ['ok' => true, 'items' => $items];
  }

  /**
   * p_drive_get/p_drive_listのJSON応答へ値を載せる直前に必ず通すためのヘルパー。
   *
   * pmeikieeApiRespond()はjson_encode()で応答を組み立てるが、json_encode()は
   * 文字列値が有効なUTF-8であることを要求する。画像・zip等の生バイナリは当然
   * これを満たさないため、そのまま載せるとjson_encode()自体が黙ってfalseを
   * 返し、応答全体が空(Content-Length:0)になる(実際にp-driveのマイファイル
   * 機能で「保存は成功するのに一覧・取得に出ない」という不具合として発生した)。
   *
   * これをp_drive_get/p_drive_list呼び出し側(p-drive/p-memo等)が個別に
   * 気を付ける前提にすると、「各サービスが保存の仕組みを知らなくても使える
   * 汎用ハンドル」という設計目標に反する。そのためエンジン側のこの1箇所で
   * 値ごとに有効なUTF-8かどうかを判定し、無効な場合だけbase64化した上で
   * encodingフィールドで印を付けて返すようにする。呼び出し側はencodingが
   * "base64"ならデコードするだけでよい(p-meikiee/pDriveEngineGet・List自体の
   * 戻り値は従来通り生のバイト列のままで、user_data・アバターなど同一プロセス
   * 内で直接呼んでいる箇所には一切影響しない)。
   */
  private static function valueNeedsBase64(string $value): bool {
    return !mb_check_encoding($value, 'UTF-8');
  }

  public static function encodeValueForJson(array $result): array {
    if (isset($result['value']) && is_string($result['value']) && self::valueNeedsBase64($result['value'])) {
      $result['value'] = base64_encode($result['value']);
      $result['encoding'] = 'base64';
    } else {
      $result['encoding'] = 'raw';
    }
    return $result;
  }

  public static function encodeItemForJson(array $item): array {
    if (isset($item['value']) && is_string($item['value']) && self::valueNeedsBase64($item['value'])) {
      $item['value'] = base64_encode($item['value']);
      $item['encoding'] = 'base64';
    } else {
      $item['encoding'] = 'raw';
    }
    return $item;
  }

  public static function engineDelete(string $userid, string $service, string $key): array {
    if (!self::isSafeSegment($userid) || !self::isSafeSegment($service) || !self::isSafeSegment($key)) {
      return ['ok' => false, 'error' => 'invalid_input', 'message' => 'userid/service/keyの形式が不正です。'];
    }

    // 【ロックを取る前にフォルダの有無を見ること】self::withLock()はロック
    // ファイルを置くために<storage_id>/<service>/をmkdir()する(あちらの
    // コメント参照)。そのため以前はここが無条件にロックを取っていて、
    // 「存在しないキーを消す」という何も起きないはずの操作が、空のフォルダと
    // 0バイトの.lockを新規に作って残していた。実際に、中身が.lockだけの
    // serviceフォルダが実在アカウントの配下に残っているのが見つかっている。
    //
    // フォルダが無いなら消す対象も無いので、ロックを取らずに成功で返す
    // (元から無い物を消した場合も成功、という他の削除系と同じ冪等の扱い)。
    // 判定とロック取得の間に他の要求がフォルダを作る可能性はあるが、その場合の
    // 意味は「こちらの削除が相手の書き込みより前に起きた」であって、実害は無い。
    if (!is_dir(self::serviceDir($userid, $service))) {
      return ['ok' => true];
    }

    $deleted = self::withLock($userid, $service, function () use ($userid, $service, $key): bool {
      return self::deleteFileVerified(self::itemFile($userid, $service, $key));
    });
    if ($deleted !== true) {
      error_log("[p-drive] delete: 削除を確認できませんでした。userid={$userid} service={$service} key={$key}");
      return ['ok' => false, 'error' => 'delete_failed', 'message' => '削除に失敗しました。'];
    }
    return ['ok' => true];
  }

  /** そのuserid+serviceぶんを丸ごと削除する(そのサービスとの連携解除等)。 */
  public static function engineDeleteService(string $userid, string $service): array {
    if (!self::isSafeSegment($userid) || !self::isSafeSegment($service)) {
      return ['ok' => false, 'error' => 'invalid_input', 'message' => 'userid/serviceの形式が不正です。'];
    }
    if (!self::rrmdir(self::serviceDir($userid, $service))) {
      error_log("[p-drive] delete_service: 削除しきれないファイルが残ったため失敗として扱います。userid={$userid} service={$service}");
      return ['ok' => false, 'error' => 'delete_failed', 'message' => '一部のデータを削除できませんでした。'];
    }
    return ['ok' => true];
  }

  /**
   * useridのぶんを全service横断で丸ごと削除する。アカウント削除連携の本体。
   * ディレクトリが元から無くても常にok(冪等)。1件でも実際に消しきれなかった
   * 場合はok:falseを返す(MeikieeAccountLifecycle::cleanupExternalData()がこれを見て、
   * account.jsonlのレコード削除に進まないようにするため。過去にここが常に
   * ok:trueを返してしまい、p_drive_storage配下だけが取り残されたままアカウント
   * は削除済み扱いになる事例が実際に確認されている)。呼び出し側は必ず
   * `storage_id`を渡すこと(生idは渡さない。上のコメント参照)。
   */
  public static function engineDeleteUser(string $userid): array {
    if (!self::isSafeSegment($userid)) {
      return ['ok' => false, 'error' => 'invalid_input', 'message' => 'useridの形式が不正です。'];
    }
    if (!self::rrmdir(self::userDir($userid))) {
      error_log("[p-drive] delete_user: 削除しきれないファイルが残ったため失敗として扱います。userid={$userid}");
      return ['ok' => false, 'error' => 'delete_failed', 'message' => '一部のデータを削除できませんでした。'];
    }
    return ['ok' => true];
  }

  public static function engineUsage(string $userid): array {
    if (!self::isSafeSegment($userid)) {
      return ['ok' => false, 'error' => 'invalid_input', 'message' => 'useridの形式が不正です。'];
    }
    return ['ok' => true, 'bytes' => self::userTotalBytes($userid), 'max_bytes' => P_DRIVE_USER_TOTAL_MAX_BYTES];
  }

  /**
   * self::engineUsage()の合計値だけでは「使用量は減っていないのに一覧に出ない
   * データがある」という異常(service名のリネーム等で発生する)に気づけないため、
   * <storage_id>直下のフォルダ(=service名)ごとの内訳を返す診断専用の関数。
   * 削除・自動修正は一切行わない(self::serviceDir()手前の【重要】コメント参照。
   * 未知のservice名を推測で孤立扱いにして消す、ということはしない方針のため、
   * ここで異常に気づいた後の対応は必ず人間が個別に判断する)。
   */
  public static function engineStorageBreakdown(string $userid): array {
    if (!self::isSafeSegment($userid)) {
      return ['ok' => false, 'error' => 'invalid_input', 'message' => 'useridの形式が不正です。'];
    }
    $dir = self::userDir($userid);
    $entries = @scandir($dir);
    if ($entries === false) {
      return ['ok' => true, 'services' => []];
    }

    $services = [];
    foreach ($entries as $entry) {
      if ($entry === '.' || $entry === '..') { continue; }
      $serviceDir = $dir . '/' . $entry;
      if (!is_dir($serviceDir)) { continue; }

      $bytes = 0;
      $lastModified = 0;
      $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($serviceDir, FilesystemIterator::SKIP_DOTS));
      foreach ($it as $file) {
        if (!$file->isFile()) { continue; }
        $bytes += $file->getSize();
        $lastModified = max($lastModified, $file->getMTime());
      }

      $services[] = [
        'service'       => $entry,
        'bytes'         => $bytes,
        'last_modified' => $lastModified > 0 ? date('c', $lastModified) : null,
      ];
    }

    return ['ok' => true, 'services' => $services];
  }

  /**
   * <storage_id>/<service>/以下(または任意の$subpath配下)を再帰的に列挙する。
   *
   * self::engineStorageBreakdown()はservice単位の集計値(合計バイト数・最終更新日時)
   * しか返さないため、「使用量は減らないのに何があるのか分からない」という
   * 診断はできても、実際に中身を把握して整理するには不十分だった。
   *
   * サービスフォルダより下の階層で何が孤立するかは、p-drive/p-meikiee側からは
   * 本質的に判断できない(ファイル名・サブフォルダ構成の意味を知っているのは
   * そのサービス自身だけであり、p-drive側が「未知の名前だから孤立」と推測するのは
   * 危険、というPDriveEngine::serviceDir()手前の【重要】コメントの結論と同じ理由)。
   * そのため自動削除や孤立判定は一切行わず、あくまで「今何が置かれているか」を
   * 生のまま返すだけの汎用ハンドルとし、実際に整理する責任はこれを呼び出す
   * サービス自身に委ねる。
   *
   * 戻り値: ['ok'=>true, 'entries'=>[
   *   ['path'=>service直下からの相対パス, 'type'=>'file'|'dir', 'bytes'=>int(fileのみ、dirはnull), 'last_modified'=>ISO8601],
   *   ...
   * ]]。.lock・.tmpのような内部管理用ファイルはノイズになるため除外する。
   */
  public static function engineListPath(string $userid, string $service, string $subpath = ''): array {
    if (!self::isSafeSegment($userid) || !self::isSafeSegment($service)) {
      return ['ok' => false, 'error' => 'invalid_input', 'message' => 'userid/serviceの形式が不正です。'];
    }
    $subpath = trim($subpath, '/');
    if ($subpath !== '') {
      foreach (explode('/', $subpath) as $segment) {
        if (!self::isSafeSegment($segment)) {
          return ['ok' => false, 'error' => 'invalid_input', 'message' => 'subpathの形式が不正です。'];
        }
      }
    }

    $root = self::serviceDir($userid, $service) . ($subpath !== '' ? '/' . $subpath : '');
    if (!is_dir($root)) { return ['ok' => true, 'entries' => []]; }

    $entries = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $item) {
      $name = $item->getFilename();
      if ($name === '.lock' || substr($name, -4) === '.tmp') { continue; }
      $rel = ($subpath !== '' ? $subpath . '/' : '') . str_replace('\\', '/', $it->getSubPathname());
      $entries[] = [
        'path'          => $rel,
        'type'          => $item->isDir() ? 'dir' : 'file',
        'bytes'         => $item->isFile() ? $item->getSize() : null,
        'last_modified' => date('c', $item->getMTime()),
      ];
    }
    return ['ok' => true, 'entries' => $entries];
  }

  /** 監査ログ(self::engineSweepOrphanedChunks()専用)への追記先。 */
  private static function orphanChunkSweepLogFile(): string {
    return (accountsHiddenStorageDir() ?? sys_get_temp_dir()) . '/orphaned_chunk_sweep_log.jsonl';
  }

  private static function orphanChunkSweepLog(array $record): void {
    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) { return; }
    @file_put_contents(self::orphanChunkSweepLogFile(), $line . "\n", FILE_APPEND | LOCK_EX);
  }

  /**
   * 「1つの論理ファイルを<id>.c<番号>という複数チャンクに分けて保存し、
   * 全チャンク送信完了後に<id>単体(メタデータ)を書いて初めて完成とする」という
   * 分割アップロードのパターンは、p-driveの「マイファイル」機能に限らず、今後
   * 他のサービスが大きなデータを保存したくなったときにも再利用されうる汎用的な
   * 手法(storage_idの構造そのものと同じ「全サービス共通の土台」の一部)である。
   * そのためこの命名規則(<id>.c<番号>)自体と、それに伴う「アップロード中断で
   * メタデータの無いチャンクだけが残る」という孤立パターンの判定・削除は、
   * 個々のサービス(p-drive/index.php等)にではなく、ここ(engine)に置く。
   * ちょうどstorage_id単位の孤立(pmeikieeSweepOrphanedStorage())と同じ位置づけ。
   *
   * サービス側(呼び出し元)が持つべき責任は「いつ実行するか」であり、判定
   * ロジックそのものはここに一本化する(account_exists()を各サービスが呼ぶ
   * だけで済むのと同じ構造)。
   *
   * $maxAgeSecondsより新しい(=直近で書き込まれた)チャンクは「まだアップロード
   * 中かもしれない」として対象外にする(進行中の正常なアップロードを誤って
   * 消さないため)。
   *
   * 【1日1回等への制限は不要】pmeikieeSweepOrphanedStorage()と違い、この関数は
   * account.jsonlを読まず、p_drive_storage全体も走査しない。1人・1serviceの
   * フォルダをscandir()するだけの軽い処理で、コストはシステム全体のアカウント数
   * ではなく「呼び出した本人が持つファイル数」だけに比例する。そのため
   * pmeikieeRunDailyMaintenanceIfNeeded()のような、呼び出し頻度を落とすための
   * 状態ファイル・ロックは不要と判断し、あえて持たせていない。呼び出し元
   * (p-driveの`pDriveFilesRunOrphanSweepIfNeeded()`等)は毎リクエスト無条件に
   * 呼んでよい。
   */
  public static function engineSweepOrphanedChunks(string $userid, string $service, int $maxAgeSeconds): array {
    if (!self::isSafeSegment($userid) || !self::isSafeSegment($service)) {
      return ['ok' => false, 'error' => 'invalid_input', 'message' => 'userid/serviceの形式が不正です。'];
    }
    $dir = self::serviceDir($userid, $service);
    if (!is_dir($dir)) { return ['ok' => true, 'removed' => []]; }

    $metaKeys = [];
    $chunksByFileId = []; // fileId => ['count'=>int, 'newest'=>int(unixtime)]
    foreach (@scandir($dir) ?: [] as $entry) {
      if (substr($entry, -4) !== '.enc') { continue; }
      $key = substr($entry, 0, -4);
      if (preg_match('/^([0-9a-f]+)\.c(\d+)$/', $key, $m) === 1) {
        $fileId = $m[1];
        $mtime = (int)(@filemtime($dir . '/' . $entry) ?: 0);
        if (!isset($chunksByFileId[$fileId])) { $chunksByFileId[$fileId] = ['count' => 0, 'newest' => 0]; }
        $chunksByFileId[$fileId]['count']++;
        $chunksByFileId[$fileId]['newest'] = max($chunksByFileId[$fileId]['newest'], $mtime);
      } else {
        $metaKeys[$key] = true;
      }
    }

    $now = time();
    $removed = [];
    foreach ($chunksByFileId as $fileId => $info) {
      if (isset($metaKeys[$fileId])) { continue; } // メタデータが有る=正常に完了したファイル
      if (($now - $info['newest']) < $maxAgeSeconds) { continue; } // まだアップロード中かもしれない

      $ok = true;
      for ($i = 0; $i < $info['count'] + 8; $i++) { // +8は欠番があっても走査を打ち切らないための余裕
        $file = $dir . '/' . $fileId . '.c' . $i . '.enc';
        if (is_file($file) && !self::deleteFileVerified($file)) { $ok = false; }
      }
      self::orphanChunkSweepLog(['ts' => date('c'), 'userid' => $userid, 'service' => $service, 'file_id' => $fileId, 'chunk_count' => $info['count'], 'removed' => $ok]);
      if ($ok) { $removed[] = ['file_id' => $fileId, 'chunk_count' => $info['count']]; }
    }
    return ['ok' => true, 'removed' => $removed];
  }
}

// ----------------------------------------------------------------
// 上のクラスへの薄い入口(グローバル関数)
//
// API振り分け・保守・画面から呼ばれているものだけを残しています。呼び出し側を
// 一斉に書き換える利益が無いので、名前はそのままにして中身をクラスへ委ねました。
// 新しく書くコードでは PDriveEngine::… を直接呼んでください。
//
// pDriveEncodeItemForJson だけは特別で、API側が
//   array_map('pDriveEncodeItemForJson', $result['items'])
// と**関数名を文字列で**渡しています。クラスのメソッドにすると文字列では届かないので、
// この入口を消すとその1行が静かに壊れます(配列がそのまま素通りし、base64にすべき
// 生バイトが混ざったJSONを返してしまう)。消さないこと。
// ----------------------------------------------------------------

function pDriveStorageKey(): ?string {
  return PDriveEngine::storageKey();
}

function pDriveUserDir(string $userid): ?string {
  return PDriveEngine::userDir($userid);
}

function pDriveRrmdir(string $dir): bool {
  return PDriveEngine::rrmdir($dir);
}

function pDriveEnginePut(string $userid, string $service, string $key, string $value): array {
  return PDriveEngine::enginePut($userid, $service, $key, $value);
}

function pDriveEngineGet(string $userid, string $service, string $key): array {
  return PDriveEngine::engineGet($userid, $service, $key);
}

function pDriveEngineList(string $userid, string $service): array {
  return PDriveEngine::engineList($userid, $service);
}

function pDriveEncodeValueForJson(array $result): array {
  return PDriveEngine::encodeValueForJson($result);
}

function pDriveEncodeItemForJson(array $item): array {
  return PDriveEngine::encodeItemForJson($item);
}

function pDriveEngineDelete(string $userid, string $service, string $key): array {
  return PDriveEngine::engineDelete($userid, $service, $key);
}

function pDriveEngineDeleteService(string $userid, string $service): array {
  return PDriveEngine::engineDeleteService($userid, $service);
}

function pDriveEngineDeleteUser(string $userid): array {
  return PDriveEngine::engineDeleteUser($userid);
}

function pDriveEngineUsage(string $userid): array {
  return PDriveEngine::engineUsage($userid);
}

function pDriveEngineStorageBreakdown(string $userid): array {
  return PDriveEngine::engineStorageBreakdown($userid);
}

function pDriveEngineListPath(string $userid, string $service, string $subpath = ''): array {
  return PDriveEngine::engineListPath($userid, $service, $subpath);
}

function pDriveEngineSweepOrphanedChunks(string $userid, string $service, int $maxAgeSeconds): array {
  return PDriveEngine::engineSweepOrphanedChunks($userid, $service, $maxAgeSeconds);
}

/** 監査ログ(pmeikieeSweepOrphanedStorage()専用)への追記先。恒久的な記録のため、
 * 一時デバッグログと違い削除・無効化スイッチは持たない。Web非公開ディレクトリ配下。 */
function pmeikieeOrphanSweepLogFile(): string {
  return (accountsHiddenStorageDir() ?? sys_get_temp_dir()) . '/orphaned_storage_sweep_log.jsonl';
}

function pmeikieeOrphanSweepLog(array $record): void {
  $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($line === false) { return; }
  @file_put_contents(pmeikieeOrphanSweepLogFile(), $line . "\n", FILE_APPEND | LOCK_EX);
}

/** ディレクトリの中身を(ファイル内容ではなく相対パスの一覧として)再帰的に列挙する。削除前の記録用。 */
function pmeikieeListDirRelative(string $dir, string $prefix = ''): array {
  $out = [];
  $entries = @scandir($dir);
  if ($entries === false) { return $out; }
  foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') { continue; }
    $path = $dir . '/' . $entry;
    $rel = $prefix === '' ? $entry : ($prefix . '/' . $entry);
    if (is_dir($path)) {
      $out = array_merge($out, pmeikieeListDirRelative($path, $rel));
    } else {
      $out[] = $rel;
    }
  }
  return $out;
}

/**
 * <storage_id>は「アカウントが1人1つだけ持つ、削除時に丸ごと消える神聖な場所」
 * という前提でp-drive全体を運用している(pDriveUserDir()手前のコメント参照)。
 * この前提を保つため、account.jsonlに存在するどのアカウントのstorage_idとも
 * 一致しないp_drive_storage直下のディレクトリ(=何らかの理由で孤児になった
 * もの。過去の不具合・障害時の中断・手動操作等が原因になりうる)を検出して
 * 削除する。
 *
 * 【なぜ通常のアカウント削除と分けて記録するか】通常の削除(pmeikieeDelete()/
 * pmeikieeAdminDelete())は「どのアカウントが・いつ・誰の操作で」消えたかが
 * 呼び出し自体に紐づいている。これに対しこの掃除処理は「account.jsonlに
 * 存在しないから」という間接的な判断だけで一括削除を行うため、判断材料
 * (読み込みタイミングのズレ等)次第では正常なデータを誤って巻き込むリスクが
 * 質的に異なる。そのため専用の恒久監査ログ(pmeikieeOrphanSweepLogFile())へ、
 * 削除する**前**に「どのディレクトリを」「中に何が入っていたか(ファイル名の
 * 一覧。暗号化済みなので内容は記録しない)」を必ず記録する。
 *
 * 呼び出しは?api=admin_sweep_orphaned_storage経由、oppai管理パネルからの
 * 明示的な操作のみを想定する(account.jsonl全件読み込み+p_drive_storage全体の
 * 走査というコストがあるため、リクエストのたびに自動実行はしない)。
 */
function pmeikieeSweepOrphanedStorage(): array {
  $validStorageIds = [];
  foreach (pmeikieeReadAll() as $user) {
    $sid = (string)($user['storage_id'] ?? '');
    if ($sid !== '') { $validStorageIds[$sid] = true; }
  }

  $entries = @scandir(P_DRIVE_STORAGE_ROOT);
  if ($entries === false) {
    return ['ok' => true, 'checked' => 0, 'removed' => []];
  }

  $removed = [];
  $failed = [];
  foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') { continue; }
    $dir = P_DRIVE_STORAGE_ROOT . '/' . $entry;
    if (!is_dir($dir)) { continue; }
    if (isset($validStorageIds[$entry])) { continue; } // 現行アカウントのstorage_id=対象外

    $contents = pmeikieeListDirRelative($dir);
    $ok = pDriveRrmdir($dir);

    pmeikieeOrphanSweepLog([
      'ts'         => date('c'),
      'storage_id' => $entry,
      'contents'   => $contents,
      'removed'    => $ok,
    ]);

    if ($ok) {
      $removed[] = ['storage_id' => $entry, 'file_count' => count($contents)];
    } else {
      $failed[] = $entry;
    }
  }

  return ['ok' => empty($failed), 'removed' => $removed, 'failed' => $failed];
}

/** 監査ログ(pmeikieeSweepStaleAccounts()専用)への追記先。pmeikieeOrphanSweepLogFile()と同じ考え方。 */
function pmeikieeStaleAccountSweepLogFile(): string {
  return (accountsHiddenStorageDir() ?? sys_get_temp_dir()) . '/stale_account_sweep_log.jsonl';
}

function pmeikieeStaleAccountSweepLog(array $record): void {
  $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($line === false) { return; }
  @file_put_contents(pmeikieeStaleAccountSweepLogFile(), $line . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * 放置されたメイキィを自動削除する。対象は次の2種類のみ(いずれも「本当に
 * 放置されている」と確度高く判定できるものに限定し、判定材料が無いアカウント
 * (created_at・last_loginのどちらも記録が無い、導入前からの既存アカウント等)は
 * 誤削除を避けるため一切対象にしない=これまで通りoppai管理パネルでの
 * 個別判断(admin_list_accountsのnever_seen一覧)に委ねる):
 *
 *   1. 「作成されたのに一度も使われていない」= created_atはあるが
 *      last_loginが一度も記録されていない状態のまま
 *      NEW_ACCOUNT_INACTIVITY_DAYS日を過ぎたもの。
 *   2. 「使われていたが長期間放置された」= last_loginが記録されており、
 *      その値がDORMANT_ACCOUNT_DAYS日より古いもの。
 *
 * 削除の実処理はpmeikieeAdminDelete()にそのまま委譲する(付随データの削除が
 * 全て確認できるまでaccount.jsonl本体には触れない、というフェイルセーフを
 * 二重に実装しないため)。orphaned storageの掃除と同様、削除する**前**に
 * 対象を専用の恒久監査ログへ記録する。
 */
function pmeikieeSweepStaleAccounts(): array {
  $now = time();
  $newAccountThreshold = $now - (NEW_ACCOUNT_INACTIVITY_DAYS * 86400);
  $dormantThreshold = $now - (DORMANT_ACCOUNT_DAYS * 86400);

  $targets = [];
  foreach (pmeikieeReadAll() as $user) {
    $createdAt = isset($user['created_at']) ? (int)$user['created_at'] : null;
    $lastLogin = isset($user['last_login']) ? (int)$user['last_login'] : null;

    if ($lastLogin === null && $createdAt !== null && $createdAt < $newAccountThreshold) {
      $reason = 'unused_new_account';
    } elseif ($lastLogin !== null && $lastLogin < $dormantThreshold) {
      $reason = 'dormant';
    } else {
      $reason = null;
    }
    if ($reason === null) { continue; }

    $targets[] = [
      'id'         => (string)($user['id'] ?? ''),
      'username'   => (string)($user['username'] ?? ''),
      'storage_id' => (string)($user['storage_id'] ?? ''),
      'created_at' => $createdAt,
      'last_login' => $lastLogin,
      'reason'     => $reason,
    ];
  }

  $removed = [];
  $failed = [];
  foreach ($targets as $target) {
    $result = pmeikieeAdminDelete($target['id']);

    pmeikieeStaleAccountSweepLog([
      'ts'         => date('c'),
      'username'   => $target['username'],
      'storage_id' => $target['storage_id'],
      'created_at' => $target['created_at'],
      'last_login' => $target['last_login'],
      'reason'     => $target['reason'],
      'removed'    => $result['ok'],
    ]);

    if ($result['ok']) {
      $removed[] = ['username' => $target['username'], 'reason' => $target['reason']];
    } else {
      $failed[] = $target['username'];
    }
  }

  return ['ok' => empty($failed), 'removed' => $removed, 'failed' => $failed];
}

/** pmeikieeRunDailyMaintenanceIfNeeded()が最終実行日を記録する先。JSON1件だけの軽量なファイル。 */
function pmeikieeDailyMaintenanceStateFile(): string {
  return (accountsHiddenStorageDir() ?? sys_get_temp_dir()) . '/daily_maintenance_state.json';
}

/**
 * 孤児storageの掃除(pmeikieeSweepOrphanedStorage())は、これまでoppai管理パネルから
 * 「?api=admin_sweep_orphaned_storage」を人間が明示的に叩く前提だったため、誰も
 * ボタンを押さなければ孤児が溜まり続けていた。放置メイキィの自動削除
 * (pmeikieeSweepStaleAccounts())も同じ理由でここに相乗りさせている。専用の
 * cronジョブを別途組む代わりに、
 * 「最終実行日」をJSON1件に記録しておき、通常のリクエストのたびに今日の日付と
 * 比較して、日付が変わっていた場合だけその場で実行する(=1日1回だけ実際には
 * 走る)、という方式でこのファイル(p-meikiee/index.php = p-driveの実態側)に
 * 自動化する。
 *
 * 通常時(その日既に実行済み)は、この小さなJSONファイル1つのstat+読み込みだけで
 * 済むため、account.jsonl全件読み込み+p_drive_storage全体の走査という重い処理を
 * 毎リクエスト行うわけではない。日付が変わった直後の1リクエストだけがこの重い
 * 処理を肩代わりする(その1回だけ応答が遅くなるが、専用cronを別途用意する
 * 複雑さと比べて許容する)。
 *
 * 日付が変わった瞬間に複数リクエストが同時に来ても二重実行しないよう、実行前に
 * 排他ロックを取り、ロック取得後にもう一度日付を確認する(他のリクエストが
 * 待っている間に既に実行を終えているかもしれないため)。ロックが取れなかった
 * 場合(=既に他のリクエストが実行中)は、待たずに諦めて通常の応答を優先する。
 */
function pmeikieeRunDailyMaintenanceIfNeeded(): void {
  $file = pmeikieeDailyMaintenanceStateFile();
  $today = date('Y-m-d');

  $raw = @file_get_contents($file);
  $state = $raw !== false ? json_decode($raw, true) : null;
  if (is_array($state) && ($state['last_run'] ?? '') === $today) {
    return; // 今日はもう実行済み
  }

  $dir = dirname($file);
  if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) { return; }
  $lockFile = $file . '.lock';
  $fp = @fopen($lockFile, 'c');
  if ($fp === false) { return; }
  if (!flock($fp, LOCK_EX | LOCK_NB)) {
    fclose($fp);
    return;
  }

  // ロック取得後の再確認(待機中に他のリクエストが実行を終えている可能性があるため)。
  $raw = @file_get_contents($file);
  $state = $raw !== false ? json_decode($raw, true) : null;
  if (is_array($state) && ($state['last_run'] ?? '') === $today) {
    flock($fp, LOCK_UN);
    fclose($fp);
    return;
  }

  pmeikieeSweepOrphanedStorage();
  pmeikieeSweepStaleAccounts();

  $body = json_encode(['last_run' => $today], JSON_UNESCAPED_UNICODE);
  if ($body !== false) {
    $tmp = $dir . '/.' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($tmp, $body) !== false) {
      @rename($tmp, $file);
    }
  }

  flock($fp, LOCK_UN);
  fclose($fp);
}

/** 監査ログ(pmeikieeMigratePDriveFilesLayoutOnce()専用)への追記先。 */
function pmeikieeFilesLayoutMigrationLogFile(): string {
  return (accountsHiddenStorageDir() ?? sys_get_temp_dir()) . '/p_drive_files_layout_migration_log.jsonl';
}

/**
 * 一回限りの移行: p-driveのマイファイル機能が以前files_meta/files_chunkの
 * 2つのserviceに分かれていた名残(またはそれを手作業で'p-drive'フォルダの
 * 中へ1段ネストしただけで、まだ中の.encファイル自体は移していない状態)を、
 * 1つの'p-drive'serviceディレクトリへ実際に統合する。
 *
 * 各アカウントのstorage_idディレクトリについて、以下の場所を移行元候補として
 * 探す(存在するものだけを対象にする。手作業の途中経過や、まだ手を付けていない
 * 旧来の状態など、複数のケースを1回でまとめて吸収できるようにするため):
 *   - <storage_id>/files_meta, <storage_id>/files_chunk (旧来のトップレベル分離)
 *   - <storage_id>/p-drive/files_meta, <storage_id>/p-drive/files_chunk (手作業で
 *     'p-drive'の中へ1段ネストしたが、まだ.encファイルは移していない状態)
 * 中の.encファイル(キー名は元のまま。メタは素の$fileId、チャンクは".cN"付きの
 * ため衝突しない)を<storage_id>/p-drive/直下へ移動し、空になった移行元
 * ディレクトリは削除する。既に'p-drive'直下に同名ファイルがあれば上書きせず
 * スキップする(2回実行しても安全な冪等な処理)。
 *
 * 呼び出しは?api=admin_migrate_pdrive_files_layout_once経由、oppai管理パネルからの
 * 明示的な操作のみを想定する。実行後、この関数とcase・管理画面のボタンは削除すること。
 */
function pmeikieeMigratePDriveFilesLayoutOnce(): array {
  $entries = @scandir(P_DRIVE_STORAGE_ROOT);
  if ($entries === false) { return ['ok' => true, 'accounts' => []]; }

  $accountResults = [];
  foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') { continue; }
    $userDir = P_DRIVE_STORAGE_ROOT . '/' . $entry;
    if (!is_dir($userDir)) { continue; }

    $targetDir = $userDir . '/p-drive';
    $sourceDirs = [
      $userDir . '/files_meta',
      $userDir . '/files_chunk',
      $targetDir . '/files_meta',
      $targetDir . '/files_chunk',
    ];

    $movedFiles = [];
    $skippedExisting = [];
    foreach ($sourceDirs as $sourceDir) {
      if (!is_dir($sourceDir)) { continue; }
      if (!is_dir($targetDir) && !@mkdir($targetDir, 0770, true) && !is_dir($targetDir)) { continue; }

      $sourceEntries = @scandir($sourceDir) ?: [];
      foreach ($sourceEntries as $file) {
        if ($file === '.' || $file === '..' || $file === '.lock') { continue; }
        $from = $sourceDir . '/' . $file;
        $to = $targetDir . '/' . $file;
        if (!is_file($from)) { continue; }
        if (is_file($to)) { $skippedExisting[] = $file; continue; }
        if (@rename($from, $to)) { $movedFiles[] = $file; }
      }

      $remaining = array_diff(@scandir($sourceDir) ?: [], ['.', '..', '.lock']);
      if (empty($remaining)) {
        @unlink($sourceDir . '/.lock');
        @rmdir($sourceDir);
      }
    }

    if (!empty($movedFiles) || !empty($skippedExisting)) {
      $accountResults[$entry] = ['moved' => $movedFiles, 'skipped_existing' => $skippedExisting];
    }
  }

  if (!empty($accountResults)) {
    $line = json_encode(['ts' => date('c'), 'accounts' => $accountResults], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line !== false) { @file_put_contents(pmeikieeFilesLayoutMigrationLogFile(), $line . "\n", FILE_APPEND | LOCK_EX); }
  }

  return ['ok' => true, 'accounts' => $accountResults];
}

function pDriveErrorStatus(string $error): int {
  switch ($error) {
    case 'quota_exceeded':
    case 'too_large':
      return 413;
    case 'invalid_input':
      return 400;
    case 'server_misconfigured':
      return 500;
    default:
      return 500;
  }
}

// ----------------------------------------------------------------------
// 汎用データストア(userData)
//
// "userData": { "<service>": { "<key>": 値 } } という汎用の入れ物を持たせ、
// サービスごとに好きなキーで小さなデータ(ブックマーク・フォロー中一覧・
// お気に入りタグなど)を保存できるようにしています。実体はp-drive上に
// 1メイキィ1項目としてまとめて持つ(下記参照)。
//
// 【スコープに関する注意】ここはあくまで「サービスごとの小さな付随データ」を
// 置く場所です。1項目としてまとめて読み書きする都合上、大きなデータ
// (本文・画像など)をここに置くとその本人自身の読み書きが遅くなります
// (p-drive自体はユーザーごとに独立しているため、他のメイキィの操作を
// 巻き込むことはありません)。まとまったデータを保存したいサービスは、
// p-memoのメモ機能のように自分自身のストレージを持つか、p-drive上の
// 別のkeyへ分けて保存してください。そのため value は1件1MB、配列は
// 1キーあたり1000件まで、かつ1メイキィのuserData合計で5MBまでに
// 制限しています。
// ----------------------------------------------------------------------

const USER_DATA_KEY_MAX_LENGTH = 64;
const USER_DATA_VALUE_MAX_BYTES = 1 * 1024 * 1024; // 1件あたり1MB
const USER_DATA_LIST_MAX_ITEMS = 1000;
const USER_DATA_TOTAL_MAX_BYTES = 5 * 1024 * 1024; // 1メイキィ合計で5MB

// 表示用の「ブロック」数。MB表記だと生々しいため、ユーザー向けの表示・エラー文言は
// すべてこのブロック数を基準にします。1ブロックのバイト数は
// USER_DATA_TOTAL_MAX_BYTES / USER_DATA_TOTAL_BLOCKS から自動算出するので、
// 将来USER_DATA_TOTAL_MAX_BYTESだけを変更しても、ここは変更不要です。
const USER_DATA_TOTAL_BLOCKS = 20;

function pmeikieeFormatBytes(int $bytes): string {
  if ($bytes >= 1024 * 1024) {
    return round($bytes / 1024 / 1024, 2) . 'MB';
  }
  if ($bytes >= 1024) {
    return round($bytes / 1024, 1) . 'KB';
  }
  return $bytes . 'B';
}

// ================================================================
// userData(アカウントに紐づく小さな保存)  MeikieeUserData
//
// 各サービスが「service名 + key名」で小さな値を預ける場所です。フォロー・
// お気に入り・設定などがここに乗ります。実体は p-drive 側に1項目としてまとめて
// 置いてあり(service='p-meikiee', key='user_data')、account.jsonl には入れません。
//
// 【なぜクラスにまとめたか】19個の関数が「容量の上限」という同じ判断を共有して
// います。1件あたりの上限、1キーの件数、利用者ごとの合計——どれか1つを変えると
// 読み書き・追加・削除の全部が影響を受けます。離して置くと、追加のときだけ上限を
// 見て削除のときは見ない、といった食い違いが生まれます。
//
// 【外から呼ばれないものはprivateにしました】
// 保存先の直接の読み書き(readUserDataStore / writeUserDataStore)は、その典型です。
// 外から使うと上限の判定を飛ばせてしまうので、必ず transactUserData() を通します。
// ================================================================

final class MeikieeUserData {
  private static function userDataBlockBytes(): float {
    return USER_DATA_TOTAL_MAX_BYTES / USER_DATA_TOTAL_BLOCKS;
  }

  public static function userDataBytesToBlocks(int $bytes): float {
    $blockBytes = self::userDataBlockBytes();
    return $blockBytes > 0 ? $bytes / $blockBytes : 0.0;
  }

  private static function userDataKeyIsValid(string $key): bool {
    return $key !== '' && strlen($key) <= USER_DATA_KEY_MAX_LENGTH && preg_match('/^[A-Za-z0-9_.-]+$/', $key) === 1;
  }

  // =====================================================================
  // userDataの実体は、account.jsonlにもp-meikiee自身のディスクにも置かず、
  // 完全に独立したp-driveサービスへ持たせる(p-drive/P_DRIVE_INTEGRATION_SPEC.md
  // 参照。service='p-meikiee', key='user_data'固定の1項目としてまとめて持つ)。
  // account.jsonl自体は1件書き換えるだけで全アカウント分を再暗号化する作りだが、
  // userDataは各サービスの設定変更・フォロー操作のたびに最も頻繁に書き込まれる
  // 部分のため、ここを分離することで「誰か1人の書き込みが他の全ユーザーの
  // 再暗号化を招く」ことが無くなる。
  //
  // 過去(account.jsonl埋め込み→p-meikiee自身の暗号化ファイル)からの移行に
  // あたって、旧データへの自動フォールバックは行わない(別途バックアップ済みの
  // ため。必要であれば手動で移す)。
  // =====================================================================

  private static function readUserDataStore(string $storageId): array {
    $res = pDriveEngineGet($storageId, 'p-meikiee', 'user_data');
    if (empty($res['ok']) || empty($res['found'])) { return []; }
    $data = json_decode((string)($res['value'] ?? ''), true);
    return is_array($data) ? $data : [];
  }

  private static function writeUserDataStore(string $storageId, array $data): bool {
    $res = pDriveEnginePut($storageId, 'p-meikiee', 'user_data', json_encode($data, JSON_UNESCAPED_UNICODE));
    return !empty($res['ok']);
  }

  /**
   * userDataの読み込み→変更→書き込みを、そのユーザー専用のローカルロックファイル
   * (p-meikiee自身の非公開ストレージ配下)で直列化する。データの実体はp-drive上に
   * あるが、get→ローカルで変更→putの3ステップ全体をアトミックにする責務は
   * p-drive側の(userid,service)単位のロックだけでは足りないため、単一サーバ構成の
   * このエコシステムでは呼び出し元(p-meikiee)自身のロックで直列化する。
   * $mutatorはarray &$dataを受け取り['save'=>bool,'result'=>mixed]を返す
   * (p-chatのpchatTransactRooms()と同じ形)。
   */
  public static function transactUserData(string $storageId, callable $mutator): array {
    $dir = (accountsHiddenStorageDir() ?? sys_get_temp_dir()) . '/user_data_locks';
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
      return ['status' => null, 'result' => null];
    }
    // ロックファイル名にstorage_id(ハッシュ)を使う。以前はここに生idを使っており、
    // 「生idを絶対にファイル名にしない」という原則(pDriveUserDir()手前の
    // 【重要・機密情報の取り扱い】コメント参照)から外れていた。
    $lockHandle = fopen($dir . '/' . $storageId . '.lock', 'c');
    if ($lockHandle === false) {
      return ['status' => null, 'result' => null];
    }
    flock($lockHandle, LOCK_EX);

    $data = self::readUserDataStore($storageId);
    $outcome = $mutator($data);
    if (empty($outcome['save'])) {
      $status = false;
    } else {
      $status = self::writeUserDataStore($storageId, $data) ? true : null;
    }

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    return ['status' => $status, 'result' => $outcome['result'] ?? null];
  }

  public static function userDataGet(array $user, string $service, string $key, $default = null) {
    $data = self::readUserDataStore((string)($user['storage_id'] ?? ''));
    return $data[$service][$key] ?? $default;
  }

  /** userData全体(全service/key合計)のバイト数。上限チェックや自分のストレージ容量表示に使います。 */
  public static function userDataTotalBytes(array $userData): int {
    $total = 0;
    foreach ($userData as $serviceData) {
      if (!is_array($serviceData)) { continue; }
      foreach ($serviceData as $value) {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
        if ($encoded !== false) {
          $total += strlen($encoded);
        }
      }
    }
    return $total;
  }

  /** userData合計から、指定した1キー分(置き換え前の古い値)だけを除いたバイト数。 */
  private static function userDataTotalBytesExcluding(array $userData, string $service, string $key): int {
    $total = self::userDataTotalBytes($userData);
    if (isset($userData[$service][$key])) {
      $encoded = json_encode($userData[$service][$key], JSON_UNESCAPED_UNICODE);
      if ($encoded !== false) {
        $total -= strlen($encoded);
      }
    }
    return max(0, $total);
  }

  private static function quotaExceededMessage(): string {
    return 'メイキィ全体の保存容量の上限(' . USER_DATA_TOTAL_BLOCKS . 'ブロック)に達しています。不要なデータを削除してください。';
  }

  /** 戻り値: ['ok'=>bool, 'error'=>?, 'message'=>string] */
  public static function userDataSet(array $user, string $service, string $key, $value): array {
    if (!self::userDataKeyIsValid($service) || !self::userDataKeyIsValid($key)) {
      return ['ok' => false, 'error' => 'invalid_input', 'message' => 'service/keyの形式が不正です(英数字・_・-のみ、64文字以内)。'];
    }
    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
    if ($encoded === false || strlen($encoded) > USER_DATA_VALUE_MAX_BYTES) {
      return ['ok' => false, 'error' => 'too_large', 'message' => '保存できるデータは1キーあたり' . round(self::userDataBytesToBlocks(USER_DATA_VALUE_MAX_BYTES), 1) . 'ブロックまでです。'];
    }
    $storageId = (string)($user['storage_id'] ?? '');
    if ($storageId === '') {
      return ['ok' => false, 'error' => 'not_found', 'message' => 'ユーザーが見つかりません。'];
    }
    $totalQuotaExceeded = false;
    $tx = self::transactUserData($storageId, function (array &$data) use ($service, $key, $value, $encoded, &$totalQuotaExceeded): array {
      $newTotal = self::userDataTotalBytesExcluding($data, $service, $key) + strlen($encoded);
      if ($newTotal > USER_DATA_TOTAL_MAX_BYTES) {
        $totalQuotaExceeded = true;
        return ['save' => false, 'result' => null];
      }
      $data[$service][$key] = $value;
      return ['save' => true, 'result' => true];
    });

    if ($tx['status'] === true) { return ['ok' => true, 'message' => '保存しました。']; }
    if ($totalQuotaExceeded) {
      return ['ok' => false, 'error' => 'quota_exceeded', 'message' => self::quotaExceededMessage()];
    }
    return ['ok' => false, 'error' => 'storage_error', 'message' => '保存中にエラーが発生しました。'];
  }

  /** userData[service][key] を丸ごと削除します(そのキーが無くてもエラーにはしません)。 */
  public static function userDataDelete(array $user, string $service, string $key): array {
    if (!self::userDataKeyIsValid($service) || !self::userDataKeyIsValid($key)) {
      return ['ok' => false, 'error' => 'invalid_input', 'message' => 'service/keyの形式が不正です(英数字・_・-のみ、64文字以内)。'];
    }
    $storageId = (string)($user['storage_id'] ?? '');
    if ($storageId === '') {
      return ['ok' => false, 'error' => 'not_found', 'message' => 'ユーザーが見つかりません。'];
    }
    $tx = self::transactUserData($storageId, function (array &$data) use ($service, $key): array {
      unset($data[$service][$key]);
      return ['save' => true, 'result' => true];
    });

    if ($tx['status'] === true) { return ['ok' => true, 'message' => '削除しました。']; }
    return ['ok' => false, 'error' => 'storage_error', 'message' => '保存中にエラーが発生しました。'];
  }

  /** userData[service][key] を配列として扱い、$value を重複なく追加します。 */
  public static function userDataListAdd(array $user, string $service, string $key, string $value): array {
    if (!self::userDataKeyIsValid($service) || !self::userDataKeyIsValid($key)) {
      return ['ok' => false, 'error' => 'invalid_input', 'message' => 'service/keyの形式が不正です(英数字・_・-のみ、64文字以内)。'];
    }
    $storageId = (string)($user['storage_id'] ?? '');
    if ($storageId === '') {
      return ['ok' => false, 'error' => 'not_found', 'message' => 'ユーザーが見つかりません。'];
    }
    $alreadySaved = false;
    $quotaExceeded = false;
    $totalQuotaExceeded = false;
    $tx = self::transactUserData($storageId, function (array &$data) use ($service, $key, $value, &$alreadySaved, &$quotaExceeded, &$totalQuotaExceeded): array {
      $list = $data[$service][$key] ?? [];
      if (!is_array($list)) { $list = []; }
      if (in_array($value, $list, true)) {
        $alreadySaved = true;
        return ['save' => false, 'result' => null];
      }
      if (count($list) >= USER_DATA_LIST_MAX_ITEMS) {
        $quotaExceeded = true;
        return ['save' => false, 'result' => null];
      }
      $newList = $list;
      $newList[] = $value;
      $encodedNewList = json_encode($newList, JSON_UNESCAPED_UNICODE);
      $newTotal = self::userDataTotalBytesExcluding($data, $service, $key)
        + ($encodedNewList === false ? 0 : strlen($encodedNewList));
      if ($newTotal > USER_DATA_TOTAL_MAX_BYTES) {
        $totalQuotaExceeded = true;
        return ['save' => false, 'result' => null];
      }
      $data[$service][$key] = $newList;
      return ['save' => true, 'result' => true];
    });

    if ($totalQuotaExceeded) { return ['ok' => false, 'error' => 'quota_exceeded', 'message' => self::quotaExceededMessage()]; }
    if ($tx['status'] === true) { return ['ok' => true, 'message' => '追加しました。']; }
    if ($quotaExceeded) { return ['ok' => false, 'error' => 'quota_exceeded', 'message' => '保存できる件数の上限(' . USER_DATA_LIST_MAX_ITEMS . '件)に達しています。']; }
    if ($alreadySaved) { return ['ok' => false, 'error' => 'already_saved', 'message' => 'この項目はすでに保存されています。']; }
    return ['ok' => false, 'error' => 'storage_error', 'message' => '保存中にエラーが発生しました。'];
  }

  /** userData[service][key] の配列から $value を取り除きます。 */
  public static function userDataListRemove(array $user, string $service, string $key, string $value): array {
    if (!self::userDataKeyIsValid($service) || !self::userDataKeyIsValid($key)) {
      return ['ok' => false, 'error' => 'invalid_input', 'message' => 'service/keyの形式が不正です(英数字・_・-のみ、64文字以内)。'];
    }
    $storageId = (string)($user['storage_id'] ?? '');
    if ($storageId === '') {
      return ['ok' => false, 'error' => 'not_found', 'message' => 'ユーザーが見つかりません。'];
    }
    $tx = self::transactUserData($storageId, function (array &$data) use ($service, $key, $value): array {
      $list = $data[$service][$key] ?? [];
      if (!is_array($list)) { $list = []; }
      $data[$service][$key] = array_values(array_diff($list, [$value]));
      return ['save' => true, 'result' => true];
    });

    if ($tx['status'] === null) {
      return ['ok' => false, 'error' => 'storage_error', 'message' => '保存中にエラーが発生しました。'];
    }
    return ['ok' => true, 'message' => '削除しました。'];
  }

  /**
   * $user['userData']を直接読んではいけない、その利用者の「今のuserData」を
   * 解決する共通ヘルパー。実体はp-drive上にあり、account.jsonl側の
   * $user['userData']は(過去の名残があっても)もう更新されないため、
   * 表示・API応答で使う場合は必ずこちらを経由すること。
   */
  public static function resolveUserData(array $user): array {
    $storageId = (string)($user['storage_id'] ?? '');
    if ($storageId === '') { return []; }
    return self::readUserDataStore($storageId);
  }

  /**
   * 「他の何件のメイキィが、自分(userData経由でrawId)を配列に含めているか」を数えます。
   * フォロワー数はこの一般形の一例(service=pips, key=following)です。
   *
   * userDataがユーザーごとの別ファイルに分離された後も、「全ユーザーが誰か」を
   * 把握するためのaccount.jsonl自体の全件読み込みは避けられない(ここが今回の
   * 分離で唯一速くならない部分。ただし現状の規模ではこの全件読み込み自体が
   * ごく軽いため実害はない)。
   */
  public static function userDataReverseCount(string $value, string $service, string $key): int {
    $count = 0;
    foreach (pmeikieeReadAll() as $user) {
      $list = self::resolveUserData($user)[$service][$key] ?? [];
      if (is_array($list) && in_array($value, $list, true)) {
        $count++;
      }
    }
    return $count;
  }

  /**
   * self::userDataReverseCount()と同じ全件走査の中で、件数の代わりに該当メイキィの
   * 公開プロフィールを集めて返します。フォロワー一覧(自分をフォローしている人の一覧)は
   * この一般形の一例(service=pips, key=following)です。
   */
  public static function userDataReverseList(string $value, string $service, string $key): array {
    $matches = [];
    foreach (pmeikieeReadAll() as $user) {
      $list = self::resolveUserData($user)[$service][$key] ?? [];
      if (is_array($list) && in_array($value, $list, true)) {
        $matches[] = pmeikieePublicUser($user);
      }
    }
    return $matches;
  }

  /**
   * userid(sha256ハッシュ)の配列を、実際のメイキィ(公開プロフィール)へ一括で解決します。
   * following配列などは相手の生idを知り得ないハッシュのみを持つため、表示用の
   * ユーザー名・名前・アバターに変換するにはこの逆引きが必要です。1回の全件走査で
   * 複数のハッシュをまとめて解決するため、フォロー中一覧のように何十件あっても
   * 走査は1回で済みます。戻り値の順序は$hashesと同じ順になります(見つからなかった
   * ものは結果に含まれません)。
   */
  public static function resolveUserIdHashes(array $hashes): array {
    $wanted = array_flip(array_map('strval', $hashes));
    $foundByHash = [];
    foreach (pmeikieeReadAll() as $user) {
      $hash = (string)($user['storage_id'] ?? '');
      if (isset($wanted[$hash])) {
        $foundByHash[$hash] = pmeikieePublicUser($user);
      }
    }

    $ordered = [];
    foreach ($hashes as $hash) {
      if (isset($foundByHash[(string)$hash])) {
        $ordered[] = $foundByHash[(string)$hash];
      }
    }
    return $ordered;
  }
}

// ----------------------------------------------------------------
// 上のクラスへの薄い入口(グローバル関数)
//
// API振り分け・画面・保守から呼ばれているものだけを残しています。
// 新しく書くコードでは MeikieeUserData::… を直接呼んでください。
// ----------------------------------------------------------------

function pmeikieeUserDataBytesToBlocks(int $bytes): float {
  return MeikieeUserData::userDataBytesToBlocks($bytes);
}

function pmeikieeTransactUserData(array $user, callable $mutator) {
  return MeikieeUserData::transactUserData($user, $mutator);
}

function pmeikieeUserDataGet(array $user, string $service, string $key) {
  return MeikieeUserData::userDataGet($user, $service, $key);
}

function pmeikieeUserDataTotalBytes(array $user): int {
  return MeikieeUserData::userDataTotalBytes($user);
}

function pmeikieeUserDataSet(array $user, string $service, string $key, $value): array {
  return MeikieeUserData::userDataSet($user, $service, $key, $value);
}

function pmeikieeUserDataDelete(array $user, string $service, string $key): array {
  return MeikieeUserData::userDataDelete($user, $service, $key);
}

function pmeikieeUserDataListAdd(array $user, string $service, string $key, string $value): array {
  return MeikieeUserData::userDataListAdd($user, $service, $key, $value);
}

function pmeikieeUserDataListRemove(array $user, string $service, string $key, string $value): array {
  return MeikieeUserData::userDataListRemove($user, $service, $key, $value);
}

function pmeikieeResolveUserData(array $user): array {
  return MeikieeUserData::resolveUserData($user);
}

function pmeikieeUserDataReverseCount(string $value, string $service, string $key): int {
  return MeikieeUserData::userDataReverseCount($value, $service, $key);
}

function pmeikieeUserDataReverseList(string $value, string $service, string $key): array {
  return MeikieeUserData::userDataReverseList($value, $service, $key);
}

function pmeikieeResolveUserIdHashes(array $hashes): array {
  return MeikieeUserData::resolveUserIdHashes($hashes);
}
// ================================================================
// アカウントの一生(MeikieeAccountLifecycle)
//
// 作る・直す・アバターを差し替える・消す。ここが扱うのは「アカウントそのものの
// 状態を変える操作」だけです。読むだけの検索は MeikieeAccounts が持ちます。
//
// 【なぜクラスにまとめたか】この4つの操作は、同じ検証を共有しています。
// パスワードの方針、メールドメインの実在確認、作成の連打制限。どれか1つを変えたら
// 作成と編集の両方を見直す必要があり、離して置くと片方だけ直して食い違います。
//
// 【外から呼ばれないものはprivateにしました】
// 検証と連打制限は、この4操作の内側で必ず通るべき関門です。外から呼べると
// 「検証を飛ばして作る」道が残ります。呼び出し箇所を数えたところ、実際にこれらを
// 外から呼んでいる場所は1つもありませんでした(2026-09-07時点)。
//
// 【付随データの後始末をここへ引き取りました】
// cleanupExternalData() は、以前ファイルの遠く離れた場所(userDataの節の手前)に
// ありましたが、呼び出しているのは delete() と adminDelete() の2箇所だけです。
// 削除の一部なので、削除と同じ場所に置くのが本来の姿です。
//
// 【削除の順序を変えないこと】付随データの削除がすべて確認できるまで
// account.jsonl には触れません。逆にすると「本体は消えたが付随データは残る」
// という中途半端な状態が起こり得ます。今の順序なら、失敗しても
// 「削除が無かったこと」になるだけで済みます。
// ================================================================

final class MeikieeAccountLifecycle {
  /**
   * アカウント削除で、account.jsonlからレコードを消す**前**に呼ぶ。アバター・
   * userData(p-drive)等、account.jsonlの外にある付随データをすべて削除できた
   * ことを確認してから、呼び出し元がaccount.jsonl自体の削除に進めるようにする
   * ためのもの。
   *
   * 【なぜaccount.jsonlの削除を最後に回すか】以前はaccount.jsonlの削除を先に
   * 確定させ、付随データの削除は「そのあとの後片付け(失敗しても記録するだけ)」
   * という順序だった。この場合、付随データの削除に失敗しても、ユーザーには
   * 「削除に成功しました」と表示されてしまう(ログインはできなくなるので
   * 「本体」は確実に消えているが、付随データだけが取り残されたまま
   * 「削除完了」を騙ることになる)。ここではその順序を逆にし、
   * **付随データの削除がすべて確認できるまでaccount.jsonlには一切触れない**
   * ようにすることで、「本体は消えたが付随データは残る」という中途半端な
   * 状態そのものを構造的に起こり得なくしている(失敗時はaccount.jsonlの
   * レコードがそのまま残るため、アカウントは削除されておらずログインも
   * 引き続き可能=削除が「無かったこと」になるだけで、中途半端な状態には
   * ならない)。
   *
   * ファイルが元から存在しない場合は「削除済み」として扱い、失敗とはしない
   * (存在するのに削除できなかった場合だけを失敗として扱う)。
   */
  private static function cleanupExternalData(array $user): array {
    $storageId = (string)($user['storage_id'] ?? '');
    $failed = [];

    $tryUnlink = static function (string $path, string $label) use (&$failed): void {
      if (!is_file($path)) { return; } // 元から無い=既に削除済み扱い。失敗ではない。
      if (!@unlink($path)) {
        $failed[] = $label;
      }
    };

    // userDataの読み書きを直列化するロックファイル(pmeikieeTransactUserData()参照)。
    // データ本体はp-drive上(下のpDriveEngineDeleteUser()の範囲)にあるが、この
    // ロックファイルだけは別ディレクトリのため個別に消しておかないと、削除済み
    // アカウントぶんの空ロックファイルがuser_data_locks/に永久に残り続ける。
    $tryUnlink((accountsHiddenStorageDir() ?? sys_get_temp_dir()) . '/user_data_locks/' . $storageId . '.lock', 'userdata_lock_cleanup');

    // p-drive上の全serviceぶん(アバター・userData含む、各サービスがservice名で
    // 持っている分すべて)を削除する本体。同一プロセス内の直接の関数呼び出しなので、
    // この行の実行が完了するまで先へ進まない。呼び出し側は必ずstorage_idを渡す
    // (2026-08-23の一回限りの移行スクリプトで、生idディレクトリに残っていた
    // データは全アカウントぶん統合済みのため、生id側を別途掃除する必要はない)。
    $pDriveResult = pDriveEngineDeleteUser($storageId);
    if (empty($pDriveResult['ok'])) {
      $failed[] = 'p_drive';
    }

    if (!empty($failed)) {
      error_log('[p-meikiee] アカウント削除: 付随データの削除に失敗したため、account.jsonlの削除は行いません。storage_id=' . $storageId . ' failed=' . implode(',', $failed));
      return ['ok' => false, 'failed' => $failed];
    }
    return ['ok' => true];
  }



  // メールアドレスのドメイン検証(送信によるメール確認は行わない前提)
  //
  // このサーバーは(OP25B等の事情で)確認メールの送信ができない場合があるため、
  // 「実際に届くか」までは確認できません。代わりに、ドメインにメールサーバーが
  // 存在するかどうかをDNS問い合わせ(ポート53、OP25Bとは無関係)だけで確認し、
  // タイプミスや完全に存在しないドメインをはじきます。

  /** ドメインにMXレコード、無ければ(RFC5321の暗黙MXとしての)A/AAAAレコードがあるか確認する。 */
  private static function emailDomainHasMailServer(string $email): bool {
    $at = strrpos($email, '@');
    if ($at === false) { return false; }
    $domain = substr($email, $at + 1);
    if ($domain === '') { return false; }

    // 国際化ドメイン(日本語ドメイン等)はDNS問い合わせの前にpunycodeへ変換する。
    if (function_exists('idn_to_ascii')) {
      $ascii = @idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
      if ($ascii !== false) { $domain = $ascii; }
    }

    if (@checkdnsrr($domain, 'MX')) { return true; }
    // MXレコードが無いドメインは、ドメイン自体のA/AAAAレコード宛に配送される
    // 構成(小規模ドメインでよくある)もあるため、無条件でNGにはしない。
    return @checkdnsrr($domain, 'A') || @checkdnsrr($domain, 'AAAA');
  }

  // アカウント作成のレート制限(IPベース)
  //
  // メール確認による自然な抑止が効かないため、スクリプトによる大量作成を防ぐ
  // 最低限の防波堤として、ログイン試行(pmeikieeLoginIsBlocked)と同じ
  // 「ファイルにIPごとの回数を記録し、一定時間で自然に期限切れる」方式を使う。
  // ログイン失敗カウントと違い成功時にクリアはしない(=作成に成功しても
  // 「短時間に何件も作る」こと自体を抑止したいため)。

  const CREATE_MAX_ATTEMPTS = 8;
  const CREATE_ATTEMPT_WINDOW = 60 * 15; // 15分

  private static function createAttemptFile(): string {
    return (accountsHiddenStorageDir() ?? sys_get_temp_dir()) . '/create_attempts.json';
  }

  public static function clientIp(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
  }

  private static function createIsBlocked(): bool {
    $file = self::createAttemptFile();
    if (!file_exists($file)) { return false; }
    $raw = file_get_contents($file);
    if ($raw === false) { return false; }
    $data = json_decode($raw, true);
    if (!is_array($data)) { return false; }

    $entry = $data[self::clientIp()] ?? null;
    if (!is_array($entry)) { return false; }
    if ((int)($entry['last'] ?? 0) + CREATE_ATTEMPT_WINDOW < time()) { return false; }
    return (int)($entry['count'] ?? 0) >= CREATE_MAX_ATTEMPTS;
  }

  private static function recordCreateAttempt(): void {
    $fp = fopen(self::createAttemptFile(), 'c+');
    if ($fp === false) { return; }
    flock($fp, LOCK_EX);

    $raw = stream_get_contents($fp);
    $data = ($raw !== false && trim($raw) !== '') ? json_decode($raw, true) : [];
    if (!is_array($data)) { $data = []; }

    $now = time();
    foreach ($data as $k => $v) {
      if (!is_array($v) || (int)($v['last'] ?? 0) + CREATE_ATTEMPT_WINDOW < $now) {
        unset($data[$k]);
      }
    }

    $ip = self::clientIp();
    $count = (int)($data[$ip]['count'] ?? 0);
    $data[$ip] = ['count' => $count + 1, 'last' => $now];

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
  }

  // メイキィの作成・編集・削除

  /**
   * パスワードがPASSWORD_MIN_LENGTH〜PASSWORD_MAX_LENGTH文字・英数混在・
   * 大文字小文字混在のポリシーを満たしていなければ、エラーメッセージを返します
   * (満たしていればnull)。新規作成・パスワード変更のどちらからも使います。
   */
  private static function passwordPolicyError(string $password): ?string {
    $len = mb_strlen($password);
    if ($len < PASSWORD_MIN_LENGTH) {
      return 'パスワードは' . PASSWORD_MIN_LENGTH . '文字以上で設定してください。';
    }
    if ($len > PASSWORD_MAX_LENGTH) {
      return 'パスワードは' . PASSWORD_MAX_LENGTH . '文字以内で設定してください。';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
      return 'パスワードは英字と数字の両方を含めてください。';
    }
    if (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password)) {
      return 'パスワードは英大文字と英小文字の両方を含めてください。';
    }
    return null;
  }

  /** 戻り値: ['ok'=>bool, 'error'=>string|null, 'message'=>string] */
  public static function create(string $name, string $email, string $username, string $password, string $passwordConfirm): array {
    // メール確認による自然な抑止が効かないため(送信不可の環境がある)、まずIPベースの
    // レート制限を見る。以降の検証に関わらず、この関数に到達した時点で1回とカウントする
    // (失敗ケースも含めて「短時間に何度も試す」こと自体を抑止するため)。
    if (self::createIsBlocked()) {
      return ['ok' => false, 'error' => 'too_many_attempts', 'message' => 'アカウント作成の試行が多すぎるため、しばらくの間停止しています。15分ほど待ってからもう一度お試しください。'];
    }
    self::recordCreateAttempt();

    $name = trim($name);
    $email = trim($email);
    $username = trim($username);

    if ($username === '' || $password === '') {
      return ['ok' => false, 'error' => 'invalid_input', 'message' => 'ユーザー名とパスワードは必須です。'];
    }
    if ($password !== $passwordConfirm) {
      return ['ok' => false, 'error' => 'password_mismatch', 'message' => 'パスワードと確認用パスワードが一致しません。'];
    }
    $passwordPolicyError = self::passwordPolicyError($password);
    if ($passwordPolicyError !== null) {
      return ['ok' => false, 'error' => 'weak_password', 'message' => $passwordPolicyError];
    }
    // 以前はメールアドレスの形式チェックが一切無く(空でなければ何でも通っていた)、
    // フォーム側もtype="text"だったため「elonmusk」のような文字列でも作成できていた。
    // 「バイパス」ではなく、そもそも検証自体が存在しなかった。
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
      return ['ok' => false, 'error' => 'invalid_email', 'message' => 'メールアドレスの形式が正しくありません。'];
    }
    // 実際にメールが届くかまでは確認できない(送信不可の環境があるため)が、
    // ドメインにメールサーバーが存在するかどうかはDNS問い合わせだけで確認できる。
    if ($email !== '' && !self::emailDomainHasMailServer($email)) {
      return ['ok' => false, 'error' => 'invalid_email', 'message' => 'メールアドレスのドメインにメールサーバーが見つかりませんでした。入力内容をご確認ください。'];
    }

    $rawId = bin2hex(random_bytes(16));
    $newAccount = [
      'id'         => $rawId,
      // ストレージ用の不変id。生成直後にこの1回だけ計算して恒久的に固定する
      // (MeikieeAccounts::computeStorageId()のコメント参照。以後、値を再計算するのではなく
      // 常にこのフィールドを参照することで、p-drive・アバター・他サービスへ
      // 渡すuseridが全経路で常に完全一致するようになる)。
      'storage_id' => hash('sha256', $rawId),
      'name'       => $name,
      'email'      => $email,
      'username'   => $username,
      'password'   => password_hash($password, PASSWORD_DEFAULT),
      // 登録日時(unixタイムスタンプ)。pmeikieeSweepStaleAccounts()が
      // 「作成されたのに一度も使われないまま放置された」アカウントを判定する
      // 起点として使う。導入前に作られた既存アカウントにはこのキー自体が無い
      // (pmeikieeNormalizeUser()では補わない。今の時刻を後から詰めてしまうと
      // 「今作られたばかりの未使用アカウント」と誤認して削除対象に巻き込む
      // 恐れがあるため、無いものは無いまま「作成日時不明」として扱う)。
      'created_at' => time(),
    ];

    // 重複の判定はトランザクション(=ファイルロック)の中で行う。ロックの外で
    // 先に確認してしまうと、同じアドレスの同時登録がすり抜けうるため。
    $conflict = null;
    $status = pmeikieeTransaction(function (array &$accounts) use ($username, $email, $newAccount, &$conflict): bool {
      if (pmeikieeFindIndexByUsername($accounts, $username) !== null) {
        $conflict = 'username';
        return false;
      }
      // メールアドレスは任意入力(空のまま作れる)なので、空欄同士は重複と見なさない。
      if ($email !== '' && pmeikieeFindIndexByEmail($accounts, $email) !== null) {
        $conflict = 'email';
        return false;
      }
      $accounts[] = $newAccount;
      return true;
    });

    if ($status === true) {
      return ['ok' => true, 'message' => 'メイキィ作成に成功しました。'];
    } elseif ($status === null) {
      return ['ok' => false, 'error' => 'storage_error', 'message' => '保存中にエラーが発生しました。しばらくしてから再度お試しください。'];
    } elseif ($conflict === 'email') {
      return ['ok' => false, 'error' => 'email_taken', 'message' => 'そのメールアドレスは既に使われています。'];
    } else {
      return ['ok' => false, 'error' => 'username_taken', 'message' => 'そのユーザー名は既に使われています。'];
    }
  }

  /** $userId は生のメイキィid(トークンから解決したもの)。戻り値: ['ok'=>bool,'error'=>?,'message'=>string,'user'=>array|null] */
  public static function edit(string $userId, string $name, string $email, string $username, string $bio, string $newPassword, string $newPasswordConfirm): array {
    $name = trim($name);
    $email = trim($email);
    $username = trim($username);
    $bio = trim($bio);

    if ($name === '' || $email === '' || $username === '') {
      return ['ok' => false, 'error' => 'invalid_input', 'message' => '名前とメールアドレスとユーザー名は必須です。'];
    }
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
      return ['ok' => false, 'error' => 'invalid_email', 'message' => 'メールアドレスの形式が正しくありません。'];
    }
    // ドメインのメールサーバー確認は、実際にメールアドレスを変更する場合だけ行う
    // (毎回の編集で同じ既存アドレスを再検証すると、ドメイン側の一時的なDNS不調等で
    // 名前やbioを直しただけの編集まで巻き添えで失敗しかねないため)。
    $currentUser = pmeikieeFindById($userId);
    $emailChanged = $currentUser === null || ($currentUser['email'] ?? '') !== $email;
    if ($emailChanged && !self::emailDomainHasMailServer($email)) {
      return ['ok' => false, 'error' => 'invalid_email', 'message' => 'メールアドレスのドメインにメールサーバーが見つかりませんでした。入力内容をご確認ください。'];
    }
    if (mb_strlen($bio) > BIO_MAX_LENGTH) {
      return ['ok' => false, 'error' => 'bio_too_long', 'message' => '自己紹介は' . BIO_MAX_LENGTH . '文字までです。'];
    }
    if ($newPassword !== '' && $newPassword !== $newPasswordConfirm) {
      return ['ok' => false, 'error' => 'password_mismatch', 'message' => '新しいパスワードと確認用パスワードが一致しません。'];
    }
    if ($newPassword !== '') {
      $passwordPolicyError = self::passwordPolicyError($newPassword);
      if ($passwordPolicyError !== null) {
        return ['ok' => false, 'error' => 'weak_password', 'message' => $passwordPolicyError];
      }
    }

    $updated = null;
    $conflict = null;
    $status = pmeikieeTransaction(function (array &$accounts) use ($userId, $name, $email, $username, $bio, $newPassword, &$updated, &$conflict): bool {
      $idx = pmeikieeFindIndexById($accounts, $userId);
      if ($idx === null) {
        $conflict = 'missing';
        return false;
      }

      $currentUsername = $accounts[$idx]['username'];
      if ($username !== $currentUsername && pmeikieeFindIndexByUsername($accounts, $username) !== null) {
        $conflict = 'username';
        return false;
      }

      // 自分自身は除外して探す。アドレスを変えていない編集(名前やbioだけの変更)で
      // 自分のアドレスに引っかかって失敗しないようにするため。
      if (pmeikieeFindIndexByEmail($accounts, $email, $idx) !== null) {
        $conflict = 'email';
        return false;
      }

      $accounts[$idx]['name']     = $name;
      $accounts[$idx]['email']    = $email;
      $accounts[$idx]['username'] = $username;
      $accounts[$idx]['bio']      = $bio;
      if ($newPassword !== '') {
        $accounts[$idx]['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
        // 新しいパスワードを設定した=再設定義務は果たされた。UI経由でもAPI経由でも
        // パスワード変更は必ずここを通るので、義務を降ろすのもここに置く
        // (呼び出し側それぞれで消す形にすると、経路が増えたときに消し忘れる)。
        unset($accounts[$idx][OBLIGATION_RESET_PASSWORD]);
      }
      $updated = $accounts[$idx];
      return true;
    });

    if ($status === true) {
      return ['ok' => true, 'message' => 'メイキィ情報を更新しました。', 'user' => pmeikieePublicUser($updated)];
    } elseif ($status === null) {
      return ['ok' => false, 'error' => 'storage_error', 'message' => '保存中にエラーが発生しました。しばらくしてから再度お試しください。'];
    } elseif ($conflict === 'email') {
      return ['ok' => false, 'error' => 'email_taken', 'message' => 'そのメールアドレスは既に使われています。'];
    } elseif ($conflict === 'username') {
      return ['ok' => false, 'error' => 'username_taken', 'message' => 'そのユーザー名は既に使われています。'];
    } else {
      return ['ok' => false, 'error' => 'not_found', 'message' => 'ユーザー情報が見つかりません。'];
    }
  }

  /**
   * プロフィール画像をアップロードします。$fileは$_FILES['avatar']をそのまま渡してください。
   * 保存前に正方形へセンタークロップ・AVATAR_SIZEへ縮小・JPEGへ再エンコードします(画像を
   * 一から作り直す形になるため、EXIF等の付随メタデータも結果的に失われます)。
   * 画像データ自体はディスク上の平文ファイルではなく、p_drive_storage/
   * <storage_id>/p-meikiee/avatar(pDriveEnginePut())に持つ。同じ利用者の
   * 再アップロードは単純な上書きで完結する。
   */
  public static function avatarSave(array $user, array $file): array {
    $rawId = (string)($user['id'] ?? '');
    $storageId = (string)($user['storage_id'] ?? '');
    if ($rawId === '' || $storageId === '') {
      return ['ok' => false, 'error' => 'not_found', 'message' => 'ユーザーが見つかりません。'];
    }
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
      return ['ok' => false, 'error' => 'upload_failed', 'message' => 'ファイルのアップロードに失敗しました。'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
      return ['ok' => false, 'error' => 'upload_failed', 'message' => 'ファイルのアップロードに失敗しました。'];
    }
    if ((int)$file['size'] > AVATAR_MAX_UPLOAD_BYTES) {
      return ['ok' => false, 'error' => 'too_large', 'message' => '画像は' . round(AVATAR_MAX_UPLOAD_BYTES / 1024 / 1024, 1) . 'MBまでです。'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $loaders = [
      'image/jpeg' => 'imagecreatefromjpeg',
      'image/png'  => 'imagecreatefrompng',
      'image/webp' => 'imagecreatefromwebp',
      'image/gif'  => 'imagecreatefromgif',
    ];
    if ($mime === false || !isset($loaders[$mime])) {
      return ['ok' => false, 'error' => 'invalid_type', 'message' => '対応していない画像形式です(jpeg/png/webp/gifのみ)。'];
    }

    $source = @($loaders[$mime])($file['tmp_name']);
    if ($source === false) {
      return ['ok' => false, 'error' => 'invalid_image', 'message' => '画像を読み込めませんでした。ファイルが壊れている可能性があります。'];
    }

    $srcW = imagesx($source);
    $srcH = imagesy($source);
    $cropSize = min($srcW, $srcH);
    $cropX = intdiv($srcW - $cropSize, 2);
    $cropY = intdiv($srcH - $cropSize, 2);

    $canvas = imagecreatetruecolor(AVATAR_SIZE, AVATAR_SIZE);
    // JPEGとして書き出すので、透過PNG等の透明部分が黒くならないよう白で塗り潰しておく。
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $white);
    imagecopyresampled($canvas, $source, 0, 0, $cropX, $cropY, AVATAR_SIZE, AVATAR_SIZE, $cropSize, $cropSize);
    imagedestroy($source);

    // ディスク上に平文の画像ファイルを作らず、いったん出力バッファへJPEGとして
    // 書き出す(imagejpeg()の第2引数をnullにするとファイルではなく出力バッファへ
    // 書き出せる)。
    ob_start();
    $rendered = imagejpeg($canvas, null, 85);
    $jpegBytes = ob_get_clean();
    imagedestroy($canvas);

    if (!$rendered || $jpegBytes === false || $jpegBytes === '') {
      return ['ok' => false, 'error' => 'storage_error', 'message' => '画像の変換に失敗しました。'];
    }

    // p_drive_storage/<storage_id>/p-meikiee/avatar.enc へ保存する(p-drive自身の
    // 暗号鍵・容量管理・ロックをそのまま再利用する)。
    $putResult = pDriveEnginePut($storageId, 'p-meikiee', 'avatar', $jpegBytes);
    if (empty($putResult['ok'])) {
      return ['ok' => false, 'error' => 'storage_error', 'message' => '画像の保存に失敗しました。'];
    }

    // account.jsonl側は「更新日時(URLのキャッシュ無効化・存在確認用)」の軽い
    // マーカーだけを持つ(画像データ自体は上で保存済みの別ファイルにある)。
    $status = pmeikieeTransaction(function (array &$accounts) use ($rawId): bool {
      $idx = pmeikieeFindIndexById($accounts, $rawId);
      if ($idx === null) { return false; }
      $accounts[$idx]['avatar_updated'] = time();
      unset($accounts[$idx]['avatar'], $accounts[$idx]['avatar_data']); // 旧方式の名残があれば併せて片付ける
      return true;
    });

    if ($status !== true) {
      return ['ok' => false, 'error' => 'storage_error', 'message' => '画像は保存しましたが、メイキィ情報の更新に失敗しました。'];
    }

    return ['ok' => true, 'message' => 'プロフィール画像を更新しました。'];
  }

  /**
   * $userId は生のメイキィid。本人確認のためユーザー名とパスワードも要求します(PIPS時代と同じ仕様)。
   *
   * $userIdはトークン検証済みの本人のものなので、ここを鍵にすることで
   * 「usernameを毎回変えて送って足止めカウンタを別バケットに逃がす」ような
   * 回避ができないようにしています(MeikieeLoginThrottleへ渡す文字列は
   * ログイン試行のバケットと混ざらないよう 'delete:' で名前空間を分けています)。
   * トークンが盗まれてパスワードだけ知らない攻撃者による総当たりを想定した対策です。
   */
  public static function delete(string $userId, string $username, string $password): array {
    $attemptKey = 'delete:' . $userId;
    if (pmeikieeLoginIsBlocked($attemptKey)) {
      return ['ok' => false, 'error' => 'too_many_attempts', 'message' => '本人確認の失敗が続いたため、しばらくの間削除操作を停止しています。15分ほど待ってからもう一度お試しください。'];
    }

    // 本人確認(読み取り専用。この時点ではまだ何も削除しない)。
    $user = pmeikieeFindById($userId);
    if ($user === null || $user['username'] !== $username || !password_verify($password, $user['password'])) {
      pmeikieeRecordLoginAttempt($attemptKey, false);
      return ['ok' => false, 'error' => 'invalid_credentials', 'message' => 'ユーザー名かパスワードが間違っています。'];
    }
    pmeikieeRecordLoginAttempt($attemptKey, true);

    // account.jsonlのレコードを消す前に、付随データ(アバター・p-drive上のuserData等)を
    // すべて削除できることを確認する。ここで失敗したら以降には一切進まず、
    // account.jsonlも変更しない(self::cleanupExternalData()のコメント参照)。
    $cleanup = self::cleanupExternalData($user);
    if (!$cleanup['ok']) {
      return [
        'ok' => false,
        'error' => 'cleanup_failed',
        'message' => 'サーバー側の設定に問題が発生したため、削除を完了できませんでした(アカウントはまだ削除されていません)。お手数ですが、しばらくしてからもう一度お試しいただくか、解決しない場合は管理者へご連絡ください。',
      ];
    }

    // 付随データの削除がすべて確認できた後、最後にaccount.jsonlからレコードを削除する。
    $deleted = false;
    $status = pmeikieeTransaction(function (array &$accounts) use ($userId, &$deleted): bool {
      $idx = pmeikieeFindIndexById($accounts, $userId);
      if ($idx === null) { return false; }
      array_splice($accounts, $idx, 1);
      $deleted = true;
      return true;
    });

    if ($status === true && $deleted) {
      return ['ok' => true, 'message' => 'メイキィを削除しました。'];
    }
    if ($status === null) {
      return ['ok' => false, 'error' => 'storage_error', 'message' => '保存中にエラーが発生しました。しばらくしてから再度お試しください。'];
    }
    return ['ok' => false, 'error' => 'not_found', 'message' => 'メイキィが見つかりませんでした。'];
  }

  /**
   * 管理者(oppai管理パネル)専用の強制削除。self::delete()と違い、本人確認
   * (username/password)を求めません(スパム・休眠アカウント整理用)。
   * api_secretだけで呼べるため、呼び出し元はoppai自身のログインで既に一段
   * 認証されている前提です(4.節参照)。
   */
  public static function adminDelete(string $userId): array {
    $user = pmeikieeFindById($userId);
    if ($user === null) {
      return ['ok' => false, 'error' => 'not_found', 'message' => '指定されたメイキィは見つかりませんでした。'];
    }

    // account.jsonlのレコードを消す前に、付随データをすべて削除できることを確認する
    // (self::delete()と同じ理由。self::cleanupExternalData()のコメント参照)。
    $cleanup = self::cleanupExternalData($user);
    if (!$cleanup['ok']) {
      return [
        'ok' => false,
        'error' => 'cleanup_failed',
        'message' => 'サーバー側の設定に問題が発生したため、削除を完了できませんでした(アカウントはまだ削除されていません)。',
      ];
    }

    $deleted = false;
    $status = pmeikieeTransaction(function (array &$accounts) use ($userId, &$deleted): bool {
      $idx = pmeikieeFindIndexById($accounts, $userId);
      if ($idx === null) { return false; }
      array_splice($accounts, $idx, 1);
      $deleted = true;
      return true;
    });

    if ($status === true && $deleted) {
      return ['ok' => true, 'message' => 'メイキィを削除しました。'];
    }
    if ($status === null) {
      return ['ok' => false, 'error' => 'storage_error', 'message' => '保存中にエラーが発生しました。しばらくしてから再度お試しください。'];
    }
    return ['ok' => false, 'error' => 'not_found', 'message' => '指定されたメイキィは見つかりませんでした。'];
  }
}

// ----------------------------------------------------------------
// 上のクラスへの薄い入口(グローバル関数)
//
// 画面(作成・編集・削除フォームの受け口)とAPI振り分けから呼ばれているものだけです。
// 新しく書くコードでは MeikieeAccountLifecycle::… を直接呼んでください。
//
// 【不変条件】ここの各関数の引数は、委譲先のクラスメソッドと**完全に同じ並び・
// 同じ型**でなければならない。この入口は値を組み替えたり既定値を補ったりせず、
// 受け取ったものをそのまま渡すだけの存在だからです。
// 破ると「TypeError: Argument #1 ($user) must be of type array, string given」
// のように、呼び出し側(画面のPOST処理)ではなくこの入口で落ちます。画面側の
// コードは正しいのに例外だけが出るので原因を見失いやすい。
// 迷ったら委譲先(MeikieeAccountLifecycle::create/edit/avatarSave/delete/
// adminDelete)の宣言を開き、引数リストをそのまま写してください。
// ----------------------------------------------------------------

function pmeikieeClientIp(): string {
  return MeikieeAccountLifecycle::clientIp();
}

function pmeikieeCreate(string $name, string $email, string $username, string $password, string $passwordConfirm): array {
  return MeikieeAccountLifecycle::create($name, $email, $username, $password, $passwordConfirm);
}

function pmeikieeEdit(string $userId, string $name, string $email, string $username, string $bio, string $newPassword, string $newPasswordConfirm): array {
  return MeikieeAccountLifecycle::edit($userId, $name, $email, $username, $bio, $newPassword, $newPasswordConfirm);
}

function pmeikieeAvatarSave(array $user, array $file): array {
  return MeikieeAccountLifecycle::avatarSave($user, $file);
}

function pmeikieeDelete(string $userId, string $username, string $password): array {
  return MeikieeAccountLifecycle::delete($userId, $username, $password);
}

// 引数はユーザー名ではなくid(account.jsonlのid)です。委譲先の
// MeikieeAccountLifecycle::adminDelete()がidで探すため、
// 呼び出し側(APIのアカウント削除・古いアカウントの掃除)もidを渡しています。
function pmeikieeAdminDelete(string $userId): array {
  return MeikieeAccountLifecycle::adminDelete($userId);
}

// ================================================================
// 失敗回数による足止め(MeikieeLoginThrottle)
// ================================================================

/**
 * 「同じ相手が短時間に何度も失敗したら、しばらく受け付けない」という足止めだけを
 * 担当します。何を確かめているか(パスワードなのか本人確認なのか)は一切知りません。
 *
 * 【なぜ認証クラスの中に入れなかったか】名前は"ログイン"試行の制限ですが、実際には
 * 2つの区分から使われています。ログイン認証(MeikieeAuth::authenticate())と、
 * アカウント削除の本人確認(MeikieeAccountLifecycle::delete())です。後者は
 * 「トークンは盗まれたがパスワードは知らない」相手による総当たりを止めるためのもので、
 * 認証とは目的が違います。認証クラスの中へ引き込むと、削除の処理が本来関係の無い
 * 認証クラスを経由することになるため、独立した部品として置いています。
 *
 * 【バケットを分ける約束】$keyは呼び出し側が決めます。用途が違うものは必ず
 * 'delete:' のような接頭辞で名前空間を分けてください。分けないと、例えば
 * 「削除の本人確認に失敗し続けた」せいで「同名ユーザーの通常ログイン」まで
 * 止まる、という無関係な巻き添えが起きます。
 *
 * 【isBlocked()とrecord()の両方を公開している理由】リカバリコード側
 * (MeikieeRecoveryCodes)は照合を1つの関数に閉じられたので足止めもprivateに
 * できましたが、こちらは「確認 → 別の処理 → 記録」と間に別の作業が挟まる
 * 呼び出し方(削除処理)があるため、2つに分けたまま公開しています。
 * **必ず対で使ってください**。isBlocked()を書き忘れると失敗が数えられるだけで
 * 止まらず、record()を書き忘れると永久に数が増えません。
 */
final class MeikieeLoginThrottle {
  /**
   * 記録の見出し。ファイルは平文なので、渡された文字列そのものは書き残しません。
   * 大文字小文字を無視するのは、ユーザー名の大小を変えて打ち直すだけで別バケットへ
   * 逃げられるのを防ぐためです。
   */
  private static function attemptKey(string $key): string {
    return hash('sha256', mb_strtolower($key));
  }

  /** リカバリコード側の記録(MeikieeRecoveryCodes)とは別ファイルです。理由は向こうのコメント参照。 */
  private static function attemptFile(): string {
    return MeikieeSecureStore::pathIn('login_attempts.json');
  }

  public static function isBlocked(string $key): bool {
    $file = self::attemptFile();
    if (!file_exists($file)) { return false; }
    $raw = file_get_contents($file);
    if ($raw === false) { return false; }
    $data = json_decode($raw, true);
    if (!is_array($data)) { return false; }

    $entry = $data[self::attemptKey($key)] ?? null;
    if (!is_array($entry)) { return false; }
    if ((int)($entry['last'] ?? 0) + LOGIN_FAILURE_WINDOW < time()) { return false; }
    return (int)($entry['count'] ?? 0) >= LOGIN_MAX_FAILURES;
  }

  public static function record(string $key, bool $success): void {
    $fp = fopen(self::attemptFile(), 'c+');
    if ($fp === false) { return; }
    flock($fp, LOCK_EX);

    $raw = stream_get_contents($fp);
    $data = ($raw !== false && trim($raw) !== '') ? json_decode($raw, true) : [];
    if (!is_array($data)) { $data = []; }

    $now = time();
    foreach ($data as $k => $v) {
      if (!is_array($v) || (int)($v['last'] ?? 0) + LOGIN_FAILURE_WINDOW < $now) {
        unset($data[$k]);
      }
    }

    $bucket = self::attemptKey($key);
    if ($success) {
      unset($data[$bucket]);
    } else {
      $count = (int)($data[$bucket]['count'] ?? 0);
      $data[$bucket] = ['count' => $count + 1, 'last' => $now];
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
  }
}

// ----------------------------------------------------------------
// 上のクラスへの薄い入口(グローバル関数)
// ----------------------------------------------------------------

function pmeikieeLoginIsBlocked(string $key): bool {
  return MeikieeLoginThrottle::isBlocked($key);
}

function pmeikieeRecordLoginAttempt(string $key, bool $success): void {
  MeikieeLoginThrottle::record($key, $success);
}

// ================================================================
// ログイン診断トレース(目印ファイルが置かれている間だけ動く)
//
// 【なぜ要るか】「特定の端末だけログインできない」という種類の不具合は、
// 利用者から見える文言がどれも同じ(「ユーザー名かパスワードが間違っています」
// 「ボタンが連続で押されたため処理を終了しました」)になるため、報告を聞いても
// 原因が1つに絞れません。しかも手元の端末では再現しないのが普通で、再現を
// 待つこともできません。そこで、ログインが成立するまでに通る分岐を、通った順に
// そのまま書き出して後から読めるようにします。
//
// 【このトレースが答えられること】
//   1. そのブラウザがセッションCookieを持ち回れているか
//      (sessionの印が毎回変わる = Cookieが保存されていない。CSRF不一致の大半はこれ)
//   2. CSRFトークンが送られてきたか・一致したか
//   3. パスワード照合まで到達したのか、その手前で弾かれたのか
//   4. 認証が通った後、他サービスへの引き渡しコードまで辿り着けたか
//
// 【絶対に書かないもの】パスワード・リカバリコード・ログイントークン・生の
// ユーザーID・セッションIDそのもの・IPアドレス。ここは非公開層にあるとはいえ
// 平文のファイルで、調査のために人が開いて読み、時には内容を貼り付けて共有する
// 物です。「後で消せばいい」が効かない種類の情報を、最初から入れないでください。
// 同じブラウザの連続したリクエストを結ぶ印(session)は、セッションIDのSHA-256の
// 先頭12文字です。突き合わせはできても、その値からセッションは復元できません。
// ユーザー名だけは例外的に書きます(どのメイキィの話かが分からないと、報告と
// 突き合わせられないため)。パスワードと違い、本人が他サービスの画面にも
// 出している公開の名前です。
//
// 【1行が単独で読めるようにしてある】端末ごとにファイルを分けていないので、
// 複数人が同時に触ると行は混ざります。どの行にもsessionの印とUAを入れて
// あるのはそのためで、混ざっていてもsessionの印で追えます。
//
// 【原因が分かったらOFFに戻すこと】管理画面(oppai)の「メイキィ ログイン記録」で
// OFFにするか、非公開層の目印ファイル(LOGIN_TRACE_FLAG_NAME)を消します。
// ================================================================

final class MeikieeLoginTrace {
  /** 保存先。認証まわりの他のファイルと同じ非公開層(Web公開ディレクトリの外)。 */
  private static function file(): string {
    return MeikieeSecureStore::pathIn('login_trace.jsonl');
  }

  /** 判定の使い回し先。1リクエストにつき1回だけ目印ファイルの有無を見ます。 */
  private static $decided = null;

  /**
   * 今このリクエストで記録を取るかどうか。
   *
   * 見るのは非公開層(鍵ファイルと同じフォルダ)に置かれた目印ファイルだけです。
   * 共有スクリプト側のSSOトレースと同じファイルを見ているので、どちらか片方だけが
   * ONという状態にはなりません。
   *
   * 【OFFのときは1バイトも書きません】判定はここ1箇所で、log()は先頭でこれを見て
   * 何もせずに帰ります。記録点をいくら増やしても、OFFなら費用はファイルの有無を
   * 1リクエストにつき1回見るだけです。
   */
  private static function enabled(): bool {
    if (self::$decided === null) {
      $hidden = MeikieeSecureStore::hiddenDir();

      if ($hidden === null) {
        // 非公開層そのものが見つからない。書く場所が無いので記録しません。
        self::$decided = false;
      } else {
        self::$decided = is_file($hidden . '/' . LOGIN_TRACE_FLAG_NAME);
      }
    }

    return self::$decided;
  }

  /**
   * 同じブラウザの連続したリクエストを結び付けるための短い印。
   * セッションIDそのものは書きません(書くと、このファイルを読めた者が
   * そのまま他人のセッションを乗っ取れてしまいます)。
   */
  private static function sessionMark(): string {
    $id = session_id();
    return (is_string($id) && $id !== '') ? substr(hash('sha256', $id), 0, 12) : '-';
  }

  /**
   * 上限を超えたら古い方から半分捨てます。
   * 「消し忘れても壊れない」ことを、運用ではなく仕組みで保証するためです。
   */
  private static function rotate(string $file): void {
    // clearstatcache()が要る。PHPは同じリクエストの中でfilesize()の結果を使い回すため、
    // これが無いと1リクエストで何行書いても「最初に見たときのサイズ」のままになり、
    // 上限を超えても回転しない(実際、入れ忘れて回転しないのを確認済み)。
    clearstatcache(true, $file);
    $size = @filesize($file);
    if ($size === false || $size < LOGIN_TRACE_MAX_BYTES) { return; }
    $lines = @file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) { return; }
    $keep = array_slice($lines, (int)floor(count($lines) / 2));
    @file_put_contents($file, implode("\n", $keep) . "\n", LOCK_EX);
  }

  /**
   * 1件記録します。
   *
   * 【何があっても例外を投げないこと】診断のための仕掛けがログインを壊したら
   * 本末転倒です。書けない(非公開層が見つからない・権限が無い)場合は、
   * 黙って何もしないのが正しい振る舞いです。だから@付きで呼んでいます。
   */
  public static function log(string $event, array $facts = []): void {
    if (!self::enabled()) {
      // 記録はOFFです。何も書きません。
    } else {
      $row = array_merge([
        'at'      => date('Y-m-d H:i:s'),
        'event'   => $event,
        'session' => self::sessionMark(),
        'method'  => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
        'ua'      => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
      ], $facts);

      $file = self::file();
      self::rotate($file);
      @file_put_contents(
        $file,
        json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX
      );
    }
  }

  /**
   * 記録が今ONかどうか、記録先はどこか、今どれだけ溜まっているか(管理画面用)。
   * 「目印を置いたのに何も出ない」という迷子を防ぐために公開しています。
   */
  public static function status(): array {
    $hidden = MeikieeSecureStore::hiddenDir();
    $file   = self::file();

    return [
      'enabled'   => self::enabled(),
      'flag_file' => ($hidden === null) ? '(非公開層が見つかりません)' : ($hidden . '/' . LOGIN_TRACE_FLAG_NAME),
      'log_file'  => $file,
      'log_bytes' => is_file($file) ? (int)filesize($file) : 0,
      'max_bytes' => LOGIN_TRACE_MAX_BYTES,
    ];
  }
}

// ================================================================
// 未完了の手続き(obligation)
//
// 「リカバリコードを発行し終えるまで」「リカバリコードで入った後、新しい
// パスワードを設定し終えるまで」は、他へ進ませない強制フローになっている。
// その"やり残し"をどこに記録するかの話。
//
// 【なぜセッションではなくaccount.jsonlに持つか】以前はこれを$_SESSIONだけに
// 持たせていたため、ブラウザを閉じる(=セッションが消える)だけで義務そのものが
// 消滅し、次回ログイン時には何事も無かったかのように使えてしまっていた。
// 強制の根拠は「そのブラウザが今どの画面を開いているか」ではなく「そのアカウントが
// どういう状態か」なので、アカウント側に持たせるのが正しい置き場所。
//
// 【セッション側に残す物と混同しないこと】$_SESSION['recovery_issue_step']・
// ['recovery_pending_codes']は「このブラウザで今ウィザードのどこを開いているか」
// という純粋に一時的な進行状況であり、閉じたら消えてよい(消えても、アカウント側に
// 義務が残っていれば次のログインでまた最初から始まるだけ)。両者を1つのフラグに
// まとめないこと。まとめると、上に書いた「閉じれば消える」問題がそのまま戻る。
//
// 【義務が残っている間に止まる物・止まらない物】止まるのは他サービスへの引き渡し
// (pmeikieeIssueHandoffCode())だけ。p-meikiee自身へのログイン
// (pmeikieeIssueToken/pmeikieeAuthenticate)は絶対に止めない。止めると、義務を
// 果たすための画面にすら入れなくなり、そのアカウントが永久に詰む。
// 認証を行うAPI(?api=create/login/edit/delete)はSSOへの完全移行に伴い削除済みなので、
// 引き渡しを止める場所は上記の1箇所だけで足りる。もしそれらを復活させるなら、
// 同じ判定を必ずそちらにも足すこと(足さないなら復活させないこと)。
// ================================================================

const OBLIGATION_ISSUE_RECOVERY_CODES = 'must_issue_recovery_codes';
const OBLIGATION_RESET_PASSWORD = 'must_reset_password';

function pmeikieeAccountMustIssueRecoveryCodes(array $user): bool {
  return !empty($user[OBLIGATION_ISSUE_RECOVERY_CODES]);
}

function pmeikieeAccountMustResetPassword(array $user): bool {
  return !empty($user[OBLIGATION_RESET_PASSWORD]);
}

/** 未完了の手続きが1つでも残っているか。他サービスへの引き渡しを止める判定はこれ1つで行う。 */
function pmeikieeAccountHasPendingObligation(array $user): bool {
  return pmeikieeAccountMustIssueRecoveryCodes($user) || pmeikieeAccountMustResetPassword($user);
}

/**
 * 義務を立てる/降ろす。
 *
 * 降ろす側は、原則としてこの関数を呼ぶのではなく「義務を果たす処理」自身が
 * 同じトランザクションの中でunset()すること(pmeikieeSaveRecoveryCodes()・
 * pmeikieeEdit()参照)。別々の書き込みに分けると、片方だけ成功して
 * 「果たしたのに義務が残る」「果たしていないのに義務が消える」という食い違いが
 * 生まれる余地ができる。
 */
function pmeikieeSetAccountObligation(string $userId, string $obligation, bool $on): bool {
  $status = pmeikieeTransaction(function (array &$accounts) use ($userId, $obligation, $on): bool {
    $idx = pmeikieeFindIndexById($accounts, $userId);
    if ($idx === null) { return false; }
    if ($on) {
      if (!empty($accounts[$idx][$obligation])) { return false; } // 既に立っている=書き込み不要
      $accounts[$idx][$obligation] = true;
      return true;
    }
    if (!isset($accounts[$idx][$obligation])) { return false; } // 元から無い=書き込み不要
    unset($accounts[$idx][$obligation]);
    return true;
  });
  return $status !== null;
}

// ================================================================
// リカバリコード
//
// このサービスにはパスワード再設定用のメールが無いため、パスワードを忘れた
// 場合の唯一の自己復旧手段です。発行はRECOVERY_CODE_COUNT本・使い捨て
// (使ったら該当ハッシュを削除)。総当たり対策は通常ログインより厳しい
// RECOVERY_MAX_ATTEMPTSで別カウントします。
// ================================================================

/**
 * リカバリコードの生成・確定・照合と、その総当たり対策を1つにまとめたもの。
 *
 * 【総当たり対策をクラスの中へ入れた理由】照合は必ず「先に停止中かどうかを確かめ、
 * 終わったら成否を記録する」の間に挟まっていなければ意味がありません。この3つが
 * バラバラのグローバル関数だった頃は、照合だけを呼ぶコードをいくらでも書けたため、
 * 書いた本人が気づかないまま「回数制限を通らないリカバリ照合口」が増える余地が
 * ありました。停止判定・記録・見出し作り・記録先をすべてprivateにし、外から
 * 呼べるのをverify()だけにすることで、その道そのものを構造的に塞いでいます。
 * 新しい照合口が要るようになっても、外に出さずこのクラスの中に足してください。
 *
 * 【回数の記録先をaccount.jsonlにしない理由】停止判定は「まだ誰か分からない相手」に
 * 対して行うものなので、アカウント台帳を開かずに済ませたい(存在しないユーザー名で
 * 叩かれるたびに全件復号が走るのを避ける)からです。そのため回数だけは平文の別ファイルに
 * 持ちます。中身はユーザー名のハッシュと回数・時刻だけで、漏れて困る物は入れないこと。
 */
final class MeikieeRecoveryCodes {
  /**
   * 回数記録の見出し。ファイルが平文なので、ユーザー名そのものは書き残しません。
   * 大文字小文字を無視するのは通常ログイン側の記録(MeikieeLoginThrottle)と
   * 揃えるためです。ここだけ揃っていないと、大文字に変えて打ち直すだけで
   * 回数制限をすり抜けられます。
   */
  private static function attemptKey(string $username): string {
    return hash('sha256', mb_strtolower($username));
  }

  /**
   * 通常ログインの試行記録(MeikieeLoginThrottle)とは**別のファイル**にします。
   * 同じファイルに相乗りさせると、リカバリの厳しい上限(RECOVERY_MAX_ATTEMPTS)と
   * ログインの緩い上限(LOGIN_MAX_FAILURES)が1つのカウンタを取り合い、どちらの
   * 意図とも違う止まり方をします。
   */
  private static function attemptFile(): string {
    return (accountsHiddenStorageDir() ?? sys_get_temp_dir()) . '/recovery_attempts.json';
  }

  private static function isBlocked(string $username): bool {
    $file = self::attemptFile();
    if (!file_exists($file)) { return false; }
    $raw = file_get_contents($file);
    if ($raw === false) { return false; }
    $data = json_decode($raw, true);
    if (!is_array($data)) { return false; }

    $entry = $data[self::attemptKey($username)] ?? null;
    if (!is_array($entry)) { return false; }
    if ((int)($entry['last'] ?? 0) + RECOVERY_ATTEMPT_WINDOW < time()) { return false; }
    return (int)($entry['count'] ?? 0) >= RECOVERY_MAX_ATTEMPTS;
  }

  private static function recordAttempt(string $username, bool $success): void {
    $fp = fopen(self::attemptFile(), 'c+');
    if ($fp === false) { return; }
    flock($fp, LOCK_EX);

    $raw = stream_get_contents($fp);
    $data = ($raw !== false && trim($raw) !== '') ? json_decode($raw, true) : [];
    if (!is_array($data)) { $data = []; }

    $now = time();
    foreach ($data as $k => $v) {
      if (!is_array($v) || (int)($v['last'] ?? 0) + RECOVERY_ATTEMPT_WINDOW < $now) {
        unset($data[$k]);
      }
    }

    $key = self::attemptKey($username);
    if ($success) {
      unset($data[$key]);
    } else {
      $count = (int)($data[$key]['count'] ?? 0);
      $data[$key] = ['count' => $count + 1, 'last' => $now];
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
  }

  /** そのユーザーの、現在有効なリカバリコードの残数。 */
  public static function remaining(array $user): int {
    $codes = $user['recovery_codes'] ?? [];
    return is_array($codes) ? count($codes) : 0;
  }

  /**
   * RECOVERY_CODE_COUNT本ぶんの平文コードを新規生成します(まだどこにも保存しません、
   * 呼び出し側がセッションに一時保持し、転記確認が済んでからsave()で確定させる
   * 想定です)。各コードはRECOVERY_CODE_ALPHABETからのランダムな文字を
   * RECOVERY_CODE_GROUP_LENGTH文字ずつRECOVERY_CODE_GROUP_COUNTグループ、ハイフンで
   * 繋いだものです(例: "3K7HD-9WGDX-M2P4R-TQ8YZ-6NB3F")。random_int()はCSPRNGです。
   */
  public static function generate(): array {
    $codes = [];
    $alphabetLength = strlen(RECOVERY_CODE_ALPHABET);
    for ($i = 0; $i < RECOVERY_CODE_COUNT; $i++) {
      $groups = [];
      for ($g = 0; $g < RECOVERY_CODE_GROUP_COUNT; $g++) {
        $chars = '';
        for ($c = 0; $c < RECOVERY_CODE_GROUP_LENGTH; $c++) {
          $chars .= RECOVERY_CODE_ALPHABET[random_int(0, $alphabetLength - 1)];
        }
        $groups[] = $chars;
      }
      $codes[] = implode('-', $groups);
    }
    return $codes;
  }

  /**
   * 発行/再発行を確定させます(転記確認が済んだ後に呼ぶこと)。パスワードと同じく
   * password_hash()でハッシュ化して保存するため、保存後は運営側も元のコードを
   * 知ることはできません。既存のコードがあれば全て入れ替えます(新旧混在させない)。
   * 同意ログ(いつ・どの文言バージョンに同意したか)は削除せず追記していきます
   * (「聞いていない」対策)。
   */
  public static function save(string $userId, array $plainCodes): bool {
    $hashed = array_map(static fn($code) => password_hash($code, PASSWORD_DEFAULT), $plainCodes);
    $now = time();
    $status = pmeikieeTransaction(function (array &$accounts) use ($userId, $hashed, $now): bool {
      $idx = pmeikieeFindIndexById($accounts, $userId);
      if ($idx === null) { return false; }
      $accounts[$idx]['recovery_codes'] = $hashed;
      $accounts[$idx]['recovery_codes_issued_at'] = $now;
      // 発行が確定した=「リカバリコードを発行せよ」という義務は果たされた。
      // 別の書き込みに分けず、必ずこの同じトランザクションで降ろす
      // (分けると、保存だけ成功して義務が残る・義務だけ消えて保存が失敗する、
      //  という食い違いが起こりうる)。
      unset($accounts[$idx][OBLIGATION_ISSUE_RECOVERY_CODES]);
      if (!isset($accounts[$idx]['recovery_codes_consent_log']) || !is_array($accounts[$idx]['recovery_codes_consent_log'])) {
        $accounts[$idx]['recovery_codes_consent_log'] = [];
      }
      $accounts[$idx]['recovery_codes_consent_log'][] = ['at' => $now, 'version' => RECOVERY_CODE_CONSENT_VERSION];
      return true;
    });
    return $status === true;
  }

  /**
   * リカバリコードでのログインを試みます。一致したコードは即座にそのハッシュを
   * 削除し(使い捨て)、コード自体の痕跡は残しません。その代わり「使用された」
   * というイベントだけを監査ログ(recovery_codes_usage_log)へ追記します
   * (削除しない・日時とIPのみ)。
   *
   * 【この3段構えを崩さないこと】(1)先にisBlocked()で止まっているかを見る →
   * (2)照合する → (3)結果に関わらずrecordAttempt()で成否を残す。どれか1つでも
   * 抜けると、失敗が数えられないまま総当たりが通ってしまうか、逆に成功しても
   * カウンタが減らず本人が締め出されます。
   *
   * 戻り値: ['ok'=>bool, 'error'=>?, 'message'=>string, 'user'=>?array, 'remaining'=>?int]
   */
  public static function verify(string $username, string $code): array {
    $username = trim($username);
    // コードは大文字のRECOVERY_CODE_ALPHABETで生成されるが、入力は小文字でも
    // 通るように正規化する(見た目は同じでも打ち間違い扱いになるのを防ぐ)。
    $code = strtoupper(trim($code));
    if ($username === '' || $code === '') {
      return ['ok' => false, 'error' => 'invalid_input', 'message' => 'ユーザー名とリカバリコードは必須です。'];
    }
    if (self::isBlocked($username)) {
      return ['ok' => false, 'error' => 'too_many_attempts', 'message' => 'リカバリコードの入力に複数回失敗したため、しばらくの間停止しています。15分ほど待ってからもう一度お試しください。'];
    }

    $ip = pmeikieeClientIp();
    $now = time();
    $matched = false;
    $matchedUserId = null;
    $remaining = null;

    $status = pmeikieeTransaction(function (array &$accounts) use ($username, $code, $ip, $now, &$matched, &$matchedUserId, &$remaining): bool {
      $idx = pmeikieeFindIndexByUsername($accounts, $username);
      $codes = ($idx !== null && is_array($accounts[$idx]['recovery_codes'] ?? null)) ? $accounts[$idx]['recovery_codes'] : [];

      $foundAt = null;
      foreach ($codes as $i => $hash) {
        if (is_string($hash) && password_verify($code, $hash)) {
          $foundAt = $i;
          break;
        }
      }
      if ($idx === null || $foundAt === null) {
        // ユーザーが存在しない/一致しない場合も、比較と同程度の時間をかけます
        // (ログイン認証と同じ、応答時間からの情報漏れ対策)。
        password_verify($code, '$2y$10$recoveryCodeDummyValueForTimingXXXXXXXXXXXXXXXXXXXXXX');
        return false;
      }

      unset($codes[$foundAt]);
      $accounts[$idx]['recovery_codes'] = array_values($codes);
      $remaining = count($accounts[$idx]['recovery_codes']);
      if (!isset($accounts[$idx]['recovery_codes_usage_log']) || !is_array($accounts[$idx]['recovery_codes_usage_log'])) {
        $accounts[$idx]['recovery_codes_usage_log'] = [];
      }
      $accounts[$idx]['recovery_codes_usage_log'][] = ['at' => $now, 'ip' => $ip];
      // リカバリコードを1本消費した=パスワードを忘れている状態なので、続けて
      // パスワード再設定を義務づける。コードの消費と同じ書き込みで立てることで、
      // この直後にリクエストが落ちても「コードだけ消えて義務は無い」状態には
      // ならない。
      $accounts[$idx][OBLIGATION_RESET_PASSWORD] = true;
      $matched = true;
      $matchedUserId = (string)$accounts[$idx]['id'];
      return true;
    });

    if ($status === null) {
      return ['ok' => false, 'error' => 'storage_error', 'message' => '保存中にエラーが発生しました。しばらくしてから再度お試しください。'];
    }
    if (!$matched) {
      self::recordAttempt($username, false);
      return ['ok' => false, 'error' => 'invalid_code', 'message' => 'ユーザー名かリカバリコードが正しくないか、既に使用済みです。'];
    }

    self::recordAttempt($username, true);
    return ['ok' => true, 'user' => pmeikieeFindById($matchedUserId), 'remaining' => $remaining, 'message' => 'リカバリコードを確認しました。'];
  }
}

// ----------------------------------------------------------------
// 上のクラスへの薄い入口(グローバル関数)
//
// 画面(設定画面のリカバリ節・発行ウィザード・リカバリログイン)から呼ばれている
// ものだけです。総当たり対策の一式(停止判定・回数記録)は意図的に外へ出していません。
// 新しく書くコードでは MeikieeRecoveryCodes::… を直接呼んでください。
// ----------------------------------------------------------------

function pmeikieeRecoveryCodesRemaining(array $user): int {
  return MeikieeRecoveryCodes::remaining($user);
}

function pmeikieeGenerateRecoveryCodes(): array {
  return MeikieeRecoveryCodes::generate();
}

function pmeikieeSaveRecoveryCodes(string $userId, array $plainCodes): bool {
  return MeikieeRecoveryCodes::save($userId, $plainCodes);
}

function pmeikieeVerifyRecoveryCode(string $username, string $code): array {
  return MeikieeRecoveryCodes::verify($username, $code);
}

// ================================================================
// 認証・ログイントークン・引き渡し(MeikieeAuth)
// ================================================================

/**
 * 「この人は本人か」を確かめ、その結果として発行される持ち物(ログイントークン・
 * SSOの引き渡しコード・管理者発行の一時ログインリンク)をすべて扱います。
 *
 * 【3種類の使い切り物を1つのクラスに置いた理由】どれも「発行 → 保存 → 使ったら
 * 即失効」という同じ形をしており、しかも互いに繋がっています。引き渡しコードの
 * 交換(exchangeHandoffCode)も、管理者の一時ログイン(consumeAdminTempLogin)も、
 * 最後は必ずissueToken()を呼んでログイントークンに化けます。別々のクラスに分けると、
 * 「使い切り」の作法を1箇所直したときに他の2つを直し忘れる余地ができます。
 *
 * 【保存先を3つに分けている理由】sessions.jsonl(長寿命のログイントークン)、
 * handoff_codes.jsonl(60秒の引き渡しコード)、admin_temp_logins.jsonl(15分の
 * 管理者リンク)。寿命も、漏れた時の被害も、掃除の頻度も違うため、同じファイルに
 * 混ぜません。混ぜると、短命な物の掃除のたびに長寿命の行まで読み書きすることになり、
 * 事故ったときの巻き添えも大きくなります。この3つのパスはprivateにしてあります。
 *
 * 【生の値は絶対に保存しない】トークンもコードも、保存するのは必ず
 * hash('sha256', …) の結果だけです。ファイルが読まれても、そこからログインできる
 * 値は復元できません。照合はhash_equals()で行います(==で比べると、一致する
 * 文字数によって処理時間が変わり、そこから正解を1文字ずつ探れてしまいます)。
 */
final class MeikieeAuth {
  // ----------------------------------------------------------------
  // 保存先(このクラスの外からは触らせない)
  // ----------------------------------------------------------------

  private static function sessionFile(): string {
    return MeikieeSecureStore::pathIn('sessions.jsonl');
  }

  private static function codeFile(): string {
    return MeikieeSecureStore::pathIn('handoff_codes.jsonl');
  }

  /** oppai管理パネル発行の一時ログインリンク(admin_issue_temp_login/temp_login)の保存先。 */
  private static function adminTempLoginFile(): string {
    return MeikieeSecureStore::pathIn('admin_temp_logins.jsonl');
  }

  /**
   * 最終ログイン日時(last_login)を更新します。
   *
   * 【issueToken()からしか呼ばないこと(だからprivate)】「実際にログイン状態に
   * なった」瞬間は、通常ログイン・SSOの引き渡し・管理者発行の一時ログインの
   * どれであっても必ずissueToken()を通ります。そこ1箇所に置くことで、経路が
   * 増えても書き忘れが起きません。以前のように呼び出し側それぞれに書く形にすると、
   * 経路を1つ足すたびに「その経路だけlast_loginが更新されない」が起こります。
   *
   * 「1年間ログインが無いアカウントを把握・整理したい」というoppai管理パネル側の
   * 要望に対応するためのものです。account.jsonlは1件書き換えるだけでも全件を
   * 再暗号化する作りのため、トークン発行のたびに毎回書き込むと、複数サービスを
   * 行き来するアクティブユーザーほど頻繁に全件再暗号化が走ってしまいます。
   * そのため直近1時間以内に更新済みなら書き込みをスキップします
   * (「最終ログイン」の粒度としては1時間で十分なため)。
   *
   * 自動削除の実行はここでは行いません(トークン発行の都度、削除まで判定するのは
   * 責務が違いすぎるため)。last_loginを起点にした実際の自動削除は
   * pmeikieeSweepStaleAccounts()が1日1回まとめて行います。詳しくは
   * DORMANT_ACCOUNT_DAYSの定義コメントを参照。
   */
  private static function touchLastLogin(string $userId): void {
    $now = time();
    pmeikieeTransaction(function (array &$accounts) use ($userId, $now): bool {
      $idx = pmeikieeFindIndexById($accounts, $userId);
      if ($idx === null) { return false; }
      $lastRecorded = (int)($accounts[$idx]['last_login'] ?? 0);
      if ($now - $lastRecorded < 3600) { return false; } // 直近1時間以内なら書き込み不要
      $accounts[$idx]['last_login'] = $now;
      return true;
    });
  }

  // ----------------------------------------------------------------
  // パスワードによる認証
  // ----------------------------------------------------------------

  /**
   * 戻り値: ['ok'=>bool,'error'=>?,'message'=>?,'token'=>?,'user'=>?]
   *
   * 足止め(MeikieeLoginThrottle)は必ず「先に確認 → 終わったら成否を記録」の
   * 対で使います。どちらかを外すと、失敗が数えられないまま総当たりが通るか、
   * 成功してもカウンタが減らず本人が締め出されます。
   */
  public static function authenticate(string $username, string $password): array {
    $username = trim($username);

    if ($username === '' || $password === '') {
      return ['ok' => false, 'error' => 'invalid_input', 'message' => 'ユーザー名とパスワードは必須です。'];
    }
    if (MeikieeLoginThrottle::isBlocked($username)) {
      return ['ok' => false, 'error' => 'too_many_attempts', 'message' => 'ログインの失敗が続いたため、しばらくの間このユーザー名でのログインを停止しています。15分ほど待ってからもう一度お試しください。'];
    }

    $user = pmeikieeFindByUsername($username);
    if ($user === null) {
      // account.jsonl 内では htmlspecialchars() 済みの形で保存されている場合があるため、
      // そちらでも探します(記号を含むユーザー名対策)。
      $escaped = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
      if ($escaped !== $username) {
        $user = pmeikieeFindByUsername($escaped);
      }
    }

    // ユーザーが存在しない場合もハッシュ照合と同程度の時間をかけ、応答時間の差から
    // 「そのユーザー名は存在する/しない」が分かってしまわないようにします。
    $hash = $user !== null ? (string)($user['password'] ?? '') : '$2y$10$usernameDoesNotExistDummyValueForTimingXXXXXXXXXXXXXXXXXXXXXX';
    $verified = password_verify($password, $hash);

    if ($user === null || !$verified) {
      MeikieeLoginThrottle::record($username, false);
      return ['ok' => false, 'error' => 'invalid_credentials', 'message' => 'ユーザー名かパスワードが間違っています。'];
    }

    MeikieeLoginThrottle::record($username, true);
    $token = self::issueToken((string)$user['id']);
    if ($token === null) {
      return ['ok' => false, 'error' => 'storage_error', 'message' => 'ログイン情報の保存に失敗しました。しばらくしてからもう一度お試しください。'];
    }

    return ['ok' => true, 'token' => $token, 'user' => pmeikieePublicUser($user, true)];
  }

  // ----------------------------------------------------------------
  // ログイントークン
  // ----------------------------------------------------------------

  public static function issueToken(string $userId): ?string {
    $token = bin2hex(random_bytes(32));
    $now = time();

    $status = accountsTransactJsonl(self::sessionFile(), function (array &$rows) use ($token, $userId, $now): bool {
      $rows = array_values(array_filter($rows, static fn($r) => is_array($r) && (int)($r['expires'] ?? 0) > $now));
      $rows[] = [
        'token'   => hash('sha256', $token), // 生トークンは保存しません
        'user_id' => $userId,
        'created' => $now,
        'expires' => $now + SESSION_TTL,
      ];
      return true;
    });

    if ($status === true) {
      // 新しいトークンが発行された = 実際にログイン状態になった、という共通の合流点。
      // login/SSOハンドオフ/管理者発行の一時ログインのどの経路でもここを必ず通る。
      self::touchLastLogin($userId);
    }

    return $status === true ? $token : null;
  }

  /** トークンからメイキィ(生データ)を引きます。有効なら期限をスライド延長します。 */
  public static function resolveToken(string $token): ?array {
    if ($token === '') { return null; }

    $hash = hash('sha256', $token);
    $now = time();
    $userId = null;

    accountsTransactJsonl(self::sessionFile(), function (array &$rows) use ($hash, $now, &$userId): bool {
      $changed = false;
      foreach ($rows as $i => $row) {
        if (!is_array($row) || !isset($row['token'])) { continue; }
        if (!hash_equals((string)$row['token'], $hash)) { continue; }
        if ((int)($row['expires'] ?? 0) <= $now) { break; }

        $userId = (string)($row['user_id'] ?? '');
        if ((int)$row['expires'] - $now < SESSION_TTL - 3600) {
          $rows[$i]['expires'] = $now + SESSION_TTL;
          $changed = true;
        }
        break;
      }
      return $changed;
    });

    if ($userId === null || $userId === '') { return null; }
    return pmeikieeFindById($userId);
  }

  public static function revokeToken(string $token): void {
    if ($token === '') { return; }
    $hash = hash('sha256', $token);

    accountsTransactJsonl(self::sessionFile(), function (array &$rows) use ($hash): bool {
      $before = count($rows);
      $rows = array_values(array_filter(
        $rows,
        static fn($r) => !(is_array($r) && isset($r['token']) && hash_equals((string)$r['token'], $hash))
      ));
      return count($rows) !== $before;
    });
  }

  /**
   * パスワード変更時に使います。同じメイキィの他のログイントークン(他端末・
   * 盗まれた可能性のあるトークンなど)をすべて失効させ、$keepTokenで指定した
   * 今回のセッションのトークンだけを残します。これが無いと、パスワードを
   * 変更しても既に発行済みのトークンはSESSION_TTL(30日)が切れるまで有効な
   * ままになり、パスワード変更の意味が薄れてしまいます。
   */
  public static function revokeOtherTokens(string $userId, string $keepToken): void {
    $keepHash = $keepToken !== '' ? hash('sha256', $keepToken) : null;

    accountsTransactJsonl(self::sessionFile(), function (array &$rows) use ($userId, $keepHash): bool {
      $before = count($rows);
      $rows = array_values(array_filter($rows, static function ($r) use ($userId, $keepHash): bool {
        if (!is_array($r) || ($r['user_id'] ?? null) !== $userId) { return true; }
        return $keepHash !== null && isset($r['token']) && hash_equals((string)$r['token'], $keepHash);
      }));
      return count($rows) !== $before;
    });
  }

  // ----------------------------------------------------------------
  // SSO用の一時コード(認可コード方式)
  //
  // 共通ログイン画面からブラウザ経由でリダイレクトし返すのは、この使い切りコードだけです。
  // 長寿命のログイントークンをURL(=ブラウザ履歴やRefererに残る場所)に載せないための工夫です。
  // 呼び出し側サービスは、このコードをサーバー間通信(?api=exchange_code)で本物の
  // トークンに交換します。
  // ----------------------------------------------------------------

  public static function issueHandoffCode(string $userId): ?string {
    // 【強制フローの最終防波堤】未完了の手続きが残っているアカウントには、
    // 他サービスへの引き渡しコードを絶対に発行しない。
    //
    // ここに置く理由: 引き渡しの経路は「ログイン成功時」「無音SSO
    // (?account=login&silent=1)」「"ログイン済みです"画面の戻るボタン」「編集完了後の
    // 戻り」と複数あるが、全てが必ずこの関数を通る唯一の合流点になっている。
    // 個々の呼び出し側に判定を書き足す形にすると、経路が1つ増えるたびに書き忘れが
    // 起きる(実際、無音SSOだけが画面側のゲートより手前でexitしており、作成直後の
    // ユーザーがリカバリコード未発行のまま他サービスへ入れてしまっていた)。
    // 呼び出し側は元々「発行失敗=null」を扱えるようになっているので、追加の対応は要らない。
    $user = pmeikieeFindById($userId);
    if ($user === null || pmeikieeAccountHasPendingObligation($user)) { return null; }

    $code = bin2hex(random_bytes(24));
    $now = time();

    $status = accountsTransactJsonl(self::codeFile(), function (array &$rows) use ($code, $userId, $now): bool {
      $rows = array_values(array_filter($rows, static fn($r) => is_array($r) && (int)($r['expires'] ?? 0) > $now));
      $rows[] = [
        'code'    => hash('sha256', $code),
        'user_id' => $userId,
        'expires' => $now + HANDOFF_CODE_TTL,
      ];
      return true;
    });

    return $status === true ? $code : null;
  }

  /** コードを1回だけ本物のトークンに交換します(使用後は即失効)。 */
  public static function exchangeHandoffCode(string $code): ?array {
    if ($code === '') { return null; }
    $hash = hash('sha256', $code);
    $now = time();
    $userId = null;

    accountsTransactJsonl(self::codeFile(), function (array &$rows) use ($hash, $now, &$userId): bool {
      $before = count($rows);
      foreach ($rows as $row) {
        if (is_array($row) && isset($row['code']) && hash_equals((string)$row['code'], $hash) && (int)($row['expires'] ?? 0) > $now) {
          $userId = (string)($row['user_id'] ?? '');
        }
      }
      // 使い切り: マッチしたものも期限切れのものも、このタイミングで全部取り除きます。
      $rows = array_values(array_filter($rows, static fn($r) => is_array($r) && isset($r['code']) && !hash_equals((string)$r['code'], $hash) && (int)($r['expires'] ?? 0) > $now));
      return count($rows) !== $before;
    });

    if ($userId === null || $userId === '') { return null; }
    $user = pmeikieeFindById($userId);
    if ($user === null) { return null; }

    $token = self::issueToken($userId);
    if ($token === null) { return null; }

    return ['token' => $token, 'user' => pmeikieePublicUser($user, true)];
  }

  // ----------------------------------------------------------------
  // 管理者(oppai管理パネル)専用: 一時ログインリンク
  //
  // サポート対応(パスワードを忘れた等)向けに、oppai側の管理者がユーザー名だけを
  // 指定して発行する使い切りのログインリンクです。handoff_codes.jsonlと同じ
  // 「使ったら即失効・期限切れも同時に掃除」という設計をそのまま踏襲しますが、
  // 別の用途(こちらはブラウザへ直接張られるリンクそのもの)なので保存先は分けています。
  // ----------------------------------------------------------------

  public static function issueAdminTempLogin(string $userId): ?string {
    $token = bin2hex(random_bytes(32));
    $now = time();

    $status = accountsTransactJsonl(self::adminTempLoginFile(), function (array &$rows) use ($token, $userId, $now): bool {
      $rows = array_values(array_filter($rows, static fn($r) => is_array($r) && (int)($r['expires'] ?? 0) > $now));
      $rows[] = [
        'token'   => hash('sha256', $token), // 生トークンは保存しません(sessions.jsonl等と同じ方針)
        'user_id' => $userId,
        'expires' => $now + ADMIN_TEMP_LOGIN_TTL,
      ];
      return true;
    });

    return $status === true ? $token : null;
  }

  /** 一時ログインリンクのトークンを1回だけ検証し、対応するユーザーIDを返します(使用後は即失効)。無効ならnull。 */
  public static function consumeAdminTempLogin(string $token): ?string {
    if ($token === '') { return null; }
    $hash = hash('sha256', $token);
    $now = time();
    $userId = null;

    accountsTransactJsonl(self::adminTempLoginFile(), function (array &$rows) use ($hash, $now, &$userId): bool {
      $before = count($rows);
      foreach ($rows as $row) {
        if (is_array($row) && isset($row['token']) && hash_equals((string)$row['token'], $hash) && (int)($row['expires'] ?? 0) > $now) {
          $userId = (string)($row['user_id'] ?? '');
        }
      }
      // 使い切り: マッチしたものも期限切れのものも、このタイミングで全部取り除きます。
      $rows = array_values(array_filter($rows, static fn($r) => is_array($r) && isset($r['token']) && !hash_equals((string)$r['token'], $hash) && (int)($r['expires'] ?? 0) > $now));
      return count($rows) !== $before;
    });

    return ($userId === null || $userId === '') ? null : $userId;
  }
}

// ----------------------------------------------------------------
// 上のクラスへの薄い入口(グローバル関数)
//
// 画面(ログイン・ログアウト・SSOの受け口・管理者リンクの着地)とAPI振り分けから
// 呼ばれているものだけです。保存先3つと最終ログインの更新は外へ出していません。
// 新しく書くコードでは MeikieeAuth::… を直接呼んでください。
// ----------------------------------------------------------------

function pmeikieeAuthenticate(string $username, string $password): array {
  return MeikieeAuth::authenticate($username, $password);
}

function pmeikieeIssueToken(string $userId): ?string {
  return MeikieeAuth::issueToken($userId);
}

function pmeikieeResolveToken(string $token): ?array {
  return MeikieeAuth::resolveToken($token);
}

function pmeikieeRevokeToken(string $token): void {
  MeikieeAuth::revokeToken($token);
}

function pmeikieeRevokeOtherTokens(string $userId, string $keepToken): void {
  MeikieeAuth::revokeOtherTokens($userId, $keepToken);
}

function pmeikieeIssueHandoffCode(string $userId): ?string {
  return MeikieeAuth::issueHandoffCode($userId);
}

function pmeikieeExchangeHandoffCode(string $code): ?array {
  return MeikieeAuth::exchangeHandoffCode($code);
}

function pmeikieeIssueAdminTempLogin(string $userId): ?string {
  return MeikieeAuth::issueAdminTempLogin($userId);
}

function pmeikieeConsumeAdminTempLogin(string $token): ?string {
  return MeikieeAuth::consumeAdminTempLogin($token);
}

// ================================================================
// フォロー・お気に入りなど「サービス固有の機能」について
//
// 以前はここに follow/unfollow/like/unlike というPIPS固有の概念を前提にした
// 関数を持たせていましたが、accountsサービス自体は「誰が誰をフォローしているか」
// のような意味を理解する必要が無いはずなので廃止しました。
// 今は上の「汎用データストア(userData)」だけを提供し、フォロー・お気に入りの
// ような機能固有のロジック(自分自身はフォローできない、対象ユーザーが存在するか
// 等の検証)は、呼び出し側のサービス(pipsなど)が
// data_get / data_set / data_list_add / data_list_remove / data_list_count /
// data_reverse_count を組み合わせて実装します。accountsは「service名+key名を
// 指定してもらえば、その中身が何であるかを知らなくても保存・集計できる」
// という立場に徹しています。
// ================================================================

// ================================================================
// APIモード (?api=<action>)
//
// 他サービスのサーバから叩かれる内部APIです。ループバック限定・JSON応答。
// このブロックの中で必ず exit するので、この先のUIモードの処理には進みません。
// ================================================================

function pmeikieeApiRespond(bool $ok, array $payload = [], int $status = 200): void {
  http_response_code($status);
  echo json_encode(array_merge(['ok' => $ok], $payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function pmeikieeApiFail(string $error, string $message, int $status = 400): void {
  pmeikieeApiRespond(false, ['error' => $error, 'message' => $message], $status);
}

function pmeikieeApiInputStr(array $input, string $key, string $default = ''): string {
  $value = $input[$key] ?? $default;
  return is_string($value) ? $value : $default;
}

/** トークン必須のアクション用。無効ならその場で401を返して終了します。 */
function pmeikieeApiRequireUser(string $token): array {
  $user = pmeikieeResolveToken($token);
  if ($user === null) {
    pmeikieeApiFail('unauthorized', 'ログインの有効期限が切れています。もう一度ログインしてください。', 401);
  }
  return $user;
}

function pmeikieeDispatchApi(string $action): void {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');

  $input = $_POST;
  if (empty($input)) {
    $rawBody = file_get_contents('php://input');
    if ($rawBody !== false && trim($rawBody) !== '') {
      $decoded = json_decode($rawBody, true);
      if (is_array($decoded)) { $input = $decoded; }
    }
  }

  $key = accountsEncryptionKey();
  if ($key === null) {
    pmeikieeApiFail('server_misconfigured', 'サーバ側の設定に問題があります。管理者にご連絡ください。', 500);
  }
  $expected = hash_hmac('sha256', 'pusyuu_accounts_api', $key);
  if (!hash_equals($expected, pmeikieeApiInputStr($input, 'api_secret'))) {
    error_log('[Accounts API] 合言葉が一致しないリクエストを拒否しました。addr=' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    pmeikieeApiFail('forbidden', 'このAPIは合言葉(api_secret)が必要です。', 403);
  }

  switch ($action) {

    // 設置確認用
    case 'selftest': {
      $keyFile = accountsFindUpward(KEY_FILE_CANDIDATES, __DIR__);
      $accounts = pmeikieeReadAll();
      pmeikieeApiRespond(true, [
        'key_file'          => $keyFile ?? '(見つかりません)',
        'key_loaded'        => accountsEncryptionKey() !== null,
        'account_file'      => ACCOUNT_FILE,
        'account_readable'  => file_exists(ACCOUNT_FILE) && is_readable(ACCOUNT_FILE),
        'account_count'     => count($accounts),
        'hidden_dir'        => accountsHiddenDir() ?? '(見つかりません。鍵ファイルが読めないと非公開ストレージも使えません)',
        'storage_writable'  => (function () { $d = accountsHiddenStorageDir(); return $d !== null && is_writable($d); })(),
        'php_version'       => PHP_VERSION,
        'secret_fingerprint'=> accountsEncryptionKey() !== null
          ? substr(hash_hmac('sha256', 'pusyuu_accounts_api', accountsEncryptionKey()), 0, 8)
          : '(キーが読めません)',
      ]);
    }

    // 【削除済み: create / login / edit / delete】
    // 以前は「各サービスが自前のフォームを持ち、サーバ間通信でここを叩く」という
    // 方式(仕様書の旧パターンA)のために存在したが、アカウントの作成・ログイン・
    // 編集・削除はすべてp-meikiee自身の画面で行う方式(SSO)へ完全移行したため、
    // 呼び出し元が1つも無い死んだ入口になっていた。
    //
    // 【復活させないこと】これらは単に使われていなかっただけでなく、この
    // ファイルが持つ強制フローの前提を壊す穴でもあった:
    //   - create: 画面を通らずにアカウントを作れるため、リカバリコード発行義務
    //     (OBLIGATION_ISSUE_RECOVERY_CODES)が付かないアカウントが生まれた。
    //   - login: 未完了の手続きが残っていても、他サービスへログインできてしまい、
    //     pmeikieeIssueHandoffCode()で引き渡しを止めている意味が無くなっていた。
    //   - edit: パスワード再設定の強制中に、画面側の制限(パスワード以外の項目を
    //     現在値で固定する)を迂回してユーザー名・メールを書き換えられた。
    // 新しいサービスを繋ぐときも自前フォームは作らず、?account=login&return_to=...
    // (必要なら silent=1)へ誘導し、?api=exchange_code でトークンを受け取ること。
    // 詳しくは ACCOUNTS_INTEGRATION_SPEC.md の3節を参照。

    case 'logout': {
      pmeikieeRevokeToken(pmeikieeApiInputStr($input, 'token'));
      pmeikieeApiRespond(true, []);
    }

    // トークンが生きているかの確認と、表示用のユーザー情報(簡易版)の取得。
    case 'session': {
      $user = pmeikieeResolveToken(pmeikieeApiInputStr($input, 'token'));
      pmeikieeApiRespond(true, ['user' => $user === null ? null : pmeikieePublicUser($user)]);
    }

    // ログイン中の自分自身の詳細情報(email・userData丸ごとを含む)。
    case 'me': {
      $user = pmeikieeResolveToken(pmeikieeApiInputStr($input, 'token'));
      pmeikieeApiRespond(true, ['user' => $user === null ? null : pmeikieePublicUser($user, true)]);
    }

    // 公開プロフィール(ユーザー名から検索)。userid(ハッシュ)/username/nameだけを返します。
    // フォロー数・お気に入りの有無などサービス固有の情報は、呼び出し側が
    // data_get/data_list_count/data_reverse_count で個別に取得してください。
    case 'profile': {
      $username = pmeikieeApiInputStr($input, 'username');
      $user = pmeikieeFindByUsername($username);
      if ($user === null) {
        pmeikieeApiFail('not_found', '指定されたユーザーは見つかりませんでした。', 404);
      }
      pmeikieeApiRespond(true, ['user' => pmeikieePublicUser($user)]);
    }

    // 指定したstorage_idが実在するアカウントのものかどうかだけを返す(氏名・
    // userData等は一切含めない)。p-chat/p-reversiのように複数アカウントが
    // 絡む自前データを持つサービスが、「相手のアカウントがまだ存在するか」を
    // 直接確認するための軽量な専用API(ACCOUNTS_INTEGRATION_SPEC.md参照)。
    // p_drive_putの書き込み時検証で使っているpmeikieeValidStorageIds()と
    // 同じ実在チェックをそのまま公開する。
    case 'account_exists': {
      $exists = isset(pmeikieeValidStorageIds()[pmeikieeApiInputStr($input, 'storage_id')]);
      pmeikieeApiRespond(true, ['exists' => $exists]);
    }

    // ---------------------------------------------------------------
    // 汎用データストア(userData)
    //
    // service名・key名は呼び出し側が自由に決めてよく、accounts側はその中身の
    // 意味を関知しません(何のサービスの何のデータかを知る必要が無いようにする
    // ためです)。1件あたりの上限は USER_DATA_VALUE_MAX_BYTES / 配列は
    // USER_DATA_LIST_MAX_ITEMS 件までです(account.jsonlを肥大化させないため)。
    // ---------------------------------------------------------------

    // 自分自身の userData[service][key] を取得します(本人のみ)。
    case 'data_get': {
      $user = pmeikieeApiRequireUser(pmeikieeApiInputStr($input, 'token'));
      $service = pmeikieeApiInputStr($input, 'service');
      $key = pmeikieeApiInputStr($input, 'key');
      pmeikieeApiRespond(true, ['value' => pmeikieeUserDataGet($user, $service, $key)]);
    }

    // 自分自身の userData[service][key] に任意の値(JSONにできるもの)を保存します(本人のみ)。
    case 'data_set': {
      $user = pmeikieeApiRequireUser(pmeikieeApiInputStr($input, 'token'));
      $service = pmeikieeApiInputStr($input, 'service');
      $key = pmeikieeApiInputStr($input, 'key');
      $rawValue = $input['value'] ?? null;
      // フォームエンコードで送られてきた場合はJSON文字列として届くのでデコードします。
      $value = is_string($rawValue) ? (json_decode($rawValue, true) ?? $rawValue) : $rawValue;
      $result = pmeikieeUserDataSet($user, $service, $key, $value);
      pmeikieeApiRespond($result['ok'], $result, $result['ok'] ? 200 : 400);
    }

    // 自分自身の userData[service][key] を丸ごと削除します(本人のみ)。値が無くてもエラーにはしません。
    case 'data_delete': {
      $user = pmeikieeApiRequireUser(pmeikieeApiInputStr($input, 'token'));
      $result = pmeikieeUserDataDelete(
        $user,
        pmeikieeApiInputStr($input, 'service'),
        pmeikieeApiInputStr($input, 'key')
      );
      pmeikieeApiRespond($result['ok'], $result, $result['ok'] ? 200 : 400);
    }

    // userData[service][key] を配列として扱い、value を重複なく追加します(本人のみ)。
    case 'data_list_add': {
      $user = pmeikieeApiRequireUser(pmeikieeApiInputStr($input, 'token'));
      $result = pmeikieeUserDataListAdd(
        $user,
        pmeikieeApiInputStr($input, 'service'),
        pmeikieeApiInputStr($input, 'key'),
        pmeikieeApiInputStr($input, 'value')
      );
      pmeikieeApiRespond($result['ok'], $result, $result['ok'] ? 200 : 409);
    }

    // userData[service][key] の配列から value を取り除きます(本人のみ)。
    case 'data_list_remove': {
      $user = pmeikieeApiRequireUser(pmeikieeApiInputStr($input, 'token'));
      $result = pmeikieeUserDataListRemove(
        $user,
        pmeikieeApiInputStr($input, 'service'),
        pmeikieeApiInputStr($input, 'key'),
        pmeikieeApiInputStr($input, 'value')
      );
      pmeikieeApiRespond($result['ok'], $result, $result['ok'] ? 200 : 400);
    }

    // 指定したユーザー(username)の userData[service][key] 配列の件数を返します(公開情報として扱います)。
    case 'data_list_count': {
      $user = pmeikieeFindByUsername(pmeikieeApiInputStr($input, 'username'));
      if ($user === null) {
        pmeikieeApiFail('not_found', '指定されたユーザーは見つかりませんでした。', 404);
      }
      $list = pmeikieeResolveUserData($user)[pmeikieeApiInputStr($input, 'service')][pmeikieeApiInputStr($input, 'key')] ?? [];
      pmeikieeApiRespond(true, ['count' => is_array($list) ? count($list) : 0]);
    }

    // 「userData[service][key] の配列に value を含んでいるメイキィ」が何件あるかを数えます。
    // フォロワー数のような「誰かが自分を配列に含めている件数」を数えたいときに使います。
    // 認証は不要です(集計値のみを返し、誰が含めているかは分からないため)。
    case 'data_reverse_count': {
      $count = pmeikieeUserDataReverseCount(
        pmeikieeApiInputStr($input, 'value'),
        pmeikieeApiInputStr($input, 'service'),
        pmeikieeApiInputStr($input, 'key')
      );
      pmeikieeApiRespond(true, ['count' => $count]);
    }

    // data_reverse_countと同じ検索条件で、件数の代わりに該当メイキィの公開プロフィール
    // (username/name/bio/avatar_url)を一覧で返します。フォロワー一覧のような
    // 「誰が自分を配列に含めているか」を実際に表示したい場合に使います。
    // data_reverse_countと同様、認証は不要です(公開プロフィール相当の情報のみのため)。
    case 'data_reverse_list': {
      $users = pmeikieeUserDataReverseList(
        pmeikieeApiInputStr($input, 'value'),
        pmeikieeApiInputStr($input, 'service'),
        pmeikieeApiInputStr($input, 'key')
      );
      pmeikieeApiRespond(true, ['users' => $users]);
    }

    // userid(sha256ハッシュ)の配列を、実際の公開プロフィールへ一括で解決します。
    // following配列のように相手の生idを知り得ないハッシュだけを持っているサービス側が、
    // 表示用のユーザー名・名前・アバターを得るために使います(フォロー中一覧など)。
    // 認証は不要です(公開プロフィール相当の情報のみを返すため)。
    case 'resolve_ids': {
      $ids = json_decode(pmeikieeApiInputStr($input, 'ids'), true);
      if (!is_array($ids)) {
        pmeikieeApiFail('invalid_input', 'idsは文字列のJSON配列で指定してください。', 400);
      }
      // 大量のIDを一度に投げて毎回全件走査させる負荷対策として、件数を制限します。
      if (count($ids) > USER_DATA_LIST_MAX_ITEMS) {
        pmeikieeApiFail('too_many_ids', 'idsは' . USER_DATA_LIST_MAX_ITEMS . '件までです。', 400);
      }
      pmeikieeApiRespond(true, ['users' => pmeikieeResolveUserIdHashes($ids)]);
    }

    // SSO用: 共通ログイン画面がブラウザへリダイレクトで渡した使い切りコードを、
    // サーバ間通信でトークンに交換します。
    case 'exchange_code': {
      $exchanged = pmeikieeExchangeHandoffCode(pmeikieeApiInputStr($input, 'code'));
      if ($exchanged === null) {
        pmeikieeApiFail('invalid_code', 'コードが無効か、有効期限切れです。もう一度ログインをやり直してください。', 401);
      }
      pmeikieeApiRespond(true, $exchanged);
    }

    // ---------------------------------------------------------------
    // 管理者専用(oppai管理パネルからのみ呼ばれる想定)。
    // api_secretの検証だけで、他のアクションと同じ認証レベルです。
    // ---------------------------------------------------------------

    // 指定ユーザー宛のパスワード再設定用「一時ログインリンク」を発行します。
    // パスワードは検証しない代わりに、トークンの寿命をADMIN_TEMP_LOGIN_TTL・1回限りにしています。
    case 'admin_issue_temp_login': {
      $user = pmeikieeFindByUsername(pmeikieeApiInputStr($input, 'username'));
      if ($user === null) {
        pmeikieeApiFail('not_found', '指定されたユーザーは見つかりませんでした。', 404);
      }
      $tempToken = pmeikieeIssueAdminTempLogin((string)$user['id']);
      if ($tempToken === null) {
        pmeikieeApiFail('storage_error', '一時ログインリンクの発行に失敗しました。', 500);
      }
      pmeikieeApiRespond(true, [
        'temp_token' => $tempToken,
        'expires_in' => ADMIN_TEMP_LOGIN_TTL,
        'user'       => pmeikieePublicUser($user),
      ]);
    }

    // 登録メイキィの検索・一覧(パスワードハッシュ・userData等の内部情報は含めません)。
    // last_login(最終ログイン日時)と、それを基にした簡易分類(dormant/never_seen)も返す。
    case 'admin_list_accounts': {
      $query = mb_strtolower(trim(pmeikieeApiInputStr($input, 'q')));
      $page = max(1, (int)pmeikieeApiInputStr($input, 'page', '1'));
      $limit = max(1, min(100, (int)pmeikieeApiInputStr($input, 'limit', '30')));
      $dormantOnly = pmeikieeApiInputStr($input, 'dormant_only') === '1';
      $now = time();
      $dormantThreshold = $now - (DORMANT_ACCOUNT_DAYS * 86400);

      $all = pmeikieeReadAll();
      if ($query !== '') {
        $all = array_values(array_filter($all, function (array $u) use ($query): bool {
          return str_contains(mb_strtolower((string)($u['username'] ?? '')), $query)
              || str_contains(mb_strtolower((string)($u['name'] ?? '')), $query)
              || str_contains(mb_strtolower((string)($u['email'] ?? '')), $query);
        }));
      }
      $all = array_map(static function (array $u) use ($dormantThreshold): array {
        $lastLogin = isset($u['last_login']) ? (int)$u['last_login'] : null;
        $u['__last_login'] = $lastLogin;
        // never_seen: このアカウントで一度もトークンが発行されていない(移行前の
        // 既存アカウント、または本当に一度もログインしていない)。しきい値が
        // 古いというより「そもそも記録が無い」ため、dormantとは区別して扱う
        // (自動的に「1年以上未使用」と決めつけて一覧に混ぜないための配慮)。
        $u['__never_seen'] = $lastLogin === null;
        $u['__dormant'] = $lastLogin !== null && $lastLogin < $dormantThreshold;
        return $u;
      }, $all);
      if ($dormantOnly) {
        $all = array_values(array_filter($all, static fn(array $u): bool => $u['__dormant']));
      }

      $total = count($all);
      $offset = ($page - 1) * $limit;
      $rows = array_map(static function (array $u): array {
        return [
          'username'   => $u['username'] ?? '',
          'name'       => $u['name'] ?? '',
          'email'      => $u['email'] ?? '',
          'created_at' => isset($u['created_at']) ? (int)$u['created_at'] : null,
          'last_login' => $u['__last_login'],
          'dormant'    => $u['__dormant'],
          'never_seen' => $u['__never_seen'],
          'storage_id' => $u['storage_id'] ?? '',
        ];
      }, array_slice($all, $offset, $limit));
      pmeikieeApiRespond(true, [
        'total'                       => $total,
        'page'                        => $page,
        'limit'                       => $limit,
        'dormant_days'                => DORMANT_ACCOUNT_DAYS,
        'new_account_inactivity_days' => NEW_ACCOUNT_INACTIVITY_DAYS,
        'users'                       => $rows,
      ]);
    }

    // 管理者による強制削除(本人確認なし)。休眠アカウント整理・スパム対応向け。
    // 自動実行はせず、必ず管理者がoppai管理パネルから個別に呼び出す想定です。
    case 'admin_delete_account': {
      $user = pmeikieeFindByUsername(pmeikieeApiInputStr($input, 'username'));
      if ($user === null) {
        pmeikieeApiFail('not_found', '指定されたユーザーは見つかりませんでした。', 404);
      }
      $result = pmeikieeAdminDelete((string)$user['id']);
      pmeikieeApiRespond($result['ok'], $result, $result['ok'] ? 200 : 400);
    }

    // p_drive_storage配下から、account.jsonlのどのstorage_idにも属さない孤児
    // ディレクトリを検出して削除する(pmeikieeSweepOrphanedStorage()参照)。
    // 通常のアカウント削除では発生しないはずの状態を掃除するための、
    // oppai管理パネルからの明示的な実行専用のメンテナンス操作。
    case 'admin_sweep_orphaned_storage': {
      $result = pmeikieeSweepOrphanedStorage();
      pmeikieeApiRespond($result['ok'], $result, $result['ok'] ? 200 : 500);
    }

    // admin_sweep_orphaned_storage()の監査ログを直近から新しい順に返す。
    // oppai管理パネルのボタンを手動で押した実行だけでなく、
    // pmeikieeRunDailyMaintenanceIfNeeded()による1日1回の自動実行の結果も
    // 同じログファイルに記録されるため、これで両方まとめて確認できる。
    case 'admin_orphan_sweep_log': {
      $limit = max(1, min(200, (int)pmeikieeApiInputStr($input, 'limit', '50')));
      $file = pmeikieeOrphanSweepLogFile();
      $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
      $lines = $lines === false ? [] : array_slice($lines, -$limit);
      $entries = [];
      foreach (array_reverse($lines) as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) { $entries[] = $decoded; }
      }
      pmeikieeApiRespond(true, ['entries' => $entries]);
    }

    // 放置メイキィの自動削除(pmeikieeSweepStaleAccounts()参照)を今すぐ実行する。
    // 通常はpmeikieeRunDailyMaintenanceIfNeeded()により1日1回自動で走るため、
    // これはoppai管理パネルから即時実行したい場合向けの手動トリガー。
    case 'admin_sweep_stale_accounts': {
      $result = pmeikieeSweepStaleAccounts();
      pmeikieeApiRespond($result['ok'], $result, $result['ok'] ? 200 : 500);
    }

    // pmeikieeSweepStaleAccounts()の監査ログを直近から新しい順に返す。
    // admin_orphan_sweep_logと同じ考え方(手動実行・自動実行の両方がここに記録される)。
    case 'admin_stale_account_sweep_log': {
      $limit = max(1, min(200, (int)pmeikieeApiInputStr($input, 'limit', '50')));
      $file = pmeikieeStaleAccountSweepLogFile();
      $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
      $lines = $lines === false ? [] : array_slice($lines, -$limit);
      $entries = [];
      foreach (array_reverse($lines) as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) { $entries[] = $decoded; }
      }
      pmeikieeApiRespond(true, ['entries' => $entries]);
    }

    // 一回限りの移行(pmeikieeMigratePDriveFilesLayoutOnce()参照)。実行後、
    // この関数・case・管理画面のボタンは削除すること。
    case 'admin_migrate_pdrive_files_layout_once': {
      $result = pmeikieeMigratePDriveFilesLayoutOnce();
      pmeikieeApiRespond($result['ok'], $result, $result['ok'] ? 200 : 500);
    }

    // ---------------------------------------------------------------
    // p-drive(全サービス共通のユーザーデータストレージ)。p-drive/index.php
    // (公開向けのURL)からの中継リクエストはここへ来る。実装本体は上の
    // pDriveEngine*()参照。認証はこのdispatchApi()自身の合言葉検証を
    // そのまま使う(p-drive専用の別の合言葉は無い)。
    // ---------------------------------------------------------------
    case 'p_drive_put': {
      $value = $input['value'] ?? null;
      if (!is_string($value)) {
        pmeikieeApiFail('invalid_input', 'valueは文字列(JSON文字列等)で渡してください。');
      }
      $putUserid = pmeikieeApiInputStr($input, 'userid');
      // 実在しないstorage_id宛の書き込みは、誰にも参照されない孤立ディレクトリを
      // 新規に作ってしまうため、書き込み時点でここだけ弾く。
      //
      // get/list/delete/delete_serviceは検証不要。存在しないuseridに対して
      // 単に「何もない」を返すだけで、新規ディレクトリを作らないため。
      // 【ただしdeleteは自動的にそうなっているわけではない】ロックを取るには
      // フォルダが要る(PDriveEngine::withLock())ので、素直に書くとdeleteでも
      // フォルダができてしまう。PDriveEngine::engineDelete()がロックを取る前に
      // フォルダの有無を確かめることで、初めてここの前提が成立している。
      // あちらの確認を外すと、この行の「検証不要」が静かに嘘になる。
      //
      // delete_user・admin_sweep_orphaned_storageはaccount.jsonlに存在しない
      // storage_id相手にも意図的に動作させたいため対象外。
      if (!isset(pmeikieeValidStorageIds()[$putUserid])) {
        pmeikieeApiFail('unknown_user', '指定されたユーザーが見つかりません。', 404);
      }
      $result = pDriveEnginePut($putUserid, pmeikieeApiInputStr($input, 'service'), pmeikieeApiInputStr($input, 'key'), $value);
      pmeikieeApiRespond($result['ok'], $result, $result['ok'] ? 200 : pDriveErrorStatus($result['error'] ?? ''));
    }

    case 'p_drive_get': {
      $result = pDriveEngineGet(pmeikieeApiInputStr($input, 'userid'), pmeikieeApiInputStr($input, 'service'), pmeikieeApiInputStr($input, 'key'));
      if ($result['ok'] && !empty($result['found'])) {
        $result = pDriveEncodeValueForJson($result);
      }
      pmeikieeApiRespond($result['ok'], $result, $result['ok'] ? 200 : pDriveErrorStatus($result['error'] ?? ''));
    }

    case 'p_drive_list': {
      $result = pDriveEngineList(pmeikieeApiInputStr($input, 'userid'), pmeikieeApiInputStr($input, 'service'));
      if ($result['ok']) {
        $result['items'] = array_map('pDriveEncodeItemForJson', $result['items']);
      }
      pmeikieeApiRespond($result['ok'], $result, $result['ok'] ? 200 : pDriveErrorStatus($result['error'] ?? ''));
    }

    case 'p_drive_delete': {
      $result = pDriveEngineDelete(pmeikieeApiInputStr($input, 'userid'), pmeikieeApiInputStr($input, 'service'), pmeikieeApiInputStr($input, 'key'));
      pmeikieeApiRespond($result['ok'], $result, $result['ok'] ? 200 : pDriveErrorStatus($result['error'] ?? ''));
    }

    case 'p_drive_delete_service': {
      $result = pDriveEngineDeleteService(pmeikieeApiInputStr($input, 'userid'), pmeikieeApiInputStr($input, 'service'));
      pmeikieeApiRespond($result['ok'], $result, $result['ok'] ? 200 : pDriveErrorStatus($result['error'] ?? ''));
    }

    case 'p_drive_delete_user': {
      $result = pDriveEngineDeleteUser(pmeikieeApiInputStr($input, 'userid'));
      pmeikieeApiRespond($result['ok'], $result, $result['ok'] ? 200 : pDriveErrorStatus($result['error'] ?? ''));
    }

    case 'p_drive_usage': {
      $result = pDriveEngineUsage(pmeikieeApiInputStr($input, 'userid'));
      pmeikieeApiRespond($result['ok'], $result, $result['ok'] ? 200 : pDriveErrorStatus($result['error'] ?? ''));
    }

    case 'p_drive_storage_breakdown': {
      $result = pDriveEngineStorageBreakdown(pmeikieeApiInputStr($input, 'userid'));
      pmeikieeApiRespond($result['ok'], $result, $result['ok'] ? 200 : pDriveErrorStatus($result['error'] ?? ''));
    }

    // 自分のservice配下(またはその中の任意のsubpath)を再帰的にls(一覧)する。
    // 削除・整理は一切行わない診断専用ハンドル(pDriveEngineListPath()参照)。
    // 各サービスはこれを使って「自分が実際に何を持っているか」を把握し、
    // 古いサブフォルダ・ファイルの整理は自分自身の判断・自分の削除操作
    // (delete/delete_service)で行う。
    case 'p_drive_ls': {
      $result = pDriveEngineListPath(pmeikieeApiInputStr($input, 'userid'), pmeikieeApiInputStr($input, 'service'), pmeikieeApiInputStr($input, 'subpath'));
      pmeikieeApiRespond($result['ok'], $result, $result['ok'] ? 200 : pDriveErrorStatus($result['error'] ?? ''));
    }

    // <id>.c<番号>という分割アップロードの命名規則(p-drive「マイファイル」機能が
    // 使っているが、この規則自体は全サービス共通のものとして扱う)に基づき、
    // メタデータ(<id>単体)の無いチャンクだけが残っている=アップロードが
    // 中断されたまま孤立しているものを検出して削除する。呼び出し元は
    // 「いつ実行するか」だけを決めればよい(判定ロジックはここに一本化)。
    case 'p_drive_sweep_orphaned_chunks': {
      $maxAge = max(3600, (int)pmeikieeApiInputStr($input, 'max_age_seconds', (string)(24 * 60 * 60)));
      $result = pDriveEngineSweepOrphanedChunks(pmeikieeApiInputStr($input, 'userid'), pmeikieeApiInputStr($input, 'service'), $maxAge);
      pmeikieeApiRespond($result['ok'], $result, $result['ok'] ? 200 : pDriveErrorStatus($result['error'] ?? ''));
    }

    case 'p_drive_selftest': {
      pmeikieeApiRespond(true, [
        'storage_key_ready' => pDriveStorageKey() !== null,
        'storage_root_writable' => is_dir(P_DRIVE_STORAGE_ROOT) ? is_writable(P_DRIVE_STORAGE_ROOT) : is_writable(dirname(P_DRIVE_STORAGE_ROOT)),
      ]);
    }

    default:
      pmeikieeApiFail('unknown_action', '不明なアクションです。', 404);
  }
}

// 1日1回だけ自動的に保守処理(孤児storageの掃除)を回す(pmeikieeRunDailyMaintenanceIfNeeded()参照)。
// 専用のcronジョブを組まずに済ませるための、通常のリクエストに便乗させる方式。
pmeikieeRunDailyMaintenanceIfNeeded();

// アバター画像の配信口。account.jsonl(暗号化ストア)に持つbase64を都度復号して
// 返すだけで、ディスク上に平文の画像ファイルを一切残さない。ログイン状態は
// 問わない(公開プロフィール画像のため、他サービスからの<img>直リンクも成立させる)。
if (isset($_GET['avatar'])) {
  $avatarHash = (string)$_GET['avatar'];
  if (preg_match('/^[a-f0-9]{64}$/', $avatarHash) !== 1) {
    http_response_code(404);
    exit;
  }
  // account.jsonlを介さず、ハッシュから直接p_drive_storage上の専用ファイルを開く
  // (全アカウントを読んで線形探索する必要が無く、account.jsonl自体の復号コストも
  // かからない)。旧保存場所(accounts_storage/avatars/)は見ない。アバターは
  // 公開プロフィール情報であり非公開の機密データではないため、そこまでして
  // 救済する価値は無いと判断し、新規保存経路(p-drive)だけを唯一の正とする
  // (旧保存場所に残っていたら、次の再アップロード時・アカウント削除時に
  // 既存の掃除経路で片付くだけで、配信時にはそもそも見に行かない)。
  $pDriveResult = pDriveEngineGet($avatarHash, 'p-meikiee', 'avatar');
  $avatarBytes = (!empty($pDriveResult['ok']) && !empty($pDriveResult['found']))
    ? (string)($pDriveResult['value'] ?? '')
    : null;
  if ($avatarBytes === null || $avatarBytes === '') {
    http_response_code(404);
    exit;
  }
  header('Content-Type: image/jpeg');
  header('Cache-Control: public, max-age=86400, immutable'); // URLに?v=保存時刻が付くため、内容が変われば別URLになる
  echo $avatarBytes;
  exit;
}

if (isset($_GET['api'])) {
  pmeikieeDispatchApi((string)$_GET['api']);
  exit; // pmeikieeDispatchApi は内部で必ず exit しますが、念のため。
}

// ================================================================
// ここから下はUIモード(ブラウザ向けの画面)
// ================================================================

header('Content-Type: text/html; charset=utf-8');
// 古いInternet Explorerは、クロスドメインのSSOリダイレクト後に戻ってきた
// 側でCookie(セッション)が保持されない場合がある。IEはP3Pポリシーの宣言が
// 無いCookieを"サードパーティ的"とみなして拒否することがあるための既知の
// 挙動で、この宣言はその互換性のためだけのものです(実際のプライバシー
// ポリシーの内容を表すものではありません)。
header('P3P: CP="CAO PSA OUR"');

// ================================================================
// 戻り先URL(MeikieeUiReturnTo)
// ================================================================

/**
 * 「どこから来たか(return_to)」の検証と、そのURLを使った組み立てを1箇所に集めたもの。
 *
 * 【このクラスに渡す$returnToは必ずvalidate()を通ったものであること】
 * appendQuery()・hiddenField()・retryButton()は、渡された値をもう検証しません。
 * 生の$_GET['return_to']をここへ渡すと、検証していないURLがそのままLocation
 * ヘッダやフォームのhiddenに載ります。このファイルでは冒頭で一度だけvalidate()を
 * 通した結果を$returnToという変数に入れており、以降はそれだけを回しています。
 * 新しく戻り先を受け取る場所を足す時も、必ず先にvalidate()を通してください。
 */
final class MeikieeUiReturnTo {
  /**
   * return_toを検証し、安全だと確認できた部品だけで組み直したURLを返します(危険ならnull)。
   *
   * 【なぜ受け取った文字列をそのまま返さないか】PHPのparse_url()とブラウザのURL解釈は
   * 一致しない。実測(PHP 8.2)では
   *   parse_url('https://evil.example\@p-memo.pusyuuwanko.com/')
   *     => host = 'p-memo.pusyuuwanko.com'(=許可リスト通過), user = 'evil.example\'
   * となる一方、ブラウザはURL標準に従いバックスラッシュを"/"と同じ区切りとして扱うため
   * 「https://evil.example/@p-memo.pusyuuwanko.com/」すなわちevil.exampleへ遷移する。
   * つまりPHP側の検査を通した"つもり"の文字列を、そのままLocationヘッダへ渡すと、
   * 検査した宛先とブラウザが実際に行く宛先が食い違う。
   *
   * 対策は2段構え:
   *   1. 解釈が割れる文字(バックスラッシュ・空白・制御文字)を含むURLは、検証以前に捨てる。
   *   2. 検証を通った後も元の文字列は使わず、検証に使った部品(scheme/host/port/path/query)
   *      だけで組み直す。user:pass@ やフラグメントのような「検証していない部分」を
   *      持ち越さないため。持ち越す実装のままだと、同種の解釈差が将来また見つかるたびに
   *      ここへ個別の除外を足し続けることになる。
   */
  public static function validate(?string $url): ?string {
    if ($url === null || $url === '') { return null; }
    if (preg_match('/[\\\\\s\x00-\x1f\x7f]/', $url) === 1) { return null; }

    $parts = parse_url($url);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) { return null; }

    $scheme = strtolower((string)$parts['scheme']);
    if ($scheme !== 'https' && $scheme !== 'http') { return null; }

    // ホスト名はDNS上大文字小文字を区別しないので、比較も区別しない。
    // (区別したままだと「https://P-MEMO.PUSYUUWANKO.COM/」のような正当なURLだけが
    //  黙って弾かれ、戻り先を失うという逆向きの不具合になる。)
    $host = strtolower((string)$parts['host']);
    if (!in_array($host, ALLOWED_RETURN_HOSTS, true)) { return null; }

    $safe = $scheme . '://' . $host;
    if (isset($parts['port'])) { $safe .= ':' . (int)$parts['port']; }
    $safe .= (string)($parts['path'] ?? '');
    if (isset($parts['query'])) { $safe .= '?' . (string)$parts['query']; }
    return $safe;
  }

  public static function appendQuery(string $url, array $params): string {
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    return $url . $sep . http_build_query($params);
  }

  /** フォームに戻り先を持ち回らせるためのhidden。$returnToがnullなら何も出しません。 */
  public static function hiddenField(?string $returnTo): string {
    return $returnTo === null ? '' : '<input type="hidden" name="return_to" value="' . htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') . '" />';
  }

  /** 入力失敗時などに「もう一度試す」ボタンを出すための共通部品。戻り先も引き継ぎます。 */
  public static function retryButton(string $section, ?string $returnTo, string $label = 'もう一度試す'): string {
    $url = self::selfUrl($section, $returnTo);
    return ' <a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="button">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
  }

  /**
   * このページ自身の「?account=<section>」へのURL。戻り先があれば引き継ぎます。
   *
   * 「もう一度試す」ボタン(retryButton)とPOST後のリダイレクト(MeikieeUiFlash::finishPost)が
   * 同じ形のURLを組み立てるので、片方だけ書き換えて食い違わないよう1箇所にしています。
   * 食い違うと「失敗して戻ったのに戻り先を失っている」という、その場では気づきにくい
   * 壊れ方をします。
   */
  public static function selfUrl(string $section, ?string $returnTo): string {
    return './?account=' . urlencode($section) . ($returnTo !== null ? '&return_to=' . urlencode($returnTo) : '');
  }
}

// ----------------------------------------------------------------
// 上のクラスへの薄い入口(グローバル関数)
// ----------------------------------------------------------------

function pmeikieeSafeReturnTo(?string $url): ?string {
  return MeikieeUiReturnTo::validate($url);
}

function pmeikieeAppendQuery(string $url, array $params): string {
  return MeikieeUiReturnTo::appendQuery($url, $params);
}

function pmeikieeUiHiddenReturnTo(?string $returnTo): string {
  return MeikieeUiReturnTo::hiddenField($returnTo);
}

function pmeikieeUiRetryButton(string $section, ?string $returnTo, string $label = 'もう一度試す'): string {
  return MeikieeUiReturnTo::retryButton($section, $returnTo, $label);
}

// ================================================================
// セッションとログイン状態(MeikieeUiSession)
// ================================================================

/**
 * このブラウザが「今どのメイキィとしてログインしているか」という状態を、
 * $_SESSION と「ログイン状態を保持する」Cookieの両方まとめて面倒を見ます。
 * CSRFトークン(PIPSのserver_tokenと同じ考え方)もここの持ち物です。
 *
 * 【セッションのキーを直接書き換えないこと】user_logged_in / accounts_token /
 * userid / username / name / server_token に代入してよいのはこのクラスの中だけです。
 * 外から個別に代入する形にすると、「ログインしたことにしたがトークンを入れ忘れた」
 * 「セッションIDを振り直し忘れた」といった半端な状態が作れてしまいます。
 * 読み取りは外から行っても構いません。
 */
final class MeikieeUiSession {
  /**
   * 「ログイン状態を保持する」Cookieの属性。set と clear で**必ず同じ値**を使うため、
   * 1箇所にまとめてあります。
   *
   * 【なぜ揃えないといけないか】ブラウザはCookieを名前だけでなく path も含めて
   * 区別します。片方だけ path を変えると、消したつもりのCookieが別物として残り、
   * ログアウトしたのに次のアクセスで復元される、という直りにくい不具合になります。
   * 期限だけが呼び出しごとに違うので、そこだけ引数で受け取ります。
   */
  private static function rememberCookieOptions(int $expires): array {
    return [
      'expires'  => $expires,
      'path'     => '/',
      'secure'   => true,
      'httponly' => true,
      'samesite' => 'Lax',
    ];
  }

  public static function rememberSet(string $token): void {
    setcookie(REMEMBER_COOKIE, $token, self::rememberCookieOptions(time() + REMEMBER_COOKIE_TTL));
    $_COOKIE[REMEMBER_COOKIE] = $token;
  }

  public static function rememberClear(): void {
    setcookie(REMEMBER_COOKIE, '', self::rememberCookieOptions(time() - 3600));
    unset($_COOKIE[REMEMBER_COOKIE]);
  }

  /** 現在「ログイン状態を保持する」が有効かどうか(Cookieの有無がそのまま設定値)。 */
  public static function rememberEnabled(): bool {
    return !empty($_COOKIE[REMEMBER_COOKIE]);
  }

  /**
   * リカバリコード発行ウィザードの「このブラウザでの進行状況」を全部捨てて、
   * 最初(危険提示画面)からやり直せる状態にします。
   *
   * ウィザードが使うセッションキーの一覧はここだけに書く。キーを増やしたときは
   * 必ずこのメソッドに足すこと(各所で個別にunset()して回る形にすると、必ずどこかが
   * 消し忘れて「前の状態が残ったまま次のウィザードが始まる」ことになる)。
   * なお、ここで消えるのは進行状況だけで、アカウント側の義務
   * (OBLIGATION_ISSUE_RECOVERY_CODES)は消えない。両者の違いは
   * 「未完了の手続き(obligation)」節のコメントを参照。
   */
  public static function resetRecoveryIssueFlow(): void {
    unset(
      $_SESSION['recovery_issue_step'],
      $_SESSION['recovery_pending_codes'],
      $_SESSION['recovery_pending_for'],
      $_SESSION['recovery_confirm_error'],
      $_SESSION['recovery_regenerated_notice']
    );
  }

  /**
   * ログイン状態をセッションへ書き込む唯一の入口です。通常ログイン・作成直後の
   * 自動ログイン・リカバリコードでのログイン・管理者発行の一時リンク・
   * 「ログイン状態を保持する」Cookieからの復元、の5経路すべてがここを通ります。
   *
   * ------------------------------------------------------------------
   * 【$_SESSION['userid']には生idを絶対に入れないこと】
   * ここに入るのは必ずstorage_id(=sha256(生id)。MeikieeAccounts::computeStorageId()参照)で
   * あって、account.jsonlの生の'id'ではない。このメソッドの引数がpmeikieePublicUser()の
   * 戻り値(=生idを構造的に含まない形)なのも、生idを渡せる形にしないための
   * 意図的な制約であって、ただの都合ではない。
   *
   *   - なぜ入れなくてよいか: 生idが必要な処理は、その場で
   *     self::currentUser()['id'](トークンから引き直したaccount.jsonlのレコード)を
   *     見れば必ず得られる。セッションに置いておく必要が構造的に無い。
   *   - なぜ入れてはいけないか: 生idは「一切外へ出さない」ことを前提にエコシステム
   *     全体が組まれている(PDriveEngine::userDir()手前の【重要・機密情報の取り扱い】
   *     コメント参照)。セッションに置くと、画面表示・フォームのhidden・ログ出力といった
   *     「うっかり外に出る経路」が一気に増える。
   *   - 【比較ロジックを書くときの指針】他サービスへ渡すuserid・userDataのfollowing
   *     配列の中身・p-driveのuserid・?avatar=のハッシュは、すべてstorage_idである。
   *     したがって照合相手も必ずstorage_id。$_SESSION['userid']を$user['id']と
   *     比較するコードを書きたくなったら、それは常に間違い(必ず不一致になる)なので、
   *     $_SESSION['userid']側を生idに戻すのではなく、比較相手の方を
   *     MeikieeAccounts::computeStorageId()でstorage_idに揃えること。
   * ------------------------------------------------------------------
   *
   * 前の利用者の途中状態を持ち越さないため、ウィザードの一時状態はここで必ず捨てる。
   * 以前はloginとtemp_loginがsession_regenerate_id()するだけでこれらを消しておらず、
   * Aが発行途中で放置したコードがそのままBの画面に出て、Bが確定するとAの知っている
   * コードがBのアカウントへ保存される、という乗っ取り経路が残っていた。
   */
  public static function establish(array $publicUser, string $token): void {
    // ログイン=権限昇格のタイミングでセッションIDを振り直し、セッション固定化を防ぐ。
    //
    // 【トレース上の注意】ここでセッションの印が必ず変わります。前後を1本の線として
    // 追えるよう、振り直す前と後の両方を1行に載せます。これを知らずに読むと
    // 「Cookieが保存されていない」と誤読します(正常でも印が変わるため)。
    $markBefore = substr(hash('sha256', (string)session_id()), 0, 12);
    session_regenerate_id(true);
    MeikieeLoginTrace::log('session_regenerated', ['from' => $markBefore]);
    self::resetRecoveryIssueFlow();

    $_SESSION['user_logged_in'] = true;
    $_SESSION['accounts_token'] = $token;
    $_SESSION['userid']         = (string)($publicUser['userid'] ?? ''); // storage_id。生idではない(上記参照)
    $_SESSION['username']       = (string)($publicUser['username'] ?? '');
    $_SESSION['name']           = (string)($publicUser['name'] ?? '');
    self::newCsrf();
  }

  public static function reset(): void {
    session_unset();
    session_destroy();
    session_start();
    session_regenerate_id(true);
    self::newCsrf();
    // 明示的なログアウトや、トークン失効時のセッション解除では、
    // 「ログイン状態を保持する」Cookieも一緒に失効させる(でないと次のアクセスで
    // すぐ復元されてしまい、ログアウトした意味が無くなる)。
    self::rememberClear();
  }

  public static function newCsrf(): void {
    $_SESSION['server_token'] = bin2hex(random_bytes(32));
  }

  public static function csrfIsValid(): bool {
    return isset($_POST['server_token']) && is_string($_POST['server_token'])
      && isset($_SESSION['server_token'])
      && hash_equals($_SESSION['server_token'], $_POST['server_token']);
  }

  /** CSRFトークンがまだ無いリクエスト(初回訪問など)のために1つ用意します。 */
  public static function ensureCsrf(): void {
    if (!isset($_SESSION['server_token'])) {
      self::newCsrf();
    }
  }

  public static function isLoggedIn(): bool {
    return !empty($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true;
  }

  /** ログイン中ユーザーの詳細(likes/following込み)。トークンが失効していればセッションも解除します。 */
  public static function currentUser(): ?array {
    if (!self::isLoggedIn() || empty($_SESSION['accounts_token'])) { return null; }
    $user = MeikieeAuth::resolveToken($_SESSION['accounts_token']);
    if ($user === null) {
      // 「ログイン中のはずなのにトークンが引けない」= その場で黙ってログアウト
      // される経路。ログイン直後にこれが出るなら、認証ではなく保存側の問題。
      MeikieeLoginTrace::log('session_dropped', ['reason' => 'token_not_resolvable']);
      self::reset();
      return null;
    }
    return $user;
  }

  /**
   * 「ログイン状態を保持する」Cookieからのログイン復元。
   *
   * PHPのセッションCookieが切れてuser_logged_inを失っていても、
   * Cookie(REMEMBER_COOKIE)に保持したaccounts_tokenがまだ有効なら
   * (有効期限の判定はsessions.jsonl側、MeikieeAuth::resolveToken()内で行われる)、
   * ここでセッションへ復元します。各サービスが行う無音SSOは、この復元結果を
   * そのまま見ることになります。
   */
  public static function restoreFromRememberCookie(): void {
    if (self::isLoggedIn() || empty($_COOKIE[REMEMBER_COOKIE])) { return; }

    $rememberedToken = (string)$_COOKIE[REMEMBER_COOKIE];
    $rememberedUser = MeikieeAuth::resolveToken($rememberedToken);
    if ($rememberedUser !== null) {
      self::establish(pmeikieePublicUser($rememberedUser), $rememberedToken);
      MeikieeLoginTrace::log('remember_restore', ['restored' => true]);
    } else {
      // トークン自体が失効/失効済みなら、もう復元できないのでCookieも片付ける。
      self::rememberClear();
      // 「Cookieは持っているのにトークンが死んでいる」状態。その端末では
      // 毎回ログインし直しになるので、繰り返し出るならここが原因。
      MeikieeLoginTrace::log('remember_restore', ['restored' => false, 'reason' => 'token_not_resolvable']);
    }
  }
}

// ----------------------------------------------------------------
// 上のクラスへの薄い入口(グローバル関数)
//
// 画面・POST処理・描画から広く呼ばれているものです。
// 新しく書くコードでは MeikieeUiSession::… を直接呼んでください。
// ----------------------------------------------------------------

function pmeikieeSetRememberCookie(string $token): void {
  MeikieeUiSession::rememberSet($token);
}

function pmeikieeClearRememberCookie(): void {
  MeikieeUiSession::rememberClear();
}

function pmeikieeRememberEnabled(): bool {
  return MeikieeUiSession::rememberEnabled();
}

function pmeikieeUiResetRecoveryIssueFlow(): void {
  MeikieeUiSession::resetRecoveryIssueFlow();
}

function pmeikieeUiEstablishSession(array $publicUser, string $token): void {
  MeikieeUiSession::establish($publicUser, $token);
}

function pmeikieeUiResetSession(): void {
  MeikieeUiSession::reset();
}

function pmeikieeUiNewCsrf(): void {
  MeikieeUiSession::newCsrf();
}

function pmeikieeUiCsrfIsValid(): bool {
  return MeikieeUiSession::csrfIsValid();
}

function pmeikieeUiIsLoggedIn(): bool {
  return MeikieeUiSession::isLoggedIn();
}

function pmeikieeUiCurrentUser(): ?array {
  return MeikieeUiSession::currentUser();
}

// 診断トレースの1行目。ここは画面・POST処理・無音SSOのどれに進む場合でも必ず
// 通る合流点なので、「そのリクエストが何を持って来たか」を記録するならここ。
//
// 特にsession_cookieが重要。これがfalseなのに以後CSRF不一致で落ちるなら、
// 原因はパスワードでもサーバーでもなく「そのブラウザがCookieを保存していない」。
// ensureCsrf()より手前に置くのは、$_COOKIEがまだ誰にも触られていない状態
// (restoreFromRememberCookie()は失敗時にREMEMBER_COOKIEをunsetする)を
// 見たいからです。順序を入れ替えないこと。
MeikieeLoginTrace::log('request', [
  'account'         => is_string($_GET['account'] ?? null) ? (string)$_GET['account'] : '',
  'silent'          => isset($_GET['silent']),
  'action'          => is_string($_POST['account_action'] ?? null) ? (string)$_POST['account_action'] : '',
  'session_cookie'  => isset($_COOKIE[session_name()]),
  'remember_cookie' => isset($_COOKIE[REMEMBER_COOKIE]),
  'https'           => $pmeikieeHttps,
  'logged_in'       => !empty($_SESSION['user_logged_in']),
  'has_csrf_in_session' => isset($_SESSION['server_token']),
]);

MeikieeUiSession::ensureCsrf();

// 「ログイン状態を保持する」Cookieからの復元。中身と理由は
// MeikieeUiSession::restoreFromRememberCookie() のコメントを参照。
// 下の無音SSOチェックは、この復元結果をそのまま見ることになるので、
// 順序を入れ替えないこと(後ろに回すと、Cookieでログインし続けている人が
// 無音SSOでは未ログイン扱いになる)。
MeikieeUiSession::restoreFromRememberCookie();

// 配列で送られてきた場合(return_to[]=...)は、文字列引数へ渡すとTypeErrorで500に
// なるため、指定が無かった場合と同じ扱いにする。
$rawReturnTo = $_POST['return_to'] ?? $_GET['return_to'] ?? null;
$returnTo = pmeikieeSafeReturnTo(is_string($rawReturnTo) ? $rawReturnTo : null);

// return_toが弾かれたかどうか。指定があったのにnullになっていれば、
// ALLOWED_RETURN_HOSTSに載っていないホストから来ている(= ログイン自体は
// 通るのに呼び出し元へ戻れない、という形の「ログインできない」)。
if ($rawReturnTo !== null) {
  MeikieeLoginTrace::log('return_to', [
    'given'    => is_string($rawReturnTo) ? mb_substr($rawReturnTo, 0, 200) : '(not a string)',
    'accepted' => $returnTo !== null,
  ]);
}
$display = '';
// renderEditForm()はサイドバー式の独自レイアウト(.settings-wrapper)を自分で
// 組み立てるため、ログイン/作成/削除フォームや単純なメッセージ向けの
// .account_cardラッパーで二重に囲まないよう、このフラグで出し分けます。
$useSidebarLayout = false;

// ---------------------------------------------------------------------
// 無音SSOチェック(?account=login&silent=1&return_to=...)
//
// 各サービスが「メイキィに既にログイン済みかどうか」を、ユーザーに
// フォームを見せずに確認するための仕組みです。判定に使うのはaccounts自身の
// ログイン状態(このページ自身のセッションクッキー)だけで、サービス間で
// クッキーやセッションを共有する必要はありません。
//
// ログイン済みなら、通常のログイン成功時と同じ使い切りコードを発行して
// return_toへ即座にリダイレクトします(フォームは一切表示しません)。
// 未ログインの場合も、フォームを見せずに「ログインしていません」という
// 印(pusyuu_silent=0)だけを付けて即座に戻します。呼び出し側はこれを見て
// 通常の未ログイン画面を表示してください。
//
// 【強制フローとの関係】このブロックは画面側のゲート(下部の「POST処理」「GET表示」)
// より手前でexitするため、ここでゲートを通ることは無い。代わりに
// pmeikieeIssueHandoffCode()が未完了の手続きを見てnullを返すので、その場合は
// 下の「コードを発行できなかった」経路に落ち、呼び出し側には未ログインとして
// 伝わる(ユーザーがp-meikieeへログインしに来た時点で、画面側のゲートが
// 強制フローへ誘導する)。ここに同じ判定を書き足さないこと。判定が2箇所に増えると、
// 片方だけ直して食い違う。
// ---------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && ($_GET['account'] ?? '') === 'login' && isset($_GET['silent']) && $returnTo !== null) {
  if (!pmeikieeUiIsLoggedIn()) {
    $code = null;
  } else {
    $silentUser = pmeikieeUiCurrentUser();
    $code = $silentUser !== null ? pmeikieeIssueHandoffCode((string)$silentUser['id']) : null;
  }
  // 無音SSOの結末。各サービスの「ログインしていますか?」の問い合わせがここ。
  // logged_inがずっとfalseのままなら、その端末はp-meikieeのセッションCookieを
  // 保てていない(= 他サービス側から見ると永久に未ログイン)。
  MeikieeLoginTrace::log('silent_sso', [
    'logged_in' => pmeikieeUiIsLoggedIn(),
    'issued'    => $code !== null,
  ]);
  if ($code !== null) {
    header('Location: ' . pmeikieeAppendQuery($returnTo, ['pusyuu_code' => $code]));
  } else {
    header('Location: ' . pmeikieeAppendQuery($returnTo, ['pusyuu_silent' => '0']));
  }
  exit;
}

// ---------------------------------------------------------------------
// 全体ログアウト(?account=logout&return_to=...)
//
// pips/p-memoなど呼び出し側で「ログアウト」しても、各サービス自身のトークンが
// 失効するだけで、accounts自身のログイン状態(このページ自身のセッション
// クッキー)は生きたままでした。そのため次回アクセス時の無音SSOチェック
// (上のブロック)が「まだログイン済み」と判断し、自動的にまた新しいトークンを
// 発行してログイン状態が復活してしまう問題がありました。
// これを避けるため、呼び出し側はログアウト時にここへブラウザを一瞬経由させ、
// accounts自身のログイン状態も一緒に終了させてからreturn_toへ戻ってください。
// ログアウトは実行してもログイン状態が消えるだけで破壊的な副作用が無いため、
// login/create/edit/deleteと違いCSRFトークン無しのGETでも受け付けます。
// ---------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && ($_GET['account'] ?? '') === 'logout' && $returnTo !== null) {
  if (pmeikieeUiIsLoggedIn()) {
    pmeikieeRevokeToken($_SESSION['accounts_token'] ?? '');
    pmeikieeUiResetSession();
  }
  header('Location: ' . $returnTo);
  exit;
}

// ---------------------------------------------------------------------
// 管理者発行の一時ログイン(?account=temp_login&token=...)
//
// oppai管理パネルがサポート対応のために発行する、パスワード再設定用の使い切り
// リンクです。通常のログインと違いパスワードを検証しませんが、トークン自体を
// ADMIN_TEMP_LOGIN_TTL・1回限りにすることで安全性を確保しています。
// 成功時はそのまま編集画面(パスワード変更欄あり)へ入るので、ユーザーは
// 自分で新しいパスワードを設定できます。
// ---------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && ($_GET['account'] ?? '') === 'temp_login' && isset($_GET['token'])) {
  $tempUserId = pmeikieeConsumeAdminTempLogin((string)$_GET['token']);
  $tempToken = $tempUserId !== null ? pmeikieeIssueToken($tempUserId) : null;
  $tempUser = $tempToken !== null ? pmeikieeFindById($tempUserId) : null;

  if ($tempToken !== null && $tempUser !== null) {
    pmeikieeUiEstablishSession(pmeikieePublicUser($tempUser), $tempToken);
    $notice = '<p style="color: #009900;">管理者が発行した一時リンクでログインしました。安全のため、下のフォームから新しいパスワードを設定してください。</p>';
    // 未完了の手続きが残っているアカウントは、設定画面ではなくその手続きの画面へ。
    // (POST側のゲートがあるので操作自体は素通りしないが、いきなり設定画面が出ると
    //  何を求められているのか分からないまま、押すボタンが全部弾かれることになる。)
    if (pmeikieeAccountMustIssueRecoveryCodes($tempUser)) {
      pmeikieeUiFinishPost('edit', null, $notice . renderRecoveryIssueStep(null), false);
    }
    if (pmeikieeAccountMustResetPassword($tempUser)) {
      pmeikieeUiFinishPost('edit', null, $notice . renderForcedPasswordResetForm($tempUser, null), false);
    }
    pmeikieeUiFinishPost('edit', null, renderEditForm(null, $notice), true);
  }

  pmeikieeUiFinishPost('login', null, '<p>この一時ログインリンクは無効か、有効期限が切れています。管理者に再発行を依頼してください。</p>' . renderLoginForm(null), false);
}

// ---------------------------------------------------------------------
// フォーム表示
// ---------------------------------------------------------------------

/**
 * POSTの値を必ず文字列として取り出します。
 * declare(strict_types=1)のため、`name[]=x` のように配列で送られた値をそのまま
 * 文字列引数の関数(pmeikieeEdit等)へ渡すとTypeErrorで500になります。
 * 「送られてこなかった」も「配列で送られてきた」も、同じく空文字として扱います。
 */
function pmeikieeUiPostStr(string $key): string {
  $value = $_POST[$key] ?? '';
  return is_string($value) ? $value : '';
}

// ---------------------------------------------------------------------
// フラッシュメッセージ(POST-Redirect-GETパターン)
//
// 以前はPOST処理の結果をそのままそのレスポンスでHTML描画していたため、
// 処理後にブラウザをリロードすると「フォームを再送信しますか?」という
// 確認ダイアログが出ていました。POST処理の最後に必ずGET(自分自身への
// リダイレクト)へ切り替え、結果はセッションに一時保存してリダイレクト先の
// GET側で1回だけ表示することで、リロードしても再送信ダイアログが出ないように
// します。
// ---------------------------------------------------------------------

/**
 * POST処理の結末(結果の一時保存とリダイレクト)をまとめたもの。
 *
 * 【保存とリダイレクトを切り離せないようにしてある】結果の保存(setFlash)は
 * privateにしてあり、外から呼べるのはfinishPost()だけです。保存だけして
 * リダイレクトしないコードを書けてしまうと、その結果は「次にこの人が何かの拍子に
 * このページをGETした時」に、まったく関係の無い画面の上へ突然表示されます。
 * 出る場所も出るタイミングも予測できないうえ、症状を再現しにくいため、
 * 構造の側で書けないようにしています。
 */
final class MeikieeUiFlash {
  private static function setFlash(string $html, bool $useSidebar): void {
    $_SESSION['flash_display'] = $html;
    $_SESSION['flash_sidebar'] = $useSidebar;
  }

  /** 一度取り出したら消える(表示は1回きり)。GET側の描画で最初に呼び出してください。 */
  public static function pop(): ?array {
    if (!isset($_SESSION['flash_display'])) { return null; }
    $flash = ['display' => (string)$_SESSION['flash_display'], 'sidebar' => (bool)($_SESSION['flash_sidebar'] ?? false)];
    unset($_SESSION['flash_display'], $_SESSION['flash_sidebar']);
    return $flash;
  }

  /** account_action(POSTの値)から、失敗時に戻すべき画面(?account=<section>)を決めます。 */
  public static function sectionForAction(string $action): string {
    switch ($action) {
      case 'create': return 'create';
      case 'delete': return 'delete';
      case 'recovery_login': return 'recovery';
      case 'edit':
      case 'avatar_upload':
      case 'userdata_delete':
      case 'unfollow':
      case 'remember_me_set':
      case 'recovery_issue_ack':
      case 'recovery_issue_next':
      case 'recovery_issue_back':
      case 'recovery_issue_confirm':
      case 'recovery_regenerate_start':
        return 'edit';
      default:
        return 'login';
    }
  }

  /**
   * POST処理の結果をフラッシュに保存し、GET(?account=<section>)へリダイレクトして
   * このリクエストを終了します。ここを通った時点でPOSTへの応答はリダイレクトのみに
   * なるため、以降ブラウザをリロードしてもフォーム再送信ダイアログは出ません。
   *
   * 【戻り値が無い=呼んだら戻ってこない】中でexitします。後始末が必要な処理は、
   * これを呼ぶ前に済ませてください。
   */
  public static function finishPost(string $section, ?string $returnTo, string $display, bool $useSidebar): void {
    self::setFlash($display, $useSidebar);
    header('Location: ' . MeikieeUiReturnTo::selfUrl($section, $returnTo));
    exit;
  }
}

// ----------------------------------------------------------------
// 上のクラスへの薄い入口(グローバル関数)
// ----------------------------------------------------------------

function pmeikieeUiPopFlash(): ?array {
  return MeikieeUiFlash::pop();
}

function pmeikieeUiSectionForAction(string $action): string {
  return MeikieeUiFlash::sectionForAction($action);
}

function pmeikieeUiFinishPost(string $section, ?string $returnTo, string $display, bool $useSidebar): void {
  MeikieeUiFlash::finishPost($section, $returnTo, $display, $useSidebar);
}

/**
 * すでにログイン中のときに、ログイン/作成フォームの代わりに出す案内です。
 * これが無いと、ログイン済みでaccountsへ直接アクセスした際にヘッダーは
 * 「ようこそ〜さん」に変わるのに、本文には(何も考慮せず)ログインフォームや
 * 作成フォームがそのまま出てしまい、「ログインできていないのでは」と
 * 誤解を招きます。
 *
 * $userはpmeikieeUiCurrentUser()で解決済みのものを渡してください(この関数内で
 * 改めて解決し直すと、その間にトークン失効でセッションがリセットされた場合に
 * 表示名だけ古いままになる、といった食い違いが起きるためです)。
 */
function renderAlreadyLoggedIn(array $user, ?string $returnTo): string {
  $name = htmlspecialchars($user['name'] ?? '', ENT_QUOTES, 'UTF-8');
  $html = '
    <h2>ログイン済みです</h2>
    <p>' . $name . 'さんとしてログイン中です。</p>
  ';

  // return_to付きで来ている(例: pipsの「ログイン」リンク経由)場合は、
  // 無音SSOと同じ要領で使い切りコードを発行し、そのまま呼び出し元へ
  // 戻れるボタンも出します(もう一度ユーザー名・パスワードを入力させずに済みます)。
  if ($returnTo !== null) {
    $code = pmeikieeIssueHandoffCode((string)$user['id']);
    $backUrl = $code !== null
      ? pmeikieeAppendQuery($returnTo, ['pusyuu_code' => $code])
      : $returnTo; // コード発行に失敗しても、戻れないよりはコード無しで戻れる方がましです
    $html .= '<a href="' . htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') . '" class="button">戻る</a> ';
  }

  $editUrl = './?account=edit' . ($returnTo !== null ? '&return_to=' . urlencode($returnTo) : '');
  $html .= '<a href="' . $editUrl . '" class="button">メイキィを編集</a>';
  return $html;
}

function renderCreateForm(?string $returnTo): string {
  $currentUser = pmeikieeUiCurrentUser();
  if ($currentUser !== null) {
    return renderAlreadyLoggedIn($currentUser, $returnTo)
      . '<p class="note">別のメイキィを作成する場合は、先にログアウトしてください。</p>';
  }
  return '
    <h2>メイキィ作成</h2>
    <form method="post" class="account_form">
      <input type="hidden" name="account_action" value="create" />
      <input type="hidden" name="server_token" value="' . $_SESSION['server_token'] . '" />
      ' . pmeikieeUiHiddenReturnTo($returnTo) . '
      <label>お名前:<input type="text" name="name" required /></label><br>
      <label>メールアドレス:<input type="email" name="email" required /></label><br>
      <label>ユーザー名:<input type="text" name="username" required /></label><br>
      <label>パスワード:<input type="password" name="password" required minlength="' . PASSWORD_MIN_LENGTH . '" maxlength="' . PASSWORD_MAX_LENGTH . '" /></label><br>
      <p class="note">' . PASSWORD_MIN_LENGTH . '文字以上、英大文字・英小文字・数字をすべて含めてください。</p>
      <label>パスワード(確認):<input type="password" name="password_confirm" required /></label><br>
      <button type="submit">作成</button>
    </form>
    <p><a href="./?account=login' . ($returnTo !== null ? '&return_to=' . urlencode($returnTo) : '') . '">ログインへ戻る</a></p>
  ';
}

function renderLoginForm(?string $returnTo): string {
  $currentUser = pmeikieeUiCurrentUser();
  if ($currentUser !== null) {
    return renderAlreadyLoggedIn($currentUser, $returnTo);
  }
  return '
    <h3>ようこそプシューメイキィへ</h3>
    <p>このメイキィを使う事によりプシューサービスの利便性が向上し、いつもならブラウザを閉じたり履歴を消したりしたら消えてしまってたデータを安全に保管し復元することができます。</p>
    <p>さっそくログインしてみましょう！</p>
    <h2>ログイン</h2>
    <form method="post" class="account_form">
      <input type="hidden" name="account_action" value="login" />
      <input type="hidden" name="server_token" value="' . $_SESSION['server_token'] . '" />
      ' . pmeikieeUiHiddenReturnTo($returnTo) . '
      <label>ユーザー名：<input type="text" name="username" required /></label><br>
      <label>パスワード：<input type="password" name="password" required /></label><br>
      <label>ログイン状態をこのデバイスに保持する(最大30日間)：<input type="checkbox" name="remember_me" value="1" switch /></label><br>
      <p class="note">通常はサーバー（プシューメイキー）に保存されるログイン情報がこのデバイス内に保存されますが、この機能を有効にするとこの端末内にログイン情報を保存することができます、しかし若干セキュリティレベルが下がりますので共有端末では有効にしないことをおすすめします。この機能は後からメイキィ編集画面でいつでも変更できます。</p>
      <button type="submit">ログイン</button>
    </form>
    <p><a href="./?account=recovery' . ($returnTo !== null ? '&return_to=' . urlencode($returnTo) : '') . '">パスワードをお忘れですか？(リカバリコードでログイン)</a></p>
    <p>メイキィをお持ちでない場合↓。</p>
    <a href="./?account=create' . ($returnTo !== null ? '&return_to=' . urlencode($returnTo) : '') . '" class="button">メイキィを作成</a>
  ';
}

/**
 * 「パスワードをお忘れですか?」の入り口。通常ログインとは別画面にしているのは、
 * リカバリコードを普段のログイン手段として使わせないためです(使い捨ての予備を
 * 温存させる設計)。
 */
function renderRecoveryLoginForm(?string $returnTo): string {
  $currentUser = pmeikieeUiCurrentUser();
  if ($currentUser !== null) {
    return renderAlreadyLoggedIn($currentUser, $returnTo);
  }
  return '
    <h2>リカバリコードでログイン</h2>
    <p>パスワードをお忘れの場合は、アカウント作成時にお渡ししたリカバリコードのうち、まだ使っていないものを1本入力してください。ログイン後、新しいパスワードの設定に進みます。</p>
    <p class="note">リカバリコードは1回使うと無効になります。残り本数が少なくなった場合は、ログイン後の画面から必ず作り直してください。</p>
    <form method="post" class="account_form">
      <input type="hidden" name="account_action" value="recovery_login" />
      <input type="hidden" name="server_token" value="' . $_SESSION['server_token'] . '" />
      ' . pmeikieeUiHiddenReturnTo($returnTo) . '
      <label>ユーザー名：<input type="text" name="username" required /></label><br>
      <label>リカバリコード：<input type="text" name="recovery_code" required placeholder="XXXXX-XXXXX-XXXXX-XXXXX-XXXXX" /></label><br>
      <button type="submit">ログイン</button>
    </form>
    <p><a href="./?account=login' . ($returnTo !== null ? '&return_to=' . urlencode($returnTo) : '') . '">通常のログインへ戻る</a></p>
  ';
}

/**
 * リカバリコードでのログイン直後に強制表示する、パスワード再設定専用の画面です。
 * サイドバー式の通常編集画面(renderEditForm)とは別に、必ず新しいパスワードを
 * 入力させることだけに絞った単独画面にしています(他のタブへ逃げられないように
 * するため)。
 */
function renderForcedPasswordResetForm(array $user, ?string $returnTo): string {
  return '
    <h2>新しいパスワードを設定してください</h2>
    <p>リカバリコードでログインしました。安全のため、続けて新しいパスワードを設定するまで他の操作はできません。</p>
    <form method="post" class="account_form">
      <input type="hidden" name="account_action" value="edit" />
      <input type="hidden" name="server_token" value="' . $_SESSION['server_token'] . '" />
      <input type="hidden" name="name" value="' . htmlspecialchars($user['name'] ?? '', ENT_QUOTES, 'UTF-8') . '" />
      <input type="hidden" name="email" value="' . htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8') . '" />
      <input type="hidden" name="username" value="' . htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8') . '" />
      <input type="hidden" name="bio" value="' . htmlspecialchars($user['bio'] ?? '', ENT_QUOTES, 'UTF-8') . '" />
      ' . pmeikieeUiHiddenReturnTo($returnTo) . '
      <label>新しいパスワード：<input type="password" name="new_password" required minlength="' . PASSWORD_MIN_LENGTH . '" maxlength="' . PASSWORD_MAX_LENGTH . '" /></label><br>
      <p class="note">' . PASSWORD_MIN_LENGTH . '文字以上、英大文字・英小文字・数字をすべて含めてください。</p>
      <label>新しいパスワード(確認)：<input type="password" name="new_password_confirm" required /></label><br>
      <button type="submit">パスワードを設定して続ける</button>
    </form>
  ';
}

/**
 * リカバリコード発行フロー(新規作成時・設定画面からの作り直し時で共通)。
 * $_SESSION['recovery_issue_step']で進行状況を管理し、URLをどう弄っても
 * この3画面(warning→card→confirm)以外には進めません(呼び出し側の強制ゲート参照)。
 */
function renderRecoveryIssueStep(?string $returnTo): string {
  $user = pmeikieeUiCurrentUser();
  if ($user === null) {
    return '<p>リカバリコードの発行にはログインが必要です。</p>';
  }

  $step = $_SESSION['recovery_issue_step'] ?? 'warning';
  $codes = pmeikieePendingRecoveryCodes($user);

  if ($step === 'card' && $codes !== null) {
    return renderRecoveryIssueCard($user, $codes, $returnTo);
  }
  if ($step === 'confirm' && $codes !== null) {
    return renderRecoveryIssueConfirm($codes, $returnTo);
  }
  return renderRecoveryIssueWarning($returnTo);
}

/**
 * セッションに一時保持している「まだ確定していないリカバリコード」を、今ログイン
 * している本人のものだと確認できた場合にだけ返します(確認できなければ捨ててnull)。
 *
 * 【なぜ持ち主の確認が要るか】発行途中のコードはaccount.jsonlではなくセッションに
 * 置かれる。同じブラウザのセッションを別の利用者が引き継いだ場合(Aが発行途中で
 * 放置→Aのトークンが失効→同じブラウザでBがログイン、等)、持ち主を確認しないと
 * Bの確認画面にAのコードが出て、Bが確定するとAの知っているコードがBのアカウントの
 * リカバリコードとして保存される。つまりAはBのアカウントへいつでも入れる。
 *
 * 紐付けに使うのはstorage_id(ハッシュ)であって生idではない
 * (pmeikieeUiEstablishSession()のコメント参照)。
 */
function pmeikieePendingRecoveryCodes(array $user): ?array {
  $codes = $_SESSION['recovery_pending_codes'] ?? null;
  if (!is_array($codes) || $codes === []) { return null; }

  $boundTo = (string)($_SESSION['recovery_pending_for'] ?? '');
  $storageId = (string)($user['storage_id'] ?? '');
  if ($boundTo === '' || $storageId === '' || !hash_equals($boundTo, $storageId)) {
    pmeikieeUiResetRecoveryIssueFlow();
    return null;
  }
  return $codes;
}

/**
 * 危険提示画面。以前はここに「3秒間押せないボタン」を置いていましたが廃止しました
 * (JS無し環境で永久に押せなくなる問題があった上、そもそも「本当に保存したか」の
 * 実質的な確認にはなっていなかったため)。代わりに、次の転記確認画面(発行した
 * コードのうち1本を実際に入力させる)が、警告を読んで実際に保存したことの
 * 確認として機能します。この画面自体はJS不要の通常のフォームです。
 */
function renderRecoveryIssueWarning(?string $returnTo): string {
  return '
    <div class="recovery-warning">
      <h2>リカバリコードの発行</h2>
      <p><strong>このサービスにはパスワード再設定用のメールがありません。</strong></p>
      <p>次の画面で表示されるコードを失うと、アカウントに二度とアクセスできなくなります。運営でも復旧できません。</p>
      <p>次の画面で必ず安全な場所に保管してください。保存できたことは、この後の画面でコードを1本入力して確認していただきます。</p>
      <form method="post" class="account_form">
        <input type="hidden" name="account_action" value="recovery_issue_ack" />
        <input type="hidden" name="server_token" value="' . $_SESSION['server_token'] . '" />
        ' . pmeikieeUiHiddenReturnTo($returnTo) . '
        <button type="submit">理解しました</button>
      </form>
    </div>
  ';
}

function renderRecoveryIssueCard(array $user, array $codes, ?string $returnTo): string {
  $regenerated = !empty($_SESSION['recovery_regenerated_notice']);
  unset($_SESSION['recovery_regenerated_notice']);
  $rawUsername = (string)($user['username'] ?? '');
  $username = htmlspecialchars($rawUsername, ENT_QUOTES, 'UTF-8');
  $issuedAtRaw = date('Y-m-d H:i');
  $issuedAt = htmlspecialchars($issuedAtRaw, ENT_QUOTES, 'UTF-8');

  $rows = '';
  $codeLines = [];
  foreach ($codes as $i => $code) {
    $n = $i + 1;
    $rows .= '<li><label><input type="checkbox" /> ' . $n . '本目: <code>' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</code></label></li>';
    $codeLines[] = $n . '本目: ' . $code;
  }

  // 「テキストで保存」はJS(Blob+createObjectURL)を使わず、data:URIへの
  // 通常のリンク(download属性つき)だけで実現しています。JSが無効な環境でも
  // クリックだけでファイルとして保存できます。
  //
  // 画面上のカードにある「保管場所: ___」の記入欄は、印刷して手書きするための
  // ものなので、このテキストファイルには(空欄の下線を並べても意味が無いため)
  // 含めていません。ただしその理由がユーザーに伝わらないと不親切なので、代わりに
  // 「保管場所を自分で記録してください」という一文をファイル内に明記しています。
  $downloadText = implode("\n", array_merge(
    ['プシューメイキィ リカバリコード', 'アカウントID: @' . $rawUsername, '発行日: ' . $issuedAtRaw, ''],
    $codeLines,
    [
      '',
      'このコードを失くすとアカウントは復旧できません。',
      'このファイルをどこに保存したか、必ずご自身で記録しておいてください。',
    ]
  ));
  $downloadHref = 'data:text/plain;charset=utf-8,' . rawurlencode($downloadText);

  return '
    ' . ($regenerated ? '<p class="error">保存の確認がやり直しになったため、コードを新しく発行し直しました。以前の画面に表示されていたコードはもう無効です。下の新しいコードを保存してください。</p>' : '') . '
    <div class="recovery-card" id="recovery-print-area">
      <h2>プシューメイキィ リカバリコード</h2>
      <p>サービス名: プシューメイキィ　/　アカウントID: @' . $username . '</p>
      <p>発行日: ' . $issuedAt . '</p>
      <ol>' . $rows . '</ol>
      <p>保管場所: ______________________________</p>
      <p><strong>このコードを失くすとアカウントは復旧できません。</strong></p>
    </div>
    <div class="recovery-card-actions no-print">
      <a href="' . htmlspecialchars($downloadHref, ENT_QUOTES, 'UTF-8') . '" download="pusyuu-meikiee-recovery-codes.txt" class="button">テキストで保存</a>
    </div>
    <p class="note no-print">印刷はブラウザの印刷機能(Ctrl+P等)をご利用ください。コードは範囲選択してコピーできます。</p>

    <div class="recovery-storage-advice no-print">
      <h3>保管場所について</h3>
      <p><strong>おすすめの保管場所</strong></p>
      <ul>
        <li>印刷して、鍵のかかる引き出しや金庫など、他人が勝手に見られない場所</li>
        <li>信頼できるパスワードマネージャー(暗号化された保管庫)への登録</li>
        <li>ネットに繋がっていないUSBメモリ等、オフラインの安全な場所</li>
      </ul>
      <p><strong>避けてほしい保管場所</strong></p>
      <ul>
        <li>スマホの「メモ」アプリにそのまま貼り付け(クラウド同期されるものは特に注意)</li>
        <li>パソコンのデスクトップや共有フォルダに置いたテキストファイル</li>
        <li>メールの下書き・自分宛メールへの平文コピー</li>
        <li>Slack・Discord・LINE等、チャットへの貼り付け(履歴に残り続けます)</li>
        <li>スクリーンショットのまま、クラウド同期される写真アプリに残す</li>
        <li>モニターやデスクへの付箋メモ(人目につく場所)</li>
      </ul>
    </div>
    <form method="post" class="account_form no-print">
      <input type="hidden" name="account_action" value="recovery_issue_next" />
      <input type="hidden" name="server_token" value="' . $_SESSION['server_token'] . '" />
      ' . pmeikieeUiHiddenReturnTo($returnTo) . '
      <button type="submit">保存しました、次へ</button>
    </form>
  ';
}

/**
 * 発行した5本すべてを入力させます(1本だけだと、画面に表示されているものを
 * その場で見て打つだけで通過できてしまい、実際に保存したことの確認として
 * 不十分なため)。不一致の際、どの本が間違っているかはあえて示さない
 * (全体が一致したかどうかだけを伝える)。
 */
function renderRecoveryIssueConfirm(array $pending, ?string $returnTo): string {
  $hasError = !empty($_SESSION['recovery_confirm_error']);
  unset($_SESSION['recovery_confirm_error']);

  $fields = '';
  foreach ($pending as $i => $code) {
    $n = $i + 1;
    $fields .= '<label>' . $n . '本目のコード：<input type="text" name="confirm_code_' . $i . '" required autocomplete="off" /></label><br>';
  }

  return '
    <h2>保存の確認</h2>
    <p>保存した<strong>5本すべて</strong>を入力してください。1本でも一致しないと発行は確定しません。</p>
    ' . ($hasError ? '<p class="error">一致しないコードがありました。もう一度確認して入力し直してください。</p>' : '') . '
    <form method="post" class="account_form">
      <input type="hidden" name="account_action" value="recovery_issue_confirm" />
      <input type="hidden" name="server_token" value="' . $_SESSION['server_token'] . '" />
      ' . pmeikieeUiHiddenReturnTo($returnTo) . '
      ' . $fields . '
      <button type="submit">確認する</button>
    </form>
    <form method="post" class="account_form">
      <input type="hidden" name="account_action" value="recovery_issue_back" />
      <input type="hidden" name="server_token" value="' . $_SESSION['server_token'] . '" />
      ' . pmeikieeUiHiddenReturnTo($returnTo) . '
      <button type="submit" class="secondary">コードをもう一度確認する</button>
    </form>
  ';
}

/**
 * 保存容量の利用状況を、2本のバーで描画します。
 *   1本目: このuserData専用の小さな枠(フォロー中一覧・各サービスの設定値。
 *          上限USER_DATA_TOTAL_MAX_BYTES)。
 *   2本目: p-drive全体の実際の使用量(p-memo・トークン管理等このメイキィが
 *          p-driveへ置いている全データの合算。上限P_DRIVE_USER_TOTAL_MAX_BYTES)。
 * ファイル自体の閲覧・アップロード・削除はここでは行わない(p-drive自身の
 * 「顔」(https://p-drive.pusyuuwanko.com/)で行う。ここは実際の使用量を
 * 見るための簡易表示に徹する)。
 * JavaScriptを一切使わず、メイキィ編集画面を開いた時点でサーバ側(PHP)がそのまま
 * 出力します(数値・バーの幅もすべてリクエスト時点でPHPが計算済みです)。
 */
function renderUserDataUsage(array $user, ?string $returnTo): string {
  $userData = pmeikieeResolveUserData($user);
  $totalBytes = pmeikieeUserDataTotalBytes($userData);
  $usedBlocks = pmeikieeUserDataBytesToBlocks($totalBytes);
  $percent = USER_DATA_TOTAL_MAX_BYTES > 0 ? min(100, ($totalBytes / USER_DATA_TOTAL_MAX_BYTES) * 100) : 0;

  $storageId = (string)($user['storage_id'] ?? '');
  $overallUsage = $storageId !== '' ? pDriveEngineUsage($storageId) : ['ok' => false];
  $overallUsedBytes = !empty($overallUsage['ok']) ? (int)($overallUsage['bytes'] ?? 0) : 0;
  $overallMaxBytes = !empty($overallUsage['ok']) ? (int)($overallUsage['max_bytes'] ?? P_DRIVE_USER_TOTAL_MAX_BYTES) : P_DRIVE_USER_TOTAL_MAX_BYTES;
  $overallPercent = $overallMaxBytes > 0 ? min(100, ($overallUsedBytes / $overallMaxBytes) * 100) : 0;

  $html = '
    <h3>保存データの利用状況</h3>

    <h4>このメイキィ全体(p-drive)</h4>
    <p class="note">p-memoのメモ・その他各サービスの保存分をすべて合算した、実際の使用量です。ファイルの閲覧・アップロード・削除は<a href="https://p-drive.pusyuuwanko.com/" target="_blank">p-drive</a>で行えます。</p>
    <div class="usage_bar" role="img"
         aria-label="全体の保存容量 ' . htmlspecialchars(pmeikieeFormatBytes($overallUsedBytes), ENT_QUOTES, 'UTF-8') . ' / ' . htmlspecialchars(pmeikieeFormatBytes($overallMaxBytes), ENT_QUOTES, 'UTF-8') . ' 使用中">
      <div class="usage_bar_fill" style="width: ' . htmlspecialchars((string)$overallPercent, ENT_QUOTES, 'UTF-8') . '%;"></div>
    </div>
    <p class="note">' . pmeikieeFormatBytes($overallUsedBytes) . ' / ' . pmeikieeFormatBytes($overallMaxBytes) . '</p>

    <h4>フォロー中一覧・各サービスの設定値専用の枠</h4>
    <p class="note">上の全体枠のうち、ごく小さな一部分です(上限' . pmeikieeFormatBytes(USER_DATA_TOTAL_MAX_BYTES) . ')。</p>
    <div class="usage_bar" style="--usage-blocks: ' . USER_DATA_TOTAL_BLOCKS . ';" role="img"
         aria-label="保存容量 ' . htmlspecialchars((string)round($usedBlocks, 1), ENT_QUOTES, 'UTF-8') . ' / ' . USER_DATA_TOTAL_BLOCKS . 'ブロック使用中">
      <div class="usage_bar_fill" style="width: ' . htmlspecialchars((string)$percent, ENT_QUOTES, 'UTF-8') . '%;"></div>
    </div>
    <p class="note">' . round($usedBlocks, 1) . ' / ' . USER_DATA_TOTAL_BLOCKS . 'ブロック使用中(' . pmeikieeFormatBytes($totalBytes) . ' / ' . pmeikieeFormatBytes(USER_DATA_TOTAL_MAX_BYTES) . ')</p>
  ';

  $groups = '';
  $hasAny = false;
  foreach ($userData as $service => $keys) {
    if (!is_array($keys) || empty($keys)) { continue; }
    $hasAny = true;

    $serviceTotal = 0;
    $rows = '';
    foreach ($keys as $key => $value) {
      $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
      $size = $encoded === false ? 0 : strlen($encoded);
      $serviceTotal += $size;

      // フォロー中一覧は「不要なデータの掃除」ではなく実際の社会的な機能なので、
      // ここから丸ごと削除させてしまうのは危険です。閲覧のみにして、実際の解除は
      // 「フォロー中」タブ(1件ずつのアンフォロー)から行うようにします。
      $isProtected = ($service === 'pips' && $key === 'following');
      $actionHtml = $isProtected
        ? '<span class="note">この項目はシステムですので削除できません。各種サービス内から削除できる場合もあります。</span>'
        : '
          <form method="post" class="nav_form">
            <input type="hidden" name="account_action" value="userdata_delete" />
            <input type="hidden" name="server_token" value="' . $_SESSION['server_token'] . '" />
            <input type="hidden" name="service" value="' . htmlspecialchars((string)$service, ENT_QUOTES, 'UTF-8') . '" />
            <input type="hidden" name="key" value="' . htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8') . '" />
            ' . pmeikieeUiHiddenReturnTo($returnTo) . '
            <button type="submit" class="danger small">削除</button>
          </form>
        ';

      $rows .= '
        <li class="userdata_row">
          <span class="userdata_name">' . htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8') . '</span>
          <span class="userdata_size">' . pmeikieeFormatBytes($size) . '</span>
          ' . $actionHtml . '
        </li>
      ';
    }

    $groups .= '
      <div class="userdata_group">
        <h4>' . htmlspecialchars((string)$service, ENT_QUOTES, 'UTF-8') . ' <span class="userdata_size">(' . pmeikieeFormatBytes($serviceTotal) . ')</span></h4>
        <ul class="userdata_list">' . $rows . '</ul>
      </div>
    ';
  }

  $html .= $hasAny
    ? $groups
    : '<p class="note">まだ保存されているデータはありません。</p>';

  return $html;
}


/**
 * p-memoの「プロダクト」機能(main/pusyuusystem/documents/futures.jsonから他サービスへの
 * リンク集を取得して描画する)と同じ考え方をそのまま持ち込んだものです。
 */
function pmeikieeProductListHtml(): string {
  // 【__DIR__ を使わないこと】このファイルは入口(実行されるスクリプトそのもの)なので、
  // PHPがカレントディレクトリをこのフォルダにしてくれます。素の相対パスで足ります。
  $products = @file_get_contents('./../main/pusyuusystem/documents/futures.json');
  if ($products === false) {
    return '<p class="note">プロダクト一覧を読み込めませんでした。</p>';
  }
  $data = json_decode($products, true);
  if (!isset($data['links']) || !is_array($data['links'])) {
    return '<p class="note">プロダクト一覧の解析に失敗しました。</p>';
  }

  $selfUrl = 'https://' . PMEIKIEE_SELF_HOST;
  $html = '<div class="yokoori center">';
  foreach ($data['links'] as $product) {
    if (($product['url'] ?? '') === $selfUrl) { continue; }
    $image = !empty($product['image']) ? $product['image'] : DEFAULT_AVATAR_URL;
    $title = htmlspecialchars((string)($product['title'] ?? ''), ENT_QUOTES, 'UTF-8');
    $html .= '
      <a class="app_link" href="' . htmlspecialchars((string)($product['url'] ?? ''), ENT_QUOTES, 'UTF-8') . '" title="' . $title . '" target="_blank">
        <div class="app_wrapper">
          <div class="app_box-1"><img src="' . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '" alt="image of app" width="100%" height="100%"></div>
          <p class="app_name">' . $title . '</p>
        </div>
      </a>
    ';
  }
  $html .= '</div>';
  return $html;
}

/** アバターアップロードフォームと、名前・メール・ユーザー名・自己紹介の編集フォーム。 */
/**
 * 編集フォームの上に、実際に他サービスから見えるプロフィール(アバター・名前・
 * 自己紹介)をそのまま表示します。ここは「見せる」だけの部分なのでinput化せず、
 * 素のテキストとして描画します。
 */
function renderProfilePreview(array $user): string {
  $bio = trim((string)($user['bio'] ?? ''));
  // .profile_preview_bio はCSS側で white-space: pre-wrap を指定して改行をそのまま
  // 見た目の改行として表示しているため、ここで追加にnl2br()すると改行1つにつき
  // <br>+実際の改行文字の2つ分が積み重なり、1回の改行が2行分空いて見えるバグに
  // なっていました。CSS側に任せてこちらではエスケープのみ行います。
  $bioHtml = $bio !== ''
    ? '<p class="profile_preview_bio">' . htmlspecialchars($bio, ENT_QUOTES, 'UTF-8') . '</p>'
    : '<p class="note">自己紹介はまだ設定されていません。</p>';

  return '
    <div class="profile_preview">
      <div class="profile_preview_avatar"><img src="' . htmlspecialchars(pmeikieeAvatarUrl($user), ENT_QUOTES, 'UTF-8') . '" alt="" /></div>
      <div class="profile_preview_body">
        <p class="profile_preview_name">' . htmlspecialchars($user['name'] ?? '', ENT_QUOTES, 'UTF-8') . '</p>
        <p class="profile_preview_username">@' . htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8') . '</p>
        ' . $bioHtml . '
      </div>
    </div>
  ';
}

function renderBasicInfoSection(array $user, ?string $returnTo): string {
  return '
    <h2>基本情報</h2>
    ' . renderProfilePreview($user) . '
    <form method="post" enctype="multipart/form-data" class="account_form">
      <input type="hidden" name="account_action" value="avatar_upload" />
      <input type="hidden" name="server_token" value="' . $_SESSION['server_token'] . '" />
      ' . pmeikieeUiHiddenReturnTo($returnTo) . '
      <label>プロフィール画像:<input type="file" name="avatar" accept="image/png,image/jpeg,image/webp,image/gif" /></label>
      <button type="submit">画像をアップロード</button>
    </form>
    <form method="post" class="account_form">
      <input type="hidden" name="account_action" value="edit" />
      <input type="hidden" name="server_token" value="' . $_SESSION['server_token'] . '" />
      ' . pmeikieeUiHiddenReturnTo($returnTo) . '
      <label>お名前:<input type="text" name="name" value="' . htmlspecialchars($user['name'] ?? '', ENT_QUOTES, 'UTF-8') . '" /></label><br>
      <label>メールアドレス:<input type="email" name="email" value="' . htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8') . '" required /></label><br>
      <label>ユーザー名:<input type="text" name="username" value="' . htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8') . '" required /></label><br>
      <label>自己紹介:<textarea name="bio" maxlength="' . BIO_MAX_LENGTH . '" rows="4">' . htmlspecialchars($user['bio'] ?? '', ENT_QUOTES, 'UTF-8') . '</textarea></label>
      <button type="submit">変更を保存</button>
    </form>
  ';
}

/**
 * パスワードのみの変更フォームです。pmeikieeEdit()は名前・メール・ユーザー名も
 * 必須項目として要求するため、変更しない値をhiddenで一緒に送ります(このタブでは
 * 見せていないだけで、実際に書き換わるわけではありません)。
 *
 * 「ログイン状態を保持する」もここに同居させている。ログイン方法・認証の
 * 持続期間という点でパスワードと近い関心事のため、基本情報タブとは分けて
 * まとめてある。
 */
function renderPasswordSection(array $user, ?string $returnTo): string {
  $rememberEnabled = pmeikieeRememberEnabled();
  return '
    <h2>パスワード変更</h2>
    <form method="post" class="account_form">
      <input type="hidden" name="account_action" value="edit" />
      <input type="hidden" name="server_token" value="' . $_SESSION['server_token'] . '" />
      <input type="hidden" name="name" value="' . htmlspecialchars($user['name'] ?? '', ENT_QUOTES, 'UTF-8') . '" />
      <input type="hidden" name="email" value="' . htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8') . '" />
      <input type="hidden" name="username" value="' . htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8') . '" />
      <input type="hidden" name="bio" value="' . htmlspecialchars($user['bio'] ?? '', ENT_QUOTES, 'UTF-8') . '" />
      ' . pmeikieeUiHiddenReturnTo($returnTo) . '
      <label>新しいパスワード:<input type="password" name="new_password" required minlength="' . PASSWORD_MIN_LENGTH . '" maxlength="' . PASSWORD_MAX_LENGTH . '" /></label><br>
      <p class="note">' . PASSWORD_MIN_LENGTH . '文字以上、英大文字・英小文字・数字をすべて含めてください。</p>
      <label>新しいパスワード(確認):<input type="password" name="new_password_confirm" required /></label><br>
      <button type="submit">パスワードを変更</button>
    </form>
    <h2>この端末内へのログイン状態の保持の選択</h2>
    <p>有効にすると、普段はサーバー内（プシューメイキー）にログイン情報を保持しますがサーバーとの接続を終了しても保持できるように、この端末内にログイン情報最大30日間ログイン情報を保持できるようにします(連携している各サービスでも同様です)。しかしこの端末内に保存する故セキュリティレベルが若干下がりますので共有端末では有効にしないことをおすすめします。</p>
    <form method="post" class="account_form">
      <input type="hidden" name="account_action" value="remember_me_set" />
      <input type="hidden" name="server_token" value="' . $_SESSION['server_token'] . '" />
      <input type="hidden" name="enabled" value="' . ($rememberEnabled ? '0' : '1') . '" />
      ' . pmeikieeUiHiddenReturnTo($returnTo) . '
      <button type="submit">' . ($rememberEnabled ? 'ログイン状態の保持をやめる' : 'ログイン状態を保持する') . '</button>
    </form>
  ';
}

/**
 * リカバリコードの残数表示と「作り直す」ボタン。作り直すと、まだ使っていない
 * 古いコードも含めて全て無効化され、新しい5本に完全に入れ替わります
 * (新旧混在させない)。
 */
function renderRecoverySection(array $user, ?string $returnTo): string {
  $remaining = pmeikieeRecoveryCodesRemaining($user);
  $issuedAt = !empty($user['recovery_codes_issued_at']) ? date('Y-m-d H:i', (int)$user['recovery_codes_issued_at']) : '未発行';
  $warn = $remaining <= 1 ? '<p class="oppai-alert error" style="color:#dc2626;">残り本数が少なくなっています。今すぐ作り直すことをおすすめします。</p>' : '';
  return '
    <h2>リカバリコード</h2>
    <p>パスワードを忘れた場合に使う、使い捨ての復旧コードです。現在の残り本数: <strong>' . $remaining . '本</strong>(最終発行: ' . htmlspecialchars($issuedAt, ENT_QUOTES, 'UTF-8') . ')</p>
    ' . $warn . '
    <p class="note">作り直すと、まだ使っていない古いコードも含めて全て無効になり、新しい5本に入れ替わります。</p>
    <form method="post" class="account_form" onsubmit="return confirm(\'リカバリコードを作り直しますか?既存のコードは全て無効になります。\');">
      <input type="hidden" name="account_action" value="recovery_regenerate_start" />
      <input type="hidden" name="server_token" value="' . $_SESSION['server_token'] . '" />
      ' . pmeikieeUiHiddenReturnTo($returnTo) . '
      <button type="submit">リカバリコードを作り直す</button>
    </form>
  ';
}

function renderDeleteSection(?string $returnTo): string {
  return '
    <h2>メイキィ削除</h2>
    <p>メイキィを削除すると、連携している他サービスでの署名や投稿の編集権限も失います。削除したメイキィは復元できませんのでご注意ください。</p>
    <a class="button danger" href="./?account=delete' . ($returnTo !== null ? '&return_to=' . urlencode($returnTo) : '') . '">メイキィ削除へ進む</a>
  ';
}

/** userData[service][key]配列のプロフィール一覧を、1件ずつのli要素として描画する共通処理。 */
function renderProfileListItems(array $profiles, ?callable $actionRenderer = null): string {
  $rows = '';
  foreach ($profiles as $profile) {
    $name = htmlspecialchars($profile['name'] ?? '', ENT_QUOTES, 'UTF-8');
    $username = htmlspecialchars($profile['username'] ?? '', ENT_QUOTES, 'UTF-8');
    $rows .= '
      <li class="userdata_row">
        <span class="userdata_name">' . $name . ' <span class="userdata_size">(@' . $username . ')</span></span>
        ' . ($actionRenderer !== null ? $actionRenderer($profile) : '') . '
      </li>
    ';
  }
  return '<ul class="userdata_list">' . $rows . '</ul>';
}

/**
 * フォロー中一覧(自分がフォローしている相手)。1件ずつ解除できます。
 *
 * 【逆引き済みの配列を受け取ること】following配列が持つのは相手の生idを
 * 知り得ないハッシュだけなので、表示用の名前を得るには
 * pmeikieeResolveUserIdHashes()での逆引き(全アカウント走査)が要ります。
 * それを以前はこの関数の中で行っていましたが、呼び出し元のサイドバーが
 * 「フォロー中 N」という件数を別途count()で出しており、退会した相手の
 * ハッシュが残っている場合に数と一覧が食い違っていました。
 * 逆引きは呼び出し元で一度だけ行い、その結果をここへ渡す形にしています。
 * この関数が自分で逆引きし直すように戻すと、その食い違いが復活します。
 */
function renderFollowingSection(array $profiles, ?string $returnTo): string {
  $html = '<h2>フォロー中</h2>';
  if (empty($profiles)) {
    return $html . '<p class="note">まだ誰もフォローしていません。</p>';
  }

  $returnToField = pmeikieeUiHiddenReturnTo($returnTo);
  $serverToken = $_SESSION['server_token'];
  $html .= renderProfileListItems($profiles, function (array $profile) use ($returnToField, $serverToken): string {
    return '
      <form method="post" class="nav_form">
        <input type="hidden" name="account_action" value="unfollow" />
        <input type="hidden" name="server_token" value="' . $serverToken . '" />
        <input type="hidden" name="userid" value="' . htmlspecialchars($profile['userid'] ?? '', ENT_QUOTES, 'UTF-8') . '" />
        ' . $returnToField . '
        <button type="submit" class="danger small">解除</button>
      </form>
    ';
  });
  return $html;
}

/**
 * フォロワー一覧(自分をフォローしている相手)。閲覧のみで解除機能はありません
 * (「相手にフォローをやめさせる」ボタンに相当し、フォロー機能としては通常
 * 提供しない操作のためです)。
 */
function renderFollowersSection(array $user): string {
  $html = '<h2>フォロワー</h2>';
  $profiles = pmeikieeUserDataReverseList((string)$user['storage_id'], 'pips', 'following');
  if (empty($profiles)) {
    return $html . '<p class="note">まだフォロワーはいません。</p>';
  }
  return $html . renderProfileListItems($profiles);
}

/**
 * $noticeHtml は「ログインしました」等、この画面を開いた直接のきっかけを伝える
 * 一時的なお知らせです。以前はこの引数を使わず呼び出し側で単純に文字列連結して
 * サイドバーの手前に置いていましたが、.settings-wrapper の外側(サイドバーの
 * margin-left補正が効かない場所)に置かれてしまい、PC幅ではposition:fixedの
 * サイドバーの下に隠れて実質見えなくなっていました。.settings-wrapper の内側・
 * 独立したカードとして描画することで、main/.account_cardと同じように埋もれず
 * 目立つ場所に表示されるようにしています。
 *
 * $noticeIsError を true にすると、お知らせカードを失敗用(赤)の見た目にします。
 * 「そのメールアドレスは既に使われています。」のような失敗の知らせを、成功と
 * 同じ緑のカードで出してしまうと意味が逆に伝わるためです。
 */
function renderEditForm(?string $returnTo, string $noticeHtml = '', bool $noticeIsError = false): string {
  $user = pmeikieeUiCurrentUser();
  if ($user === null) {
    return '<p>編集にはログインが必要です。</p><a href="./?account=login' . ($returnTo !== null ? '&return_to=' . urlencode($returnTo) : '') . '" class="button">ログイン</a>';
  }

  $userData = pmeikieeResolveUserData($user);

  // サイドバーには「保存データ(userData)」だけの小さな上限(5MB)ではなく、
  // p-memo・マイファイル等も含めた実際の全体使用量(P_DRIVE_USER_TOTAL_MAX_BYTES、
  // 現在5GB)を表示する。以前はここに🧱ブロック数(userDataだけの上限に対する
  // 割合)を出していたが、マイファイル機能でGB単位のファイルを置けるように
  // なった今、その表示のままだと「ほとんど空」に見えてしまい実態と合わない。
  $storageIdForUsage = (string)($user['storage_id'] ?? '');
  $overallUsage = $storageIdForUsage !== '' ? pDriveEngineUsage($storageIdForUsage) : ['ok' => false];
  $overallUsedBytes = !empty($overallUsage['ok']) ? (int)($overallUsage['bytes'] ?? 0) : 0;
  $overallMaxBytes = !empty($overallUsage['ok']) ? (int)($overallUsage['max_bytes'] ?? P_DRIVE_USER_TOTAL_MAX_BYTES) : P_DRIVE_USER_TOTAL_MAX_BYTES;

  // フォロー中/フォロワー数は本来accounts側が意味を関知しないpips固有のデータ
  // (service='pips', key='following')だが、ユーザーからの明示的な要望により
  // サイドバー表示だけの意図的な例外としてここで直接読みます。
  //
  // 【件数を配列の長さで数えないこと】following配列が持っているのは相手の
  // storage_idのハッシュだけで、相手が退会してもこの配列からは消えません
  // (退会処理が触るのは退会した本人のデータだけで、その人をフォローしていた
  // 全員の配列を書き換えて回るようなことはしないため)。つまり配列には
  // 「もう存在しない誰か」のハッシュが残り続けます。
  // 一覧側(renderFollowingSection())は逆引きできなかったハッシュを黙って
  // 落とすので、ここでcount()を使うと「フォロー中 3」と出ているのに2件しか
  // 並ばない、という食い違いが起きます。数と一覧は必ず同じ解決結果から
  // 作ること。そのためここで一度だけ逆引きし、その結果を一覧へ渡します
  // (逆引きは全アカウントの走査なので、二度やる意味もありません)。
  $followingHashes = is_array($userData['pips']['following'] ?? null) ? $userData['pips']['following'] : [];
  $followingProfiles = pmeikieeResolveUserIdHashes($followingHashes);
  $followingCount = count($followingProfiles);
  $followerCount = pmeikieeUserDataReverseCount((string)$user['storage_id'], 'pips', 'following');
  $recoveryRemaining = pmeikieeRecoveryCodesRemaining($user);

  if ($noticeHtml === '') {
    $notice = '';
  } elseif ($noticeIsError) {
    $notice = '<div class="settings-notice error">' . $noticeHtml . '</div>';
  } else {
    $notice = '<div class="settings-notice">' . $noticeHtml . '</div>';
  }

  $sidebar = '
    <input type="radio" name="account-tab" id="tab-basic" class="tab-ctrl" checked>
    <input type="radio" name="account-tab" id="tab-password" class="tab-ctrl">
    <input type="radio" name="account-tab" id="tab-following" class="tab-ctrl">
    <input type="radio" name="account-tab" id="tab-followers" class="tab-ctrl">
    <input type="radio" name="account-tab" id="tab-storage" class="tab-ctrl">
    <input type="radio" name="account-tab" id="tab-recovery" class="tab-ctrl">
    <input type="radio" name="account-tab" id="tab-products" class="tab-ctrl">
    <input type="radio" name="account-tab" id="tab-delete" class="tab-ctrl">

    <div class="sidebar">
      <div class="sidebar-brand">
        <div class="sidebar-avatar"><img src="' . htmlspecialchars(pmeikieeAvatarUrl($user), ENT_QUOTES, 'UTF-8') . '" alt="" /></div>
        <div>
          <p class="sidebar-name">' . htmlspecialchars($user['name'] ?? '', ENT_QUOTES, 'UTF-8') . '</p>
          <p class="sidebar-sub">@' . htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8') . '</p>
        </div>
      </div>
      <div class="sidebar-stats">
        <span title="保存データ・マイファイル・各サービスの保存分すべてを合算した実際の使用量">💾 ' . pmeikieeFormatBytes($overallUsedBytes) . ' / ' . pmeikieeFormatBytes($overallMaxBytes) . '</span>
        <label for="tab-following">フォロー中 ' . $followingCount . '</label>
        <label for="tab-followers">フォロワー ' . $followerCount . '</label>
        <label for="tab-recovery"' . ($recoveryRemaining <= 1 ? ' style="color:#dc2626;font-weight:bold;"' : '') . '>🔑 リカバリコード 残り' . $recoveryRemaining . '本</label>
      </div>
      <div class="sidebar-tab-labels">
        <label for="tab-basic" class="sidebar-tab-label">基本情報</label>
        <label for="tab-password" class="sidebar-tab-label">パスワード・ログイン保持</label>
        <label for="tab-following" class="sidebar-tab-label">フォロー中</label>
        <label for="tab-followers" class="sidebar-tab-label">フォロワー</label>
        <label for="tab-storage" class="sidebar-tab-label">保存データ</label>
        <label for="tab-recovery" class="sidebar-tab-label">リカバリコード</label>
        <label for="tab-products" class="sidebar-tab-label">プロダクト一覧</label>
        <label for="tab-delete" class="sidebar-tab-label danger">メイキィ削除</label>
      </div>
      <!--
        :has()未対応ブラウザ(IE等)では上の.sidebar-tab-labelsをCSS側で常に
        非表示にしています(タブが切り替わらないボタンを置いても意味が無いため)。
        その代わりにこちらは通常の<a href="#...">によるページ内ジャンプなので、
        :has()どころかCSSが一切効かなくても最低限アンカーとして機能します。
        :has()対応ブラウザでは上のタブUIと重複するため、CSS側で非表示にします。
      -->
      <div class="sidebar-tab-anchors">
        <a href="#section-basic" class="sidebar-tab-label">基本情報</a>
        <a href="#section-password" class="sidebar-tab-label">パスワード・ログイン保持</a>
        <a href="#section-following" class="sidebar-tab-label">フォロー中</a>
        <a href="#section-followers" class="sidebar-tab-label">フォロワー</a>
        <a href="#section-storage" class="sidebar-tab-label">保存データ</a>
        <a href="#section-recovery" class="sidebar-tab-label">リカバリコード</a>
        <a href="#section-products" class="sidebar-tab-label">プロダクト一覧</a>
        <a href="#section-delete" class="sidebar-tab-label danger">メイキィ削除</a>
      </div>
      <div class="sidebar-logout">
        <form method="post" class="nav_form">
          <input type="hidden" name="server_token" value="' . $_SESSION['server_token'] . '" />
          <input type="hidden" name="account_action" value="logout" />
          <button type="submit" class="link_button">ログアウト</button>
        </form>
      </div>
    </div>
  ';

  $sections = '
    <div class="tab-section basic" id="section-basic">' . renderBasicInfoSection($user, $returnTo) . '</div>
    <div class="tab-section password" id="section-password">' . renderPasswordSection($user, $returnTo) . '</div>
    <div class="tab-section following" id="section-following">' . renderFollowingSection($followingProfiles, $returnTo) . '</div>
    <div class="tab-section followers" id="section-followers">' . renderFollowersSection($user) . '</div>
    <div class="tab-section storage" id="section-storage">' . renderUserDataUsage($user, $returnTo) . '</div>
    <div class="tab-section recovery" id="section-recovery">' . renderRecoverySection($user, $returnTo) . '</div>
    <div class="tab-section products" id="section-products"><h2>プロダクト一覧</h2>' . pmeikieeProductListHtml() . '</div>
    <div class="tab-section delete" id="section-delete">' . renderDeleteSection($returnTo) . '</div>
  ';

  return '<div class="settings-wrapper">' . $notice . $sidebar . $sections . '</div>';
}

function renderDeleteForm(?string $returnTo): string {
  if (!pmeikieeUiIsLoggedIn()) {
    return '<p>削除にはログインが必要です。</p><a href="./?account=login' . ($returnTo !== null ? '&return_to=' . urlencode($returnTo) : '') . '" class="button">ログイン</a>';
  }
  return '
    <h2>メイキィ削除</h2>
    <form method="post" class="account_form">
      <input type="hidden" name="account_action" value="delete" />
      <input type="hidden" name="server_token" value="' . $_SESSION['server_token'] . '" />
      ' . pmeikieeUiHiddenReturnTo($returnTo) . '
      <label>ユーザー名:<input type="text" name="username" required /></label><br>
      <label>パスワード:<input type="password" name="password" required /></label><br>
      <button type="submit" class="danger">削除</button>
    </form>
  ';
}

// ---------------------------------------------------------------------
// POST処理
// ---------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!pmeikieeUiCsrfIsValid()) {
    $failedAction = pmeikieeUiPostStr('account_action');
    $failedSection = pmeikieeUiSectionForAction($failedAction);
    // 【ここが「一部の端末だけログインできない」の第一容疑者】
    // 画面には「ボタンが連続で押されたため処理を終了しました」としか出ませんが、
    // 実際にはボタンの二度押しに限らず、セッションCookieが往復しなかった場合も
    // ここへ落ちます。どちらなのかを区別できるように、揃っていた材料を残します。
    //   in_post=false            … フォームにトークンが入っていなかった
    //   in_session=false         … サーバー側にトークンが無い(=Cookieが来ていない
    //                              ので、GETで作ったセッションと別物になっている)
    //   両方true で一致しない     … 本当に古いフォームからの再送信(二度押し等)
    MeikieeLoginTrace::log('csrf_failed', [
      'action'         => $failedAction,
      'in_post'        => isset($_POST['server_token']) && is_string($_POST['server_token']),
      'in_session'     => isset($_SESSION['server_token']),
      'session_cookie' => isset($_COOKIE[session_name()]),
    ]);
    $msg = '<p>ボタンが連続で押されたため処理を終了しました。もう一度お試しください。</p>';
    if ($failedSection !== 'edit') {
      $msg .= pmeikieeUiRetryButton($failedSection, $returnTo);
    }
    pmeikieeUiFinishPost($failedSection, $returnTo, $msg, false);
  }

  $uiAction = pmeikieeUiPostStr('account_action');
  pmeikieeUiNewCsrf();

  // 強制フロー(リカバリコード発行・パスワード再設定)の最中は、対応するアクション
  // 以外を受け付けない(フォームを保存して直接POSTする等の迂回を防ぐため)。
  // GET側の表示ゲート(下部の「GET表示」節)と対になっています。
  //
  // 【判定材料が2つあるのは意図的。混ぜないこと】
  //   - アカウント側の義務(pmeikieeAccountMust*): ブラウザを閉じても消えない。
  //     作成直後・リカバリコードでのログイン後など「発行しないと本当に困る」場合。
  //   - このブラウザでの進行状況($_SESSION['recovery_issue_step']): 設定画面からの
  //     任意の「作り直す」はこちらだけが立つ。既存のコードはまだ有効で、途中で
  //     やめても失われる物が無いため、アカウントに義務を焼き付けて他サービスまで
  //     止める理由が無い(うっかり押した人が全サービスから締め出される)。
  $gateUser = pmeikieeUiIsLoggedIn() ? pmeikieeUiCurrentUser() : null;
  $issuingRecoveryCodes = $gateUser !== null
    && (pmeikieeAccountMustIssueRecoveryCodes($gateUser) || !empty($_SESSION['recovery_issue_step']));
  if ($issuingRecoveryCodes
      && !in_array($uiAction, ['recovery_issue_ack', 'recovery_issue_next', 'recovery_issue_back', 'recovery_issue_confirm', 'logout'], true)) {
    pmeikieeUiFinishPost('edit', null, '<p>先にリカバリーコードの発行手続きを完了してください。</p>' . renderRecoveryIssueStep(null), false);
  }
  if ($gateUser !== null && !$issuingRecoveryCodes && pmeikieeAccountMustResetPassword($gateUser)
      && !in_array($uiAction, ['edit', 'logout'], true)) {
    pmeikieeUiFinishPost('edit', null, '<p>先に新しいパスワードを設定してください。</p>' . renderForcedPasswordResetForm($gateUser, null), false);
  }

  switch ($uiAction) {
    case 'create': {
      // フォーム表示側(renderCreateForm)はログイン中なら作成フォーム自体を
      // 見せませんが、開いたままのタブや直接POSTからは素通りしてしまうため、
      // ここでも同じ方針を強制します。
      if (pmeikieeUiIsLoggedIn()) {
        pmeikieeUiFinishPost('create', $returnTo, '<p>別のメイキィを作成する場合は、先にログアウトしてください。</p>', false);
      }
      $result = pmeikieeCreate(
        pmeikieeUiPostStr('name'), pmeikieeUiPostStr('email'), pmeikieeUiPostStr('username'),
        pmeikieeUiPostStr('password'), pmeikieeUiPostStr('password_confirm')
      );
      if (!$result['ok']) {
        $msg = '<p>' . htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8') . '</p>' . pmeikieeUiRetryButton('create', $returnTo);
        pmeikieeUiFinishPost('create', $returnTo, $msg, false);
      }

      // 作成直後にそのままログインさせ(パスワードは今の入力値で確定検証済み)、
      // リカバリコード発行が完了するまでスキップできない状態にします。
      $loginResult = pmeikieeAuthenticate(pmeikieeUiPostStr('username'), pmeikieeUiPostStr('password'));
      if ($loginResult['ok']) {
        pmeikieeUiEstablishSession($loginResult['user'], (string)$loginResult['token']);
        $createdUser = pmeikieeUiCurrentUser();
        if ($createdUser !== null) {
          // 「発行し終えるまで他サービスへ引き渡さない」という義務は、セッションでは
          // なくアカウント側に記録する。セッションに持たせていた頃は、この画面で
          // ブラウザを閉じるだけで義務が消え、リカバリコードを1本も持たないまま
          // 使い始められた(次回ログイン時に再強制する仕組みも無かった)。
          pmeikieeSetAccountObligation((string)$createdUser['id'], OBLIGATION_ISSUE_RECOVERY_CODES, true);
        }
        pmeikieeUiFinishPost('edit', $returnTo, '<p>メイキィを作成しました。続けてリカバリコードを発行します。</p>' . renderRecoveryIssueStep($returnTo), false);
      }

      // 作成はできたがこの場での自動ログインだけ失敗した(理論上ほぼ起きない)場合は、
      // 従来どおり手動ログインへ誘導します(リカバリコードは次回ログイン後の
      // 設定画面から発行してもらう形になります)。
      $msg = '<p>メイキィを作成しました。</p><a href="./?account=login' . ($returnTo !== null ? '&return_to=' . urlencode($returnTo) : '') . '" class="button">ログイン</a>';
      pmeikieeUiFinishPost('create', $returnTo, $msg, false);
    }

    case 'login': {
      $result = pmeikieeAuthenticate(pmeikieeUiPostStr('username'), pmeikieeUiPostStr('password'));
      // 認証の結末。errorは内部の理由コード(invalid_input / too_many_attempts /
      // invalid_credentials / storage_error)で、画面の文言より細かく分かれます。
      // パスワードは長さだけ残します(値は絶対に書かない)。「打てているつもりで
      // 実は空」「自動入力で末尾に空白が入る」といった端末差はこれで見えます。
      MeikieeLoginTrace::log('authenticate', [
        'username'    => mb_substr(pmeikieeUiPostStr('username'), 0, 60),
        'pw_length'   => strlen(pmeikieeUiPostStr('password')),
        'ok'          => (bool)$result['ok'],
        'error'       => (string)($result['error'] ?? ''),
        'remember_me' => pmeikieeUiPostStr('remember_me') === '1',
      ]);
      if ($result['ok']) {
        pmeikieeUiEstablishSession($result['user'], (string)$result['token']);

        if (pmeikieeUiPostStr('remember_me') === '1') {
          pmeikieeSetRememberCookie((string)$result['token']);
        }

        $welcome = '<p>ようこそ' . htmlspecialchars((string)$_SESSION['name'], ENT_QUOTES, 'UTF-8') . 'さん、ログインしました。</p>';

        $currentUser = pmeikieeUiCurrentUser();
        if ($currentUser === null) {
          // 今発行したばかりのトークンが解決できない(理論上ほぼ起きない)。
          pmeikieeUiFinishPost('login', $returnTo, '<p>ログイン状態を確認できませんでした。もう一度お試しください。</p>' . pmeikieeUiRetryButton('login', $returnTo), false);
        }

        // 未完了の手続きが残っているなら、return_toより先にその画面へ。
        // ここを書かなくてもpmeikieeIssueHandoffCode()が引き渡しコードを出さないので
        // 素通りはしないが、その場合ユーザーには「戻せませんでした」としか出ず、
        // 何をすればいいのか分からない。理由を示すために明示的に分岐する。
        // 認証は通ったのに、ここで足止めされる経路。利用者からは
        // 「ログインしたはずなのに元のサービスへ戻れない」と見えます。
        MeikieeLoginTrace::log('obligations', [
          'must_issue_recovery_codes' => pmeikieeAccountMustIssueRecoveryCodes($currentUser),
          'must_reset_password'       => pmeikieeAccountMustResetPassword($currentUser),
        ]);

        if (pmeikieeAccountMustIssueRecoveryCodes($currentUser)) {
          pmeikieeUiFinishPost('edit', $returnTo, $welcome . '<p>リカバリコードの発行手続きが完了していません。先にこちらを終わらせてください。</p>' . renderRecoveryIssueStep($returnTo), false);
        }
        if (pmeikieeAccountMustResetPassword($currentUser)) {
          pmeikieeUiFinishPost('edit', $returnTo, $welcome . '<p>新しいパスワードの設定が完了していません。</p>' . renderForcedPasswordResetForm($currentUser, $returnTo), false);
        }

        if ($returnTo !== null) {
          $code = pmeikieeIssueHandoffCode((string)$currentUser['id']);
          // 引き渡しコードが出せたか。ログイン自体は成功しているので、ここが
          // falseなら「メイキィにはログインできているのに、pips等へ戻ると
          // 未ログインのまま」という形の不具合になります。
          MeikieeLoginTrace::log('handoff_code', ['issued' => $code !== null]);
          if ($code !== null) {
            header('Location: ' . pmeikieeAppendQuery($returnTo, ['pusyuu_code' => $code]));
            exit;
          }
          // コード発行に失敗しても、戻り先へのリンクくらいは出しておきます
          // (無音でなければ何もせず放置するとユーザーが元のページへ戻る手段を失います)。
          $welcome .= '<p class="note">自動的に元のページへ戻せませんでした。</p>'
            . '<a href="' . htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') . '" class="button">元のページへ戻る</a>';
          pmeikieeUiFinishPost('login', $returnTo, $welcome, false);
        }

        // return_to無し(accounts.example自身へ直接ログインした場合)は、
        // 「ログインしました」だけの行き止まりページを出さず、そのままメイキィ
        // 管理画面(サイドバー)へ進みます。歓迎メッセージはrenderEditForm()の
        // notice引数として渡し、.settings-notice(サイドバーのmargin補正が効く
        // .settings-wrapperの内側)として表示することで、サイドバーの下に隠れて
        // 埋もれないようにします。
        pmeikieeUiFinishPost('edit', null, renderEditForm(null, $welcome), true);
      }

      $msg = '<p>' . htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8') . '</p>' . pmeikieeUiRetryButton('login', $returnTo);
      pmeikieeUiFinishPost('login', $returnTo, $msg, false);
    }

    case 'edit': {
      $user = pmeikieeUiCurrentUser();
      if ($user === null) {
        pmeikieeUiFinishPost('login', $returnTo, '<p>編集にはログインが必要です。</p>', false);
      }
      $forcingPasswordReset = pmeikieeAccountMustResetPassword($user);
      $newPassword = pmeikieeUiPostStr('new_password');
      if ($forcingPasswordReset && $newPassword === '') {
        pmeikieeUiFinishPost('edit', $returnTo, '<p>先に新しいパスワードを設定してください。</p>' . renderForcedPasswordResetForm($user, $returnTo), false);
      }
      // リカバリコードでのログイン直後は、以前と同じパスワードの再設定を許さない
      // (単に前のパスワードを打ち直すだけで「再設定した」ことにできてしまうと、
      // 強制する意味が無くなるため)。
      if ($forcingPasswordReset && $newPassword !== '' && password_verify($newPassword, (string)($user['password'] ?? ''))) {
        pmeikieeUiFinishPost('edit', $returnTo, '<p>以前と同じパスワードは設定できません。別のパスワードを入力してください。</p>' . renderForcedPasswordResetForm($user, $returnTo), false);
      }
      // 強制の再設定中に許すのは「新しいパスワードを決めること」だけ。他の項目は
      // 現在値で固定する。強制画面(renderForcedPasswordResetForm)は名前・メール・
      // ユーザー名・自己紹介をhiddenで持っているので、それを書き換えて送れば
      // パスワード再設定に相乗りして別人のような名前・メールへ差し替えられた。
      if ($forcingPasswordReset) {
        $editName     = (string)($user['name'] ?? '');
        $editEmail    = (string)($user['email'] ?? '');
        $editUsername = (string)($user['username'] ?? '');
        $editBio      = (string)($user['bio'] ?? '');
      } else {
        $editName     = pmeikieeUiPostStr('name');
        $editEmail    = pmeikieeUiPostStr('email');
        $editUsername = pmeikieeUiPostStr('username');
        $editBio      = pmeikieeUiPostStr('bio');
      }

      $result = pmeikieeEdit(
        (string)$user['id'], $editName, $editEmail, $editUsername,
        $editBio, $newPassword, pmeikieeUiPostStr('new_password_confirm')
      );
      if ($result['ok']) {
        $_SESSION['name']     = $result['user']['name'];
        $_SESSION['username'] = $result['user']['username'];
        // パスワードを変更した場合、他端末等で発行済みの他のトークンは
        // (盗まれている可能性を考慮して)すべて失効させます。今のセッションの
        // トークンだけは残すので、この場でログアウトさせられることはありません。
        if ($newPassword !== '') {
          pmeikieeRevokeOtherTokens((string)$user['id'], $_SESSION['accounts_token'] ?? '');
        }

        if ($forcingPasswordReset && $newPassword !== '') {
          // OBLIGATION_RESET_PASSWORDはpmeikieeEdit()がパスワード保存と同じ
          // トランザクションで降ろしているので、ここでは何もしない。
          // リカバリコードでのログインでここまで来た場合、消費後の残数が
          // 1本以下ならこのまま続けて作り直しを強制します(スキップさせない)。
          $refreshedUser = pmeikieeFindById((string)$user['id']);
          if ($refreshedUser !== null && pmeikieeRecoveryCodesRemaining($refreshedUser) <= 1) {
            pmeikieeSetAccountObligation((string)$user['id'], OBLIGATION_ISSUE_RECOVERY_CODES, true);
            pmeikieeUiResetRecoveryIssueFlow();
            pmeikieeUiFinishPost('edit', null, '<p>新しいパスワードを設定しました。リカバリコードの残数が少ないため、続けて作り直します。</p>' . renderRecoveryIssueStep(null), false);
          }
          pmeikieeUiFinishPost('edit', null, renderEditForm(null, '<p>新しいパスワードを設定しました。</p>'), true);
        }

        // return_toが指定されていれば、呼び出し元サービスへ新しいコードを添えて
        // 自動で戻します(呼び出し元がexchange_codeで新しいトークンと更新後の
        // 名前・ユーザー名を受け取り直せるようにするためです)。
        if ($returnTo !== null) {
          $code = pmeikieeIssueHandoffCode((string)$user['id']);
          if ($code !== null) {
            header('Location: ' . pmeikieeAppendQuery($returnTo, ['pusyuu_code' => $code]));
            exit;
          }
        }
      }
      $msg = '<p>' . htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8') . '</p>';
      if ($result['ok'] && $returnTo !== null) {
        // ここに到達するのは、上のheader()+exitが(コード発行失敗で)実行されなかった場合のみです。
        $msg .= '<p class="note">自動的に元のページへ戻せませんでした。</p>'
          . '<a href="' . htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') . '" class="button">元のページへ戻る</a>';
      }
      pmeikieeUiFinishPost('edit', $returnTo, renderEditForm($returnTo, $msg, !$result['ok']), true);
    }

    // ---------------------------------------------------------------
    // リカバリコード: パスワードを忘れた場合の代替ログイン
    // ---------------------------------------------------------------
    case 'recovery_login': {
      if (pmeikieeUiIsLoggedIn()) {
        pmeikieeUiFinishPost('edit', $returnTo, '<p>ログイン中は使用できません。</p>', true);
      }
      $result = pmeikieeVerifyRecoveryCode(pmeikieeUiPostStr('username'), pmeikieeUiPostStr('recovery_code'));
      if (!$result['ok']) {
        $msg = '<p>' . htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8') . '</p>' . pmeikieeUiRetryButton('recovery', $returnTo);
        pmeikieeUiFinishPost('recovery', $returnTo, $msg, false);
      }

      $user = $result['user'];
      $token = pmeikieeIssueToken((string)$user['id']);
      if ($token === null) {
        pmeikieeUiFinishPost('recovery', $returnTo, '<p>ログイン情報の保存に失敗しました。しばらくしてからもう一度お試しください。</p>' . pmeikieeUiRetryButton('recovery', $returnTo), false);
      }

      pmeikieeUiEstablishSession(pmeikieePublicUser($user), $token);
      // 通常ログインと違い、必ずパスワード再設定を強制する(スキップ不可)。
      // その義務はpmeikieeVerifyRecoveryCode()が、コードを消費したのと同じ書き込みで
      // 既にアカウント側へ立てている(ここでセッションに立て直さないこと。
      // セッションに持つと、ブラウザを閉じるだけで再設定を回避できてしまう)。

      pmeikieeUiFinishPost('edit', null, '<p>リカバリコードでログインしました。続けて新しいパスワードを設定してください。</p>' . renderForcedPasswordResetForm($user, null), false);
    }

    // ---------------------------------------------------------------
    // リカバリコード発行フロー(新規作成後・設定画面からの作り直し、共通)
    // ---------------------------------------------------------------

    // 「作り直す」ボタン(設定画面から、強制フロー外での任意実行)。
    case 'recovery_regenerate_start': {
      $user = pmeikieeUiCurrentUser();
      if ($user === null) {
        pmeikieeUiFinishPost('login', $returnTo, '<p>リカバリコードの発行にはログインが必要です。</p>', false);
      }
      // ここではアカウント側の義務(OBLIGATION_ISSUE_RECOVERY_CODES)を立てない。
      // 今あるコードはまだ有効で、途中でやめても失われる物が無いため
      // (義務にすると、うっかり押した人が完了するまで全サービスから締め出される)。
      // このブラウザでウィザードを開いている、という進行状況だけを立てる。
      pmeikieeUiResetRecoveryIssueFlow();
      $_SESSION['recovery_issue_step'] = 'warning';
      pmeikieeUiFinishPost('edit', $returnTo, '', false);
    }

    // 危険提示画面の「理解しました」。ここでコードを生成し、セッションに一時保持します
    // (まだaccount.jsonlには保存しません。保存は転記確認が済んでから)。
    case 'recovery_issue_ack': {
      $user = pmeikieeUiCurrentUser();
      if ($user === null || !$issuingRecoveryCodes) {
        pmeikieeUiFinishPost('login', $returnTo, '<p>ログインが必要です。</p>', false);
      }
      $_SESSION['recovery_pending_codes'] = pmeikieeGenerateRecoveryCodes();
      // 生成したコードは、生成した本人のものとして紐付ける(pmeikieePendingRecoveryCodes()
      // 参照)。紐付けに使うのはstorage_idであって生idではない。
      $_SESSION['recovery_pending_for'] = (string)($user['storage_id'] ?? '');
      $_SESSION['recovery_issue_step'] = 'card';
      pmeikieeUiFinishPost('edit', $returnTo, '', false);
    }

    // 印刷用カード画面の「保存しました、次へ」。
    case 'recovery_issue_next': {
      $user = pmeikieeUiCurrentUser();
      if ($user === null || !$issuingRecoveryCodes || pmeikieePendingRecoveryCodes($user) === null) {
        pmeikieeUiFinishPost('login', $returnTo, '<p>ログインが必要です。</p>', false);
      }
      $_SESSION['recovery_issue_step'] = 'confirm';
      pmeikieeUiFinishPost('edit', $returnTo, '', false);
    }

    // 転記確認画面の「コードをもう一度確認する」。ここで同じコード(recovery_pending_codes)
    // をそのまま見せ直すと、確認画面の入力欄に出た値を一時的に暗記しただけで
    // (実際には保存していなくても)戻る→もう一度見る→暗記した値を打つ、で
    // 転記確認そのものを素通りできてしまいます。account.jsonlへの保存はまだ
    // 行われていない(recovery_issue_confirmで全本一致した時点で初めて保存する)
    // ので、ここで作り直しても問題は無く、むしろ作り直して確認をやり直させます。
    case 'recovery_issue_back': {
      $user = pmeikieeUiCurrentUser();
      if ($user === null || !$issuingRecoveryCodes || pmeikieePendingRecoveryCodes($user) === null) {
        pmeikieeUiFinishPost('login', $returnTo, '<p>ログインが必要です。</p>', false);
      }
      $_SESSION['recovery_pending_codes'] = pmeikieeGenerateRecoveryCodes();
      $_SESSION['recovery_pending_for'] = (string)($user['storage_id'] ?? '');
      $_SESSION['recovery_issue_step'] = 'card';
      $_SESSION['recovery_regenerated_notice'] = true;
      pmeikieeUiFinishPost('edit', $returnTo, '', false);
    }

    // 転記確認画面。発行した5本すべてが一致すれば、ここで初めてハッシュ化して
    // 確定保存します(1本だけの確認だと、画面に表示されているものをその場で
    // 見て打つだけで通過できてしまうため、全本チェックにしています)。
    case 'recovery_issue_confirm': {
      $user = pmeikieeUiCurrentUser();
      $pending = $user === null ? null : pmeikieePendingRecoveryCodes($user);
      if ($user === null || !$issuingRecoveryCodes || $pending === null) {
        pmeikieeUiFinishPost('login', $returnTo, '<p>ログインが必要です。</p>', false);
      }
      // どの本が間違っているかは(たとえ本人の入力ミスであっても)教えない。
      // 全体が一致したかどうかだけを判定する。
      $allMatched = true;
      foreach ($pending as $i => $expected) {
        $input = strtoupper(trim(pmeikieeUiPostStr('confirm_code_' . $i)));
        if (!hash_equals((string)$expected, $input)) {
          $allMatched = false;
        }
      }
      if (!$allMatched) {
        $_SESSION['recovery_confirm_error'] = true;
        pmeikieeUiFinishPost('edit', $returnTo, '', false);
      }

      // 発行義務(OBLIGATION_ISSUE_RECOVERY_CODES)は、pmeikieeSaveRecoveryCodes()が
      // コードの保存と同じトランザクションで降ろす。ここで消すのは、このブラウザの
      // 進行状況だけ。
      $saved = pmeikieeSaveRecoveryCodes((string)$user['id'], $pending);
      pmeikieeUiResetRecoveryIssueFlow();
      if (!$saved) {
        // 保存できていないので、義務(立っていた場合)はアカウント側に残ったまま。
        // 表示していたコードはどこにも記録されていないため使えない。ウィザードを
        // 最初(危険提示画面)から開き直す。任意の作り直し中でここに来た場合、
        // 進行状況を立て直さないと次の「理解しました」が受け付けられなくなるため、
        // 義務の有無にかかわらず必ずここで立てる。
        $_SESSION['recovery_issue_step'] = 'warning';
        pmeikieeUiFinishPost('edit', $returnTo, '<p>保存中にエラーが発生しました。先ほど表示したコードは保存されていないため使えません。お手数ですが、もう一度最初から発行してください。</p>' . renderRecoveryIssueStep($returnTo), false);
      }
      pmeikieeUiFinishPost('edit', $returnTo, renderEditForm($returnTo, '<p>リカバリコードを発行しました。</p>'), true);
    }

    // メイキィ編集画面の「基本情報」タブから、プロフィール画像をアップロードします。
    // 他のアクションと違いreturn_toでは呼び出し元へ戻さず、編集画面に留まります
    // (画像を確認しながら必要ならもう一度選び直したい場合があるためです)。
    case 'avatar_upload': {
      $user = pmeikieeUiCurrentUser();
      if ($user === null) {
        pmeikieeUiFinishPost('login', $returnTo, '<p>画像のアップロードにはログインが必要です。</p>', false);
      }
      if (!isset($_FILES['avatar'])) {
        pmeikieeUiFinishPost('edit', $returnTo, renderEditForm($returnTo, '<p>画像が選択されていません。</p>', true), true);
      }
      $result = pmeikieeAvatarSave($user, $_FILES['avatar']);
      $msg = renderEditForm($returnTo, '<p>' . htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8') . '</p>', !$result['ok']);
      pmeikieeUiFinishPost('edit', $returnTo, $msg, true);
    }

    // メイキィ編集画面の「保存データの利用状況」から、service/keyを1件だけ削除します。
    // return_toが指定されていても、ここでは呼び出し元へは戻さず編集画面に留まります
    // (データ整理のついでにほかの項目も削除したい場合があるためです)。
    case 'userdata_delete': {
      $user = pmeikieeUiCurrentUser();
      if ($user === null) {
        pmeikieeUiFinishPost('login', $returnTo, '<p>データの削除にはログインが必要です。</p>', false);
      }
      $result = pmeikieeUserDataDelete($user, pmeikieeUiPostStr('service'), pmeikieeUiPostStr('key'));
      $msg = renderEditForm($returnTo, '<p>' . htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8') . '</p>', !$result['ok']);
      pmeikieeUiFinishPost('edit', $returnTo, $msg, true);
    }

    // メイキィ編集画面の「基本情報」タブから、「ログイン状態を保持する」を切り替えます。
    case 'remember_me_set': {
      $user = pmeikieeUiCurrentUser();
      if ($user === null) {
        pmeikieeUiFinishPost('login', $returnTo, '<p>この設定の変更にはログインが必要です。</p>', false);
      }
      $enabled = pmeikieeUiPostStr('enabled') === '1';
      if ($enabled) {
        pmeikieeSetRememberCookie((string)$_SESSION['accounts_token']);
        $msg = '<p>ログイン状態の保持を有効にしました。</p>';
      } else {
        pmeikieeClearRememberCookie();
        $msg = '<p>ログイン状態の保持を無効にしました。</p>';
      }
      pmeikieeUiFinishPost('edit', $returnTo, renderEditForm($returnTo, $msg), true);
    }

    // メイキィ編集画面の「フォロー中」タブから、1件だけ解除します。
    case 'unfollow': {
      $user = pmeikieeUiCurrentUser();
      if ($user === null) {
        pmeikieeUiFinishPost('login', $returnTo, '<p>フォロー解除にはログインが必要です。</p>', false);
      }
      // フォームから来るuseridは、フォロー中一覧に入っているstorage_id(ハッシュ)。
      // 生idではない(pmeikieeUiEstablishSession()のコメント参照)。
      $result = pmeikieeUserDataListRemove($user, 'pips', 'following', pmeikieeUiPostStr('userid'));
      $msg = renderEditForm($returnTo, '<p>' . htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8') . '</p>', !$result['ok']);
      pmeikieeUiFinishPost('edit', $returnTo, $msg, true);
    }

    case 'delete': {
      $user = pmeikieeUiCurrentUser();
      if ($user === null) {
        pmeikieeUiFinishPost('login', $returnTo, '<p>削除にはログインが必要です。</p>', false);
      }
      $result = pmeikieeDelete((string)$user['id'], pmeikieeUiPostStr('username'), pmeikieeUiPostStr('password'));
      if ($result['ok']) {
        pmeikieeRevokeToken($_SESSION['accounts_token'] ?? '');
        pmeikieeUiResetSession();
        // return_toが指定されていれば呼び出し元サービスへ戻します。トークンは
        // 既に失効しているので、呼び出し元は次にトークンを検証したタイミングで
        // 自然にログアウト扱いになります。
        if ($returnTo !== null) {
          header('Location: ' . $returnTo);
          exit;
        }
        $msg = '<p>' . htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8') . '</p>'
          . ' <a href="https://pusyuuwanko.com/" class="button">プシューサービスのトップページへ戻る</a>';
        pmeikieeUiFinishPost('login', null, $msg, false);
      }
      $msg = '<p>' . htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8') . '</p>' . pmeikieeUiRetryButton('delete', $returnTo);
      pmeikieeUiFinishPost('delete', $returnTo, $msg, false);
    }

    case 'logout': {
      pmeikieeRevokeToken($_SESSION['accounts_token'] ?? '');
      pmeikieeUiResetSession();
      // 以前はメッセージだけで、ここからログインし直すボタンが無い行き止まりに
      // なっていました。ログインフォーム自体を続けて表示し、その場でログイン
      // し直せるようにします(pmeikieeUiResetSession()後なのでちゃんと未ログイン
      // 状態のフォームが返ります)。
      pmeikieeUiFinishPost('login', null, '<h3>通知</h3><p style="color: #009900;">あなたのメイキィはログアウトされました。</p>' . renderLoginForm(null), false);
    }
  }
}

// ---------------------------------------------------------------------
// GET表示(フォーム)
// ---------------------------------------------------------------------

// 直前のPOST処理からのリダイレクト(PRGパターン)であれば、その結果を最優先で
// 1回だけ表示します。無ければ通常どおり?accountの内容に応じたフォームを描画します。
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  $flash = pmeikieeUiPopFlash();
  if ($flash !== null) {
    $display = $flash['display'];
    $useSidebarLayout = $flash['sidebar'];
  }
}

if ($display === '' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  // 強制フローのゲート: リカバリコード発行の途中、またはリカバリコードで
  // ログインした直後(パスワード再設定が済むまで)は、?accountに何が指定されて
  // いても専用の強制画面だけを表示する(他のタブ・URLへは進めない)。
  // POST側のゲート(上部の「POST処理」節)と対になっている。
  // 判定はPOST側のゲートと同じ組み合わせ(アカウント側の義務 or このブラウザでの
  // 進行状況)。片方だけ直すと表示と受付がずれるので、必ず両方を同じ形に保つこと。
  $gateUser = pmeikieeUiIsLoggedIn() ? pmeikieeUiCurrentUser() : null;
  if ($gateUser !== null
      && (pmeikieeAccountMustIssueRecoveryCodes($gateUser) || !empty($_SESSION['recovery_issue_step']))) {
    $display = renderRecoveryIssueStep($returnTo);
  } elseif ($gateUser !== null && pmeikieeAccountMustResetPassword($gateUser)) {
    $display = renderForcedPasswordResetForm($gateUser, $returnTo);
  } else {
    $section = $_GET['account'] ?? null;
    if ($section === null) {
      // クエリパラメータ無しでの直接アクセス(例: https://accounts.example/)では、
      // ログイン済みなら「ログイン済みです」という一枚挟むだけの画面を見せず、
      // 直接メイキィ管理画面(サイドバー)を表示します。明示的に?account=loginで
      // 来た場合(pips/p-memoのログインリンク等)は、従来どおりrenderLoginForm()の
      // 「ログイン済みです」案内(戻るボタン付き)を経由します。
      $section = pmeikieeUiCurrentUser() !== null ? 'edit' : 'login';
    }
    switch ($section) {
      case 'create': $display = renderCreateForm($returnTo); break;
      case 'edit':
        // ログインしていない場合、renderEditForm()は(サイドバーではなく)
        // 通常のカード表示に収まる案内メッセージを返すので、その場合は
        // $useSidebarLayoutをtrueにしません。
        $useSidebarLayout = pmeikieeUiCurrentUser() !== null;
        $display = renderEditForm($returnTo);
        break;
      case 'delete':   $display = renderDeleteForm($returnTo); break;
      case 'login':    $display = renderLoginForm($returnTo); break;
      case 'recovery': $display = renderRecoveryLoginForm($returnTo); break;
      default:         $display = renderLoginForm($returnTo);
    }
  }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex,nofollow" />
  <link rel="shortcut icon" href="https://pusyuuwanko.com/pusyuusystem/images/favicon.ico" />
  <title>プシューサービス - プシューメイキィ</title>
  <link rel="stylesheet" href="./assets/styles/style.css" />
  <script src="https://pusyuuwanko.com/pusyuusystem/scripts/request-indicator.js" data-include-async="true"></script>
   <!--
      *----------------------------------
      |  ThisPageVersion: 2.0.0       |
      |  © 2021-2026 By ISAMI ABE     |
      |  LastUpdate: 2026-09-18       |
      |  License: MIT License         |
      |  PusyuuMeikiee                |
    ----------------------------------*
  -->
</head>
<body>
  <?php if (!$useSidebarLayout) { ?>
  <!--
    サイドバー(メイキィ編集画面)にはアバター・名前・ログアウトが既に
    含まれているため、常時表示のヘッダーは出しません(ログイン/作成/削除画面など
    サイドバーが無いページでのみ、この最小限のヘッダーを表示します)。
  -->
  <header>
    <h1>プシューメイキィ</h1>
  </header>
  <?php } ?>
  <main>
    <?php if ($useSidebarLayout) { ?>
      <?php echo $display; ?>
    <?php } else { ?>
      <div class="account_card">
        <?php echo $display; ?>
      </div>
    <?php } ?>
  </main>
</body>
</html>