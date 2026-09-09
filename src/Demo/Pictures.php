<?php

namespace Storyfeed\Demo;

use Storyfeed\ActivityStreams\ObjectType;

/** Tiny original illustrations embedded in snapshots: no asset publishing or network. */
final class Pictures
{
    /** @return array<string, array<string, int|string>> */
    public static function for(string $name, ObjectType $type): array
    {
        $file = match ($type) {
            ObjectType::Person => 'person-'.(hexdec(substr(hash('sha256', $name), 0, 4)) % 5),
            ObjectType::Organization => 'organization',
            ObjectType::Document => 'document',
            ObjectType::Object => 'project',
            default => 'task',
        };
        $svg = file_get_contents(__DIR__.'/../../resources/demo/'.$file.'.svg');
        $slot = in_array($type, [ObjectType::Person, ObjectType::Organization], true) ? 'icon' : 'preview';

        return [$slot => [
            'src' => 'data:image/svg+xml;base64,'.base64_encode($svg),
            'mediaType' => 'image/svg+xml',
            'width' => 64,
            'height' => 64,
            'alt' => $name,
        ]];
    }
}
