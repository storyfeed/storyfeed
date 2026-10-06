# Entity links in the payload

Party nodes use the existing entity shape. A party with an external home declared through `Party::make(..., url: 'https://example.com')` carries that home in the existing entity `url` field, in any role. An absent or removed URL is `null`; no additional link field is introduced.

The reserved party data slot `$url` reaches the snapshot through `toFeed()`. `Party::feedMedia()` resolves it from snapshot data; `NodePresenter` emits the same `url` field it emits for other entities. App data named `url` is not interpreted as a link. Images still use `$media` and the entity `media` field. A plain external URL is not an image and does not create `media.url`.

Saved party changes refresh existing snapshots, including labels and external homes. Reading a feed or an Activity Streams document does not create, update, or query a Party row. Retiring a party retains its historical snapshot, including its link.
