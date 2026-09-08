<?php

// Captured from the pre-W78 source at 59658b4; payload and event JSON are byte fixtures.
return [
    'node' => '{"kind":"activity","id":"01J00000000000000000000000","verb":"confirm","published_at":"2026-09-08T12:00:00.000000Z","headline_template":null,"headline":null,"glyph":null,"actor":{"type":"user","id":"1","label":"Operator","url":"\\/users\\/1","attributes":[],"modal":false,"component":null,"data":{"id":1,"name":"Operator"},"media":null},"object":{"type":"delivery","id":"1","label":"Delivery #W78","url":"\\/deliveries\\/1","attributes":{"data-status":null},"modal":false,"component":"Resource","data":{"id":1,"tracking_number":"W78","status":null},"media":null},"target":{"type":"customer","id":"1","label":"Destination","url":"\\/customers\\/1","attributes":[],"modal":false,"component":null,"data":{"id":1,"name":"Destination"},"media":null},"context":{"type":"customer","id":"2","label":"Workspace","url":"\\/customers\\/2","attributes":[],"modal":false,"component":null,"data":{"id":2,"name":"Workspace"},"media":null},"data":null,"thread":null,"change":null}',
    'event' => '{"id":1,"uid":"01J00000000000000000000000","verb":"confirm","actor":{"type":"user","id":1,"label":"Operator","component":null,"data":{"id":1,"name":"Operator"},"content":null,"mediaType":null,"attributedTo":null},"object":{"type":"delivery","id":1,"label":"Delivery #W78","component":"Resource","data":{"id":1,"tracking_number":"W78","status":null},"content":null,"mediaType":null,"attributedTo":null},"target":{"type":"customer","id":1,"label":"Destination","component":null,"data":{"id":1,"name":"Destination"},"content":null,"mediaType":null,"attributedTo":null},"context":{"type":"customer","id":2,"label":"Workspace","component":null,"data":{"id":2,"name":"Workspace"},"content":null,"mediaType":null,"attributedTo":null},"data":[],"published_at":"2026-09-08T12:00:00+00:00","deleted_at":null,"forceDeleted":false}',
    'hashes' => [
        'actors' => 'confirm:customer:1:2026-09-08',
        'targets' => 'user:1:confirm:2026-09-08',
        'object' => 'user:1:confirm:delivery:1:2026-09-08',
        'repeat' => 'user:1:confirm:delivery:customer:1:2026-09-08',
    ],
];
