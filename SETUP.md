# セットアップ

## 0. 先に読んでほしいこと — 公開してはいけない物

このソフトウェアは、**動かした瞬間から個人情報を持つ**タイプのものです。
フォークして自分の環境で使う場合、次の線引きを守ってください。リポジトリの
`.gitignore` は既にこの通りに書いてありますが、**なぜそうなのか**を知らないまま
編集すると簡単に破れます。

### ソフトウェア(公開してよい)

- `p-meikiee/index.php` — 本体
- `p-meikiee/assets/styles/` — CSS
- `p-drive/index.php` — ファイル管理画面
- `main/pusyuusystem/scripts/php_scripts/meikiee_client.php` — クライアント
- `docs/` — 仕様書
- `tools/` — セットアップ用スクリプト

### 個人情報・秘密(絶対に公開しない)

| 物 | 場所 | なぜ危険か |
|---|---|---|
| アカウント台帳 | `p-meikiee/assets/posts/account.jsonl` | 氏名・メール・パスワードハッシュ・リカバリコードハッシュ。暗号化されているが、鍵と一緒に漏れれば終わり |
| 台帳のバックアップ | `*.bak` | 同上。移行作業で作られがち。**見落としやすい最大の穴** |
| 利用者のストレージ | `p-meikiee/p_drive_storage/` | アバター・各サービスのデータ・アップロードしたファイル |
| 暗号鍵 | `.pusyuuHiddenFiles/*_key.php` | これが漏れると上の暗号化が全部無意味になる |
| 秘密鍵・管理者資格情報 | `.pusyuuHiddenFiles/private_key_pem.php` ほか | そのまま乗っ取りに使える |
| セッション | `accounts_storage/sessions.jsonl` | 有効なログイントークンのハッシュ |
| 各種ログ | `*_trace.jsonl` / `*.log` | IPアドレス・ユーザー名・ログイン時刻 |

**特に注意:** 台帳の `.bak` は、移行やリファクタの途中で手作業で作られることが多く、
`account.jsonl` だけを `.gitignore` に書いていると素通りします。本リポジトリの
`.gitignore` が `*.bak` をまとめて弾いているのはこのためです。

---

## 1. 置く

リポジトリの `p-meikiee/`・`p-drive/`・`main/` を、**この階層関係のまま**公開
ディレクトリへ置きます。サービスごとにバーチャルホストを1つ与えるのが想定された
形です。

```
/var/www/html/
├── p-meikiee/   → https://meikee.example.com/
├── p-drive/     → https://drive.example.com/
└── main/pusyuusystem/scripts/php_scripts/meikiee_client.php
```

**`main/` の位置を動かさないでください。** `p-drive/index.php` は
`../main/pusyuusystem/scripts/php_scripts/meikiee_client.php` という相対パスで
クライアントを読みます。見つからないと、致命的エラーにはなりませんが
アカウント機能だけが畳まれ、マイファイル画面が空になります。

`p-drive/` は任意です。ファイル管理画面が要らないなら置かなくて構いません
(保存の実体は `p-meikiee/` の中なので、p-meikiee 単体で完全に動きます)。

サブディレクトリ配置でも動きますが、Cookie のパスが `/` 固定なので、同じドメインに
他のアプリを同居させる場合は衝突に注意してください。

## 2. 鍵の置き場を作る

**公開ディレクトリの外側**にディレクトリを1つ用意します。名前は
`.pusyuuHiddenFiles` である必要があります(本体がこの名前で探すため)。

```
/var/www/
├── .pusyuuHiddenFiles/     ← ここ。Webからは絶対に見えない場所
└── html/
    └── p-meikiee/          ← 公開ディレクトリ
        └── index.php
```

本体は `p-meikiee/` から親へ向かって**最大8階層**遡り、
`.pusyuuHiddenFiles/pips_account_key.php` を探します。見つかったディレクトリが
そのまま「非公開の保存層」になり、セッションや監査ログもそこへ入ります。

念のため、そのディレクトリに配信拒否の `.htaccess` も置いてください。

```apache
<IfModule mod_authz_core.c>
  Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
  Order allow,deny
  Deny from all
</IfModule>
```

> **なぜ `pips_` という名前なのか**
> この仕組みは PIPS というサービスから育ちました。名前として据わりは悪いのですが、
> 改名すると既存の台帳を積み上げてきた環境が鍵を見失うため、互換性のために
> そのままにしています。新規に立てる場合もこの名前で作ってください。

## 3. 鍵を作る

**これは自動生成されません。** 必ずこの手順を踏んでください。

```
php tools/generate_key.php /var/www/.pusyuuHiddenFiles
```

32バイトのランダム鍵が `pips_account_key.php` として作られます。

> **なぜ自動生成にしないのか**
> 「無ければ作る」にすると、鍵を見失ったサーバーが黙って新しい鍵を作り、既存の
> `account.jsonl` を復号できないまま「アカウント0件」として平常運転を始めます。
> 利用者から見れば全員のアカウントが消えたのと同じで、しかもエラーは出ません。
> だから本番の起動経路では鍵を作らせず、人間が一度だけ叩くこの入口に限定しています。
>
> 一方、ストレージ側の鍵(`p_drive_storage_key.php`)は自動生成されます。
> こちらは失っても「ファイルが読めなくなる」だけで、台帳ほど致命的ではないためです。

### バックアップしてください

この鍵を失うと、**全アカウントが復号できなくなります。復旧手段はありません。**
台帳(`account.jsonl`)と鍵は、必ずセットで、しかし**別の場所に**保管してください。

## 4. パーミッション

PHP を動かすユーザーに、次の書き込み権限が要ります。

- `.pusyuuHiddenFiles/` — セッション・ログ・ロックの作成
- `p-meikiee/assets/posts/` — 台帳の作成
- `p-meikiee/p_drive_storage/` — 利用者データの作成

## 5. 動作確認

ブラウザで `?account=create` を開き、アカウントを1つ作ります。

設置そのものを確かめたい場合は `?api=selftest` があります。鍵が読めているか、
非公開ディレクトリがどこと判定されたか、そこへ書き込めるか、台帳が何件あるかを
返します。ストレージ側だけを見る `?api=p_drive_selftest` もあります。
どちらも共有鍵(`api_secret`)を知るサーバからしか叩けません。詳細は
[docs/ACCOUNTS_INTEGRATION_SPEC.md](docs/ACCOUNTS_INTEGRATION_SPEC.md) を参照してください。

---

## 6. 他サービスから使う

`main/pusyuusystem/scripts/php_scripts/meikiee_client.php` を読み込む前に、接続先を
`define()` で上書きします。既定値は元の稼働環境のものなので、**必ず自分の環境の値に
変えてください**。`p-drive/index.php` の冒頭が、実際にそうしている見本になります。

```php
define('PUSYUU_ACCOUNTS_BASE_URL', 'https://127.0.0.1/index.php');
define('PUSYUU_ACCOUNTS_HOST',     'meikee.example.com');
require_once __DIR__ . '/meikiee_client.php';
```

`BASE_URL` をループバック(`127.0.0.1`)にして `HOST` でバーチャルホストを指定するのは、
名前ベースのバーチャルホストを使っている環境で、DNS やルーターの都合に左右されずに
サーバ間通信を成立させるためです。

また、SSO の戻り先ホストは本体側の許可リストに登録が必要です。
`p-meikiee/index.php` の `ALLOWED_RETURN_HOSTS` を自分のサービスのホスト名に
書き換えてください(既定値は元の稼働環境のものです)。これはオープンリダイレクト
対策なので、ワイルドカードにはしないでください。

連携の全体像・API一覧・データモデルは
[docs/ACCOUNTS_INTEGRATION_SPEC.md](docs/ACCOUNTS_INTEGRATION_SPEC.md) にあります。
