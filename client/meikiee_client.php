<?php
/*****************************************
  *----------------------------------
  |  ThisScriptVersion: 2.0.0     |
  |  © 2026 ISAMI ABE             |
  |  License: MIT                 |
  |  SPDX-License-Identifier: MIT |
  |  meikiee_client (共有メイキィクライアント) |
----------------------------------*
  プシューメイキィ(p-meikiee)と話すためのコードは、全プロダクトを通してこの1ファイル
  だけです。各プロダクトはこれを読み込んで、下の方にある pusyuuAccount〜() という
  関数群を呼ぶだけで、メイキィがどこに居てどう認証するかを知りません。

  【なぜ1ファイルに集めたか】
  以前は、メイキィと話すクラスの全文を各プロダクトのindex.phpへ複製し、「直したら
  他へ貼り替える」という運用で揃えていました。実際には揃いませんでした。2026-09-06に
  調べたところ、6つの複製のうち toolbox のものだけが

      $port = ... ?: ($scheme === 'http' ? 443 : 80);   ← 'https' が正しい

  となっており、httpsで80番ポートへ繋ごうとして**アカウント機能が全滅**していました。
  誰も気づけなかったのは、複製同士が食い違っていないことを機械的に確かめる手段が
  無かったからです。1ファイルにすれば、そもそも食い違いようがありません。

  【プロダクトが知ってよいのは、このファイルの名前と関数名だけです】
  接続先のホスト・ポート・スキーム、合言葉の作り方、鍵ファイルの場所、SSOの往復手順、
  ?api= のアクション名、応答の形——これらは全部このファイルの中の話です。
  プロダクト側に一つでも漏らすと、直すときに7箇所を探して回ることになり、
  1箇所直し忘れたのが今回の toolbox です。

  【このファイルが無くてもプロダクトは動きます】
  読み込み側は、既存の共有スクリプト(bot_detect.php・notation.php・usage_tracker.php)と
  まったく同じ形で書いてください。専用の探索も、専用の定数も要りません。

      $directry = $dir . "../main/pusyuusystem/scripts/php_scripts/meikiee_client.php";
      if (file_exists($directry)) {
        require_once($directry);
      }

  そのうえで、使う側は必ず function_exists() で存在を確かめてから呼びます。
  これは pusyuuIsKnownCrawler() や notationDisp() で既に使っている、このリポジトリの
  作法そのものです。

      if (!function_exists('pusyuuAccountReady') || !pusyuuAccountReady()) {
        ... アカウント機能が無いときの答え ...
      } else {
        ... pusyuuAccountSelf() などを使う本来の処理 ...
      }

  PHPは関数名もクラス名も「呼び出した瞬間」に解決するので、到達しない else の中に
  未定義の呼び出しを書いても構文検査も実行も通ります(PHP 8.2で実測済み)。
  身代わりの空関数をプロダクト側に置く必要はありません。

  【破ったときに何が起きるか】
  function_exists() を挟まずに pusyuuAccount〜() を呼ぶと、このファイルが無い環境で
  「Error: Call to undefined function」が投げられます。誰も catch していないので
  致命的エラーになり、ページは真っ白になります。機能が1つ欠けるのではなく、1行も
  表示されません。迷ったら必ず上の if/else で囲んでください。

  【2階建てになっています】
    1階(このファイルの大部分) PusyuuMeikieeClient
        メイキィの都合そのもの。合言葉の作り方、HTTPの投げ方、SSOの往復手順。
        **プロダクトからは絶対に直接呼ばないでください。**
    2階(このファイルの末尾) pusyuuAccount〜() の関数群と、手元保存の受け皿。
        プロダクトが呼んでよいのはここだけです。
        1階を直接呼ばせない理由は隠すためではなく、呼び出し面を狭くしておくためです。
        面が狭いほど、直すときに影響範囲を数えきれます。

  【迷ったときの倒し方】
  「これはアカウント基盤の都合か、プロダクトの都合か」で決めます。合言葉・HTTP・SSOの
  手順は基盤の都合なのでこのファイルへ。自分の$_SESSIONのどのキーに何を入れるか、
  ログイン後に何をするか、保存先に渡すservice名、フォローやお気に入りといった意味づけは
  プロダクトの都合なので、各プロダクト側の「顔」(pipsならPipsAccountFeature)へ。
  ここへプロダクト固有の事情を1つでも入れると、他のプロダクトがそれを引きずります。

  詳しい仕様は p-meikiee/ACCOUNTS_INTEGRATION_SPEC.md を参照してください。
******************************************/

// 同じフォルダに居るクローラ判定を、ここで読み込んでおきます。
//
// 【なぜプロダクトではなくここで読むか】これを使うのは、このファイルの中の
// 自動サインイン判定だけです。以前は各プロダクトが $pusyuuBotDetectPath という
// 変数へパスを入れてから読み込む約束になっていて、プロダクトごとに階層が違うぶん
// 4種類の相対パスが散らばっていました。使う本人が自分の隣から読めば、その約束ごと
// 消えます。無ければ function_exists() 側で素通りするので、必須ではありません。
$pusyuuAccountSibling = __DIR__ . '/bot_detect.php';
if (file_exists($pusyuuAccountSibling)) {
  require_once($pusyuuAccountSibling);
}

// ---------------------------------------------------------------------
// 接続先の設定
//
// すべて if (!defined(...)) で囲んであるので、特別な事情があるプロダクトは
// このファイルを読み込む前に自分で define すれば上書きできます。
// 事情が無いなら触らないでください。ここがプロダクトごとにバラバラだったのが、
// 今回まとめる直前の状態です(pipsとp-chatだけが http:// になっていました)。
// ---------------------------------------------------------------------

// メイキィの居場所。同一サーバ内のループバック経由で呼びます。
// 接続先を "127.0.0.1" 固定にしているのは、*.pusyuuwanko.com が名前ベースの
// バーチャルホスト(プロジェクトフォルダ名=サブドメイン)で構成されているためです。
// ここを "p-meikiee.pusyuuwanko.com" のままにすると、DNSやルーターの都合で
// 想定と違うIPへ繋がることがあります。実際にどのバーチャルホストへ振り分けるかは、
// 下の PUSYUU_ACCOUNTS_HOST から送るHostヘッダで決まります
// (ルーティングのためだけの指定で、認証には一切使われません)。
//
// 【スキームを https から変えないこと】プレーンHTTP(80番)にすると、Apache側の
// 強制HTTPSリダイレクトに乗ります。follow_location=0 にしてあるので追従こそしませんが、
// 返ってくるのはリダイレクト用のHTML本文で、JSONとして解釈できず毎回失敗します。
// 過去にpipsがこれで壊れました。
if (!defined('PUSYUU_ACCOUNTS_BASE_URL')) {
  define('PUSYUU_ACCOUNTS_BASE_URL', 'https://127.0.0.1/index.php');
}
if (!defined('PUSYUU_ACCOUNTS_HOST')) {
  define('PUSYUU_ACCOUNTS_HOST', 'p-meikiee.pusyuuwanko.com');
}
if (!defined('PUSYUU_ACCOUNTS_API_TIMEOUT')) {
  define('PUSYUU_ACCOUNTS_API_TIMEOUT', 5);
}

// メイキィ側(p-meikiee/index.php)の暗号化キーファイルと同じ場所を指します。
// Docker環境ではREMOTE_ADDRが信用できないため、メイキィは接続元IPではなく
// このキーファイルから導出した合言葉(api_secret)だけで呼び出し元を認証します。
if (!defined('PUSYUU_ACCOUNTS_KEY_FILE_CANDIDATES')) {
  define('PUSYUU_ACCOUNTS_KEY_FILE_CANDIDATES', ['.pusyuuHiddenFiles/pips_account_key.php']);
}

// 到達確認(reachable)の結果を使い回す秒数。
// メイキィが落ちている間、ページを開くたびに5秒待たされるのを避けるためです。
// 逆に長すぎると、メイキィが復旧してもしばらく「使えない」ままになります。
if (!defined('PUSYUU_ACCOUNTS_REACHABLE_TTL')) {
  define('PUSYUU_ACCOUNTS_REACHABLE_TTL', 60);
}

final class PusyuuMeikieeClient {
  /**
   * SSOの往復に添える使い捨ての合言葉(state)の置き場所と寿命。
   *
   * 【何のためにあるか】メイキィが戻り先へ渡す ?pusyuu_code= は、持参した者が誰でも
   * 換金できる持参人払いの証券です。交換に必要なapi_secretを持っているのはサーバ側の
   * 各プロダクトなので、コードだけ盗んだ第三者が自分のブラウザでこのサイトを開けば、
   * このサイトが代わりに交換して**盗んだ側のセッション**に被害者のトークンを入れて
   * しまいます。stateは「往路を開始したブラウザだけが復路を完了できる」ことを保証し、
   * これを防ぎます。メイキィ側のALLOWED_RETURN_HOSTS(戻り先の許可リスト)と役割が
   * 重なりますが、あちらが破れた場合(許可済みホストに踏み台となるリダイレクトが
   * あった等)の二重の備えです。どちらか片方だけにしないこと。
   *
   * 複数のタブ・同一ページ内の複数のログインリンクぶんを同時に扱えるよう、
   * 1個ではなく短い一覧として持ちます(1個だけだと、後から描画したリンクが
   * 先のタブのstateを上書きして、先のタブのログインが必ず失敗します)。
   */
  private const STATE_SESSION_KEY = 'pusyuu_sso_states';
  private const STATE_TTL = 900; // 15分
  private const STATE_MAX = 16;

  /** 到達確認の結果をブラウザセッションに控えるキー。 */
  private const REACHABLE_SESSION_KEY = 'pusyuu_meikiee_reachable';

  /** サインインの失敗理由を、リダイレクトを跨いで持ち越すためのキー。 */
  private const SIGNIN_OUTCOME_KEY = 'pusyuu_signin_error';

  /**
   * 直近の失敗の記録。呼び出し側が「なぜ駄目だったのか」を利用者へ見せるために使います。
   *
   * 【なぜ要るか】以前このクライアントは、鍵が見つからない・繋がらない・TLSで弾かれた・
   * リダイレクトされた・HTTPが500を返した・本文がJSONでない、という**まったく別の失敗**を
   * すべて 'api_unreachable' か 'api_broken_response' の2語に潰していました。その結果、
   * 障害が起きても error.log には「接続に失敗しました」としか出ず、どこを見ればいいのか
   * 誰にも分かりませんでした。段階(stage)を残すのはそのためです。
   */
  private static $lastError = null;

  // ----------------------------------------------------------------
  // 失敗の記録と取り出し
  // ----------------------------------------------------------------

  /**
   * 失敗を1件記録し、呼び出し側へ返す ok=false の連想配列を組み立てます。
   *
   * $stage  … どの段階で折れたか(key_not_found / unreachable / http_error など)
   * $action … 何をしようとしていたか(?api= の値。SSO側の失敗なら sso_* )
   * $detail … 開発者が原因に辿り着くための具体値(URL・HTTPステータス・PHPのエラー文言等)
   * $message… 利用者にそのまま見せてよい日本語
   */
  private static function noteFailure(string $stage, string $action, string $detail, string $message): array {
    self::$lastError = [
      'stage'   => $stage,
      'action'  => $action,
      'detail'  => $detail,
      'message' => $message,
      'at'      => time(),
    ];
    error_log('[メイキィ] ' . $stage . ' | action=' . $action . ' | ' . $detail);
    return ['ok' => false, 'error' => $stage, 'message' => $message];
  }

  /** 直近の失敗の全内容(無ければnull)。診断画面やログ出力に使ってください。 */
  public static function lastError(): ?array {
    return self::$lastError;
  }

  // ----------------------------------------------------------------
  // 経過の記録(トレース)
  //
  // 【なぜ error_log と別にするか】
  // error_log はサーバ全体の共用です。ここへ正常系まで書くと、他のプロダクトや
  // PHP自身の警告と混ざって、追いたい1本の往復が埋もれます。かといって失敗だけを
  // 書いていると、「どこまでは正常に進んでいたのか」が分かりません。今回まさに
  // それで、失敗した事実(state が無い)は分かるのに、その前に何が起きていたのかが
  // 一切追えませんでした。
  //
  // そこで、往復の全段階を——うまくいった段階も含めて——専用のファイルへ書きます。
  //
  // 【既定では何も書きません】書き込むのは
  //   .pusyuuHiddenFiles/pusyuu_sso_trace_on
  // というファイルが存在するときだけです。調べたいときに置いて、終わったら消す。
  // 置きっぱなしでも上限(TRACE_MAX_BYTES)で頭打ちになるので、ディスクは溢れません。
  //
  // 【秘密は書きません】state・引き渡しコード・トークンは先頭8文字だけにします。
  // 全文を書くと、このログを読める人がそのまま他人のセッションを乗っ取れます。
  // ----------------------------------------------------------------

  private const TRACE_FLAG_NAME = 'pusyuu_sso_trace_on';
  private const TRACE_FILE_NAME = 'pusyuu_sso_trace.log';
  private const TRACE_MAX_BYTES = 4 * 1024 * 1024; // 4MiB を超えたら書かない

  /** 記録先のディレクトリ(鍵ファイルと同じ隠しフォルダ)。見つからなければ null。 */
  private static function traceDir(): ?string {
    $keyFile = self::keyFilePath();

    if ($keyFile === null) {
      // 鍵が無い環境。記録の置き場も決められないので、記録そのものを諦めます。
      $dir = null;
    } else {
      $dir = dirname($keyFile);
    }

    return $dir;
  }

  /** 今このリクエストで記録を取るかどうか。1リクエストにつき1回だけ判定します。 */
  /**
   * 今このリクエストで記録を取るかどうか。1リクエストにつき1回だけ判定します。
   *
   * ---------------------------------------------------------------
   * 【切り替え方】2通りあります。どちらもコードを直す必要はありません。
   *
   *   (1) ファイルを置く / 消す  ← 普段はこちら
   *         .pusyuuHiddenFiles/pusyuu_sso_trace_on  という空ファイルを作れば ON、
   *         消せば OFF。サーバへ入れる人なら誰でも切り替えられ、再起動も要りません。
   *         調べたいときだけ置いて、終わったら必ず消してください。
   *
   *   (2) 定数で上書きする      ← 常時ONにしたい/絶対にOFFにしたい場合
   *         このファイルを読み込む前に PUSYUU_ACCOUNTS_TRACE を定義すると、
   *         (1)のファイルの有無に関係なくその値が優先されます。
   *           define('PUSYUU_ACCOUNTS_TRACE', true);   // 常にON
   *           define('PUSYUU_ACCOUNTS_TRACE', false);  // 絶対にOFF(本番の固定用)
   *
   * 【OFFのときは1バイトも書きません】判定はここ1箇所だけで、trace() は先頭で
   * これを見て何もせずに帰ります。記録点をいくら増やしても、OFFなら費用は
   * ファイルの有無を1回見るだけです。
   *
   * 【消し忘れても壊れません】TRACE_MAX_BYTES で頭打ちになるので、置きっぱなしでも
   * ディスクを埋めることはありません。ただし秘密の断片(先頭8文字)が残り続けるので、
   * 調査が終わったら消す方が安全です。
   * ---------------------------------------------------------------
   */
  /**
   * 判定の使い回し先。
   *
   * 【関数内のstaticではなくクラスの持ち物にしてある理由】管理画面からON/OFFを
   * 切り替えると、同じリクエストの中で答えが変わります。関数内のstaticだと外から
   * 消せないので、切り替えた直後の画面が切り替える前の状態を表示してしまい、
   * 「押したのに変わらない」ように見えます。traceSetEnabled()がここをnullへ戻します。
   */
  private static $traceDecided = null;

  private static function traceEnabled(): bool {
    if (self::$traceDecided === null) {
      $dir = self::traceDir();

      if (defined('PUSYUU_ACCOUNTS_TRACE')) {
        // 定数による明示指定が最優先。本番で「絶対に書かない」と固定したいときに使います。
        self::$traceDecided = (bool)PUSYUU_ACCOUNTS_TRACE;
      } else if ($dir === null) {
        self::$traceDecided = false;
      } else {
        self::$traceDecided = is_file($dir . '/' . self::TRACE_FLAG_NAME);
      }
    }

    return self::$traceDecided;
  }

  /**
   * 記録のON/OFFを切り替えます(管理画面用)。戻り値は ['ok'=>bool, 'message'=>string]。
   *
   * 【なぜ管理画面から切り替えられるようにしたか】切り替え手段が「サーバへ入って
   * .pusyuuHiddenFiles に空ファイルを置く」だけだったので、その手順を知らない・
   * 今できない状況では調査そのものが始められませんでした。仕組みは今までと同じ
   * ファイルのままで、置く/消すを画面からも行えるようにしただけです。
   * サーバへ直接入って置いた場合も、消した場合も、これまでどおり効きます。
   *
   * 【権限が無いときに黙って成功したことにしないこと】このフォルダはWebサーバの
   * 実行ユーザーが書ける保証がありません(sambaで直結している環境では特に)。
   * 書けなかったら、その事実と書けなかった場所をそのまま返します。ここで
   * 場所を変えて置き直すような回避をすると、traceEnabled()が見る場所と
   * ずれて「ONにしたのに何も記録されない」という最悪の形になります。
   */
  public static function traceSetEnabled(bool $on): array {
    $dir    = self::traceDir();
    $result = [];

    if (defined('PUSYUU_ACCOUNTS_TRACE')) {
      $result = [
        'ok' => false,
        'message' => '定数 PUSYUU_ACCOUNTS_TRACE で ' . (PUSYUU_ACCOUNTS_TRACE ? 'ON' : 'OFF')
          . ' に固定されているため、画面からは切り替えられません。'
          . 'この定数を定義しているPHPを直してください。',
      ];
    } else if ($dir === null) {
      $result = [
        'ok' => false,
        'message' => '鍵ファイルの置き場(.pusyuuHiddenFiles)が見つからないため切り替えられません。',
      ];
    } else {
      $flag   = $dir . '/' . self::TRACE_FLAG_NAME;
      $exists = is_file($flag);

      if ($on && $exists) {
        $result = ['ok' => true, 'message' => '記録は既にONでした。'];
      } else if ($on) {
        // 中身は判定に使いません(is_fileで見るだけです)が、後からこのフォルダを
        // 覗いた人が「これは何のファイルか」を迷わないように説明を入れておきます。
        $note = "このファイルがある間だけ、メイキィのログイン往復の経過が\n"
          . self::TRACE_FILE_NAME . " に記録されます。\n"
          . "調査が終わったらこのファイルを消してください(消せば記録は止まります)。\n"
          . "管理画面(oppai)の「メイキィ ログイン記録」からも切り替えられます。\n"
          . "作成: " . date('Y-m-d H:i:s') . "\n";
        $written = @file_put_contents($flag, $note);

        if ($written === false) {
          $result = [
            'ok' => false,
            'message' => 'ONにできませんでした。' . $flag . ' を作成できません。'
              . 'このフォルダにWebサーバの実行ユーザーの書き込み権限があるか確認してください。',
          ];
        } else {
          $result = ['ok' => true, 'message' => '記録をONにしました。'];
        }
      } else if (!$exists) {
        $result = ['ok' => true, 'message' => '記録は既にOFFでした。'];
      } else {
        $removed = @unlink($flag);

        if ($removed === false) {
          $result = [
            'ok' => false,
            'message' => 'OFFにできませんでした。' . $flag . ' を削除できません。'
              . 'このフォルダにWebサーバの実行ユーザーの書き込み権限があるか確認してください。',
          ];
        } else {
          $result = ['ok' => true, 'message' => '記録をOFFにしました。'];
        }
      }

      // 次にtraceEnabled()が呼ばれたら、今の状態を見直させます。
      self::$traceDecided = null;
    }

    return $result;
  }

  /**
   * 溜まった記録を捨てます(管理画面用)。
   * 調査を始める前に一度空にしておくと、今回の往復だけを読むことができます。
   */
  public static function traceClear(): array {
    $dir    = self::traceDir();
    $result = [];

    if ($dir === null) {
      $result = ['ok' => false, 'message' => '記録先が見つからないため消せません。'];
    } else {
      $path = $dir . '/' . self::TRACE_FILE_NAME;

      if (!is_file($path)) {
        $result = ['ok' => true, 'message' => '記録はまだ1件もありませんでした。'];
      } else if (@unlink($path) === false) {
        $result = [
          'ok' => false,
          'message' => $path . ' を削除できませんでした。書き込み権限を確認してください。',
        ];
      } else {
        $result = ['ok' => true, 'message' => '記録を消しました。'];
      }
    }

    return $result;
  }

  /**
   * 記録の末尾を返します(管理画面で読むため)。記録が無ければ空文字。
   *
   * 末尾から読むのは、調べたいのがいつも「今さっき起きたこと」だからです。
   * 4MiBまで育ち得るファイルを丸ごとメモリへ載せないよう、後ろから必要な分だけ拾います。
   */
  public static function traceTail(int $lines = 200): string {
    $dir    = self::traceDir();
    $result = '';

    if ($dir === null) {
      $result = '';
    } else {
      $path = $dir . '/' . self::TRACE_FILE_NAME;
      $fp   = is_file($path) ? @fopen($path, 'rb') : false;

      if ($fp === false) {
        $result = '';
      } else {
        // 1行はおおむね300バイト前後なので、余裕をみて1行1KiBで見積もって後ろから読みます。
        //
        // 【大きさは filesize() で測らないこと】filesize()は同じリクエストの中で
        // 一度調べた結果を使い回します(stat cache)。このリクエストが既に trace() で
        // 書き足していると、古い——つまり実際より小さい——大きさが返り、
        // 読み出す位置が前へずれて、末尾のつもりで途中を切り出します。
        // 開いているファイル自身の末尾へ寄せて測れば、その取り違えが起きません。
        fseek($fp, 0, SEEK_END);
        $size = (int)ftell($fp);
        $want = min($size, max(1, $lines) * 1024);

        if ($want <= 0) {
          // 中身が1バイトも無い状態。記録先のファイルだけが在って中が空、という
          // ことは実際に起こります(書き込みに失敗した直後など)。
          // 【0を渡さないこと】PHP8の fread() は長さ0でValueErrorを投げます。
          // ここを素通りさせると、記録が空のときに管理画面が真っ白になります。
          fclose($fp);
          $result = '';
        } else {
          fseek($fp, $size - $want);
          $chunk = (string)fread($fp, $want);
          fclose($fp);

          $rows = preg_split('/\R/', trim($chunk));
          if ($rows === false) {
            $result = '';
          } else {
            // 後ろから読んだせいで先頭行が途中から始まっている可能性があるため、
            // 全体を読み切っていないときだけ1行目を捨てます。
            if ($want < $size && count($rows) > 1) {
              array_shift($rows);
            }
            $result = implode("\n", array_slice($rows, -max(1, $lines)));
          }
        }
      }
    }

    return $result;
  }

  /**
   * 今、記録が有効かどうか(プロダクトの管理画面などから確かめる用)。
   * 「フラグを置いたのに何も出ない」という迷子を防ぐために公開しています。
   */
  public static function traceStatus(): array {
    $dir = self::traceDir();

    if ($dir === null) {
      $flag     = '(隠しフォルダが見つからないため判定できません)';
      $file     = '(同上)';
      $bytes    = 0;
      $writable = false;
    } else {
      $flag     = $dir . '/' . self::TRACE_FLAG_NAME;
      $file     = $dir . '/' . self::TRACE_FILE_NAME;
      $bytes    = is_file($file) ? (int)filesize($file) : 0;
      $writable = is_writable($dir);
    }

    if (defined('PUSYUU_ACCOUNTS_TRACE')) {
      $switchedBy = '定数 PUSYUU_ACCOUNTS_TRACE';
      $canSwitch  = false;
      $whyNot     = 'PHPの定数で固定されているため、画面からは切り替えられません。';
    } else if ($dir === null) {
      $switchedBy = 'フラグファイルの有無';
      $canSwitch  = false;
      $whyNot     = '鍵ファイルの置き場(.pusyuuHiddenFiles)が見つかりません。';
    } else if (!$writable) {
      $switchedBy = 'フラグファイルの有無';
      $canSwitch  = false;
      $whyNot     = $dir . ' にWebサーバの実行ユーザーの書き込み権限がありません。';
    } else {
      $switchedBy = 'フラグファイルの有無';
      $canSwitch  = true;
      $whyNot     = '';
    }

    return [
      'enabled'      => self::traceEnabled(),
      'switched_by'  => $switchedBy,
      'can_switch'   => $canSwitch,
      'cannot_why'   => $whyNot,
      'dir'          => $dir ?? '',
      'dir_writable' => $writable,
      'flag_file'    => $flag,
      'log_file'     => $file,
      'log_bytes'    => $bytes,
      'max_bytes'    => self::TRACE_MAX_BYTES,
    ];
  }

  /** 秘密を含む値を、突き合わせにだけ使える短い形へ。空なら "(なし)"。 */
  private static function traceMask(?string $value): string {
    if ($value === null || $value === '') {
      $masked = '(なし)';
    } else {
      // 先頭8文字だけ。突き合わせには足りて、値そのものは復元できません。
      $masked = substr($value, 0, 8) . '…';
    }

    return $masked;
  }

  /**
   * セッションまわりの環境を1リクエストにつき1行だけ残します。
   *
   * 【なぜこれが要るか】「セッションが保持されない」という症状は、原因がこちらの
   * コードの外にあることが多く、コードをいくら読んでも分かりません。実際に切り分けが
   * 要るのは次のようなことです。
   *
   *   - ブラウザはセッションCookieを送ってきたのに、PHPがそれを捨てて新しいIDを
   *     振り直していないか(受け取った値と今のIDが違えば、そうなっています)
   *   - Cookieの有効ドメインが .pusyuuwanko.com のような親ドメインになっていて、
   *     メイキィとプロダクトが**同じ1個のCookieを奪い合っていない**か
   *     (届いたCookie名の一覧に他プロダクトのものまで見えていれば疑わしい)
   *   - セッションの保存先に書けているか(書けないと毎回空のセッションになります)
   *
   * どれも設置環境の話なので、記録に残さないと永久に分かりません。
   * Cookieは**名前だけ**を残します。値は認証情報そのものなので書きません。
   */
  private static function traceEnvironmentOnce(): void {
    static $done = false;

    // 記録が無効なとき、または既に1行書いたときは、この塊ごと素通りします。
    if ($done === false && self::traceEnabled()) {
      $done = true;

      $incoming = (string)($_COOKIE[session_name()] ?? '');
      if ($incoming === '') {
        $match = 'そもそも来ていない';
      } else if ($incoming === session_id()) {
        $match = '一致(そのまま使われている)';
      } else {
        $match = '**不一致(PHPが別のIDを振り直した)**';
      }

      $savePath = (string)ini_get('session.save_path');
      if ($savePath === '') {
        $writable = '(既定の場所)';
      } else if (is_dir($savePath) && is_writable($savePath)) {
        $writable = '書ける';
      } else {
        $writable = '**書けない**';
      }

      // Cookieは**名前だけ**を残します。値は認証情報そのものなので書きません。
      self::trace('環境', [
        'Cookie名'             => session_name(),
        '受け取ったIDと今のID' => $match,
        '届いたCookieの名前'   => $_COOKIE ? implode(',', array_keys($_COOKIE)) : '(1つも無い)',
        'cookie_domain'        => ini_get('session.cookie_domain') === '' ? '(未設定=このホストだけ)' : ini_get('session.cookie_domain'),
        'cookie_path'          => ini_get('session.cookie_path'),
        'cookie_samesite'      => ini_get('session.cookie_samesite') === '' ? '(未設定)' : ini_get('session.cookie_samesite'),
        'cookie_secure'        => ini_get('session.cookie_secure') ? 'on' : 'off',
        'use_strict_mode'      => ini_get('session.use_strict_mode') ? 'on' : 'off',
        '保存先'               => $writable,
        'HTTPSで来たか'        => (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'はい' : 'いいえ',
        'リファラ'             => $_SERVER['HTTP_REFERER'] ?? '(なし)',
      ]);
    }
  }

  /**
   * 往復の1段階を記録します。$event は「何が起きたか」、$fields は補足。
   *
   * 同じ往復の記録を後から繋げられるよう、毎行に時刻・セッションID・
   * リクエストの宛先を付けます。セッションIDが行ごとに変わっていれば、それだけで
   * 「このブラウザはセッションを保持できていない」と読み取れます。
   */
  public static function trace(string $event, array $fields = []): void {
    $dir = self::traceEnabled() ? self::traceDir() : null;
    $path = ($dir === null) ? '' : $dir . '/' . self::TRACE_FILE_NAME;

    // 書くのは「記録が有効」「置き場がある」「上限まで太っていない」の3つが
    // 揃ったときだけ。1つでも欠けたら、この塊ごと素通りして何もしません。
    $canWrite = ($path !== '')
      && !(is_file($path) && filesize($path) > self::TRACE_MAX_BYTES);

    if ($canWrite) {
      $head = sprintf(
        '%s | sid=%s | cookie=%s | %s %s',
        date('Y-m-d H:i:s'),
        session_id() === '' ? '(なし)' : session_id(),
        empty($_COOKIE[session_name()]) ? '来ていない' : '来ている',
        $_SERVER['REQUEST_METHOD'] ?? '?',
        ($_SERVER['HTTP_HOST'] ?? '?') . ($_SERVER['REQUEST_URI'] ?? '')
      );

      $parts = [];
      foreach ($fields as $k => $v) {
        if (is_bool($v)) {
          $v = $v ? 'はい' : 'いいえ';
        } else if ($v === null) {
          $v = '(なし)';
        } else if (is_array($v)) {
          $v = implode(',', $v);
        }
        $parts[] = $k . '=' . $v;
      }

      $written = @file_put_contents(
        $path,
        $head . ' | ' . $event . ($parts ? ' | ' . implode(' | ', $parts) : '') . "\n",
        FILE_APPEND | LOCK_EX
      );

      // 【書けなかったことを黙って飲み込まないこと】
      // 記録を取ろうとしてフラグを置いた人が、あとから空のフォルダを見て
      // 「何も起きていない」と誤解するのが一番まずい状態です。書き込みに失敗した
      // ときだけ error_log へ1回出して、置き場所と権限を疑えるようにします。
      // 1リクエストにつき1回だけにするのは、失敗し続けて error_log を埋め尽くさない
      // ようにするためです。
      if ($written === false) {
        static $warned = false;
        if (!$warned) {
          $warned = true;
          error_log('[メイキィ] 経過の記録を書き込めませんでした。file=' . $path
            . ' / このフォルダにWebサーバの書き込み権限があるか確認してください。');
        }
      }
    }
  }

  /**
   * サインインの往復がどう終わったかを、リダイレクトを跨いで持ち越せるように残します。
   *
   * 【なぜセッションに置くか】往復の判定はリダイレクトの直前で行われ、判定した
   * リクエストは header()+exit でそのまま終わります。結果を変数に持っても、戻ってきた
   * 次のリクエストには何も残りません。画面に「ログインできませんでした」と出すには、
   * リクエストを跨げる場所に置くしかありません。
   */
  private static function noteSignInOutcome(bool $ok, string $message): void {
    if ($ok) {
      // 成功したら前回の失敗の残骸を必ず消します。残すと次のページで
      // 「何をしても消えないエラー」として出続けます。
      unset($_SESSION[self::SIGNIN_OUTCOME_KEY]);
    } else {
      $text = ($message === '')
        ? 'ログインを完了できませんでした。お手数ですが、このページのログインボタンからもう一度お試しください。'
        : $message;

      $_SESSION[self::SIGNIN_OUTCOME_KEY] = $text;
      error_log('[メイキィ] sign_in_failed | ' . $text);
    }
  }

  /**
   * 直近のサインインが失敗していれば、その理由を1度だけ返します(読んだら消えます)。
   * 失敗していなければ空文字。
   *
   * 【1度だけにする理由】残したままにすると、次のページでも、その次のページでも
   * 同じ警告が出続けます。利用者から見ると「何をしても消えないエラー」になり、
   * 本当に今起きた失敗なのか、前の失敗の残骸なのか区別できなくなります。
   */
  public static function takeSignInError(): string {
    if (empty($_SESSION[self::SIGNIN_OUTCOME_KEY])) {
      $message = '';
    } else {
      $message = (string)$_SESSION[self::SIGNIN_OUTCOME_KEY];
      unset($_SESSION[self::SIGNIN_OUTCOME_KEY]); // 読んだら消す(1度だけ出す)
    }

    return $message;
  }

  /**
   * 直近の失敗を、利用者に見せてよい1文にして返します。まだ失敗していなければ空文字。
   * 画面に「今アカウント機能が使えない理由」を出すための入口です。
   */
  public static function lastErrorMessage(): string {
    if (self::$lastError === null) {
      $message = '';
    } else {
      $message = (string)self::$lastError['message'];
    }

    return $message;
  }

  /**
   * 設置状態をまとめて返します(診断用)。
   * 合言葉そのものは絶対に返さず、突き合わせ用に先頭8文字の指紋だけを返します。
   * メイキィ側の ?api=selftest が返す secret_fingerprint と同じ作り方なので、
   * 両者を見比べれば「鍵ファイルが食い違っている」ことが一目で分かります。
   */
  public static function diagnostics(): array {
    $secret = self::apiSecret();
    if ($secret === null) {
      $fingerprint = '(合言葉を導出できていません)';
    } else {
      $fingerprint = substr($secret, 0, 8);
    }

    return [
      'base_url'            => PUSYUU_ACCOUNTS_BASE_URL,
      'host_header'         => PUSYUU_ACCOUNTS_HOST,
      'timeout'             => PUSYUU_ACCOUNTS_API_TIMEOUT,
      'key_file'            => self::keyFilePath() ?? '(見つかりません)',
      'secret_fingerprint'  => $fingerprint,
      // 通信に使うのは file_get_contents だけなので、確認するのもこの2つだけです。
      // allow_url_fopen が無効だと一切通信できません。https で繋ぐ設定のときは
      // https の口(openssl)も要ります。curlは使わないので見ません。
      'allow_url_fopen'     => (bool)ini_get('allow_url_fopen'),
      'wrappers'            => implode(',', stream_get_wrappers()),
      'last_error'          => self::$lastError,
    ];
  }

  // ----------------------------------------------------------------
  // 合言葉(api_secret)の導出
  // ----------------------------------------------------------------

  /**
   * 鍵ファイルの実際の場所を、自分の位置から上へ順に探して返します(無ければnull)。
   *
   * 【なぜ決め打ちの相対パスにしないか】このファイルは main/ の中に居ますが、
   * プロジェクトフォルダごと別の深さへ移されることがあります。"../../../" のような
   * 決め打ちにすると、移した瞬間に鍵が見つからなくなり、しかも「合言葉なしで
   * リクエストが飛んでメイキィに拒否される」という、原因の見えない形で壊れます。
   */
  private static function keyFilePath(): ?string {
    static $resolved = false;
    static $path = null;

    // 2回目以降は $resolved が true なので、この塊ごと素通りして答えを使い回します。
    if ($resolved === false) {
      $resolved = true;
      $dir = __DIR__;

      // 見つかった時点で $path に入れて打ち切ります。上限8段は、
      // 見つからないときに延々と登り続けないための歯止めです。
      for ($i = 0; $i <= 8 && $path === null; $i++) {
        foreach (PUSYUU_ACCOUNTS_KEY_FILE_CANDIDATES as $relative) {
          $candidate = $dir . '/' . $relative;
          if (is_file($candidate)) {
            $path = $candidate;
            break;
          }
        }

        if ($path === null) {
          $parent = dirname($dir);
          if ($parent === $dir) {
            break; // ルートまで来た
          }
          $dir = $parent;
        }
      }
    }

    return $path;
  }

  /**
   * メイキィ側と同じ手順(上位ディレクトリ探索→キー読込→HMAC)で合言葉を導出します。
   * 見つからなければnull。
   *
   * publicなのは、p-driveの中継(p-drive.pusyuuwanko.com/?api=...)がまったく同じ
   * 合言葉を使う契約になっているためです(p-drive専用の別の合言葉は存在しません)。
   * p-driveを呼ぶ関数を持つプロダクトは、そこから直接この値をもらってください。
   * 同じ導出手順をプロダクト側にもう1つ書くと、鍵の場所が変わったときに片方だけ
   * 直して食い違います。
   */
  public static function apiSecret(): ?string {
    static $loaded = false;
    static $secret = null;

    // 2回目以降は $loaded が true なので、この塊ごと素通りして答えを使い回します。
    if ($loaded === false) {
      $loaded = true;
      $keyFile = self::keyFilePath();
      $encoded = ($keyFile === null) ? null : require $keyFile;
      $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;

      // 失敗の理由は必ず noteFailure へ残します。黙って null を返すと、
      // 「合言葉なしでリクエストが飛んで拒否される」という原因の見えない壊れ方をします。
      if ($keyFile === null) {
        self::noteFailure(
          'key_not_found',
          'api_secret',
          '暗号化キーファイルが見つかりません。探した名前=' . implode(' / ', PUSYUU_ACCOUNTS_KEY_FILE_CANDIDATES)
            . ' / 探索の起点=' . __DIR__ . ' から上へ8階層',
          'このサーバにメイキィの鍵が設置されていないため、アカウント機能は使えません。'
        );
        $secret = null;
      } else if (!is_string($encoded)) {
        self::noteFailure(
          'key_malformed',
          'api_secret',
          '鍵ファイルが文字列を返しませんでした。file=' . $keyFile . ' / 実際の型=' . gettype($encoded),
          'メイキィの鍵ファイルの中身が壊れているため、アカウント機能は使えません。'
        );
        $secret = null;
      } else if ($decoded === false || strlen($decoded) !== 32) {
        $why = ($decoded === false)
          ? 'base64として解釈できません'
          : '復号後の長さが32バイトではありません(実際=' . strlen($decoded) . ')';

        self::noteFailure(
          'key_malformed',
          'api_secret',
          '鍵ファイルの中身が不正です。file=' . $keyFile . ' / ' . $why,
          'メイキィの鍵ファイルの中身が壊れているため、アカウント機能は使えません。'
        );
        $secret = null;
      } else {
        $secret = hash_hmac('sha256', 'pusyuu_accounts_api', $decoded);
      }
    }

    return $secret;
  }

  // ----------------------------------------------------------------
  // メイキィへの生API呼び出し
  // ----------------------------------------------------------------

  /**
   * 応答ヘッダの並びからHTTPステータス番号を取り出します。取れなければ0。
   * file_get_contents は $http_response_header という特別な変数に生ヘッダを置くので、
   * それをこの関数へ渡してください。
   */
  private static function statusFromHeaders(array $headers): int {
    // 0 は「番号が読み取れなかった」の意味。
    //
    // 【最初に見つかった行を採る。後ろで上書きしないこと】リダイレクトを挟むと
    // $http_response_header にはヘッダの塊が複数並びます。ここで break を外して
    // 最後の行を採る形にすると、302で弾かれた応答が最終的な200として読めてしまい、
    // 「失敗しているのに成功として扱う」という一番まずい壊れ方をします。
    $status = 0;

    foreach ($headers as $line) {
      if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
        $status = (int)$m[1];
        break;
      }
    }

    return $status;
  }

  /**
   * メイキィへ ?api=<action> でPOSTし、デコード済みの連想配列を返します。
   * 操作(アクション)はクエリパラメータで指定し、POST本文にはそれ以外のパラメータ
   * (username/password/tokenなど)だけを乗せます。
   * 通信自体に失敗した場合も ok=false の同じ形で返すので、呼び出し側は分岐を増やさずに
   * 済みます。ただし error は段階ごとに違う値になるので、詳しく知りたいときは
   * lastError() を見てください。
   */
  private static function callApi($action, array $params = []) {
    $scheme = parse_url(PUSYUU_ACCOUNTS_BASE_URL, PHP_URL_SCHEME) ?: 'https';
    $loopbackHost = parse_url(PUSYUU_ACCOUNTS_BASE_URL, PHP_URL_HOST) ?: '127.0.0.1';
    // 【この三項を反転させないこと】以前 toolbox だけが 'http' と書かれており、
    // httpsなのに80番が選ばれてメイキィ関連機能が全滅していました。
    if (parse_url(PUSYUU_ACCOUNTS_BASE_URL, PHP_URL_PORT)) {
      $port = parse_url(PUSYUU_ACCOUNTS_BASE_URL, PHP_URL_PORT);
    } else if ($scheme === 'https') {
      $port = 443;
    } else {
      $port = 80;
    }
    $path = parse_url(PUSYUU_ACCOUNTS_BASE_URL, PHP_URL_PATH) ?: '/index.php';
    $query = '?api=' . urlencode($action);

    // 接続先はループバックIP。どのバーチャルホストへ配るかは下のHostヘッダで指示します。
    $loopbackUrl = $scheme . '://' . $loopbackHost . ':' . $port . $path . $query;

    $secret = self::apiSecret();

    // 通信は file_get_contents + stream_context_create の1本だけです。
    //
    // 【curlを使わないこと】curl拡張は標準で入っているとは限りません。入っていない
    // サーバでも php.ini を触らずに動く必要があるので、PHP本体だけで完結する
    // この方法に統一しています。pips が投稿APIを叩いている PipsPostIO::postData() と
    // 同じやり方で、あちらと同じく $http_response_header でステータスを見ます。
    // 「curlがあれば使う」という分岐を足すと、curlのある環境とない環境で挙動が
    // 分かれ、どちらで動いているのか追えなくなります。足さないでください。
    //
    // ここから下は「通信する前に分かる失敗」→「通信して分かる失敗」→「成功」の順に
    // 1つのif/elseで並べます。答えは必ず $result に入れて、最後に1回だけ返します。
    if ($secret === null) {
      // apiSecret() が既に理由を記録しているので、ここでは上書きせずそれを返します。
      // 合言葉なしで投げてもメイキィに403で弾かれるだけで、原因が「鍵が無いこと」だと
      // 分からなくなるため、通信そのものを行いません。
      $result = [
        'ok'      => false,
        'error'   => self::$lastError['stage'] ?? 'key_not_found',
        'message' => self::lastErrorMessage(),
      ];
    } else if (!ini_get('allow_url_fopen')) {
      $result = self::noteFailure(
        'no_transport',
        $action,
        'php.ini の allow_url_fopen が無効なため、メイキィへ通信できません。',
        'サーバの設定によりアカウント機能が利用できません。管理者にご連絡ください。'
      );
    } else if (!in_array($scheme, stream_get_wrappers(), true)) {
      // 使うスキームの口が open されているかを、通信する前に確かめます。
      //
      // 【なぜ先に見るか】https の口(openssl)が無い環境で https のURLを渡すと、
      // file_get_contents は「そのラッパーが見つからない」という、接続失敗と見分けの
      // つかない形で false を返します。原因が「メイキィが落ちている」なのか
      // 「このPHPに https の口が無い」なのかを取り違えると、探す場所を丸ごと間違えます。
      // ここで先に切り分けておけば、ログを見た瞬間にどちらかが分かります。
      $result = self::noteFailure(
        'no_transport',
        $action,
        'このPHPには "' . $scheme . '" で通信する口がありません(使える口: '
          . implode(', ', stream_get_wrappers()) . ')。'
          . 'httpsで繋ぐ設定ならopenssl拡張が要ります。拡張を足せない場合は、'
          . 'ループバック接続なので PUSYUU_ACCOUNTS_BASE_URL を http:// にする手もあります'
          . '(その場合はApacheの強制HTTPSリダイレクトに当たらないかを確かめてください。'
          . '当たっていれば下の redirected として記録されます)。',
        'サーバの設定によりアカウント機能が利用できません。管理者にご連絡ください。'
      );
    } else {
      $params['api_secret'] = $secret;
      $body = http_build_query($params);

      $context = stream_context_create([
        'http' => [
          'method'          => 'POST',
          // 接続先(127.0.0.1)とは別に、名前ベースバーチャルホストをメイキィへ
          // 振り分けさせるためのHostヘッダを常に送ります。
          'header'          => "Content-Type: application/x-www-form-urlencoded\r\n" .
          "Content-Length: " . strlen($body) . "\r\n" .
          "Host: " . PUSYUU_ACCOUNTS_HOST . "\r\n",
          'content'         => $body,
          'timeout'         => PUSYUU_ACCOUNTS_API_TIMEOUT,
          'ignore_errors'   => true,
          // リダイレクトを自動追従しません。追従するとPOSTがGETに化けて
          // 合言葉(api_secret)を含むボディが失われるため、ここで確実に検知します。
          'follow_location' => 0,
        ],
        'ssl' => [
          // 接続先IPは127.0.0.1固定だが、SNI送出と証明書検証はメイキィの
          // 実際のバーチャルホスト名(PUSYUU_ACCOUNTS_HOST)で行う。
          // httpで繋ぐ設定のときは、この節は単に使われません。
          'peer_name'        => PUSYUU_ACCOUNTS_HOST,
          'verify_peer'      => true,
          'verify_peer_name' => true,
        ],
      ]);

      // $http_response_header は file_get_contents がこのスコープへ自動的に作る変数です。
      // 失敗したときは作られないので、事前に空で用意しておきます(用意しておかないと、
      // 失敗時に未定義変数の警告が出るうえ、直前の呼び出しの値が残っていると
      // 前回のステータスを今回のものと取り違えます)。
      $http_response_header = [];
      $raw = @file_get_contents($loopbackUrl, false, $context);

      if ($raw === false) {
        $lastPhpError = error_get_last();
        $why = ($lastPhpError === null) ? '理由は取得できませんでした' : $lastPhpError['message'];

        $result = self::noteFailure(
          'unreachable',
          $action,
          'メイキィへ接続できませんでした。url=' . $loopbackUrl
            . ' / Host: ' . PUSYUU_ACCOUNTS_HOST . ' / ' . $why,
          'プシューメイキィに接続できませんでした。しばらくしてからもう一度お試しください。'
        );
      } else {
        $status = self::statusFromHeaders($http_response_header);
        $where = 'url=' . $loopbackUrl . ' / Host: ' . PUSYUU_ACCOUNTS_HOST . ' / HTTP ' . $status;

        // メイキィとのやり取りも、成功した分まで残します。応答の中身は秘密を含むので
        // 書きません(長さだけ)。「呼べていたのか」「何を返してきたのか」の切り分けに使います。
        self::trace('メイキィへ問い合わせ', [
          'アクション'     => $action,
          'HTTPステータス' => $status,
          '応答の長さ'     => strlen($raw) . 'バイト',
        ]);

        if ($status >= 300 && $status <= 399) {
          $result = self::noteFailure(
            'redirected',
            $action,
            'リダイレクトされました(追従しません)。POSTがGETに化けて合言葉が失われるためです。'
              . 'PUSYUU_ACCOUNTS_BASE_URL のスキームがhttpになっていないか確認してください。' . $where,
            'プシューメイキィへの接続設定に問題があります。管理者にご連絡ください。'
          );
        } else if ($status !== 0 && $status !== 200) {
          $result = self::noteFailure(
            'http_error',
            $action,
            'メイキィがHTTP ' . $status . ' を返しました。' . $where
              . ' / 本文の先頭=' . substr($raw, 0, 300),
            'プシューメイキィが応答できない状態です。しばらくしてからもう一度お試しください。'
          );
        } else if (trim($raw) === '') {
          $result = self::noteFailure(
            'empty_response',
            $action,
            'メイキィが空の応答を返しました(p-meikiee/index.php が致命的エラーで停止している'
              . '可能性があります。apache/error.log の同時刻を確認してください)。' . $where,
            'プシューメイキィが応答できない状態です。しばらくしてからもう一度お試しください。'
          );
        } else {
          $decoded = json_decode($raw, true);

          if (!is_array($decoded)) {
            $result = self::noteFailure(
              'not_json',
              $action,
              'メイキィの応答をJSONとして解釈できませんでした。' . $where
                . ' / json_error=' . json_last_error_msg()
                . ' / 本文の先頭=' . substr($raw, 0, 300),
              'プシューメイキィからの応答を解釈できませんでした。'
            );
          } else {
            // ここから先はメイキィ自身が返した「意味のある答え」です。ok=false であっても
            // 通信は成功しているので、通信の失敗(上のstage)とは区別して記録します。
            // この区別が無いと「トークンが切れただけ」と「メイキィが落ちている」を
            // 呼び出し側が見分けられません。
            if (empty($decoded['ok'])) {
              self::$lastError = [
                'stage'   => 'api_error',
                'action'  => $action,
                'detail'  => 'メイキィが ok=false を返しました。error=' . (string)($decoded['error'] ?? '(なし)'),
                'message' => (string)($decoded['message'] ?? 'プシューメイキィの処理が失敗しました。'),
                'at'      => time(),
              ];
            }
            $result = $decoded;
          }
        }
      }
    }

    return $result;
  }

  /**
  /**
   * アカウント機能を「使える状態に設置してあるか」。通信は一切しません。
   *
   * 【ready()の門番にネットワークを使ってはいけません】
   * 最初はここに reachable()(疎通確認)を置いていました。それは誤りでした。
   * 各プロダクトの顔は、アカウント関係のほぼ全メソッドをこの判定で囲みます。
   * そこへ通信を挟むと、
   *   1. 呼び出しが毎回2回になる(疎通確認 + 本来のAPI)。
   *   2. 疎通確認が一瞬でも失敗した瞬間、本人が取れず「ログアウト状態」に見える。
   * となります。2は、他プロダクトで直したばかりの「一時的な不通で全員ログアウト」を
   * 別の層で作り直したのと同じです。実際p-chatで、メイキィのチャットだけ読めず
   * 匿名タブは読める(匿名はアカウントを必要としないため)という形で出ました。
   *
   * 本来のAPI呼び出しは、失敗すれば自分で理由を段階付きで返します。事前に様子を
   * 見に行く必要はありません。疎通確認が要るのは「ブラウザをメイキィへ送り出す前」
   * だけで、そこは handleSsoRoundTrip() が reachable() で確かめています。
   *
   * ここで見るのは「共有スクリプトが読み込まれていて、合言葉を作れるか」だけです。
   * 合言葉が作れない=鍵ファイルが無い環境では、何を呼んでも必ず失敗するので、
   * 先に畳んでおく意味があります(こちらはファイルを1回見るだけで、通信しません)。
   */
  public static function configured(): bool {
    static $decided = null;

    // 2回目以降は $decided が入っているので、この塊ごと素通りします。
    if ($decided === null) {
      $decided = self::apiSecret() !== null;
    }

    return $decided;
  }

  /**
   * メイキィが今そこに居るかどうか(通信して確かめます)。
   *
   * 【何のためにあるか】未ログインの利用者をメイキィのログイン画面へ302で送り出す前に、
   * 送り先が実在するかを確かめるためです。確かめずに送ると、メイキィが無い環境では
   * ブラウザが「このサイトにアクセスできません」を表示して終わり、こちらのページは
   * 一度も描画されません。サーバは302を返して仕事を終えたつもりなので、error.logにも
   * 何も残りません。真っ白なページの原因として最も見つけにくい類です。
   *
   * 【使ってよい場所は限られます】通信を伴い、失敗すれば「使えない」と答えるので、
   * 画面の描画やデータ取得の門番には使わないでください(理由は configured() のコメント)。
   * 使ってよいのは、ブラウザを実際にメイキィへ送り出す直前だけです。
   *
   * 確認には ?api=selftest を使います。合言葉の一致まで含めて確かめられるので、
   * 「繋がるが鍵が違う」という状態もここで弾けます。
   * 結果はブラウザセッションに PUSYUU_ACCOUNTS_REACHABLE_TTL 秒だけ控えます。
   */
  public static function reachable(): bool {
    static $decided = null;

    // 2回目以降は $decided が入っているので、この塊ごと素通りします。
    if ($decided === null) {
      $now = time();
      // ブラウザセッションに控えが残っていて、まだ新しいかどうか。
      $cached = (session_status() === PHP_SESSION_ACTIVE)
        && isset($_SESSION[self::REACHABLE_SESSION_KEY]['at'])
        && ((int)$_SESSION[self::REACHABLE_SESSION_KEY]['at'] + PUSYUU_ACCOUNTS_REACHABLE_TTL > $now);

      if (!self::configured()) {
        // 鍵が無い環境。通信しても必ず失敗するので、確かめるまでもありません。
        $decided = false;
      } else if ($cached) {
        $decided = (bool)$_SESSION[self::REACHABLE_SESSION_KEY]['ok'];
      } else {
        $res = self::callApi('selftest');
        $decided = !empty($res['ok']);

        if (session_status() === PHP_SESSION_ACTIVE) {
          $_SESSION[self::REACHABLE_SESSION_KEY] = ['ok' => $decided, 'at' => $now];
        }
      }
    }

    return $decided;
  }

  /** 設置確認。key_file・account_count・secret_fingerprint 等が返ります(診断用)。 */
  public static function selftest() {
    return self::callApi('selftest');
  }

  /**
   * 管理系APIの呼び出し口。callApi() が private なので、ここだけ通します。
   * **管理画面(oppai)専用**。プロダクトからは呼ばないでください。
   * 詳しくはこのファイル末尾の pusyuuAccountAdminCall() のコメントを参照。
   */
  public static function adminCall(string $action, array $params = []) {
    return self::callApi($action, $params);
  }

  public static function logout($token) {
    return self::callApi('logout', ['token' => $token]);
  }

  /** 基本情報(userid/username/name)のみ。トークン失効時は user=null。 */
  public static function session($token) {
    return self::callApi('session', ['token' => $token]);
  }

  /** 自分自身の詳細(email・userData丸ごと込み)。 */
  public static function me($token) {
    return self::callApi('me', ['token' => $token]);
  }

  /** 公開プロフィール(userid/username/nameのみ、API生応答)。username から検索します。 */
  public static function fetchProfile($username) {
    return self::callApi('profile', ['username' => $username]);
  }

  /**
   * 「トークンが確かに無効だ」とメイキィが答えたのかどうか。
   *
   * 【なぜ必要か】me()やsession()が ok=false を返す理由には、
   *   (A) トークンが失効・破棄された(＝本当にログアウトさせるべき)
   *   (B) メイキィに繋がらない・鍵が無い・500が返った(＝こちらの都合。触ってはいけない)
   * の2種類があります。以前これを区別せず「ok以外なら全部ログアウト」としていたため、
   * メイキィが一時的に落ちただけで利用者のセッションが丸ごと破棄され、
   * SSOの控え(state)まで消えてログインし直すこともできなくなっていました。
   * ログアウト処理を書くときは必ずこの関数で(A)だけを選り分けてください。
   */
  /**
   * 「メイキィまで届かなかった」ことを表す段階(stage)の一覧。
   *
   * 【新しい失敗の段階を足したらここにも足すこと】ここに載っていない段階は
   * 「メイキィ自身がそう答えた」と見なされます。載せ忘れると、単に通信できなかった
   * だけなのに「トークンが無効」「そのユーザーは存在しない」と断定してしまい、
   * 利用者を勝手にログアウトさせたり、生きているデータを消したりします。
   */
  private const TRANSPORT_STAGES = [
    'key_not_found', 'key_malformed', 'no_transport',
    'unreachable', 'redirected', 'http_error', 'empty_response', 'not_json',
  ];

  public static function isTokenRejected(array $res): bool {
    if (!empty($res['ok'])) {
      // 成功しているので、そもそも拒否されていません。
      $rejected = false;
    } else if (($res['error'] ?? '') === 'api_error') {
      // 通信が成立したうえでメイキィ自身が答えを返したときだけが判断材料になります。
      $rejected = true;
    } else if (in_array((string)($res['error'] ?? ''), self::TRANSPORT_STAGES, true)) {
      // 届かなかっただけ。こちらの都合なので、利用者のトークンは触りません。
      $rejected = false;
    } else {
      $rejected = true;
    }

    return $rejected;
  }

  /**
   * 直近の失敗が「メイキィまで届かなかった」ものかどうか。
   *
   * 【何のためにあるか】値が返らなかったとき、それが「メイキィが"無い"と答えた」のか
   * 「そもそもメイキィに聞けなかった」のかで、利用者に見せるべき言葉も、こちらが
   * 取るべき行動もまったく違います。前者なら「見つかりません」でよいですが、
   * 後者で同じことを言うと、実在する相手を「居ない」と断定することになります。
   * 相手を招待できないだけならまだしも、それを根拠にデータを消す処理があると、
   * 一時的な不通で生きているデータが失われます。
   */
  public static function lastFailureWasConnection(): bool {
    if (self::$lastError === null) {
      // まだ一度も失敗していない
      $wasConnection = false;
    } else {
      $wasConnection = in_array((string)(self::$lastError['stage'] ?? ''), self::TRANSPORT_STAGES, true);
    }

    return $wasConnection;
  }

  // ---------------------------------------------------------------------
  // 汎用データストア(userData)
  //
  // account.jsonl の各レコードに持たせている "userData": { "<service>": { "<key>": 値 } }
  // を読み書きするための、完全に汎用な関数群です。$service には自分のサービス名
  // (例: 'pips', 'p-memo')、$key には用途名(例: 'likes', 'following', 'settings')を
  // 指定してください。フォロー・お気に入りのような「意味のある機能」は、この
  // 汎用関数を組み合わせてサービス側(呼び出し元)で組み立てます
  // (対象ユーザーが存在するか、自分自身でないか、といった検証も呼び出し側の責務です)。
  //
  // 1件あたりの上限は4KB、配列は1キーあたり200件までです。まとまったデータ
  // (本文・画像など)を保存したい場合は、自分のサービス自身のストレージを
  // 使ってください(p-memoのメモ機能がその例です)。
  // ---------------------------------------------------------------------

  /** 自分自身の userData[$service][$key] を取得します。 */
  public static function dataGet($token, $service, $key) {
    return self::callApi('data_get', ['token' => $token, 'service' => $service, 'key' => $key]);
  }

  /**
   * 指定したstorage_idが実在するアカウントのものかどうかだけを返します。
   * (p-chat/p-reversiのように複数アカウントが絡む自前データを持つプロダクトが、
   *  相手のアカウントがまだ存在するかを確かめる用途。)
   */
  public static function accountExists($storageId) {
    return self::callApi('account_exists', ['storage_id' => $storageId]);
  }

  /** 自分自身の userData[$service][$key] を丸ごと削除します(無くてもエラーにしません)。 */
  public static function dataDelete($token, $service, $key) {
    return self::callApi('data_delete', ['token' => $token, 'service' => $service, 'key' => $key]);
  }

  /** 自分自身の userData[$service][$key] に任意の値(配列やスカラー)を保存します。 */
  public static function dataSet($token, $service, $key, $value) {
    if (is_string($value)) {
      $encoded = $value;
    } else {
      $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    return self::callApi('data_set', [
      'token' => $token, 'service' => $service, 'key' => $key, 'value' => $encoded,
    ]);
  }

  /** userData[$service][$key] を配列として扱い、$value を重複なく追加します。 */
  public static function dataListAdd($token, $service, $key, $value) {
    return self::callApi('data_list_add', ['token' => $token, 'service' => $service, 'key' => $key, 'value' => $value]);
  }

  /** userData[$service][$key] の配列から $value を取り除きます。 */
  public static function dataListRemove($token, $service, $key, $value) {
    return self::callApi('data_list_remove', ['token' => $token, 'service' => $service, 'key' => $key, 'value' => $value]);
  }

  /** 指定したユーザー(username)の userData[$service][$key] 配列の件数(公開情報)。 */
  public static function dataListCount($username, $service, $key) {
    return self::callApi('data_list_count', ['username' => $username, 'service' => $service, 'key' => $key]);
  }

  /**
   * 「userData[$service][$key] の配列に $value を含んでいるアカウント」が何件あるかを数えます。
   * フォロワー数のような「誰かが自分を配列に含めている件数」を数えたいときに使います。
   */
  public static function dataReverseCount($value, $service, $key) {
    return self::callApi('data_reverse_count', ['value' => $value, 'service' => $service, 'key' => $key]);
  }

  /**
   * dataReverseCount()と同条件で、件数の代わりに該当アカウントの
   * 公開プロフィール一覧(userid/username/name/bio/avatar_url)を返します。
   * フォロワー一覧の表示に使います。
   */
  public static function dataReverseList($value, $service, $key) {
    return self::callApi('data_reverse_list', ['value' => $value, 'service' => $service, 'key' => $key]);
  }

  /**
   * userid(sha256ハッシュ)の配列を、公開プロフィール一覧へ一括で解決します。
   * following配列は相手の生idを知り得ないハッシュだけを持っているため、
   * フォロー中一覧を表示する際にユーザー名・名前・アバターを得るために使います。
   */
  public static function resolveIds(array $userIds) {
    return self::callApi('resolve_ids', ['ids' => json_encode(array_values($userIds), JSON_UNESCAPED_UNICODE)]);
  }

  /**
   * 共通ログイン画面(p-meikiee/index.php)へ return_to 付きでリダイレクトしたあと、
   * 戻ってきたときの ?pusyuu_code=... をトークンに交換します(SSOの受け口)。
   */
  public static function exchangeCode($code) {
    return self::callApi('exchange_code', ['code' => $code]);
  }

  // ----------------------------------------------------------------
  // 共通ストレージ(p-drive)
  //
  // 実体はメイキィの中にあり、p-drive.pusyuuwanko.com は「マイファイル」画面
  // (顔)だけを担当します。以前は各プロダクトがp-driveへ投げ、p-driveが中身を
  // 見ずにメイキィへ横流しする、という取次を挟んでいましたが、実体がメイキィに
  // ある以上その一段は無駄で、配管が二重になるだけだったので廃止しました。
  // 保存先の指定に使う userid は storage_id(ハッシュ)です。生idではありません。
  // ----------------------------------------------------------------

  /**
   * 値の取り出し口。メイキィは、UTF-8として妥当でない値(画像・zip等の生バイト)を
   * base64に包んで encoding='base64' の印を付けて返します。呼び出し側がその印を
   * 見落とすと壊れたデータを掴むので、ここで必ず開いてから渡します。
   */
  private static function driveDecodeValue(array $res): array {
    if (($res['encoding'] ?? null) === 'base64' && isset($res['value']) && is_string($res['value'])) {
      $plain = base64_decode($res['value'], true);
      if ($plain !== false) {
        $res['value'] = $plain;
      }
      unset($res['encoding']);
    }
    if (isset($res['items']) && is_array($res['items'])) {
      foreach ($res['items'] as $i => $item) {
        if (is_array($item)) {
          $res['items'][$i] = self::driveDecodeValue($item);
        }
      }
    }
    return $res;
  }

  public static function drivePut($userid, $service, $key, $value) {
    return self::callApi('p_drive_put', ['userid' => $userid, 'service' => $service, 'key' => $key, 'value' => $value]);
  }

  public static function driveGet($userid, $service, $key) {
    return self::driveDecodeValue(self::callApi('p_drive_get', ['userid' => $userid, 'service' => $service, 'key' => $key]));
  }

  public static function driveList($userid, $service) {
    return self::driveDecodeValue(self::callApi('p_drive_list', ['userid' => $userid, 'service' => $service]));
  }

  public static function driveDelete($userid, $service, $key) {
    return self::callApi('p_drive_delete', ['userid' => $userid, 'service' => $service, 'key' => $key]);
  }

  /** そのプロダクトぶんを丸ごと削除します(連携解除など)。 */
  public static function driveDeleteService($userid, $service) {
    return self::callApi('p_drive_delete_service', ['userid' => $userid, 'service' => $service]);
  }

  /** そのメイキィが使っている合計バイト数と上限。 */
  public static function driveUsage($userid) {
    return self::callApi('p_drive_usage', ['userid' => $userid]);
  }

  /** プロダクト単位の内訳(診断用。削除は一切しません)。 */
  public static function driveStorageBreakdown($userid) {
    return self::callApi('p_drive_storage_breakdown', ['userid' => $userid]);
  }

  /** 自分のプロダクトの保存先を再帰的に一覧します(診断用)。 */
  public static function driveLs($userid, $service, $subpath = '') {
    return self::callApi('p_drive_ls', ['userid' => $userid, 'service' => $service, 'subpath' => $subpath]);
  }

  /** 分割アップロードが中断して取り残された断片を掃除します。 */
  public static function driveSweepOrphanedChunks($userid, $service, $maxAgeSeconds) {
    return self::callApi('p_drive_sweep_orphaned_chunks', [
      'userid' => $userid, 'service' => $service, 'max_age_seconds' => $maxAgeSeconds,
    ]);
  }

  // ----------------------------------------------------------------
  // URL組み立てとSSOの往復
  // ----------------------------------------------------------------

  /**
   * 今開いているページのURL。SSOの往復で使った印(pusyuu_code/pusyuu_silent/pusyuu_state)は
   * 必ず落とします。落とさないとアドレスバーに残り続け、次の往復で古い値が混ざります。
   */
  public static function currentUrl(): string {
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $query = [];
    parse_str($_SERVER['QUERY_STRING'] ?? '', $query);
    // meikee_signin は「ログインリンクが押された」という出発の合図です。これを
    // 落とし忘れると、戻り先にそのまま残り、メイキィから帰ってきた瞬間にもう一度
    // 出発の合図として読まれて、往復が永久に終わりません。必ずここで落とすこと。
    //
    // 旧名の pusyuu_signin も一緒に落とします。改名(2026-09-15)より前に配られた
    // リンクやクローラーの控えが ?pusyuu_signin= を持ったまま残っており、落とさないと
    // 「もう誰も読まない印」が戻り先URLに紛れ込んだまま往復を繰り返します。
    // 害はありませんが、トレースを読むときに毎回「これは何だ」と迷うので消します。
    unset(
      $query['pusyuu_code'], $query['pusyuu_silent'], $query['pusyuu_state'],
      $query['meikee_signin'], $query['pusyuu_signin']
    );
    $qs = http_build_query($query);
    $host = $_SERVER['HTTP_HOST'] ?? '';
    // スキームは決め打ちでhttps。$_SERVER['HTTPS']から判定する形にしていた時期が
    // あるが、リバースプロキシ越しだとHTTPSで来ていてもこの値が立たないことがあり、
    // 戻り先がhttpになってSSOの往復が壊れる。戻り先はメイキィ側の許可リストに
    // 載っている公開ホスト名(すべてhttps)に限られるので、判定する意味も無い。
    if ($qs === '') {
      $url = 'https://' . $host . $path;
    } else {
      $url = 'https://' . $host . $path . '?' . $qs;
    }

    return $url;
  }

  /**
   * 出発の合図。この印が付いた要求だけが、メイキィへの往路を始めます。
   *
   * 【2026-09-15に pusyuu_signin から改名しました】メイキィへ行くための印だけが
   * pusyuu_ で始まっていると、URLを見ただけではどのサービスの都合か分かりません。
   * 往路の起点であることが名前で分かるよう meikee_ にしています。
   *
   * 改名して良いのは、この印を読む場所が departIfRequested() の1箇所、書く場所が
   * accountUrl() の1箇所しか無いからです。プロダクト側はどちらも文字列として
   * 持っていません(2026-09-15に全PHPを検索して確認済み)。ここを直接 '...' と
   * 書いた場所をプロダクトに作ると、次の改名でその場所だけ取り残されます。
   * 印の名前が要るときは必ずこの定数を参照してください。
   */
  private const DEPARTURE_PARAM = 'meikee_signin';

  /** 出発の合図として受け付ける行き先。知らない値が来たらログインとして扱います。 */
  private const DEPARTURE_SECTIONS = ['login', 'create', 'edit'];

  /**
   * メイキィ側の画面(作成・編集・ログイン)へのリンクを組み立てます。
   *
   * 【ここではメイキィのURLを返しません】返すのは**戻り先のページに出発の合図を
   * 付けたURL**です。押されると、そのページが自分でstateを控えてからメイキィへ
   * 送り出します(handleSsoRoundTrip()の冒頭)。
   *
   * 【なぜ直接メイキィへ向けないか】メイキィのURLには戻り先を埋める必要があり、
   * その戻り先にはstateを添えなければなりません。つまり**リンクを描いた時点で
   * stateを1本発行して控える**ことになります。ところが描画は、人がページを開いた
   * ときだけ起きるものではありません。BOT・クローラ・先読み・ページ内のJSからの
   * 定期通信——どれでも起きます。控えは16本の一覧なので、押す気のない相手の
   * ぶんで枠が埋まり、利用者が本当にログインして戻ってきた頃には自分のstateが
   * 押し出されていて、照合が必ず外れます。
   *
   * 2026-09-08のトレースでは、控えを取った549回のうち531回が本文の届かないHEAD
   * 要求からで、控えの件数は常に上限の16でした。利用者から見ると「承認して戻ったら
   * 使用済みリンクだと言われる」という、本人には手の打ちようがない壊れ方です。
   *
   * 【なぜ「誰が来たか」で判定しないか】要求の見た目から、本物の利用者と、
   * 埋め込まれたJSと、BOTを見分ける確かな方法はありません。今回の相手が分かったのも、
   * たまたまクエリに分かりやすい名前が付いていたからにすぎません。
   * そもそも**まだ誰も欲しがっていない段階で先に配っている**ことが問題なので、
   * 配るのを「実際に出発する瞬間」まで遅らせます。そうすれば見分ける必要が消えます。
   * 発行の回数は、実際に往路へ出た回数とぴったり一致します。
   *
   * 利用者から見た動きは今までと同じです(押したらメイキィのログイン画面が出る)。
   */
  public static function accountUrl(string $section, ?string $returnTo = null): string {
    if ($returnTo === null) {
      $destination = self::currentUrl();
    } else {
      $destination = $returnTo;
    }
    if (strpos($destination, '?') === false) {
      $sep = '?';
    } else {
      $sep = '&';
    }
    return $destination . $sep . self::DEPARTURE_PARAM . '=' . urlencode($section);
  }

  /**
   * 出発の合図が付いていれば、ここでstateを控えてメイキィへ送り出します。
   * 送り出したらtrueを返します(呼び出し元はそのまま終わってください)。
   *
   * この関数を通る要求は「利用者がログインリンクを押した」ものだけです。だから
   * ここで控えるstateは、必ず1回の意思に対して1本になります。
   */
  private static function departIfRequested(callable $redirect): bool {
    $asked = isset($_GET[self::DEPARTURE_PARAM]) && is_string($_GET[self::DEPARTURE_PARAM]);

    if (!$asked) {
      // 出発の合図が無い普通の要求。呼び出し元はそのままページを描いてください。
      $departed = false;
    } else {
      $given = $_GET[self::DEPARTURE_PARAM];
      // 知らない行き先はログインとして扱います。そのままメイキィへ渡すと
      // 利用者の書いた文字列が行き先になってしまいます。
      $section = in_array($given, self::DEPARTURE_SECTIONS, true) ? $given : 'login';

      if (!self::configured()) {
        // メイキィが居ない/設定が無い環境で送り出すと、利用者はブラウザの
        // 「アクセスできません」を見ることになります。合図だけ落として、
        // そのページを普通に表示させます。
        self::trace('出発: 取りやめ(メイキィの設定が無い)', ['行き先' => $section]);
        $redirect(self::currentUrl());
      } else {
        // currentUrl() は出発の合図を落とした今のページを返します。そこが戻り先です。
        $url = 'https://' . PUSYUU_ACCOUNTS_HOST . '/?account=' . urlencode($section)
          . '&return_to=' . urlencode(self::returnToWithState(null));

        self::trace('出発: ログインリンクが押されたので往路へ', [
          '行き先'     => $section,
          '戻り先'     => self::currentUrl(),
          '控えた件数' => count($_SESSION[self::STATE_SESSION_KEY] ?? []),
        ]);
        $redirect($url);
      }

      // どちらの枝も送り出しています(片方は自分自身へ)。呼び出し元は終わってください。
      $departed = true;
    }

    return $departed;
  }

  /** 無音SSO(フォームを見せずにログイン済みかだけ確かめる)用のURL。 */
  public static function silentLoginUrl(): string {
    return 'https://' . PUSYUU_ACCOUNTS_HOST . '/?account=login&silent=1'
      . '&return_to=' . urlencode(self::returnToWithState());
  }

  /**
   * ?account=logout の往復用URL。復路で引き渡しコードを受け取らないので、
   * stateは添えません(添えると控えを1枠使うだけで、守る物が何もない)。
   *
   * $returnTo を省くと今のページへ戻ります。ログアウト後にトップページへ戻したい等、
   * 戻り先を決めたいプロダクトだけが渡してください(戻り先はメイキィ側の
   * 許可リストに載っているホストである必要があります)。
   */
  public static function logoutUrl(?string $returnTo = null): string {
    if ($returnTo === null) {
      $destination = self::currentUrl();
    } else {
      $destination = $returnTo;
    }
    return 'https://' . PUSYUU_ACCOUNTS_HOST . '/?account=logout&return_to=' . urlencode($destination);
  }

  /**
   * 戻り先URLに使い捨ての合言葉(state)を添えます。
   *
   * 【戻り先を自前で組み立てないこと】このstateを付け忘れた戻り先を使うと、
   * メイキィから ?pusyuu_code= を持って帰ってきても、照合できないので必ず捨てられます。
   * 画面には何のエラーも出ず、ただ「ログインを押したのにログインされない」という
   * 状態になります。実際、toolboxの設定アプリがログインURLを手で組み立てていて、
   * この理由でサインインが成立していませんでした。
   * 戻り先を変えたいときは、URLを自作せず必ず $returnTo でここへ渡してください。
   */
  private static function returnToWithState(?string $returnTo = null): string {
    if ($returnTo === null) {
      $destination = self::currentUrl();
    } else {
      $destination = $returnTo;
    }
    if (strpos($destination, '?') === false) {
      $sep = '?';
    } else {
      $sep = '&';
    }
    return $destination . $sep . 'pusyuu_state=' . urlencode(self::issueState());
  }

  /**
   * stateを1つ発行し、このブラウザのセッションへ控えます(期限切れは同時に掃除)。
   *
   * publicなのは、往復の条件がプロダクトごとに違う場合(p-chatのように「一度も
   * ログインしたことが無いブラウザには無音SSOを試さない」等の独自条件がある場合)に、
   * そのプロダクトが自前で往復を組み立てられるようにするためです。手順が
   * pips/p-memoと同じで足りるなら、自前で組まずにhandleSsoRoundTrip()を使ってください。
   */
  /**
   * 【この関数は「実際に往路へ出る要求」からしか呼ばないこと】
   *
   * 呼ばれるたびに、16本しかない控えの枠を1つ使います。リンクを描くたびに呼ぶ形へ
   * 戻すと、押す気のない相手(BOT・クローラ・先読み・ページ内のJSの定期通信)の
   * ぶんで枠が埋まり、利用者が本当にログインして戻ってきた頃には自分のstateが
   * 押し出されていて、照合が必ず外れます。実際に2026-09-08まで、その状態でした
   * (経緯は accountUrl() のコメント)。
   *
   * 現在の呼び出し元は次の2つだけで、どちらも**その要求自身がメイキィへ出発します**。
   *   departIfRequested()  … 利用者がログインリンクを押した
   *   silentLoginUrl()     … 無音サインインの往路へ出る直前
   * 3つ目を足すときは、それが本当に出発する要求なのかを確かめてください。
   * 「押されるかもしれない画面を描いている」だけの場所からは呼ばないこと。
   */
  public static function issueState(): string {
    // 同じリクエストの中では1本だけにする。1ページに「ログイン」「作成」「編集」等の
    // リンクが複数あると、描画のたびに人数分の控えが増えて古い控えを押し出してしまい、
    // 別のタブで開いたままのページから戻ってきたときに照合が外れる。
    // どのリンクを押しても往路を始めたのは同じブラウザなので、1本で足りる。
    static $issued = null;

    // 2回目以降は $issued が入っているので、この塊ごと素通りして同じ値を返します。
    if ($issued === null) {
      $state = bin2hex(random_bytes(16));
      $now = time();

      if (isset($_SESSION[self::STATE_SESSION_KEY]) && is_array($_SESSION[self::STATE_SESSION_KEY])) {
        $list = $_SESSION[self::STATE_SESSION_KEY];
      } else {
        $list = [];
      }

      // 期限切れを落としてから足します。落とさないと、古い控えが枠を埋めて
      // 今発行したぶんを押し出してしまいます。
      $list = array_values(array_filter($list, static function ($row) use ($now): bool {
        return is_array($row) && (int)($row['at'] ?? 0) + self::STATE_TTL > $now;
      }));
      $list[] = ['v' => $state, 'at' => $now];

      if (count($list) > self::STATE_MAX) {
        $list = array_slice($list, -self::STATE_MAX);
      }

      $_SESSION[self::STATE_SESSION_KEY] = $list;
      $issued = $state;

      // 発行した事実と、そのときのセッションの状態を残します。
      // 復路の行と sid を見比べれば「往路と復路で同じセッションだったか」が一目で
      // 分かります。ここが往復の起点なので、正常時も必ず記録します。
      self::trace('往路: stateを発行して控えた', [
        '発行したstate' => self::traceMask($state),
        '控えの件数'    => count($list),
      ]);
    }

    return $issued;
  }

  /**
   * このリクエストが「人がブラウザでページを開いた」ものかどうか。
   *
   * 【なぜ要るか】ページの中で動くJavaScriptが、同じURLへ定期的に通信していることが
   * あります(共有の internet_checker.js は1秒ごとに接続確認のリクエストを送ります)。
   * これをページを開いた操作と区別せずにSSOの往復判定へ通すと、1秒ごとに新しい
   * stateが発行されて控えが押し流され、利用者が本当にログインして戻ってきた頃には
   * 自分のstateが残っておらず、照合が必ず外れます(実際にpipsとtoolboxで
   * SSOが成立しなくなっていました)。ついでに、その通信のたびにメイキィへ
   * 302を投げ続けるという無駄も止まります。
   *
   * 判定は3つ。GET以外(接続確認はHEAD、その事前確認はOPTIONS)は除く。
   * Sec-Fetch-Modeが付いていて navigate でないものは、JSからの通信なので除く
   * (この見出しを送らない古いブラウザでは判定に使わない=従来通り通す)。
   * 昔ながらのXHRの目印が付いていれば除く。
   */
  /**
   * このブラウザが、こちらのセッションCookieを実際に持ち帰ってきているか。
   *
   * 【なぜこの確認が要るか】
   * 往復の1歩目(無音サインインへの302)は、そのブラウザにとって**一番最初の
   * リクエスト**で起きることがあります。そのときセッションCookieの Set-Cookie は
   * 302応答に乗ります。ところが古いブラウザ(特に旧Android標準ブラウザ)には、
   * リダイレクト応答の Set-Cookie を無視するものがあります。無視されると
   * セッションは一度も成立せず、
   *
   *   往路でstateを控える → 控えごとセッションが消える → 別のsidで戻ってくる
   *   → 控えが無いので照合が外れる → 未サインインなのでまた往路へ → 無限に繰り返す
   *
   * となります。2026-09-06のerror.logに、15秒ごとに毎回違うsidで
   * sso_state_missing が並んでいたのがこの状態です。利用者から見ると
   * 「いつまでもログインできない」、サーバから見ると「無駄な往復が延々続く」。
   *
   * そこで、Cookieを持ち帰ってきていることが確認できるまで往路に出ません。
   * 初回アクセスはそのままページを描画し(Set-Cookieは通常の200応答に乗ります)、
   * 次にページを開いたときに往路へ出ます。サインインが1回分遅れるだけで、
   * Cookieを保持できないブラウザやクローラは静かに素通りします。
   */
  private static function browserKeepsSession(): bool {
    return !empty($_COOKIE[session_name()]);
  }

  public static function isPageNavigation(): bool {
    $mode = (string)($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '');
    // 【ヘッダの有無ではなく「値」で判定すること】
    // X-Requested-With は、昔ながらのJSライブラリがXHRの目印として
    // "XMLHttpRequest" を入れてくるヘッダです。しかしAndroidのブラウザおよび
    // WebViewは、**普通のページ遷移を含む全リクエスト**に、自分のアプリの
    // パッケージ名(例: com.android.browser)をこのヘッダで付けてきます。
    //
    // そのため「ヘッダがあれば除く」と書くと、Android端末ではページ遷移が
    // 1件残らずJS通信と誤判定され、ログインの復路(?pusyuu_code=...)を
    // **見もせずに捨てます**。利用者から見ると「メイキィで戻るを押しても
    // ログインされない」、サーバから見ると何のエラーも出ない、という状態です。
    //
    // 2026-09-06の経過記録で、Android 4.4.2(304SH)の端末がまさにこれで弾かれて
    // いました。Cookieもstateの控えも引き渡しコードも揃っているのに
    // 「ページ遷移か=いいえ」で即座に帰っていました。原因がここだと分かるまで、
    // Cookieやセッションの側を何度も疑って外しています。
    //
    // 目印として意味があるのは "XMLHttpRequest" という値だけなので、それだけを見ます。
    $requestedWith = strtolower(trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
      $isNavigation = false;
    } else if ($mode !== '' && $mode !== 'navigate') {
      $isNavigation = false;
    } else if ($requestedWith === 'xmlhttprequest') {
      $isNavigation = false;
    } else {
      $isNavigation = true;
    }

    return $isNavigation;
  }

  /**
   * 控えの中に一致するstateが有れば、それを消費して(=二度使えなくして)trueを返します。
   * 自前で往復を組むプロダクトは、引き渡しコードを交換する前に必ずこれをtrueで通すこと。
   * 通さずに交換すると、コードを盗んだ第三者のセッションへ被害者のトークンが入ります。
   *
   * 外れたときは、控えが空だったのか・期限切れだったのか・別の値だったのかまで
   * 記録します。ここを「一致しない」の一言で済ませていたために、pipsのSSOが
   * 成立しない原因を長く特定できませんでした。
   */
  public static function consumeState(string $state): bool {
    if ($state === '') {
      self::noteFailure(
        'sso_state_absent',
        'consume_state',
        'メイキィから戻ってきたURLに pusyuu_state が付いていませんでした。'
          . 'メイキィ側が return_to のクエリを落としていないか確認してください。',
        'ログインの情報が途中で失われました。お手数ですが、古いリンクやブックマークからではなく、'
          . 'このページのログインボタンからもう一度お試しください。'
      );
      $ok = false;
    } else {
      $now = time();
      if (isset($_SESSION[self::STATE_SESSION_KEY]) && is_array($_SESSION[self::STATE_SESSION_KEY])) {
        $list = $_SESSION[self::STATE_SESSION_KEY];
      } else {
        $list = [];
      }

      $total = count($list);
      $expired = 0;
      $matched = false;
      $kept = [];

      foreach ($list as $row) {
        if (!is_array($row) || (int)($row['at'] ?? 0) + self::STATE_TTL <= $now) {
          $expired++;
        } else if (!$matched && hash_equals((string)($row['v'] ?? ''), $state)) {
          // 一致したものは $kept に戻しません。これが「二度使えなくする」ことそのものです。
          $matched = true;
        } else {
          $kept[] = $row;
        }
      }
      $_SESSION[self::STATE_SESSION_KEY] = $kept;

      // 照合の内訳を、成功・失敗どちらの場合も残します。
      // 「控えが何件あって、そのうち何件が期限切れで、一致したのか」までを見ないと、
      // セッションが消えたのか・時間切れなのか・別の値が来たのかを区別できません。
      $traceHave = [];
      foreach ($list as $row) {
        if (is_array($row)) {
          $traceHave[] = self::traceMask((string)($row['v'] ?? '')) . '(' . ($now - (int)($row['at'] ?? 0)) . '秒前)';
        }
      }
      self::trace('復路: stateの照合', [
        '来たstate'   => self::traceMask($state),
        '控えの件数'  => $total,
        '期限切れ'    => $expired,
        '控えの中身'  => $traceHave ? implode(' / ', $traceHave) : '(空)',
        '一致したか'  => $matched,
      ]);

      if ($matched) {
        $ok = true;
      } else if ($total === 0) {
        self::noteFailure(
          'sso_state_missing',
          'consume_state',
          'このブラウザのセッションに控えが1件もありませんでした。往路を始めたときと'
            . '別のセッションで戻ってきています。セッションを破棄する処理(session_destroy等)が'
            . '往路と復路の間に走っていないか確認してください。sid=' . session_id(),
          'ログインの情報をブラウザに保存できなかったため、完了できませんでした。'
            . 'Cookieを有効にするか、プライベートモードを解除したうえで、'
            . 'もう一度ログインボタンを押してください。'
        );
        $ok = false;
      } else if ($expired === $total) {
        self::noteFailure(
          'sso_state_expired',
          'consume_state',
          '控えは ' . $total . ' 件ありましたが、すべて期限切れ(' . self::STATE_TTL . '秒)でした。'
            . 'ログイン画面を開いたまま長く放置した場合に起きます。',
          'ログイン画面を開いたまま時間が経ちすぎたため、やり直しが必要です。'
            . 'お手数ですが、もう一度ログインボタンからお試しください。'
        );
        $ok = false;
      } else {
        self::noteFailure(
          'sso_state_mismatch',
          'consume_state',
          '控えは ' . $total . ' 件(うち期限切れ ' . $expired . ' 件)ありましたが、'
            . '戻ってきた値と一致するものがありませんでした。sid=' . session_id(),
          'このログインリンクは既に使用済みか、古いものです。ブラウザの「戻る」や以前のリンクからではなく、'
            . 'このページのログインボタンからもう一度お試しください。'
        );
        $ok = false;
      }
    }

    return $ok;
  }

  /**
  /**
   * SSOの往復をまとめて処理します。プロダクト固有の部分だけを受け取ります。
   *   $isLoggedIn … このプロダクト自身のログイン判定を返す (): bool
   *   $onLogin    … 交換に成功したときの取り込み (array $user, string $token): void
   *   $options    … 下記の任意設定
   *
   * 呼び出しは session_start() より後、画面の出力より前に1回だけ。
   * この中で header()+exit する経路があるため、出力後に呼ぶと動きません。
   *
   * 【自前で往復を組まないでください】
   * 以前は p-drive・p-chat・p-reversi が、それぞれ固有の事情(ダウンロード中は往復
   * しない/初回訪問者には試さない/対局中に画面を飛ばさない)のためにこの関数を使わず、
   * consumeState() などの部品から往復を自前で組んでいました。その結果、
   * **引き渡しコードを交換する前のstate照合が4箇所に複製**されていました。
   * この照合は、コードを盗んだ第三者が自分のブラウザでページを開くだけで
   * 被害者のトークンを奪えてしまうのを防ぐ、唯一の砦です。1箇所でも書き忘れれば
   * そのプロダクトに穴が開き、しかも普段は正常に動くので誰も気づけません。
   * 固有の事情は下の $options で吸収できるようにしたので、往復そのものは
   * 必ずこの関数へ通してください。
   *
   * $options に渡せるもの:
   *   'silent'    … 無音サインイン(フォームを見せずにログイン済みか確かめる往復)を
   *                  試すかどうか。
   *                    false      … 一切試さない(p-reversiのように、対局中に画面が
   *                                 飛ぶのを避けたいプロダクト)
   *                    callable   … そのプロダクト独自の条件 (): bool
   *                    省略       … 既定(未ログインで、このブラウザセッションで
   *                                 まだ試していなければ1回だけ試す)
   *   'onFailure' … サインインに失敗したときの追加処理 (string $message): void
   *                  失敗理由は省略しても takeSignInError() で拾えます。画面へ独自の
   *                  形で出したいプロダクト(p-chatのフラッシュ等)だけが渡してください。
   *   'redirect'  … リダイレクトのやり方 (string $url): void
   *                  省略すると header()+exit。独自のリダイレクト関数を持つ
   *                  プロダクトだけが渡してください。
   */
  public static function handleSsoRoundTrip(callable $isLoggedIn, callable $onLogin, array $options = []): void {
    // 【正常系も含めて全段階を記録します】どこで止まったかだけでなく、どこまでは
    // 正常に進んだのかが分からないと、往復の不具合は追えません。記録は既定では
    // 何も書かず、.pusyuuHiddenFiles/pusyuu_sso_trace_on を置いたときだけ働きます
    // (詳しくは trace() のコメント)。
    self::traceEnvironmentOnce();
    self::trace('往復の判定を開始', [
      'メソッド'        => $_SERVER['REQUEST_METHOD'] ?? '?',
      'Sec-Fetch-Mode'  => $_SERVER['HTTP_SEC_FETCH_MODE'] ?? '(なし)',
      'X-Requested-With' => $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '(なし)',
      'ページ遷移か'    => self::isPageNavigation(),
      '引き渡しコード'  => self::traceMask($_GET['pusyuu_code'] ?? null),
      '無音の印'        => isset($_GET['pusyuu_silent']) ? '有り' : '無し',
      'state'           => self::traceMask($_GET['pusyuu_state'] ?? null),
      '控えの件数'      => count($_SESSION[self::STATE_SESSION_KEY] ?? []),
      '確認済みの印'    => !empty($_SESSION['pusyuu_sso_checked']),
      'UA'              => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? '(なし)'), 0, 120),
    ]);

    if (isset($options['redirect']) && is_callable($options['redirect'])) {
      $redirect = $options['redirect'];
    } else {
      $redirect = static function (string $url): void {
        header('Location: ' . $url);
        exit;
      };
    }

    // ================================================================
    // この往復で「今どの場面なのか」を、ここ1本のif/elseで決めます。
    //
    // 【並び順に意味があります。入れ替えないこと】上から順に、
    //   ページを開いた操作か → 出発の合図か → 復路(コード有り) → 復路(無音)
    //   → 既にログイン済みか → 無音を試してよい相手か → 往路へ
    // です。とくに **出発の判定は復路より前・ログイン済みの判定より前** に
    // 置いてください。既にログインしている人が「アカウント管理」を押したときも、
    // メイキィへ送り出す必要があるためです。
    //
    // 枝を足すときは、この鎖に else if を1本足してください。鎖の外で
    // $redirect を呼ぶ場所を作ると、「どのURLで何が起きるのか」が
    // ここを読んでも分からなくなります。
    // ================================================================
    if (!self::isPageNavigation()) {
      // ページを開いた操作でなければ何もしない(理由はisPageNavigation()参照)。
      self::trace('何もしない: ページを開いた操作ではない');

    } else if (self::departIfRequested($redirect)) {
      // 出発の合図が付いていた。departIfRequested() が中で送り出し済みです。

    } else if (isset($_GET['pusyuu_code']) && is_string($_GET['pusyuu_code'])) {
      $_SESSION['pusyuu_sso_checked'] = true;
      $state = (isset($_GET['pusyuu_state']) && is_string($_GET['pusyuu_state']))
        ? $_GET['pusyuu_state']
        : '';

      // 戻り先へ送り出すかどうか。既定は送り出す。送り出さないのは1通りだけです。
      $sendBack = true;

      // 【サインインの結果を必ず残すこと】この直後にリダイレクトするので、ここで何も
      // 残さないと、失敗したことが利用者にも開発者にも一切伝わりません。
      // 「ログインを押したのに何も起きずに元の画面へ戻る」という、最も原因を追いにくい
      // 壊れ方になります(実際にpipsとtoolboxでこの状態が起きていました)。
      if (!self::consumeState($state)) {
        // このブラウザが始めた往復ではないコードなので、交換しません(交換すると、
        // コードを盗んだ第三者のセッションへ被害者のトークンを入れてしまいます)。
        // 詳しい理由は consumeState() が既に記録済みなので、それを引き継ぎます。
        if (!self::browserKeepsSession()) {
          // セッションCookieがそもそも返ってきていない場合は話が別です。
          // それは「盗まれたコード」ではなく「このブラウザがCookieを保持できていない」
          // ということなので、利用者に伝えるべき内容がまったく違います。
          self::trace('復路(コード有り): 照合できず。セッションCookieも来ていない', [
            '判断' => 'このブラウザはCookieを保持できていない',
          ]);
          self::finishSignIn(
            false,
            'ブラウザがCookieを保存できていないため、ログインを完了できません。'
              . 'Cookieを有効にするか、プライベートモードを解除してお試しください。',
            $options
          );
          // 【ここでリダイレクトしないこと】理由の控えもセッションに置くしかなく、
          // そのセッションが保持されないのだから、リダイレクトすれば理由ごと消えます。
          // 利用者には「押したのに何も起きない」としか見えません。
          // 送り出さずにこのまま描画へ進めば、同じリクエストの中で理由を表示できます。
          // アドレスバーに往復の印が残りますが、無言で失敗するよりはるかにましです。
          $sendBack = false;

        } else if ($isLoggedIn()) {
          // 【既にサインイン済みなら、失敗として見せないこと】
          // 引き渡しコードの付いたURLは使い捨てです。ログインが成立した後に
          // ブラウザの「戻る」を押す・そのページを再読み込みする・履歴から開き直すと、
          // 同じURLがもう一度この関数へ届きます。控えは1度目で使い切っているので
          // 照合は必ず外れますが、**その人は既にログインできています**。
          //
          // ここで失敗を出すと、ログインできているのに「確認できませんでした。
          // もう一度お試しください」と言われることになり、何をすれば直るのか
          // 分からないまま同じ操作を繰り返させます(実際にその報告が出ました)。
          // 交換しないという判断はそのままに、見せ方だけを実態に合わせます。
          self::trace('復路(コード有り): 照合できないが、既にサインイン済み', [
            '判断' => '使用済みリンクを開き直しただけ。失敗としては扱わない',
          ]);
          self::finishSignIn(true, '', $options);

        } else {
          self::trace('復路(コード有り): 照合できず。Cookieは来ている', [
            '理由' => self::$lastError['stage'] ?? '(不明)',
          ]);
          self::finishSignIn(false, self::lastErrorMessage(), $options);
        }

      } else {
        self::trace('復路(コード有り): 照合に成功。トークンへの引き換えへ');
        $exchanged = self::exchangeCode($_GET['pusyuu_code']);

        if (!empty($exchanged['ok']) && !empty($exchanged['token']) && !empty($exchanged['user'])) {
          $onLogin($exchanged['user'], (string)$exchanged['token']);
          self::trace('復路(コード有り): ログイン成立', [
            'ユーザー名' => (string)($exchanged['user']['username'] ?? '(不明)'),
            'トークン'   => self::traceMask((string)$exchanged['token']),
            '新しいsid'  => session_id(),
          ]);
          self::finishSignIn(true, '', $options);
        } else {
          self::trace('復路(コード有り): 引き換えに失敗', [
            '理由' => self::$lastError['stage'] ?? '(不明)',
          ]);
          self::finishSignIn(false, 'ログインの引き換えに失敗しました。' . self::lastErrorMessage(), $options);
        }
      }

      if ($sendBack) {
        self::trace('復路(コード有り): 戻り先へ送り出す', ['戻り先' => self::currentUrl()]);
        $redirect(self::currentUrl());
      }

    } else if (isset($_GET['pusyuu_silent'])) {
      $_SESSION['pusyuu_sso_checked'] = true;
      if (isset($_GET['pusyuu_state']) && is_string($_GET['pusyuu_state'])) {
        self::consumeState($_GET['pusyuu_state']); // 使い終わった控えを残さない
      }

      if (!self::browserKeepsSession()) {
        // Cookieが返ってきていないなら、リダイレクトしても pusyuu_sso_checked ごと
        // 消えるので、また往路に出て延々と往復し続けます。ここで止めて描画へ進みます。
        // (無音サインインは利用者が始めた操作ではないので、画面には何も出しません。
        //  静かに未サインインのまま普通に使えれば十分です。)
        self::trace('復路(無音): セッションCookieが来ていないので、ここで打ち切る');
      } else {
        self::trace('復路(無音): 未ログインと分かった。戻り先へ送り出す', [
          '戻り先' => self::currentUrl(),
        ]);
        $redirect(self::currentUrl());
      }

    } else if ($isLoggedIn()) {
      self::trace('何もしない: すでにこのプロダクトでログイン済み');

    } else if (array_key_exists('silent', $options) && $options['silent'] === false) {
      // 無音サインインを試すかどうか。プロダクト固有の事情はこの3本で吸収します。
      self::trace('何もしない: このプロダクトは無音サインインを行わない設定');

    } else if (array_key_exists('silent', $options)
      && is_callable($options['silent']) && !$options['silent']()) {
      self::trace('何もしない: プロダクト独自の条件が「試さない」と判断');

    } else if (!array_key_exists('silent', $options) && !empty($_SESSION['pusyuu_sso_checked'])) {
      // 既定は1ブラウザセッションにつき1回だけ(無限リダイレクト防止)。
      self::trace('何もしない: このセッションでは既に1回確認済み');

    } else if (function_exists('pusyuuIsKnownCrawler') && pusyuuIsKnownCrawler()) {
      // クローラを往復させても意味が無いので巻き込まない(判定関数が無い環境では素通り)。
      self::trace('何もしない: 既知のクローラと判定');

    } else if (!self::browserKeepsSession()) {
      // 【この確認を外さないこと】まだセッションCookieが返ってきていないブラウザを
      // 往路へ送り出してはいけません。往路の302に乗せた Set-Cookie を無視する
      // 古いブラウザ(旧Android標準ブラウザ等)では、セッションが一度も成立せず、
      // 15秒おきに往復を繰り返すだけの無限ループになります(理由の詳細は
      // browserKeepsSession() のコメント)。初回はそのまま描画して通常の200応答で
      // Cookieを渡し、次にページを開いたときに往路へ出れば済みます。
      // Cookieを保持できない相手(クローラ等)は、ここで静かに素通りします。
      self::trace('何もしない: セッションCookieがまだ返ってきていない(初回か、保持できない相手)');

    } else if (!self::reachable()) {
      // 【この確認を外さないこと】メイキィが居ない環境でここを無条件に通すと、
      // 初めて訪れた人のブラウザを存在しないホストへ302で送り出してしまいます。
      // 利用者が見るのはブラウザの「このサイトにアクセスできません」で、こちらの
      // ページは一度も描画されません。サーバは302を返して正常終了したつもりなので、
      // error.log にも何も残らず、原因に辿り着けません。
      // ここで到達を確かめておけば、メイキィが居ないときは黙って素通りし、
      // アカウント機能だけが畳まれた状態でページが普通に表示されます。
      //
      // pusyuu_sso_checked は意図的に立てません。立てるとメイキィが復旧しても、
      // そのブラウザセッションが終わるまで無音サインインが二度と試されなくなります。
      self::trace('何もしない: メイキィへ到達できない', [
        '理由' => self::$lastError['stage'] ?? '(不明)',
      ]);

    } else {
      $silentUrl = self::silentLoginUrl();
      self::trace('往路へ送り出す(無音サインイン)', [
        '行き先'       => $silentUrl,
        '控えた件数'   => count($_SESSION[self::STATE_SESSION_KEY] ?? []),
      ]);
      $redirect($silentUrl);
    }
  }

  /**
   * サインインの結末を記録し、プロダクト独自の後始末があれば呼びます。
   * 記録は独自処理の有無にかかわらず必ず行います。プロダクトが独自の見せ方を
   * したからといって、開発者向けの記録まで消えてよい理由はないためです。
   */
  private static function finishSignIn(bool $ok, string $message, array $options): void {
    self::noteSignInOutcome($ok, $message);

    // 失敗したときだけ、プロダクト側の受け口へも伝えます。
    if ($ok === false && isset($options['onFailure']) && is_callable($options['onFailure'])) {
      $text = ($message === '')
        ? 'ログインを完了できませんでした。お手数ですが、このページのログインボタンからもう一度お試しください。'
        : $message;

      $options['onFailure']($text);
    }
  }
}

// =====================================================================
// 2階: プロダクトへ見せる窓口
//
// ここから下がプロダクトから呼んでよい唯一の面です。名前にも引数にも戻り値にも
// 「メイキィ」は一切出てきません。プロダクトは「アカウント機能がある/ない」と
// 「その機能に何を頼めるか」だけを知っていれば足ります。
//
// 【全部の関数に共通の約束】
//   1. 呼ぶ前に必ず function_exists('pusyuuAccountReady') && pusyuuAccountReady() で
//      確かめること。このファイルが無い環境では関数そのものが存在しません。
//   2. 失敗しても例外は投げません。必ず ['ok' => false, 'message' => 利用者に見せてよい日本語]
//      を含む配列が返ります。呼び出し側で分岐を増やさずに済ませるためです。
//   3. 'message' はそのまま画面に出せます。開発者向けの詳細は error.log と
//      pusyuuAccountDiagnostics() にあります。両者を混ぜないでください。
//      利用者に接続先URLやファイルパスを見せても、何の助けにもなりません。
// =====================================================================

/**
 * アカウント機能が今使えるかどうか。プロダクトはこれ1つだけを見て分岐します。
 *
 * 「このファイルが読み込まれていること」と「アカウント基盤に実際に届くこと」の
 * 両方が揃ってはじめて true です。片方だけを見ると、基盤が落ちている間に
 * ログイン画面へ誘導してしまい、利用者は行き止まりに突き当たります。
 */
function pusyuuAccountReady(): bool {
  return PusyuuMeikieeClient::configured();
}

/**
 * アカウント基盤へ実際に届くかどうかを、通信して確かめます。
 *
 * 【ふだんは使わないでください】pusyuuAccountReady() で足ります。こちらは通信を伴い、
 * 一瞬でも届かなければ false になります。それを画面の描画やデータ取得の門番にすると、
 * 基盤がわずかに詰まっただけで利用者が「ログアウトした」ように見えます。
 * 本来のAPI呼び出しは失敗すれば自分で理由を返すので、事前に様子を見る必要はありません。
 *
 * 使ってよいのは、ブラウザを実際にメイキィの画面へ送り出す直前や、
 * 管理画面で疎通を確かめたいときだけです。
 */
function pusyuuAccountReachable(): bool {
  return PusyuuMeikieeClient::reachable();
}

/**
 * 使えないときの理由を、利用者にそのまま見せてよい1文で返します。
 * まだ何も失敗していなければ空文字なので、そのときは画面に何も出さないでください。
 *
 * 「アカウント機能が使えません」とだけ出して理由を伏せると、利用者は自分の操作が
 * 悪いのか、こちらが壊れているのかを区別できません。必ず添えてください。
 */
function pusyuuAccountUnavailableReason(): string {
  return PusyuuMeikieeClient::lastErrorMessage();
}

/**
 * 直近の失敗が「アカウント基盤まで届かなかった」ものかどうか。
 *
 * 値が返らなかったときに、「基盤が"無い"と答えた」のか「そもそも聞けなかった」のかを
 * 見分けるために使います。両者を混ぜると、実在する相手を「居ない」と断定することに
 * なります。その判定を根拠にデータを消す処理があると、一時的な不通で生きている
 * データが失われます。実際、その取り違えでp-chatの部屋とメッセージが消えていました。
 */
function pusyuuAccountLastFailureWasConnection(): bool {
  return PusyuuMeikieeClient::lastFailureWasConnection();
}

/**
 * 開発者向けの設置状態。接続先・鍵ファイルの場所・合言葉の指紋・直近の失敗が入ります。
 * 管理画面や診断ページでだけ使ってください(利用者向けの画面には出さないこと)。
 */
function pusyuuAccountDiagnostics(): array {
  return PusyuuMeikieeClient::diagnostics();
}

/**
 * ログインの経過記録(トレース)が今ONかOFFか、記録先はどこか、今どれだけ溜まっているか。
 *
 * 切り替えは次のどちらかで、コードを直す必要はありません。
 *   .pusyuuHiddenFiles/pusyuu_sso_trace_on を置けばON、消せばOFF
 *   読み込み前に PUSYUU_ACCOUNTS_TRACE を定義すれば、そちらが優先
 *
 * 【ONのまま放置しないこと】記録には state や引き渡しコードの先頭8文字が残ります。
 * 全文ではないので直ちに悪用はできませんが、調査が終わったらOFFへ戻してください。
 * この関数は管理画面から状態を確かめるためのもので、利用者向けの画面には出しません。
 */
function pusyuuAccountTraceStatus(): array {
  return PusyuuMeikieeClient::traceStatus();
}

/**
 * ログインの経過記録(トレース)をONまたはOFFにします。戻り値は ['ok'=>bool,'message'=>string]。
 *
 * 【管理画面(oppai)からだけ呼んでください】利用者向けの画面から切り替えられるように
 * すると、記録の中身(state や引き渡しコードの先頭8文字)を集める口を外へ開くことに
 * なります。呼ぶ前に必ず管理者としてログイン済みであることを確かめてください。
 *
 * 【失敗したら、そのまま画面に出してください】書き込み権限が無いときは ok=false と
 * 場所の分かるmessageが返ります。ここで黙って握り潰すと、「ONにしたのに記録が
 * 増えない」という、原因の見えない形になります。
 */
function pusyuuAccountTraceSet(bool $on): array {
  return PusyuuMeikieeClient::traceSetEnabled($on);
}

/** 溜まった経過記録を捨てます(管理画面用)。調査を始める前に空にすると読みやすくなります。 */
function pusyuuAccountTraceClear(): array {
  return PusyuuMeikieeClient::traceClear();
}

/** 経過記録の末尾を返します(管理画面用)。記録が無ければ空文字。 */
function pusyuuAccountTraceTail(int $lines = 200): string {
  return PusyuuMeikieeClient::traceTail($lines);
}

// ---------------------------------------------------------------------
// 管理者用の入口
//
// 【これは管理画面(oppai)専用です。普通のプロダクトからは呼ばないでください。】
// ここから呼べるのはメイキィの管理系API——アカウントの一覧・削除・一時ログインの
// 発行・取り残されたデータの掃除——で、他人のアカウントを操作できる力を持ちます。
// 利用者向けのプロダクトがこれを呼ぶ理由は無く、呼べる場所を増やすほど事故の
// 起きる面が広がります。
//
// 【なぜ個別の関数にせず、アクション名を渡す形なのか】
// 管理系のアクションはメイキィ側の都合で増減し、呼び出し側は管理画面ただ1つです。
// 1つずつ薄い関数を用意しても、メイキィに合わせてここを書き換える手間が増えるだけで、
// 誰の助けにもなりません。逆に「合言葉の作り方・HTTPの投げ方・失敗の段階分け」は
// 他と完全に同じなので、そこだけは共有します。それがこの関数の役割です。
// ---------------------------------------------------------------------

/**
 * メイキィの管理系APIを呼びます。戻り値は他と同じく ['ok' => bool, ...]。
 * 失敗の理由は pusyuuAccountUnavailableReason() で取れます。
 */
function pusyuuAccountAdminCall(string $action, array $params = []): array {
  return PusyuuMeikieeClient::adminCall($action, $params);
}

// ---------------------------------------------------------------------
// サインインとサインアウト
// ---------------------------------------------------------------------

/**
 * サインインの往復をまとめて面倒みます。プロダクト固有の部分だけを渡してください。
 *   $isSignedIn … このプロダクト自身のログイン判定を返す (): bool
 *   $onSignIn   … サインインできたときの取り込み (array $user, string $token): void
 *   $options    … 固有の事情があるプロダクトだけが渡す任意設定(下記)
 *
 * $user には userid / username / name が入っています。userid はハッシュで、
 * 生のidではありません。保存先の指定にはこの userid を使ってください。
 *
 * session_start() より後、画面の出力より前に1回だけ呼ぶこと。
 * この中で header()+exit する経路があるため、出力後に呼ぶと動きません。
 *
 * アカウント基盤に届かないときは、何もせず静かに戻ります。行き先が無いのに
 * ブラウザを送り出すと、利用者はブラウザのエラー画面を見ることになり、
 * こちらのページは一度も表示されません。
 *
 * 【往復を自前で組まないでください】
 * 固有の事情があっても、この関数を迂回して自分で往復を書かないこと。往復には、
 * 引き渡しコードを交換する前の照合という、絶対に省けない一手があります。省くと、
 * コードを盗んだ第三者が自分のブラウザでページを開くだけで、こちらが代わりに交換して
 * 盗んだ側へ被害者のトークンを渡してしまいます。しかも普段は正常に動くので気づけません。
 * 実際に p-drive・p-chat・p-reversi がこれを自前で持っていて、同じ照合が4箇所に
 * 複製されていました。固有の事情は $options で吸収できます。
 *
 * $options に渡せるもの:
 *   'silent'    … 無音サインイン(フォームを見せずに確かめる往復)を試すかどうか。
 *                  false なら一切試さない。callable ならそのプロダクト独自の条件。
 *                  省略時は「未ログインで、このブラウザセッションでまだ試していなければ
 *                  1回だけ」。
 *   'onFailure' … 失敗したときの追加処理 (string $message): void。
 *                  渡さなくても理由は pusyuuAccountTakeSignInError() で拾えます。
 *                  独自の見せ方をしたいプロダクトだけが渡してください。
 *   'redirect'  … リダイレクトのやり方 (string $url): void。
 *                  省略すると header()+exit。独自のリダイレクト関数を持つ
 *                  プロダクトだけが渡してください。
 */
function pusyuuAccountHandleSignIn(callable $isSignedIn, callable $onSignIn, array $options = []): void {
  PusyuuMeikieeClient::handleSsoRoundTrip($isSignedIn, $onSignIn, $options);
}

/**
 * 直近のサインインが失敗していれば、その理由を1度だけ返します(読んだら消えます)。
 * 成功した場合や、そもそもサインインを試していない場合は空文字です。
 *
 * 【毎ページで呼んで、空でなければ画面に出してください】呼ばないと、サインインの失敗は
 * 誰にも見えません。サインインの判定はリダイレクトの直前で行われ、そのリクエストは
 * そのまま終わるので、利用者には「ログインを押したのに元の画面へ戻っただけ」に見えます。
 * 開発者にも報告のしようがありません。実際、pipsとtoolboxがこの状態で止まっていて、
 * 原因に辿り着くのに時間がかかりました。
 *
 * 出力はPHPが書くHTMLで行ってください。JavaScriptに任せると、JSが無い環境で
 * 理由が読めなくなります。
 */
function pusyuuAccountTakeSignInError(): string {
  return PusyuuMeikieeClient::takeSignInError();
}

/**
 * サインイン画面へのリンク。$returnTo を省くと今のページへ戻ります。
 *
 * 【このURLを自前で組み立てないこと】戻り先には、往路を始めたブラウザだけが復路を
 * 完了できるようにするための使い捨ての合言葉が必要です。手で組み立てるとそれが
 * 抜け落ち、サインインして戻ってきても**何のエラーも出さずにサインインされません**。
 * iframeの中など、今のページとは別の場所へ戻したいときは $returnTo を渡してください。
 */
function pusyuuAccountSignInUrl(?string $returnTo = null): string {
  return PusyuuMeikieeClient::accountUrl('login', $returnTo);
}

/** アカウント作成画面へのリンク。$returnTo の扱いは pusyuuAccountSignInUrl() と同じです。 */
function pusyuuAccountCreateUrl(?string $returnTo = null): string {
  return PusyuuMeikieeClient::accountUrl('create', $returnTo);
}

/** アカウント管理(編集)画面へのリンク。$returnTo の扱いは pusyuuAccountSignInUrl() と同じです。 */
function pusyuuAccountManageUrl(?string $returnTo = null): string {
  return PusyuuMeikieeClient::accountUrl('edit', $returnTo);
}

/**
 * サインアウト用のリンク。
 *
 * 【自分のセッションを畳むだけでは足りません】プロダクト側のトークンを捨てても、
 * アカウント基盤側のログイン状態は生きたままです。そのまま次のページを開くと、
 * 自動サインインが働いてすぐログイン状態に戻ってしまいます。必ずこのURLへ
 * 送り出して、基盤側のログイン状態も一緒に終わらせてください。
 *
 * $returnTo を省くと今のページへ戻ります。
 */
function pusyuuAccountSignOutUrl(?string $returnTo = null): string {
  return PusyuuMeikieeClient::logoutUrl($returnTo);
}

/** 手元のトークンを失効させます(画面遷移は伴いません)。 */
function pusyuuAccountRevokeToken(string $token): array {
  return PusyuuMeikieeClient::logout($token);
}

/**
 * 今開いているページのURL。往復で使った印(引き渡しコード・state等)は落とします。
 *
 * 落とさないとアドレスバーに残り続け、次の往復で古い値が混ざります。自分で
 * $_SERVER から組み立てると、この「落とす」処理を忘れがちなので、戻り先を作る用途では
 * こちらを使ってください。スキームはhttps固定です(リバースプロキシ越しだと
 * $_SERVER['HTTPS'] が立たないことがあり、戻り先がhttpになって往復が壊れるためで、
 * 戻り先はどのみち許可リストに載っている公開ホスト名に限られます)。
 */
function pusyuuAccountCurrentUrl(): string {
  return PusyuuMeikieeClient::currentUrl();
}

// ---------------------------------------------------------------------
// 本人と他人の情報
// ---------------------------------------------------------------------

/**
 * トークンの持ち主の詳細(email・保存済みデータ込み)を返します。
 *
 * 戻り値は必ず次の形です。
 *   ['ok' => true,  'user' => [...]]
 *   ['ok' => false, 'rejected' => bool, 'message' => '...']
 *
 * 【rejected を必ず見てください】false が返る理由には2種類あります。
 *   rejected = true  … 「そのトークンはもう無効だ」と基盤が答えた。本当にサインアウト
 *                       させるべき場合です。
 *   rejected = false … 基盤に届かなかった・鍵が無い・500が返った。こちらの都合であって、
 *                       利用者は何も悪くありません。**セッションを畳まないでください。**
 *
 * この2つを混ぜて「ok以外なら全部サインアウト」と書いていたために、基盤が一時的に
 * 落ちただけで利用者のセッションが丸ごと破棄され、サインインの控えまで消えて
 * ログインし直すこともできなくなる、という不具合が実際に起きました。
 */
function pusyuuAccountSelf(string $token): array {
  $res = PusyuuMeikieeClient::me($token);

  if (!empty($res['ok']) && !empty($res['user'])) {
    $result = ['ok' => true, 'user' => $res['user']];
  } else {
    $result = [
      'ok'       => false,
      'rejected' => PusyuuMeikieeClient::isTokenRejected($res),
      'message'  => (string)($res['message'] ?? 'アカウント情報を確認できませんでした。'),
    ];
  }

  return $result;
}

/**
 * ユーザー名から公開プロフィール(userid/username/name/bio/avatar_url)を取得します。
 * 見つからない場合も、基盤に届かない場合も null です。
 * 両者を区別したいときは pusyuuAccountUnavailableReason() を見てください。
 */
function pusyuuAccountProfile(string $username): ?array {
  $res = PusyuuMeikieeClient::fetchProfile($username);

  if (!empty($res['ok']) && !empty($res['user'])) {
    $user = $res['user'];
  } else {
    $user = null;
  }

  return $user;
}

/**
 * その保存先ID(userid)のアカウントがまだ存在するかどうか。
 * 複数人が絡む自前データを持つプロダクト(p-chat/p-reversi)が、相手が退会済みで
 * ないかを確かめる用途です。確認できなかった場合は、消してしまうより残す方が
 * 安全なので true を返します。
 */
function pusyuuAccountExists(string $userid): bool {
  $res = PusyuuMeikieeClient::accountExists($userid);

  if (empty($res['ok'])) {
    // 確かめられなかった。生きている相手を「退会済み」と誤認してデータを消すより、
    // 消さずに残す方が安全なので、居ることにします。
    $exists = true;
  } else {
    $exists = !empty($res['exists']);
  }

  return $exists;
}

/**
 * pusyuuAccountExists() と同じ確認を、「確かめられたのか」まで込みで返します。
 *   ['confirmed' => bool, 'exists' => bool]
 *
 * 【なぜ真偽値だけでは足りないか】
 * pusyuuAccountExists() は、確認できなかったときに true(居る)を返します。生きている
 * 相手を「退会済み」と誤認してデータを消すより、消さずに残す方が安全だからです。
 * ただしその安全側の答えは、「確かに居る」と区別が付きません。
 *
 * 掃除のように「居ないなら消す」処理では、この区別が要ります。区別しないと、
 * 基盤へ恒久的に届かなくなった環境で、掃除は毎回「全員居る」と判断して何もせず、
 * **止まっていることに誰も気づけません**。confirmed=false のときは「判断できな
 * かった」と記録に残し、あとから気づけるようにしてください。
 */
function pusyuuAccountExistsChecked(string $userid): array {
  $res = PusyuuMeikieeClient::accountExists($userid);

  if (empty($res['ok'])) {
    $answer = ['confirmed' => false, 'exists' => true];
  } else {
    $answer = ['confirmed' => true, 'exists' => !empty($res['exists'])];
  }

  return $answer;
}

/**
 * userid(ハッシュ)の配列を、公開プロフィールの一覧へ一括で解決します。
 * フォロー中一覧のように、手元にはIDしか無い場面で名前やアバターを得るために使います。
 */
function pusyuuAccountResolveIds(array $userids): array {
  $res = PusyuuMeikieeClient::resolveIds($userids);

  if (!empty($res['ok']) && is_array($res['users'] ?? null)) {
    $users = $res['users'];
  } else {
    $users = [];
  }

  return $users;
}

// ---------------------------------------------------------------------
// 小さな設定値の保存(1件4KBまで、配列は1キー200件まで)
//
// $service には自分のプロダクト名(例: 'pips', 'toolbox')、$key には用途名
// (例: 'likes', 'following', 'icon_order')を渡してください。
// 「フォロー」「お気に入り」がそのプロダクトにとって何を意味するかは、この汎用の
// 入れ物を組み合わせてプロダクト自身が組み立てます。相手が実在するか、自分自身で
// ないか、といった検証も呼び出し側の責任です。
//
// 本文や画像のようなまとまったデータは、下の「大きなデータの保存」を使ってください。
// ---------------------------------------------------------------------

/** 自分の $service/$key を取り出します。 */
function pusyuuAccountDataGet(string $token, string $service, string $key): array {
  return PusyuuMeikieeClient::dataGet($token, $service, $key);
}

/** 自分の $service/$key に値(配列でもスカラーでも可)を保存します。 */
function pusyuuAccountDataSet(string $token, string $service, string $key, $value): array {
  return PusyuuMeikieeClient::dataSet($token, $service, $key, $value);
}

/** 自分の $service/$key を丸ごと消します(無くてもエラーにしません)。 */
function pusyuuAccountDataDelete(string $token, string $service, string $key): array {
  return PusyuuMeikieeClient::dataDelete($token, $service, $key);
}

/** $service/$key を配列として扱い、$value を重複なく足します。 */
function pusyuuAccountListAdd(string $token, string $service, string $key, $value): array {
  return PusyuuMeikieeClient::dataListAdd($token, $service, $key, $value);
}

/** $service/$key の配列から $value を取り除きます。 */
function pusyuuAccountListRemove(string $token, string $service, string $key, $value): array {
  return PusyuuMeikieeClient::dataListRemove($token, $service, $key, $value);
}

/** 指定したユーザーの $service/$key 配列の件数(公開情報)。取れなければ0。 */
function pusyuuAccountListCount(string $username, string $service, string $key): int {
  $res = PusyuuMeikieeClient::dataListCount($username, $service, $key);

  if (empty($res['ok'])) {
    $count = 0;
  } else {
    $count = (int)($res['count'] ?? 0);
  }

  return $count;
}

/**
 * 「$service/$key の配列に $value を含んでいるアカウント」の件数。
 * フォロワー数のように「誰かが自分を配列に入れている数」を数えるときに使います。
 */
function pusyuuAccountReverseCount(string $value, string $service, string $key): int {
  $res = PusyuuMeikieeClient::dataReverseCount($value, $service, $key);

  if (empty($res['ok'])) {
    $count = 0;
  } else {
    $count = (int)($res['count'] ?? 0);
  }

  return $count;
}

/** pusyuuAccountReverseCount()と同条件で、件数ではなく公開プロフィールの一覧を返します。 */
function pusyuuAccountReverseList(string $value, string $service, string $key): array {
  $res = PusyuuMeikieeClient::dataReverseList($value, $service, $key);

  if (!empty($res['ok']) && is_array($res['users'] ?? null)) {
    $users = $res['users'];
  } else {
    $users = [];
  }

  return $users;
}

// ---------------------------------------------------------------------
// 大きなデータの保存(利用者の共有ストレージ)
//
// 保存先の指定に使う $userid は、サインイン時に受け取ったハッシュです。生のidでは
// ありません。画像やzipのような生バイトも渡せます(取り出すときも元のバイト列で
// 返ります。途中の詰め替えは窓口の内側で面倒をみています)。
// ---------------------------------------------------------------------

function pusyuuAccountStoragePut(string $userid, string $service, string $key, $value): array {
  return PusyuuMeikieeClient::drivePut($userid, $service, $key, $value);
}

function pusyuuAccountStorageGet(string $userid, string $service, string $key): array {
  return PusyuuMeikieeClient::driveGet($userid, $service, $key);
}

function pusyuuAccountStorageList(string $userid, string $service): array {
  return PusyuuMeikieeClient::driveList($userid, $service);
}

function pusyuuAccountStorageDelete(string $userid, string $service, string $key): array {
  return PusyuuMeikieeClient::driveDelete($userid, $service, $key);
}

/** そのプロダクトぶんを丸ごと削除します(連携解除など)。 */
function pusyuuAccountStorageDeleteService(string $userid, string $service): array {
  return PusyuuMeikieeClient::driveDeleteService($userid, $service);
}

/** その利用者が使っている合計バイト数と上限。 */
function pusyuuAccountStorageUsage(string $userid): array {
  return PusyuuMeikieeClient::driveUsage($userid);
}

/** プロダクト単位の内訳(診断用。削除は一切しません)。 */
function pusyuuAccountStorageBreakdown(string $userid): array {
  return PusyuuMeikieeClient::driveStorageBreakdown($userid);
}

/** 自分のプロダクトの保存先を再帰的に一覧します(診断用)。 */
function pusyuuAccountStorageLs(string $userid, string $service, string $subpath = ''): array {
  return PusyuuMeikieeClient::driveLs($userid, $service, $subpath);
}

/** 分割アップロードが中断して取り残された断片を掃除します。 */
function pusyuuAccountStorageSweepChunks(string $userid, string $service, int $maxAgeSeconds): array {
  return PusyuuMeikieeClient::driveSweepOrphanedChunks($userid, $service, $maxAgeSeconds);
}

// =====================================================================
// 手元保存(メイキィが無い/届かないときの受け皿)
//
// メイキィと同じ service / key で読み書きできる、ただの入れ物です。
// これがあるおかげで、各プロダクトの顔は「保存先が向こうかこちらか」を切り替えるだけで
// 済み、機能そのものを畳まずに生かせます。
//
// 【ここは判断をしません】「そのキーはメイキィが無くても意味を持つか」は
// プロダクトの都合なので、各プロダクトの顔が決めてください。目安はこうです。
//
//   置いてよい … この端末/この人自身のもので、メイキィは同期先にすぎないもの。
//                toolboxのアイコン並び順、インストール済みアプリ、pipsのお気に入り、
//                p-memoのメモ。メイキィが無くても、それ単体で意味が通ります。
//
//   置いてはいけない … 他のアカウントとの関係そのもの。pipsのフォローが典型です。
//                相手の識別子はメイキィが発行したハッシュなので、メイキィが無ければ
//                相手が実在するかも確かめられず、ハッシュを名前に戻せず、一覧に何も
//                表示できません。そもそも「自分」が誰かも決まりません。貯めても
//                読み出せない文字列が溜まるだけです。フォロワー数・プロフィール・
//                サインインも同じ理由で置けません。
//
// 【寿命】このブラウザのセッションです。ブラウザを閉じると消えます。
// pipsが以前から $_SESSION["saveUserData"] でお気に入りに対してやっていたことと
// まったく同じ寿命で、それ以上を約束していません。閉じても残したくなったら、
// この節の4つの関数の中だけを差し替えてください(呼び出し側は変わりません)。
// =====================================================================

const PUSYUU_ACCOUNT_LOCAL_KEY = 'pusyuu_account_local';

/** 手元の $service/$key を読みます。無ければ $fallback。 */
function pusyuuAccountLocalGet(string $service, string $key, $fallback = null) {
  if (isset($_SESSION[PUSYUU_ACCOUNT_LOCAL_KEY][$service][$key])) {
    $value = $_SESSION[PUSYUU_ACCOUNT_LOCAL_KEY][$service][$key];
  } else {
    $value = $fallback;
  }

  return $value;
}

/** 手元の $service/$key へ書きます。 */
function pusyuuAccountLocalSet(string $service, string $key, $value): void {
  if (isset($_SESSION[PUSYUU_ACCOUNT_LOCAL_KEY]) && is_array($_SESSION[PUSYUU_ACCOUNT_LOCAL_KEY])) {
    $all = $_SESSION[PUSYUU_ACCOUNT_LOCAL_KEY];
  } else {
    $all = [];
  }
  $all[$service][$key] = $value;
  $_SESSION[PUSYUU_ACCOUNT_LOCAL_KEY] = $all;
}

/** 手元の $service/$key を配列として扱い、重複なく足します。 */
function pusyuuAccountLocalListAdd(string $service, string $key, $value): void {
  $list = pusyuuAccountLocalGet($service, $key, []);
  if (!is_array($list)) {
    $list = [];
  }
  if (!in_array($value, $list, true)) {
    $list[] = $value;
    pusyuuAccountLocalSet($service, $key, $list);
  }
}

/** 手元の $service/$key の配列から取り除きます。 */
function pusyuuAccountLocalListRemove(string $service, string $key, $value): void {
  $list = pusyuuAccountLocalGet($service, $key, []);

  // 配列でないものが入っていたら触りません。書き換えると、元が何だったか分からなくなります。
  if (is_array($list)) {
    pusyuuAccountLocalSet($service, $key, array_values(array_filter(
      $list,
      static function ($item) use ($value) {
        return $item !== $value;
      }
    )));
  }
}
