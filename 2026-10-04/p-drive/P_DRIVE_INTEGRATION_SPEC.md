# p-drive 連携仕様書

version: 3.0 / 最終更新: 2026-09-06

> 3.0の変更点: **`p-drive/index.php`の中継口(`?api=<action>`)を廃止しました。**
> 各サービスはp-driveではなく、メイキィへ直接 `?api=p_drive_<action>` を呼びます
> (共通クライアント `PusyuuMeikieeClient` の `drivePut`/`driveGet`/`driveList`/
> `driveDelete` 等)。保存の実体がメイキィの中にある以上、p-driveを経由する一段は
> 同じHTTPの配管を二重に持つだけで、利点がありませんでした。
>
> **併せて、その中継口には認証がありませんでした。** 呼び出し側が持ってきた
> api_secretを検証しないまま、転送時にp-drive自身の正規のapi_secretを付け直して
> 送っていたため、誰でも(インターネットから直接)任意のstorage_id配下のデータを
> 読み・書き・削除できる状態でした。storage_idは公開プロフィールのアバターURLに
> 現れる値で秘密ではないため、事実上の無認証ゲートウェイになっていました。
> 中継口ごと削除して塞いであります。

## 1. これは何か

全サービス共通の**ユーザーデータストレージAPI**です。`userid`(呼び出し側が自由に決める
不透明な文字列)ごとに1つのフォルダを持ち、その下を`service`ごとのサブフォルダに分けて、
`key`単位で暗号化した値を保存します。

```
p-meikiee/p_drive_storage/<userid>/<service>/<key>.enc
```

**実装上の重要な注意**: `p-drive`フォルダには`index.php`以外、実体は何もありません。
ストレージエンジン(コード)・保存データ(実体)はどちらも`p-meikiee/index.php`の中に
一本化されています(2節参照)。将来p-driveフォルダをdisposable(使い捨て)だと
誤解して削除・再デプロイしても、実データを一切巻き込みません。

`p-drive/index.php`が持つ役割は**1つだけ**です(3.0で中継口を廃止したため)。

ユーザー自身が自分のstorage_id配下の「files」(どのサービスにも属さない個人用
ファイル)を閲覧・アップロード・ダウンロード・削除できるHTML画面、いわゆる
p-driveの「顔」です。ログインはp-memo/pips等と同じSSO
(ACCOUNTS_INTEGRATION_SPEC.md パターンB/C)で、p-drive自身が自分の`$_SESSION`に
メイキィのトークンを持ちます。ここも**保存ロジックの実装は一切持たず**、
共通クライアントの `drivePut`/`driveGet`/`driveList`/`driveDelete`(`service='files'`)を
呼ぶだけです。専用のバックエンドAPIは新設していないため、「p-driveは実体を持たない」
という2節の大前提は変わっていません。

**他のサービスがストレージを使う場合、p-driveは経由しません。** メイキィへ直接
`?api=p_drive_<action>` を呼びます(4節)。

## 2. なぜp-meikiee側に一本化しているか

当初はp-drive/p-meikieeを完全に独立した2つのサービス(別ファイル・別プロセス)として
設計していましたが、2つの問題が分かりました:

1. **アカウント削除連携の脆さ**: p-meikieeの削除処理がp-driveをHTTP経由で呼ぶ設計だと、
   p-driveがその瞬間たまたま落ちていた場合、削除の後始末(userData等の削除)が
   反映されずに孤立したデータが残ってしまう。リトライや未処理キューで軽減はできても、
   無くすことはできない。
2. **単一障害点の追加**: ストレージエンジンの実体を`p_drive_engine.php`という
   別ファイルに切り出したところ、「index.php以外のPHPファイルが何らかの理由
   (誤操作・デプロイミス等)で消えると、それをrequireしているindex.php自体が
   起動不能になる」という新しい単一障害点を生んでしまった。

これを両方とも構造的に無くすため、ストレージエンジン(`pDriveEngine*()`等の関数群)は
`p-meikiee/index.php`という**1つのファイルの中**に直接実装しています
(p-meikiee自身がこのファイル冒頭で説明している「複数ファイルに分けず1つに統合する」
という既存の設計方針と同じ理由です)。これにより:

- p-meikiee自身のアカウント削除処理(`pmeikieeDelete()`/`pmeikieeAdminDelete()`)は、
  `pDriveEngineDeleteUser($userId)`を**同一プロセス内の直接のPHP関数呼び出し**として
  呼びます。「呼び出しに失敗する」という経路自体が存在しないため、リトライ処理・
  未処理キューの類は一切不要です。
- p-meikiee以外のPHPファイルが消えて起動不能になる、という心配がありません
  (p-drive/index.php自体は画面だけの薄いファイルなので、仮に消えても実データには
  一切影響しません。再作成すれば済みます)。

各サービスがそれぞれ自前のディレクトリに暗号化ファイルを持つ従来方式(p-chat・p-memo・
p-meikieeのuserData旧実装等)の課題(削除連携漏れ・容量管理がバラバラ)は、
p-driveが「ユーザー1人分 = 1フォルダ」という構造を持つことで解決されます。

## 3. 認証

`p_drive_*`アクションは、p-meikieeの既存API(`?api=session`/`me`等)と**同じ
合言葉検証**(`pmeikieeDispatchApi()`が行う、`accountsEncryptionKey()`から
`hash_hmac('sha256', 'pusyuu_accounts_api', $key)`で導出する`api_secret`)を
そのまま使います。p-drive専用の別の合言葉はありません。

つまり、**既にp-meikieeの`accounts`連携(`ACCOUNTS_INTEGRATION_SPEC.md`)を
組み込み済みのサービスは、そこで使っている`pusyuuAccountsApiSecret()`相当の
合言葉を、そのままp-driveへの呼び出しにも使い回せます**。新しい合言葉の導出コードを
追加でコピーする必要はありません。

## 4. 新しいサービスを繋ぐ手順

### 4.1 サーバ側(共通クライアントを自分のプロダクトへ置く)

ストレージ専用のクライアントを別に用意する必要はありません。メイキィ連携で使う
共通クラス `PusyuuMeikieeClient`(ACCOUNTS_INTEGRATION_SPEC.md 3.0節)が、そのまま
ストレージの操作も持っています。まだ導入していないプロダクトは、そのクラスを
自分のプロダクトへ丸ごとコピーしてください(pips/p-memo/p-chat/p-reversi/p-drive/
toolboxのものは全て同一テキストです)。

合言葉も接続先も、メイキィ連携で使っているものをそのまま使います。ストレージ専用の
合言葉・ホスト名・導出コードは**存在しません**。以前は`P_DRIVE_API`/`P_DRIVE_HOST`と
`pDriveApiSecret()`を各プロダクトへコピーしてp-drive宛に投げていましたが、
保存の実体はメイキィの中にあるため、その一段は同じHTTPの配管を二重に持つだけでした
(3.0で廃止)。

### 4.2 実際の呼び出し

`userid`には、そのアカウントの`storage_id`(メイキィが返す`userid`。sha256ハッシュで、
生idではない)を渡します。

- 保存: `PusyuuMeikieeClient::drivePut($userid, 'my-service', 'settings', $json)`
  値は呼び出し側で必ず文字列化してください(メイキィは中身を解釈しません)。
- 取得: `PusyuuMeikieeClient::driveGet($userid, 'my-service', 'settings')`
  `ok`と`found`を確認してから`value`を使います。
- 1項目削除: `PusyuuMeikieeClient::driveDelete($userid, 'my-service', 'settings')`
- 自サービスぶんを丸ごと削除: `PusyuuMeikieeClient::driveDeleteService($userid, 'my-service')`
- 全項目の一覧: `PusyuuMeikieeClient::driveList($userid, 'my-service')`
  `items`が`[{key, value}, ...]`で返ります(p-memoのメモ一覧のように、1件ずつ
  持つ使い方向け)。
- 使用量: `driveUsage` / 内訳: `driveStorageBreakdown` / 再帰一覧: `driveLs` /
  中断した分割アップロードの掃除: `driveSweepOrphanedChunks`

**バイナリの扱いは気にしなくて構いません。** メイキィはUTF-8として妥当でない値
(画像・zip等)をbase64に包んで`encoding`の印を付けて返しますが、共通クライアントが
`driveGet`/`driveList`の中で必ず開いてから渡すため、呼び出し側は常に元のバイト列
だけを見ていれば済みます(この印を見落として壊れたデータを掴む、という事故が
実際に起きたため、1箇所に寄せてあります)。

`delete_user`(全service横断の削除)はp-meikieeのアカウント削除処理専用の想定で、
他サービスから呼ぶ機会は通常ありません(呼んでも動作はしますが、他サービスは
自分のservice名の範囲=`delete_service`の方が適切です)。

**接続済みサービスの実装例**: `p-meikiee/index.php`のuserData(1ユーザー1項目、
service名固定)、`p-memo/index.php`のメモ(1メモ=1項目、keyにメモidを使う
「複数項目を一覧・個別管理する」パターン)を参照してください。

## 5. APIリファレンス (`p-meikiee/index.php?api=p_drive_<action>`)

すべて`POST`(フォームエンコード or JSON body)。`api_secret`は全アクション必須
(3節参照)。応答は共通して`{"ok": true/false, ...}`のJSON。

呼び出し先は**メイキィ**で、アクション名には`p_drive_`が前に付きます(例: 下表の
`put`は`?api=p_drive_put`)。共通クライアントの`drivePut`等を使っていれば、この
前置きはクラス側が行うので意識する必要はありません。

| アクション | 主なパラメータ | 説明 |
|---|---|---|
| `put` | userid, service, key, value(文字列) | 1項目を保存(容量上限チェック付き)。`userid`はp-meikieeの実在するアカウントの`storage_id`であることを検証する(存在しなければ`unknown_user`エラー。誰にも参照されない孤立ディレクトリを新規に作らせないための書き込み時チェック) |
| `get` | userid, service, key | 1項目を取得。`found`(bool)と`value`(文字列) |
| `list` | userid, service | そのuserid+service配下の全項目を復号して返す(`items`: `[{key, value}, ...]`)。get()をキー数ぶん繰り返すより1回で済む(p-memoのメモ一覧等) |
| `delete` | userid, service, key | 1項目を削除 |
| `delete_service` | userid, service | そのuserid+serviceぶんを丸ごと削除 |
| `delete_user` | userid | useridのぶんを全service横断で丸ごと削除(p-meikieeのアカウント削除連携用) |
| `usage` | userid | 現在の使用量(`bytes`)と上限(`max_bytes`)を返す |
| `storage_breakdown` | userid | `<storage_id>`直下のservice名ごとの合計バイト数・最終更新日時を返す(`services`: `[{service, bytes, last_modified}, ...]`)。usageの合計値だけでは分からない「使用量は減らないのに一覧に出ないservice名がある」異常(6節参照)の診断用。削除・自動修正はしない |
| `ls` | userid, service, subpath(任意) | `<storage_id>/<service>/`(またはその中の`subpath`)配下を再帰的に列挙する(`entries`: `[{path, type: 'file'\|'dir', bytes, last_modified}, ...]`)。サービス自身が「自分が実際に何を持っているか」を把握し、古いサブフォルダ・ファイルの整理を自分の判断で行うための汎用ハンドル。削除・孤立判定は一切しない |
| `sweep_orphaned_chunks` | userid, service, max_age_seconds(任意、既定86400) | `<id>.c<番号>`という分割アップロードの命名規則に基づき、メタデータ(`<id>`単体)の無いチャンクだけが一定時間(既定24時間)残っているものを検出して削除する(`removed`: `[{file_id, chunk_count}, ...]`)。6節参照 |
| `selftest` | なし | 暗号化キー・ストレージの疎通確認 |

## 6. サービスキーのリネーム禁止と内訳の可視化

`service`名(drivePut()等に渡す固定文字列)は、`storage_id`と同じく
**一度決めたら不変の定数**として扱ってください。理由: `<storage_id>/<service>/`
という物理フォルダ名として直接使われるため、コード側でserviceの文字列を
書き換えただけでは、旧service名のフォルダの中身は新service名からは一切
見えなくなります(`usage`の合計バイト数には残り続けるので消えたようには
見えませんが、`get`/`list`では二度と取得できません)。実際にこの形で
データが孤立した事例があります。

「未知のフォルダ名=孤立とみなして削除」も採用していません(正規サービスが
単にservice名を変えただけのケースと、本当に孤立したデータとを外部から
区別できず、現用データを誤って消しかねないため)。改名したくなったら新しい
service名を追加するのではなく、`storage_breakdown`・`ls`で内訳を見て
気づけるようにしておき、必要なら移行は人間の判断で個別に行ってください。

**サービスフォルダの分裂も禁止**です。`<storage_id>/<service>/`は「そのサービス
専用の1つのフォルダ」という前提で`storage_breakdown`・`ls`・`delete_service`が
成り立っています。1つの機能(例: p-driveの「マイファイル」)のために、
実データ用のservice('p-drive')と内部管理用のservice('p-drive-internal'のような
もの)を分けて2つ作ってしまうと、`delete_service('p-drive')`のような既存の
汎用ハンドルが後者を取り残す新しい孤立の種になります(実際にこの形を一度
試して問題になりました)。「ユーザーの持ち物ではない内部の管理用データを
どこに置くか」で悩んだ場合、そのservice内に置いて`storage_breakdown`等から
除外する専用の仕組みを用意するか、あるいはそもそもその管理用データ自体が
本当に必要か(6.1節の実例のように「そもそも持たない」で解決できないか)を
先に検討してください。

### 6.1. 分割アップロードの孤立チャンクを掃除する実例

p-driveの「マイファイル」機能(チャンク分割アップロード、`<fileId>.c<番号>.enc`)は、
アップロード中にブラウザが閉じられる等で中断すると、完了時に書くはずの
メタデータ(`<fileId>.enc`)が無いままチャンク本体だけが残ることがある
(`list`はメタデータ起点でファイルを認識するため、このチャンクは一覧にも
UIの削除ボタンにも一切出てこないが、`usage`には合算され続ける。実際に
約293MBの孤立チャンクが発生した)。

`<id>.c<番号>`という分割アップロードの命名規則自体は、p-driveの「マイファイル」
専用のものではなく、storage_idの構造と同じ「今後どのサービスが大きなデータを
持ちたくなっても使える共通の土台」として扱い、判定・削除のロジックは
p-meikiee側のengine(`pDriveEngineSweepOrphanedChunks()`、
`?api=p_drive_sweep_orphaned_chunks`。userid・service・
max_age_secondsを渡す)に一本化している。「最終更新から一定時間(既定24時間)
経っていて、かつメタデータが無いチャンク」だけを対象にする(進行中の正常な
アップロードを誤って消さないため)。

各サービス(p-drive自身を含む)が持つべき責任は「いつ実行するか」という
オーケストレーションだけであり、判定ロジック自体を自分で持つ必要はない
(`account_exists`をp-chat/p-reversiが呼ぶだけで済むのと同じ構造)。p-drive
自身はこれを`pDriveFilesRunOrphanSweepIfNeeded()`で、ファイル管理画面が
表示されるたびに**無条件に**呼んでいる。

**「1日1回」のような頻度制限は意図的に付けていない。** この判定は
`pmeikieeSweepOrphanedStorage()`と違い、account.jsonlの読み込みも
p_drive_storage全体の走査も行わない。1人・1serviceフォルダをscandir()する
だけの軽い処理で、コストはシステム全体のアカウント数ではなく「呼び出した
本人が持つファイル数」だけに比例する。頻度制限を付けるには「最終実行日」を
どこかに記録する必要が生じるが、それ自体が新たな持ち物(かつ、ユーザーの
p-drive領域に置けば使用量に混ざり、別serviceに分ければ上記の「サービス
分裂禁止」に反し、p-meikiee側の共有領域に1ファイルでまとめれば全ユーザーが
同じロックを取り合う・退会時に個別削除できない、といった別の問題を生む)
になると分かったため、そもそも記録しないことにした。

oppai管理パネルからも、任意のstorage_id・serviceに対して
`p_drive_sweep_orphaned_chunks`を直接呼べる(ストレージ内訳画面の
「孤立チャンクを掃除」ボタン)。

## 7. 複数ユーザーが絡むデータ(部屋・対局等)の設計方針

p-chatの部屋(メンバー複数名)・p-reversiの対局(黒白2名)のように、「1データ=
1ユーザー所有」という前提に乗らない、複数ユーザーが関わるデータについては、
p-drive側に専用の共有ストレージ機構は用意していません(検討の結果、あえて
持たせない方針にしました)。

理由: p-driveは「そのユーザー自身のプライベートな持ち物」を1箇所に集約する
ための場所という性質を持っています。第三者(会話やゲームの相手)との
やり取りの実体まで1つのレコードとしてここに置いてしまうと、この「個人の
プライベート空間」という前提が崩れます。

代わりに、各サービスは以下の設計を採ってください(p-chat・p-reversiが採用している方式):

- 会話・対局などの実データは、引き続きそのサービス自身のストレージで管理する。
- 「相手のアカウントがまだ存在するか」は、p-drive上に何か目印を作って間接的に
  確認するのではなく、p-meikieeのアカウントAPI`account_exists`
  (ACCOUNTS_INTEGRATION_SPEC.md参照、`storage_id`を渡すと`exists`のbooleanを
  返す)を**直接**呼んで確認してください。以前はp-drive上に「自分がこの会話に
  参加している」という目印(マーカー)を各参加者ごとに書き込み、その存在を
  もって相手の生死を判定する方式を検討しましたが、これは結局
  p-meikieeが既に持っている正解データ(account.jsonl)への間接的な代理
  チェックに過ぎず、マーカー自体が(アカウント削除と無関係な理由で)本体の
  状態からズレて存在し得るという弱点がありました。`account_exists`を直接
  叩けばこの種のズレは原理的に起こりません。
- 部屋・対局を有効化する際は、両参加者の`account_exists`がtrueであることを
  確認してから初めて有効化する(p-drive側に何かを書き込む必要はないので、
  部分失敗のロールバックのような手当ても不要)。
- 実際の閲覧時に、相手の`account_exists`を確認し、falseなら(相手が退会した
  ということなので)自分のローカルデータをその場で片付ける。
- 上記は「誰かがその会話/対局を実際に開いたとき」だけ働く受動的な仕組みの
  ため、これを補う能動的な定期スイープも用意する。全roomを巡回して両参加者の
  `account_exists`を確認し、片方でもfalseであれば誰も開かなくても削除する。
  専用cronを組む代わりに、p-meikieeの1日1回自動保守
  (`pmeikieeRunDailyMaintenanceIfNeeded()`)と同じ「最終実行日をJSON1件に
  記録し、通常のリクエストのたびに日付が変わっていないか確認する」方式で
  1日1回だけ実行する(p-chatの`pchatRunDailyMaintenanceIfNeeded()`/
  `pchatSweepStaleRooms()`、p-reversiの`prevarsiRunDailyMaintenanceIfNeeded()`/
  `prevarsiSweepStaleMatches()`参照)。

こうすることで、各サービスはp-drive上に何も書き込むことなく、p-meikieeの
`account_exists`という単純で確実な1点のAPIだけを頼りに、相手アカウントの
削除を(誰かがその会話を開くのを待たなくても)検知できます。

## 8. 既知の制約

- 1ユーザーあたりの合計保存容量(全service合算)は既定5GB(`P_DRIVE_USER_TOTAL_MAX_BYTES`)、
  1項目あたり既定20MB(`P_DRIVE_VALUE_MAX_BYTES`、いずれもp-meikiee/index.php内で定義)。
  p-driveはp-5secondのような期限付き共有ではなく恒久的なプライベートストレージ
  という性質のため、GB単位を既定にしている(MB単位だったのは技術的な制約では
  なく、単に保守的な初期値だったため)。とはいえ超大容量ファイル(動画等)の
  一時的な共有には引き続きp-5secondを使ってください。個々のサービス側でも
  自前のより厳しい上限を持つことを推奨します(1サービスの暴走が同じユーザーの
  他サービスぶんを圧迫しないようにするため。p-meikieeのuserData実装がその例です)。
- `userid`/`service`/`key`はいずれも英数字・`_`・`-`・`.`のみ、128文字以内
  (ディレクトリ・ファイル名の構成要素として使うため)。
- どのサービスが呼んでいるかを検証する仕組みはありません(合言葉さえ正しければ
  任意の`service`名を名乗れます)。これは`pusyuu_push`等、既存の他APIと同じ
  信頼モデルです。
- p-meikiee以外のサービスからの呼び出しはネットワーク越しになるため、通常の
  他サーバ間API呼び出しと同じ「失敗しても例外を投げず、ログに残す」程度の扱いで
  構いません(p-meikiee自身のアカウント削除は同一プロセス内の関数呼び出しで完結する
  ため、ネットワークの状態に影響されません。2節参照)。
