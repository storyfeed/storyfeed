# Parties

A party is a named participant without a host application record. Strings in activity roles resolve parties by their slugged keys.

## Linking a party

Declare a party’s external home, then use its name as a string in an activity:

```php
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Party;

Party::make('TalkingFeed', url: 'https://talkingfeed.app');
Storyfeed::activity('launch')->object('TalkingFeed')->publish();
```

Its entity node carries the external home in `url`, and Activity Streams serialization carries AS2 `url` in every party role, including actor. With no URL the entity remains unlinked. A URL is a home outside the host app, not a generated host route.

Renaming with the same key preserves the link:

```php
Party::make('TalkingFeed app', key: 'talkingfeed');
```

Pass `url: null` explicitly to remove the link. Passing another URL updates existing activities through the snapshot. Replacing application data preserves the link unless that data explicitly replaces the reserved `$url` slot. `$media` pictures can accompany a URL. Reads use the snapshot and never query or write the party.
