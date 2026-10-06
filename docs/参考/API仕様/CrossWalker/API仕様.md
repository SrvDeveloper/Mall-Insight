# CrossWalker 外部APIリファレンス

CrossWalkerに登録された品番、SKU、Amazon ASIN、TQ商品キーを外部システムから参照するJSON APIです。

## 基本情報

| 項目 | 内容 |
| --- | --- |
| ベースURL | `https://{サイトのホスト名}/api/v1` |
| データ形式 | JSON |
| 文字コード | UTF-8 |
| 認証方式 | Bearer認証 |
| 通信方式 | HTTPS（本番環境では必須） |
| APIバージョン | `v1` |

本書の例では、ベースURLを `https://example.com/api/v1`、APIキーを `${API_KEY}` と表記します。実際のサイトURLと、サイト設定画面で発行したAPIキーに置き換えてください。

品番コード、SKUコード、ASIN、TQ品番、TQカラーNo、TQサイズは文字列として扱われます。先頭のゼロを失わないよう、JSONでは文字列として指定してください。パスおよびクエリパラメータはURLエンコードしてください。

## 認証と接続元制限

すべてのエンドポイントで次の条件を満たす必要があります。

1. サイト設定で外部APIが有効になっていること
2. `Authorization` ヘッダーに有効なAPIキーを指定していること
3. 接続元IPアドレスが、サイト設定の許可IPアドレスまたはCIDRに含まれていること
4. 本番環境ではHTTPSで接続していること

```http
Authorization: Bearer ${API_KEY}
Accept: application/json
```

APIキーは発行直後に一度だけ表示されます。再発行すると、それまでのAPIキーは直ちに無効になります。

## 共通仕様

- 成功レスポンスとエラーレスポンスの `Content-Type` は `application/json` です。
- APIレスポンスには `Cache-Control: no-store` が付与されます。
- 一覧検索の `status` 条件を除き、有効状態による除外は行いません。品番に所属するSKUは、無効なSKUも含めてすべて返します。
- SKUの `status` は実効状態です。品番が無効な場合、SKU自体の設定にかかわらず `inactive` になります。
- 未設定の親ASIN、子ASINは `null`、サイズなしの `tq_size` は空文字 `""` です。
- 同一接続元IPアドレスからのリクエストは毎分120回までです。この制限は認証処理より先に適用されます。

## エンドポイント一覧

| メソッド | パス | 用途 |
| --- | --- | --- |
| `POST` | `/items/lookup` | 複数の品番コードから品番と所属SKUを一括取得 |
| `GET` | `/items` | 品番一覧を検索 |
| `GET` | `/items/{item_no}` | 品番コードから品番と所属SKUを取得 |
| `GET` | `/skus/{sku_code}` | SKUコードからSKUと所属品番を取得 |
| `GET` | `/asins/{asin}` | 親ASINまたは子ASINから品番を取得 |
| `GET` | `/tq-skus` | TQ商品キーからSKUと所属品番を取得 |

## レスポンスオブジェクト

### 品番

| 項目 | 型 | 説明 |
| --- | --- | --- |
| `item_no` | string | 品番コード |
| `brand` | string | ブランド名称 |
| `category` | string | カテゴリ名称 |
| `parent_asin` | string \| null | Amazon親ASIN。未設定の場合は `null` |
| `status` | `active` \| `inactive` | 品番の有効状態 |
| `skus` | SKU[] | 所属SKU。表示順、同順位の場合は内部IDの昇順 |
| `updated_at` | string \| null | UTCのISO 8601形式の更新日時。未設定の場合は `null` |

### SKU

| 項目 | 型 | 説明 |
| --- | --- | --- |
| `sku_code` | string | SKUコード |
| `child_asin` | string \| null | Amazon子ASIN。未設定の場合は `null` |
| `status` | `active` \| `inactive` | SKUの実効状態。品番が無効な場合は `inactive` |
| `tq_item_no` | string | TQ品番 |
| `tq_color_no` | string | TQカラーNo |
| `tq_size` | string | TQサイズ。サイズなしの場合は空文字 |

品番オブジェクトの例:

```json
{
  "item_no": "fisi-05",
  "brand": "栞",
  "category": "老眼鏡",
  "parent_asin": "B09T32PVM5",
  "status": "active",
  "skus": [
    {
      "sku_code": "fisi-05-1-10",
      "child_asin": "B09EXAMPLE1",
      "status": "active",
      "tq_item_no": "FISI05",
      "tq_color_no": "01",
      "tq_size": "10"
    }
  ],
  "updated_at": "2026-09-02T00:00:00.000000Z"
}
```

## POST /items/lookup

複数の品番コードに完全一致する品番と、その品番に所属するすべてのSKUを一括取得します。有効・無効による除外とページ分割は行いません。

### リクエスト

`Content-Type: application/json` を指定します。

| パラメータ | 場所 | 必須 | 型・制約 | 説明 |
| --- | --- | :---: | --- | --- |
| `item_nos` | JSONボディ | ○ | string[]、1～100件 | 品番コード。各要素は空でない255文字以内の文字列 |

```json
{
  "item_nos": ["00001", "00002"]
}
```

### curl例

```bash
curl --request POST 'https://example.com/api/v1/items/lookup' \
  --header "Authorization: Bearer ${API_KEY}" \
  --header 'Accept: application/json' \
  --header 'Content-Type: application/json' \
  --data '{"item_nos":["00001","00002"]}'
```

### 成功レスポンス

指定値の重複は1件にまとめ、品番の内部IDの昇順で返します。未登録の品番コードは結果から除外され、全件が未登録の場合も `200 OK` と空の `data` を返します。

```json
{
  "data": [
    {
      "item_no": "00001",
      "brand": "栞",
      "category": "老眼鏡",
      "parent_asin": null,
      "status": "active",
      "skus": [
        {
          "sku_code": "00001-01-00",
          "child_asin": null,
          "status": "active",
          "tq_item_no": "00001",
          "tq_color_no": "01",
          "tq_size": ""
        }
      ],
      "updated_at": "2026-09-02T00:00:00.000000Z"
    }
  ]
}
```

## GET /items

品番と所属SKUを検索し、更新日時の降順、同じ更新日時では内部IDの降順で返します。条件を省略すると、有効な品番を10件ずつ取得します。

### クエリパラメータ

| パラメータ | 必須 | 型・制約 | 既定値 | 説明 |
| --- | :---: | --- | --- | --- |
| `keyword` |  | string、最大255文字 | なし | 品番コード、親ASIN、SKUコード、子ASIN、TQ品番、TQカラーNo、TQサイズを大文字・小文字を区別せず部分一致検索 |
| `brand_id` |  | integer、1以上 | なし | ブランドIDで絞り込み |
| `category_id` |  | integer、1以上 | なし | カテゴリIDで絞り込み |
| `status` |  | `active`、`inactive`、`all` | `active` | 品番の有効状態で絞り込み |
| `filter` |  | `no_parent_asin`、`no_child_asin` | なし | 親ASIN未設定、または子ASIN未設定の有効なSKUを持つ品番で絞り込み |
| `page` |  | integer、1以上 | `1` | ページ番号 |
| `per_page` |  | integer、1～100 | `10` | 1ページの取得件数 |

複数の検索条件を指定した場合は、すべての条件を満たす品番を返します。該当データがない場合や、存在する最終ページより後のページを指定した場合は、`200 OK` と空の `data` を返します。

### curl例

```bash
curl --get 'https://example.com/api/v1/items' \
  --header "Authorization: Bearer ${API_KEY}" \
  --header 'Accept: application/json' \
  --data-urlencode 'keyword=00001' \
  --data-urlencode 'status=all' \
  --data-urlencode 'page=1' \
  --data-urlencode 'per_page=20'
```

### 成功レスポンス

`data` に品番、`links` と `meta` にページ情報を返します。詳しくは「ページング」を参照してください。

```json
{
  "data": [
    {
      "item_no": "00001",
      "brand": "栞",
      "category": "老眼鏡",
      "parent_asin": "B09T32PVM5",
      "status": "active",
      "skus": [],
      "updated_at": "2026-09-02T00:00:00.000000Z"
    }
  ],
  "links": {
    "first": "https://example.com/api/v1/items?page=1",
    "last": "https://example.com/api/v1/items?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "links": [
      {"url": null, "label": "&laquo; Previous", "page": null, "active": false},
      {"url": "https://example.com/api/v1/items?page=1", "label": "1", "page": 1, "active": true},
      {"url": null, "label": "Next &raquo;", "page": null, "active": false}
    ],
    "path": "https://example.com/api/v1/items",
    "per_page": 20,
    "to": 1,
    "total": 1
  }
}
```

## GET /items/{item_no}

品番コードに完全一致する1件の品番と、所属するすべてのSKUを取得します。

### パスパラメータ

| パラメータ | 必須 | 型 | 説明 |
| --- | :---: | --- | --- |
| `item_no` | ○ | string | 取得する品番コード |

### curl例

```bash
curl 'https://example.com/api/v1/items/00001' \
  --header "Authorization: Bearer ${API_KEY}" \
  --header 'Accept: application/json'
```

### 成功レスポンス

```json
{
  "item": {
    "item_no": "00001",
    "brand": "栞",
    "category": "老眼鏡",
    "parent_asin": "B09T32PVM5",
    "status": "active",
    "skus": [],
    "updated_at": "2026-09-02T00:00:00.000000Z"
  }
}
```

一致する品番がない場合は `404 Not Found` です。

## GET /skus/{sku_code}

SKUコードに完全一致するSKUと、そのSKUが所属する品番を取得します。`item.skus` には、一致したSKUだけでなく所属品番のすべてのSKUが含まれます。

### パスパラメータ

| パラメータ | 必須 | 型 | 説明 |
| --- | :---: | --- | --- |
| `sku_code` | ○ | string | 取得するSKUコード |

### curl例

```bash
curl 'https://example.com/api/v1/skus/fisi-05-1-10' \
  --header "Authorization: Bearer ${API_KEY}" \
  --header 'Accept: application/json'
```

### 成功レスポンス

`sku` は `item.skus` 内の同じSKUと同じ内容です。

```json
{
  "item": {
    "item_no": "fisi-05",
    "brand": "栞",
    "category": "老眼鏡",
    "parent_asin": "B09T32PVM5",
    "status": "active",
    "skus": [
      {
        "sku_code": "fisi-05-1-10",
        "child_asin": "B09EXAMPLE1",
        "status": "active",
        "tq_item_no": "FISI05",
        "tq_color_no": "01",
        "tq_size": "10"
      }
    ],
    "updated_at": "2026-09-02T00:00:00.000000Z"
  },
  "sku": {
    "sku_code": "fisi-05-1-10",
    "child_asin": "B09EXAMPLE1",
    "status": "active",
    "tq_item_no": "FISI05",
    "tq_color_no": "01",
    "tq_size": "10"
  }
}
```

一致するSKUがない場合は `404 Not Found` です。

## GET /asins/{asin}

指定値が親ASINまたは子ASINに完全一致する品番を、内部IDの昇順で10件ずつ返します。子ASINで一致した場合も、その品番に所属するすべてのSKUが含まれます。有効・無効による除外は行いません。

### パラメータ

| パラメータ | 場所 | 必須 | 型・制約 | 既定値 | 説明 |
| --- | --- | :---: | --- | --- | --- |
| `asin` | パス | ○ | string | - | 親ASINまたは子ASIN |
| `page` | クエリ |  | integer、1以上 | `1` | ページ番号 |

`per_page` は指定できず、常に10件単位です。

### curl例

```bash
curl --get 'https://example.com/api/v1/asins/B09T32PVM5' \
  --header "Authorization: Bearer ${API_KEY}" \
  --header 'Accept: application/json' \
  --data-urlencode 'page=1'
```

### 成功レスポンス

`GET /items` と同じページング形式です。一致する品番が1件もない場合は `404 Not Found` です。一致する品番が存在し、指定ページだけが範囲外の場合は `200 OK` と空の `data` を返します。

```json
{
  "data": [
    {
      "item_no": "fisi-05",
      "brand": "栞",
      "category": "老眼鏡",
      "parent_asin": "B09T32PVM5",
      "status": "active",
      "skus": [],
      "updated_at": "2026-09-02T00:00:00.000000Z"
    }
  ],
  "links": {
    "first": "https://example.com/api/v1/asins/B09T32PVM5?page=1",
    "last": "https://example.com/api/v1/asins/B09T32PVM5?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "links": [],
    "path": "https://example.com/api/v1/asins/B09T32PVM5",
    "per_page": 10,
    "to": 1,
    "total": 1
  }
}
```

## GET /tq-skus

TQ品番、TQカラーNo、TQサイズの組み合わせに完全一致するSKUと、そのSKUが所属する品番を取得します。`item.skus` には所属品番のすべてのSKUが含まれます。

### クエリパラメータ

| パラメータ | 必須 | 型・制約 | 説明 |
| --- | :---: | --- | --- |
| `tq_item_no` | ○ | string、最大255文字 | TQ品番 |
| `tq_color_no` | ○ | string、最大255文字 | TQカラーNo |
| `tq_size` |  | string、最大255文字 | TQサイズ。省略または空文字の場合は、サイズなしの商品に一致 |

### curl例

```bash
curl --get 'https://example.com/api/v1/tq-skus' \
  --header "Authorization: Bearer ${API_KEY}" \
  --header 'Accept: application/json' \
  --data-urlencode 'tq_item_no=FISI05' \
  --data-urlencode 'tq_color_no=01' \
  --data-urlencode 'tq_size=10'
```

### 成功レスポンス

レスポンス形式は `GET /skus/{sku_code}` と同じです。必須パラメータがない場合は `400 Bad Request`、一致するSKUがない場合は `404 Not Found` です。

```json
{
  "item": {
    "item_no": "fisi-05",
    "brand": "栞",
    "category": "老眼鏡",
    "parent_asin": "B09T32PVM5",
    "status": "active",
    "skus": [
      {
        "sku_code": "fisi-05-1-10",
        "child_asin": "B09EXAMPLE1",
        "status": "active",
        "tq_item_no": "FISI05",
        "tq_color_no": "01",
        "tq_size": "10"
      }
    ],
    "updated_at": "2026-09-02T00:00:00.000000Z"
  },
  "sku": {
    "sku_code": "fisi-05-1-10",
    "child_asin": "B09EXAMPLE1",
    "status": "active",
    "tq_item_no": "FISI05",
    "tq_color_no": "01",
    "tq_size": "10"
  }
}
```

## ページング

`GET /items` と `GET /asins/{asin}` は、Laravel形式のページ情報を返します。

| 項目 | 型 | 説明 |
| --- | --- | --- |
| `data` | object[] | 現在ページの品番オブジェクト |
| `links.first` | string | 最初のページのURL |
| `links.last` | string | 最後のページのURL |
| `links.prev` | string \| null | 前ページのURL。存在しない場合は `null` |
| `links.next` | string \| null | 次ページのURL。存在しない場合は `null` |
| `meta.current_page` | integer | 現在のページ番号（1始まり） |
| `meta.from` | integer \| null | 現在ページの先頭データの通し番号。空の場合は `null` |
| `meta.to` | integer \| null | 現在ページの末尾データの通し番号。空の場合は `null` |
| `meta.last_page` | integer | 最終ページ番号。検索結果が0件でも1 |
| `meta.per_page` | integer | 1ページの取得件数 |
| `meta.total` | integer | 条件に一致する品番の総件数 |
| `meta.path` | string | クエリ文字列を含まないエンドポイントURL |
| `meta.links` | object[] | 前後および各ページへの表示用リンク |
| `meta.links[].url` | string \| null | リンク先URL。移動先がない場合は `null` |
| `meta.links[].label` | string | 表示ラベル。HTMLエンティティを含む場合あり |
| `meta.links[].page` | integer \| null | 移動先ページ番号。省略記号の要素ではキー自体を含まない |
| `meta.links[].active` | boolean | 現在ページのリンクの場合は `true` |

返却されるページURLには、`page` 以外の検索条件や `per_page` が含まれません。ページを移動するクライアントは、元の検索条件と `per_page` を再指定してください。

## エラーレスポンス

エラー時は、成功時の `item`、`sku`、`data` を含まず、次の形式で返します。内部の例外情報やスタックトレースは返しません。

```json
{
  "message": "エラーの説明"
}
```

入力検証エラー（`400 Bad Request`）では、パラメータごとのエラーも返します。`errors` 内の文言はサーバーの言語設定により異なる場合があります。

```json
{
  "message": "入力内容を確認してください。",
  "errors": {
    "per_page": [
      "The per page field must be between 1 and 100."
    ]
  }
}
```

対象データが存在しない場合の例:

```json
{
  "message": "対象データが見つかりません。"
}
```

## HTTPステータス

| ステータス | 内容 |
| --- | --- |
| `200 OK` | 取得成功 |
| `400 Bad Request` | パラメータの不足、形式不正、または入力値のエラー |
| `401 Unauthorized` | APIキーが未指定、または正しくない |
| `403 Forbidden` | 外部APIが無効、接続元IPアドレスが未許可、または本番環境でHTTPSを使用していない |
| `404 Not Found` | 対象データが存在しない |
| `429 Too Many Requests` | 同一接続元から毎分120回の利用上限を超過。再試行可能になるまでの秒数は `Retry-After` ヘッダーを参照 |
| `500 Internal Server Error` | サーバーエラー |