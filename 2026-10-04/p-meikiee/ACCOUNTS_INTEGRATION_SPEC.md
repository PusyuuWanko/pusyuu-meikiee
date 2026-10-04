# Pusyuu共通アカウントサービス 連携仕様書

version: 1.5 / 最終更新: 2026-09-06

> 1.5の変更点: 認証を行うAPI(`create`/`login`/`edit`/`delete`)を廃止し、アカウントの
> 作成・ログイン・編集・削除はメイキィ自身の画面(SSO)のみに一本化しました(3節)。
> 併せて、未完了の手続きが残っているアカウントには引き渡しコードを発行しなくなりました
> (パターンC の注意書き参照)。

## 1. これは何か

アカウントの**作成・編集・削除・ログイン・ログアウト**を提供する、全サービス共通の
アカウント基盤です。これに加えて、サービスごとに好きなキーで小さなデータを
保存できる**汎用データストア(userData)**も提供します(フォロー中一覧やお気に入り
ブックマークのような「機能」はaccounts自体は理解せず、この汎用ストアの上に
呼び出し側サービスが組み立てます。詳しくは4節・8節)。

これまで `pips/index.php` が単独で持っていたアカウント機能(account.jsonl の読み書き)を
ここへ移行し、`pips` を含む**全サービス共通のアカウント基盤**にしました。今後、新しく
作るサービスでも「ログイン機能」や「ユーザーごとの小さなデータ保存」が必要になったら、
自前で作り直さずここに接続してください。

- アカウント本体: `accounts/assets/posts/account.jsonl` (AES-256-GCMで暗号化。PIPS時代の
  ファイルをそのまま移行してきたものです)
- 暗号化キー: `.pusyuuHiddenFiles/pips_account_key.php` (PIPS時代から変更していません。
  Web公開ディレクトリの外側にあるため、HTTP経由では絶対に取得できません)
- ログインセッショントークン・SSO用コード・ログイン失敗回数などの付随データ:
  暗号化キーと同じ非公開層(`.pusyuuHiddenFiles/accounts_storage/`)に保存しています。
  `.htaccess` のようなWebサーバ設定には一切頼っていません(設定ミスで漏れる心配がない
  「そもそもWeb公開ディレクトリの外にある」構成です)。

## 2. 全体構成

```
accounts/
  index.php                            … サービス本体(単一ファイル)。データ層・API・共通ログイン画面をすべて含む
  assets/posts/account.jsonl           … アカウント本体(暗号化済み)
  ACCOUNTS_INTEGRATION_SPEC.md          … この仕様書
```

以前は「データ層(lib/store.php)」「内部API(api/index.php)」「共通ログイン画面
(index.php)」の3ファイルに分かれていましたが、入口(HTTPから直接届く可能性のあるファイル)
が増えるほど、それぞれで接続元チェックやCSRF対策を作り込む必要が生まれ、抜け漏れの
リスクが上がります。そのため **`index.php` 1ファイルに統合** し、動作モードを
**クエリパラメータで**切り替える形にしています。この考え方は呼び出し側サービスにも
適用していて、各サービスの入口(`pips/index.php`・`p-memo/index.php`)も同様に
1ファイルへ統合しています(以前は `client/pusyuu_accounts_client.php` という共通の
クライアントファイルを置いていましたが、廃止しました。詳しくは3節)。

- `?api=<action>` … 他サービスのサーバから叩く内部API。JSON応答。
  **暗号化キーから導出した合言葉(`api_secret`)が無いと問答無用で403を返します。**
  以前は `REMOTE_ADDR` がループバック(127.0.0.1)かどうかも見ていましたが、Docker上で
  動かす構成だとコンテナ間・ホスト⇄コンテナ間の通信がNAT経由になり、内部からの正当な
  呼び出しでも `REMOTE_ADDR` がブリッジのゲートウェイIPやLAN側のIPに化けてしまい信用
  できないため廃止し、合言葉だけを認証の根拠にしています。
- `?account=<form>` … 人間がブラウザで触る画面(作成/編集/削除/ログイン)。
  通常のセッション+CSRFトークンで保護します。

## 3. 連携方法(SSOのみ)

**アカウントの作成・ログイン・編集・削除の画面は、このサービスにしかありません。
呼び出し側サービスは自前のフォームを一切持ちません。**

かつては「自分のサイトにフォームを置き、その送信先から `?api=create`/`login`/`edit`/`delete`
をサーバ間通信で呼ぶ」という方式(旧パターンA)もありましたが、**2026-09-06にこれらのAPIごと
廃止しました**。理由は2つです。

- 全サービスがSSO(下記パターンB/C)へ移行済みで、実際の呼び出し元が1つも残っていなかった。
- 認証を画面の外でも行えることが、画面側の強制フロー(リカバリコードの発行、リカバリコード
  ログイン後のパスワード再設定)を迂回する抜け道になっていた。`create` は発行義務の付かない
  アカウントを作れてしまい、`login` は義務が残ったまま他サービスへ入れてしまい、`edit` は
  再設定を強制している最中に画面側の入力制限を迂回できてしまっていた。

新しいサービスを繋ぐときも、この4つを復活させないでください。必要なのは下の下準備と、
パターンB(またはC)だけです。

### 3.0. 共通の下準備

1. `pusyuuAccountsApi()` とその周辺の関数(`Logout`/`Session`/`Me`/`Profile`/`data_*` 系・
   `exchange_code` など、自分のサービスが実際に使うものだけで構いません)を、
   **自分のサービスの入口ファイル(基本的に `index.php` 1つ)へ直接書き込む**。
   別ファイルに切り出してrequireする形は取りません(サービスの入口を1つにまとめておくと、
   accounts側のディレクトリ構成が将来変わっても他サービスが影響を受けず、かつ「入口が
   増えるほど抜け漏れが増える」という2節の理由がそのまま各サービスにも当てはまるためです)。
   実装例は `pips/index.php`・`p-memo/index.php` の該当セクションをそのままコピーしてください。
2. `PUSYUU_ACCOUNTS_HOST` 定数が自分の環境の accounts のバーチャルホスト名になっているか
   確認する(デフォルトは `accounts.pusyuuwanko.com`)。`PUSYUU_ACCOUNTS_BASE_URL` は
   ループバックIP(`127.0.0.1`)宛の接続先で、通常は変更不要です。
3. `?api=selftest` (直接ブラウザからは403になるので、サーバ内から合言葉付きで)にアクセスし、
   鍵ファイル・account.jsonl・非公開ストレージがすべて見えているか確認する。
4. ログイン・作成・編集・削除へのリンクは、すべて `?account=login` / `create` / `edit` /
   `delete`(いずれも `return_to` 付き)へ向ける。トークンはパターンBの `exchange_code` で
   受け取り、**自分のサイト自身の `$_SESSION`** に保存する(例: `$_SESSION['accounts_token']`)。
   以降のログイン状態確認は `pusyuuAccountsSession($token)` または `pusyuuAccountsMe($token)`。
5. ログアウト時は `pusyuuAccountsLogout($token)` を呼んでからセッションを畳み、その後
   `./?account=logout&return_to=<戻り先URL>` へリダイレクトする(3.1節参照)。

**このトークンは自分のサイトの `$_SESSION` にだけ保存してください。Cookieをサービス間で
共有する必要はありません**(サブドメインをまたいだ `session.cookie_domain` の変更等は不要です)。

### 3.1. ログアウト時は必ずaccounts自身のログイン状態も一緒に終了させる

無音SSO(パターンC)は「accounts自身のログイン状態(このページ自身のセッション
クッキー)」だけを見て自動ログインを判断します。呼び出し側が自分のサイトの
トークン・セッションだけを畳んでも、accounts自身のログイン状態が生きたままだと、
次にページを開いた瞬間の無音SSOチェックが「まだログイン済み」と判断し、
自動的にまた新しいトークンを発行してログイン状態を復活させてしまいます
(ユーザーから見ると「ログアウトしたのに勝手にログインし直される」ように見えます)。

これを避けるため、ログアウト処理の最後に**必ず**ブラウザを
`https://accounts.example/?account=logout&return_to=<戻り先URL>` へ一瞬経由させてください
(`return_to` は `ALLOWED_RETURN_HOSTS` に登録済みのホストである必要があります)。
このエンドポイントはaccounts自身のログイン状態を終了させたあと、即座に`return_to`へ
リダイレクトで返します。ログアウトは実行してもログイン状態が消えるだけで破壊的な
副作用が無いため、login/create/edit/deleteと違いCSRFトークン無しのGETで受け付けます。
実装例は `pips/index.php`・`p-memo/index.php` の `handleAccountLogout()`/ログアウト処理を
参照してください。

### パターンB: 共通ログイン画面へ誘導する(自前フォームを作りたくない場合)

自前のログインフォームを持たず、ユーザーを `accounts/index.php` の共通ログイン画面へ
リンクさせるだけで済ませる方式です。OAuthの認可コードフローに近い考え方です。

1. `accounts/index.php` の `ALLOWED_RETURN_HOSTS` に、自分のサービスの戻り先ホスト名を
   追加してもらう(オープンリダイレクト対策のホワイトリストです。ここに無いホストへは
   絶対にリダイレクトしません)。
2. 自分のサービスに「ログインしたら戻ってくる先」のコールバックURLを1つ用意する
   (例: `https://your-service.example/pusyuu_callback.php`)。
3. ログインへのリンクを `pusyuuAccountsLoginUrl($accountsLoginUrl, $callbackUrl)` で組み立てる。
   例: `https://accounts.pusyuuwanko.com/?account=login&return_to=<callbackUrlをURLエンコード>`
4. ユーザーが共通ログイン画面でログインに成功すると、`<callbackUrl>?pusyuu_code=<使い切りコード>`
   へリダイレクトされます。このコードは**60秒・1回限り**有効です。
5. コールバックURL側(サーバ側PHP)で `pusyuuAccountsExchangeCode($code)` を呼び、本物の
   ログイントークンと交換します。交換で得た `token` を自分の `$_SESSION` に保存してください。
6. 以降はパターンAと同じく `pusyuuAccountsSession`/`pusyuuAccountsMe` でログイン状態を確認します。

コードをURL上に長時間残さない(ブラウザ履歴やReferer、アクセスログに長寿命トークンが
残らない)ようにするため、コード自体の寿命は極端に短くしてあります。ブラウザへ渡すのは
このコードだけで、本物のトークンは必ずサーバ間通信(`exchange_code`)で取得してください。

なお `?account=create`/`edit`/`delete` も `return_to` を渡しておくと、作成後は
ログイン画面(`return_to`付き)へ、編集・削除の成功後は呼び出し元へ自動でリダイレクト
されます(編集成功時はパターンBと同じ使い切りコード付きで戻るので、呼び出し元は
`exchange_code`で更新後の名前・ユーザー名を取り直せます)。pips・p-memoは作成・編集・
削除・ログインのすべてをこの方式(accounts自身の画面への単純なリンク)に統一しており、
自前のフォームは一切持っていません。

### パターンC: 無音SSO(ログイン済みかどうかをフォーム無しで確認する)

パターンBの発展形です。`return_to`に加えて `silent=1` を付けて
`?account=login&silent=1&return_to=...` へアクセスすると、accountsはフォームを一切
表示せず、自分自身のログイン状態だけを見て即座にリダイレクトで返します。

- ログイン済みなら、パターンBと同じ使い切りコード付きで`return_to`へリダイレクト
  (`?pusyuu_code=...`)。
- 未ログインなら、`pusyuu_silent=0` を付けて`return_to`へリダイレクト。呼び出し側は
  これを見て「フォームを見せずにログイン済みではないと分かった」と判断し、通常の
  未ログイン画面を表示してください。

**注意: 「メイキィ側ではログイン済みなのに `pusyuu_silent=0` が返る」ことがあります。**
そのアカウントに未完了の手続き(リカバリコードの発行、リカバリコードでログインした後の
パスワード再設定)が残っている間、引き渡しコードは発行されません。呼び出し側は特別な
対応をする必要はなく、通常どおり未ログインとして扱ってください(ユーザーがメイキィへ
ログインしに行った時点で、その手続きの画面へ誘導されます)。この判定は
`pmeikieeIssueHandoffCode()` 1箇所だけで行われており、パターンBのログイン成功時も
同じように「コードを発行できない」という結果になります。

呼び出し側は、未ログイン状態でページを開いた瞬間にこの往復を1回だけ行い
(`$_SESSION`に確認済みフラグを持たせて無限リダイレクトを防いでください)、
ログイン済みなら`exchange_code`でトークンを受け取って自動的にログイン状態にします。
これにより「accounts.pusyuuwanko.comに一度ログインしておけば、他のどのサービスを
開いても自動的にログイン済みになる」というGoogleのアカウント連携に近い体験になります。
実装例は `pips/index.php`・`p-memo/index.php` の「無音SSOチェック」セクションを
参照してください。

## 4. API リファレンス (`accounts/index.php?api=<action>`)

操作(action)は**クエリパラメータ** `?api=` で指定します。その他のパラメータ
(username/password/tokenなど)は `POST` (フォームエンコード or JSON body) で渡します。
レスポンスは共通して `{"ok": true/false, ...}` のJSON。`ok:false` のときは `error`
(機械可読なコード) と `message` (人間向けの日本語メッセージ) が入ります。

例: `POST https://accounts.example/index.php?api=session` (body: `token=...&api_secret=...`)

アカウントの作成・ログイン・編集・削除を行うAPIはありません(3節参照)。ここにあるのは
「すでにログインした後」の読み書きと、管理パネル専用のものだけです。

| ?api=  | 主なパラメータ(POST) | 認証 | 説明 |
|---|---|---|---|
| `selftest` | なし | 不要 | 鍵ファイル・account.jsonl・非公開ストレージの疎通確認 |
| `logout` | token | token | トークンを失効させる |
| `session` | token | token | ログイン状態の確認。`user`(userid/username/name/bio/avatar_url) or `null` |
| `me` | token | token | 自分の詳細情報。`email`と`userData`(保存した全サービスぶんの値)を含む |
| `profile` | username | 不要 | 公開プロフィール(userid/username/name/bio/avatar_url) |
| `account_exists` | storage_id | 不要 | 指定したuserid(storage_id)が実在するアカウントかどうかだけを返す(`exists`のbool)。氏名等は一切含まない軽量な専用API。p-chat/p-reversiのように複数アカウントが絡む自前データを持つサービスが「相手のアカウントがまだ存在するか」を直接確認する用途 |
| `data_get` | token, service, key | token | 自分自身の `userData[service][key]` を取得 |
| `data_set` | token, service, key, value | token | 自分自身の `userData[service][key]` に任意の値を保存 |
| `data_delete` | token, service, key | token | 自分自身の `userData[service][key]` を丸ごと削除(無くてもエラーにしない) |
| `data_list_add` | token, service, key, value | token | `userData[service][key]` を配列として扱い、value を重複なく追加 |
| `data_list_remove` | token, service, key, value | token | `userData[service][key]` の配列から value を除去 |
| `data_list_count` | username, service, key | 不要 | 指定ユーザーの `userData[service][key]` 配列の件数(公開) |
| `data_reverse_count` | value, service, key | 不要 | `userData[service][key]` に value を含むアカウントの件数 |
| `data_reverse_list` | value, service, key | 不要 | `data_reverse_count`と同条件で、件数の代わりに該当アカウントの公開プロフィール一覧を返す |
| `resolve_ids` | ids(useridハッシュのJSON配列文字列。最大`USER_DATA_LIST_MAX_ITEMS`件) | 不要 | 複数のuseridハッシュを公開プロフィールへ一括解決(followingのように生idを知らないハッシュ配列を表示用に変換したい場合に使う) |
| `exchange_code` | code | なし(コードが鍵) | パターンBの使い切りコードをトークンに交換 |

`user` オブジェクトの形:

```jsonc
// session / profile / exchange_code
// bio・avatar_urlは(email等と違い)公開プロフィール情報として扱うため常に含まれます。
// avatar_urlは未設定なら既定のアバター画像のURLになります。
{ "userid": "<sha256ハッシュ>", "username": "...", "name": "...", "bio": "...", "avatar_url": "https://..." }

// me (本人にのみ返す詳細。userDataは自分が保存した内容がそのまま丸ごと返ります)
{ "userid": "...", "username": "...", "name": "...", "bio": "...", "avatar_url": "https://...", "email": "...",
  "userData": { "pips": { "likes": ["..."], "following": ["<sha256ハッシュ>", ...] } } }
```

**`id` の生値は一切外に出しません。** すべて `sha256(id)` のハッシュとして渡します。
これは `$_SESSION["userid"]` がPIPS時代からずっとハッシュ値だったことと一貫性を
保つためです。フォロー機能で「相手の生idを漏らさない」という性質は、accounts側が
何か特別なハッシュ化処理をしているからではなく、**呼び出し側(pipsなど)がそもそも
他人の生idを一度も受け取らない**(profileで受け取れるのは常にハッシュ済みのuserid)
ことによって自然に成り立っています。詳しくは4.1節。

### 4.1. 汎用データストア(userData)の使い方

`follow`/`unfollow`/`like`/`unlike` のような「意味のある機能」はaccounts側には
一切ありません。accountsが提供するのは `data_get`/`data_set`/`data_list_add`/
`data_list_remove`/`data_list_count`/`data_reverse_count` という汎用の読み書き
だけで、`service`(自分のサービス名。例: `pips`)と `key`(用途名。例: `likes`,
`following`)を呼び出し側が指定します。accounts側はその中身が何を意味するかを
一切関知しません。

例: pipsの「フォロー」機能は、この汎用APIの組み合わせだけで実装されています
(pips/index.php の `handleFollowUser`/`handleUnfollowUser`/`pipsIsFollowing`/
`pipsFollowerCount`/`pipsFollowingCount` を参照)。

1. フォローするときは、まず `profile(username)` で相手の `userid`(ハッシュ)を取得する。
2. 自分自身でないこと(`userid` を比較)、対象ユーザーが実在すること(`profile`が
   404を返さないこと)を、呼び出し側(pips)が確認する。
3. `data_list_add(token, 'pips', 'following', 相手のuserid)` で自分のフォロー中
   一覧に追加する。
4. 「自分がフォロー中か」は `data_get(token, 'pips', 'following')` で自分の一覧を
   取得し、相手のuseridが含まれるかを呼び出し側でチェックする。
5. 「フォロワー数」は `data_reverse_count(自分のuserid, 'pips', 'following')` で、
   自分のuseridを一覧に含んでいる他アカウントが何件あるかを数える。
6. 「フォロー数」は `data_list_count(username, 'pips', 'following')` で、その
   ユーザー自身の一覧の件数を数える。

following配列に**相手の生idではなくuseridハッシュ**を入れているのは、pips自身が
他ユーザーの生idを一度も受け取らないためです。accounts側はvalueが何であるかを
関知しないので、このハッシュ化は完全にpips側の設計です。

**上限に関する注意**: `data_set`の値は1件1MBまで、配列(`data_list_add`)は
1キーあたり1000件までに制限されています(2026-07-29: 4KB/200件から変更)。
まとまったデータ(本文・画像など)を保存したいサービスは、p-memoのメモ機能の
ように自分自身のストレージを持ってください。account.jsonl は1件書き換える
だけでも全アカウントぶん再暗号化する作りのため、上限を1MBに広げても
「多くのユーザーがこの上限近くまで使うと全サービスのログイン・アカウント
操作が重くなる」というリスク自体は変わりません。あくまで小さな付随データ
専用の場所である前提は変わっていないので注意してください。

## 5. 認証まわりの仕組み

- パスワードは `password_hash`/`password_verify` (bcrypt)。
- ログイントークンは `bin2hex(random_bytes(32))`。保存する際は `sha256` した値だけを
  非公開ストレージに置き、生トークンはどこにも保存しません(発行時に呼び出し元へ
  返すのみ)。
- トークンの有効期限は30日、アクセスのたびにスライド式で延長されます。
- ログイン失敗はユーザー名ごとに15分間で10回まで。超えると429を返します
  (ブルートフォース対策。ユーザー名の存在有無で応答時間が変わらないようダミーハッシュ
  との比較も行っています)。
- SSO用の使い切りコード(パターンB)は60秒・1回限りです。

### 認証にIPアドレスを使わない理由

`accounts/index.php` はかつて `?api=` モードのとき `REMOTE_ADDR` がループバック
(127.0.0.1/::1)かどうかでアクセス制御していましたが、この方式は廃止しました。
Docker上で動かす構成だと、コンテナ間・ホスト⇄コンテナ間の通信がNAT経由になり、
内部からの正当な呼び出しでも `REMOTE_ADDR` がDockerブリッジのゲートウェイIPや
LAN側のIPに化けてしまい、`127.0.0.1`という一つの値を信用の根拠にできないためです。
現在は暗号化キーから導出した合言葉(`api_secret`)の一致だけを認証の根拠にしています。
自分のサービス側の `pusyuuAccountsApiSecret()`(3節参照)が同じ暗号化キーファイルを
自動で読んで導出するため、合言葉自体を手動で配布・設定する必要はありません。

## 6. 新しいサービスを連携させる手順(まとめ)

1. `accounts/index.php` の `ALLOWED_RETURN_HOSTS` に自分のホスト名を追加する
   (SSOの戻り先ホワイトリスト。ここに無いホストへは絶対にリダイレクトしません)。
2. `pusyuuAccountsApi()` 等を自分のサービスの入口ファイルへ直接書き込む(3節参照。
   `pips/index.php`・`p-memo/index.php` からコピーするのが早いです)。
3. `PUSYUU_ACCOUNTS_HOST` が正しいバーチャルホスト名を指しているか確認し、
   `?api=selftest` で疎通確認。
4. パターンB(共通ログイン画面へリダイレクト)を実装する。ログイン済みかどうかを
   フォーム無しで確かめたいならパターンC(無音SSO)も併せて実装する。自前の
   ログイン/作成/編集フォームは作らない(3節参照)。
5. ログイン中フラグ・ユーザー名・表示名など、自分のサイトの `$_SESSION` にどう保存するかは
   自由ですが、**ログイントークン自体は他サイトのCookie/Sessionと共有しない**でください。
6. 動作確認: 作成 → ログイン → (必要なら)編集・削除・フォロー・お気に入り の一通りを
   実際に試す。

## 7. 既存サービスへの影響

- **pips**: アカウント関連コード(暗号化・account.jsonl直接読み書き)をすべて削除し、
  `pusyuuAccountsApi()` 等の関数を `pips/index.php` 自身に直接書き込んでいます。
  フォロー・お気に入りの「意味」(自分自身をフォローできない、対象ユーザーが実在するか
  等の検証)は accounts 側からは無くなったため、pips/index.php 内に`pipsIsFollowing`/
  `pipsFollowerCount`/`pipsFollowingCount`/`pipsMyFollowingList`として実装し直し、
  4.1節の汎用データストアの組み合わせで実現しています。
  **作成・編集・削除・ログインはすべてaccounts自身の画面へのリンクに統一**しており
  (3節パターンB/C)、pips自身は自前フォームを持ちません。ログアウトだけはパスワード
  不要で単純なため、引き続きpips側で処理します。ログイン状態は無音SSO(パターンC)で
  自動的に復元され、未ログイン時は初回アクセス時に1往復だけaccountsへ確認しに行きます。
- **p-memo**: 旧・読み取り専用の中継API(`main/pusyuusystem/apis/pusyuu_memo`、旧称 `pusyuu_account`)経由から、
  この `accounts` サービスへ直接つなぐ形に切り替え済みです。p-memo独自の
  `pmemoCurrentUser()`/`pmemoLogout()` 等の関数も含め、以前は
  `assets/scripts/php_scripts/pusyuu_account_client.php` という別ファイルに分かれて
  いましたが、`p-memo/index.php` へ統合しました。同様に、エディタ本体の保存/ダウンロード/
  文字コード変換等を担っていた `save.php` も `p-memo/index.php` のPOST処理
  (`action=`パラメータで振り分け)へ統合し、`save.php` 自体は削除しています。
  **ログインもaccountsへのリンクに統一**しており、p-memo自身はログインフォームを
  持ちません(作成へのリンクも同様にaccountsへ向けています)。
  2026-08時点では、メモ本体の保存/一覧/取得/削除も `main/pusyuusystem/apis/pusyuu_memo` への
  中継をやめ、`p-memo/index.php` 自身が `storage/memos/` を直接管理する形へ移設しました
  (`pmemoSaveMemo()`/`pmemoGetMemo()`/`pmemoListMemos()`/`pmemoDeleteMemo()`)。中継専用の
  トークン(`$_SESSION['pusyuu_token']`、旧`login_via_accounts_token`アクション)は
  不要になり、`pmemoCurrentUser()` が返す accounts の `userid` を直接メモファイルの
  キーとして使っています(旧中継APIも同じ値でファイル名を決めていたため、既存データは
  そのまま引き継げました)。暗号化キー・書式(AES-256-GCM)は変更していません。

## 8. データモデル (account.jsonl の1行)

```jsonc
{
  "id": "…",              // 生成時のランダムid(外部には絶対に生値を出さない)
  "name": "…",
  "email": "…",
  "username": "…",
  "password": "$2y$...",   // bcryptハッシュ
  "bio": "…",              // 自己紹介(任意、BIO_MAX_LENGTH文字まで。無ければキー自体が無いこともある)
  "avatar": "…",           // プロフィール画像のファイル名(sha256(id).jpg)。未設定ならキーが無いか空文字
  "created_at": 1234567890, // 登録日時(unixタイムスタンプ)。2026-08-31以降に作られたアカウントにのみ
                             // 存在する。導入前の既存アカウントにはこのキー自体が無い(後から現在時刻を
                             // 詰める後方互換処理はしていない。8.2節参照)
  "last_login": 1234567890, // 最終ログイン日時(unixタイムスタンプ)。一度もログインが無ければキー自体が無い
  "userData": {            // サービスごとの名前空間を持つ汎用の入れ物(4.1節参照)
    "pips": {
      "likes": ["…"],          // pipsのお気に入り(投稿の共有URL)
      "following": ["…"]       // pipsのフォロー中一覧(相手のuseridハッシュの配列)
    }
    // 他のサービスも "p-memo": { ... } のように自分の名前空間を追加できます
  }
}
```

`likes`/`following` がアカウントレコードの**トップレベル**に直接入っていたのは
旧仕様です。全アカウントぶん `userData.pips.*` への移行は完了済みで、
`accountsNormalizeUser()` はもう旧形式を意識しない汎用の初期化処理だけになっています。

`userData` 以外の書式(id/name/email/username/passwordの意味)は変更しないでください。
変更すると既存の account.jsonl (暗号化済み) が読めなくなります。

### 8.1 プロフィール画像(アバター)

画像データそのもの(JPEGバイナリ)は `account.jsonl` には一切持たせません。
`p_drive_storage/<storage_id>/p-meikiee/avatar`(p-drive自身の項目として
`pDriveEnginePut()`/`pDriveEngineGet()`で読み書き。p-drive自身の暗号鍵で
暗号化)に1ユーザー1項目として保存します。ディスク上に平文の画像ファイルは
一切残りません。

`storage_id`は`sha256(id)`(=4節の`userid`と同一の値)で、アカウント作成時に
1回だけ計算されaccount.jsonlの`storage_id`フィールドへ永続化される不変id
です(8節参照)。p-drive・アバターに限らず、そのアカウントに紐づく「ファイルの
置き場所」は必ずこの1つのidだけで表現し、生のid(account.jsonlの`id`
フィールド)をディレクトリ・ファイル名に使うことは原則禁止しています
(`pDriveCanonicalUserId()`のコメント参照)。**アカウント削除時に
`p_drive_storage/<storage_id>/`を丸ごと削除するだけで、そのユーザーの
p-drive上の持ち物(アバター含む、各サービスがservice名で持っている分すべて)が
一括で消える**という前提を成り立たせるためです(以前はアバターを含め、
サービスごとに保存先idの計算がバラバラで、削除時に一部だけ取り残される
不具合が実際にありました)。

`account.jsonl`側が持つのは `avatar_updated`(保存時刻。未設定ならアバター
未設定として既定画像にフォールバックする軽いマーカーだけです。画像データ本体を
持たないため、**誰か1人がアバターを変更しても`account.jsonl`自体(全アカウント
ぶんまとめて1個の暗号文)は一切書き換わらず**、他の全ユーザーのログイン・
アカウント操作が重くなることもありません(4.1節の「まとまったデータは自分自身の
ストレージを持つ」方針と同じ理由で、意図的に分離しています)。

配信は `https://accounts.example/index.php?avatar=<storage_id>&v=<avatar_updated>`
という早期GET分岐(`?api=`と同様、UIモードより前段で処理される)が、
`account.jsonl`を経由せずp-drive上の該当項目を直接開いて復号し、その場で
JPEGとして返す形です(これを公開しても、既に他サービスへ渡している`userid`
と同じ情報量にしかなりません)。

アップロード時にGDで正方形へセンタークロップ・`AVATAR_SIZE`へ縮小・JPEGへ
再エンコードしてから保存するため、`storage_id`はアカウントごとに固定で、
再アップロードは単純な上書きで完結します。アカウント削除時はp-drive上の
avatar項目も(`p_drive_storage/<storage_id>/`ごと)合わせて削除されるため、
画像だけが取り残される心配もありません。

配信(`?avatar=`)はp-drive上のavatar項目だけを見ます。旧保存場所
(account.jsonlの鍵で別ツリーに保存していた頃・ディスク上の生JPEGファイルで
持っていた頃の名残)へは、配信のたびにフォールバックして探しには行きません
(アバターは公開プロフィール情報であり非公開の機密データではないため、
新旧2つの保存場所を都度またぐ複雑な移行処理を組むほどの価値は無いと判断した
ためです)。旧ファイルが残っていた場合の掃除は、次回のアバター再アップロード時
(`pmeikieeAvatarSave()`)またはアカウント削除時(`pmeikieeCleanupExternalData()`)
という既存の掃除経路にまかせます。

### 8.2 放置アカウントの自動削除

`pmeikieeSweepStaleAccounts()`(`index.php`)が、`pmeikieeRunDailyMaintenanceIfNeeded()`
経由で1日1回自動的に、次の2種類のメイキィを削除します(削除処理自体は
`pmeikieeAdminDelete()`をそのまま使うため、削除前に付随データ(p-drive上の
アバター・userData)が全て消せることを確認してから`account.jsonl`本体を消す、
という通常のアカウント削除と同じフェイルセーフを経ます)。

1. **作成されたのに一度も使われていないアカウント**: `created_at`はあるが
   `last_login`が一度も記録されていない状態のまま`NEW_ACCOUNT_INACTIVITY_DAYS`
   (既定7日)を過ぎたもの。
2. **使われていたが長期間放置されたアカウント**: `last_login`が記録されており、
   その値が`DORMANT_ACCOUNT_DAYS`(既定365日)より古いもの。

`created_at`・`last_login`のどちらも記録が無いアカウント(導入前の既存アカウント
等、作成日時が不明なもの)はどちらの条件にも当てはまらないため、自動削除の対象
には**なりません**。誤って移行前のアカウントを巻き込むリスクを避けるためで、
これらは引き続き`?api=admin_list_accounts`の`never_seen`一覧から管理者が
個別に判断して`?api=admin_delete_account`で削除する運用のままです。

削除前に対象を`stale_account_sweep_log.jsonl`(Web非公開ディレクトリ配下)へ
記録します。`?api=admin_sweep_stale_accounts`で今すぐ手動実行、
`?api=admin_stale_account_sweep_log`でログの閲覧ができます
(`pmeikieeSweepOrphanedStorage()`/`admin_sweep_orphaned_storage`と同じ構成)。
