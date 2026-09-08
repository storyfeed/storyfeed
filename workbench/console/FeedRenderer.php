<?php

namespace Workbench\Console;

use DateTimeImmutable;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/** Pure payload renderer; all domain labels and grammar come from the page. */
class FeedRenderer
{
    private const ROLES = ['actor', 'object', 'target', 'context', 'origin', 'result', 'instrument'];

    private array $imageCache = [];

    public function __construct(private string $rung = 'termwind', private string $images = 'none', private bool $expanded = false) {}

    public static function clean(string $text): string
    {
        // Strip terminal controls before HTML escaping or emitting raw ANSI.
        return preg_replace('/[\x00-\x1f\x7f-\x9f]/u', '', str_replace(["\r", "\n", "\t"], ' ', $text)) ?? '';
    }

    public function render(array $page, int $width = 80, bool $color = true): string
    {
        if (($page['payload_version'] ?? null) !== 1) {
            throw new \InvalidArgumentException('Unsupported payload version');
        }
        $result = '';
        foreach ($page['items'] as $node) {
            $result .= $this->node($node, max(16, $width), 0, $color);
        }
        if ($page['items'] === []) {
            $result .= "(empty head page)\n";
        }

        return $result.($page['next_cursor'] === null ? 'End of feed.' : 'Older history available; this prototype shows the head page only.')."\n";
    }

    /** @return list<array{0: string, 1: bool}> */
    public function headline(array $node): array
    {
        if ($node['headline_template'] === null) {
            return [[self::clean($node['headline'] ?? ($node['kind'] === 'group' ? ($node['count'].' activities') : ($node['verb'] ?? 'Activity'))), false]];
        }
        $parts = preg_split('/(:[a-z_]+)\b/u', $node['headline_template'], -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = [];
        foreach ($parts as $part) {
            $token = ltrim($part, ':');
            $value = $part;
            $noun = false;
            if (str_starts_with($part, ':') && in_array($token, self::ROLES, true)) {
                $entity = $node[$token] ?? null;
                $value = $entity === null ? ($token === 'actor' ? 'Someone' : '[unknown '.$token.']') : ($entity['label'] ?? '[unavailable '.$token.']');
                $noun = true;
            } elseif (str_starts_with($part, ':') && in_array(rtrim($token, 's'), self::ROLES, true) && str_ends_with($token, 's')) {
                $entities = $node['exemplars'][$token] ?? [];
                $labels = array_map(fn ($entity) => $entity['label'] ?? '[unavailable entity]', $entities);
                $more = max(0, ($node['distinct'][$token] ?? count($entities)) - count($entities));
                if ($more > 0) {
                    $labels[] = $more.' more';
                }
                $value = $labels === [] ? '[unknown '.$token.']' : implode(', ', $labels);
                $noun = true;
            } elseif ($part === ':count') {
                $value = (string) ($node['count'] ?? 1);
            } elseif ($part === ':others') {
                $value = max(0, ($node['distinct']['actors'] ?? 0) - count($node['exemplars']['actors'] ?? [])).' others';
            }
            $out[] = [self::clean($value), $noun];
        }

        return $out;
    }

    public function node(array $node, int $width, int $depth, bool $color): string
    {
        $group = $node['kind'] === 'group';
        $ascii = $this->rung === 'ascii';
        $prefix = str_repeat(' ', min($depth * 2, 8));
        $rail = $ascii ? '|' : '│';
        $actor = $node['actor'] ?? null;
        $actors = $group ? ($node['exemplars']['actors'] ?? []) : ($actor === null ? [] : [$actor]);
        $badges = [];
        foreach ($actors as $entity) {
            $label = $entity['label'] ?? '?';
            $words = preg_split('/\s+/u', self::clean($label));
            $badges[] = '['.implode('', array_map(fn ($word) => mb_substr($word, 0, 1), array_slice($words, 0, 2))).']';
        }
        $badge = implode('', $badges) ?: '[?]';
        $head = [[$badge.' ', true], ...$this->headline($node), ['  | '.self::time($node['published_at']), false]];
        $lines = $this->wrap($head, max(8, $width - strlen($prefix) - 4));
        if ($group) {
            $metadata = [[($this->expanded ? '[-] ' : '[+] ').$node['count'].' activities; '.count($node['children']).' children supplied'.($this->expanded ? '' : ' (use --expanded)'), false]];
            $lines = [...$lines, ...$this->wrap($metadata, max(8, $width - strlen($prefix) - 4))];
        }
        if (($thread = $node['thread'] ?? null) !== null) {
            $lines = [...$lines, ...$this->wrap([['> '.$thread['text'].($thread['truncated'] ? '...' : ''), false]], max(8, $width - strlen($prefix) - 4))];
            $by = $thread['by'] === ($actor['label'] ?? null) ? '' : ($thread['by'] ?? '');
            $caption = trim($by.' '.($thread['kind'] ?? ''));
            if ($thread['replies'] !== null) {
                $caption .= ' | '.$thread['replies'].($thread['replies'] === 1 ? ' reply' : ' replies');
            }
            $lines = [...$lines, ...$this->wrap([[$caption, false]], max(8, $width - strlen($prefix) - 4))];
        }
        // Known portable bodies; arbitrary component/data is intentionally not interpreted.
        foreach (self::ROLES as $role) {
            $entity = $node[$role] ?? null;
            if (isset($entity['content'])) {
                $type = $entity['mediaType'] ?? 'text/html';
                $body = $type === 'text/html' ? html_entity_decode(strip_tags($entity['content'])) : $entity['content'];
                $lines = [...$lines, ...$this->wrap([[$role.' body ('.$type.'): '.$body, false]], max(8, $width - strlen($prefix) - 4))];
            }
            if (isset($entity['component'])) {
                $lines[] = [['[component '.$entity['component'].': no terminal adapter]', false]];
            }
            if (isset($entity['media']['attachment'])) {
                $attachment = $entity['media']['attachment'];
                $lines = [...$lines, ...$this->wrap([['Attachment: '.($attachment['name'] ?? $attachment['type']).' ('.$attachment['mediaType'].') '.$attachment['href'], false]], max(8, $width - strlen($prefix) - 4))];
            }
        }
        foreach ($node['change']['changes'] ?? [] as $change) {
            $lines = [...$lines, ...$this->wrap([[$change['label'].': '.($change['before'] ?? '(null)').' -> '.($change['after'] ?? '(null)'), false]], max(8, $width - strlen($prefix) - 4))];
        }
        $result = '';
        if ($this->rung === 'termwind') {
            $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, $color);
            \Termwind\renderUsing($output);
            $html = '<div class="ml-'.min($depth * 2, 8).'">';
            foreach ($lines as $line) {
                $html .= '<div><span class="text-slate-600">'.$rail.'&nbsp;</span>';
                foreach ($line as [$text, $noun]) {
                    $html .= '<span class="'.($noun ? 'font-bold text-cyan-300' : 'text-slate-400').'">'.str_replace(' ', '&nbsp;', htmlspecialchars(OutputFormatter::escape(self::clean($text)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')).'</span>';
                }
                $html .= '</div>';
            }
            $html .= '<div class="text-slate-600">└'.str_repeat('─', min(30, $width - strlen($prefix) - 3)).'</div></div>';
            try {
                \Termwind\render($html);
                $result = $output->fetch();
            } finally {
                \Termwind\renderUsing(null);
            }
        } else {
            foreach ($lines as $line) {
                $result .= $prefix.$rail.' ';
                foreach ($line as [$text, $noun]) {
                    $ansi = match ($this->rung) {
                        '256' => $noun ? "\033[1;38;5;117m" : "\033[38;5;245m",
                        'truecolor' => $noun ? "\033[1;38;2;103;232;249m" : "\033[38;2;148;163;184m",
                        default => '',
                    };
                    $result .= ($color ? $ansi : '').self::clean($text).($color && $ansi !== '' ? "\033[0m" : '');
                }
                $result .= "\n";
            }
            $result .= $prefix.($ascii ? '+' : '└').str_repeat($ascii ? '-' : '─', min(30, $width - strlen($prefix) - 3))."\n";
        }
        foreach (self::ROLES as $role) {
            $entity = $node[$role] ?? null;
            foreach (['icon', 'preview', 'image'] as $slot) {
                if (isset($entity['media'][$slot])) {
                    $result .= $prefix.$this->media($entity['media'][$slot], $slot, $width - strlen($prefix));
                }
            }
        }
        if ($group && $this->expanded) {
            foreach ($node['children'] as $child) {
                $result .= $this->node($child, $width, $depth + 1, $color);
            }
            if ($node['children_truncated']) {
                $result .= $prefix.'  ... '.max(0, $node['count'] - count($node['children']))." members not supplied by the server.\n";
            }
        }

        return $result;
    }

    /** Wrap Unicode codepoints by cell width, preserving noun spans and every character. */
    private function wrap(array $parts, int $width): array
    {
        $lines = [];
        $line = [];
        $used = 0;
        foreach ($parts as [$text, $noun]) {
            foreach (preg_split('//u', self::clean($text), -1, PREG_SPLIT_NO_EMPTY) as $char) {
                $cells = mb_strwidth($char);
                if ($used + $cells > $width) {
                    $lines[] = $line;
                    $line = [];
                    $used = 0;
                }
                $last = array_key_last($line);
                if ($last !== null && $line[$last][1] === $noun) {
                    $line[$last][0] .= $char;
                } else {
                    $line[] = [$char, $noun];
                }
                $used += $cells;
            }
        }
        if ($line !== []) {
            $lines[] = $line;
        }

        return $lines;
    }

    public static function time(?string $date): string
    {
        if ($date === null) {
            return 'time unknown';
        }
        try {
            $at = new DateTimeImmutable($date);
            $age = time() - $at->getTimestamp();

            return match (true) {
                $age < 0 => $at->format('Y-m-d H:i T'),
                $age < 60 => 'just now',
                $age < 3600 => intdiv($age, 60).'m ago',
                $age < 86400 => intdiv($age, 3600).'h ago',
                $age < 604800 => intdiv($age, 86400).'d ago',
                default => $at->format('Y-m-d H:i T'),
            };
        } catch (\Exception) {
            return 'time unknown';
        }
    }

    public function media(array $image, string $slot, int $width): string
    {
        $caption = '['.$slot.': '.self::clean($image['alt'] ?? 'image').']';
        if ($this->images === 'none') {
            return $caption." (inline images off)\n";
        }
        try {
            $src = $image['src'];
            if (! isset($this->imageCache[$src])) {
                // Explicit graphics opt-in only; no auth or arbitrary file reads.
                if (! preg_match('~^https?://~', $src)) {
                    throw new \RuntimeException('requires absolute HTTP(S) image URL');
                }
                $context = stream_context_create(['http' => ['timeout' => 2, 'follow_location' => 0, 'ignore_errors' => false]]);
                $bytes = @file_get_contents($src, false, $context, 0, 524289);
                if ($bytes === false || strlen($bytes) > 524288) {
                    throw new \RuntimeException('fetch failed or exceeds 512 KiB');
                }
                $info = @getimagesizefromstring($bytes);
                if ($info === false || $info[0] * $info[1] > 4000000) {
                    throw new \RuntimeException('invalid or oversized raster');
                }
                if ($info['mime'] !== 'image/png') {
                    if (! extension_loaded('gd')) {
                        throw new \RuntimeException('non-PNG conversion needs ext-gd');
                    }
                    $raster = imagecreatefromstring($bytes);
                    ob_start();
                    imagepng($raster);
                    $bytes = ob_get_clean();
                }
                if (count($this->imageCache) >= 32) {
                    array_shift($this->imageCache);
                }
                $this->imageCache[$src] = $bytes;
            }
            if (in_array($this->images, ['blocks', 'sixel'], true)) {
                return $caption."\n".RasterProbe::encode($this->imageCache[$src], $this->images, min(32, max(8, $width - 4)));
            }
            $data = base64_encode($this->imageCache[$src]);
            $cols = $slot === 'icon' ? 4 : min(32, max(8, $width - 4));
            if ($this->images === 'iterm2') {
                return $caption."\n\033]1337;File=inline=1;width={$cols};preserveAspectRatio=1:{$data}\x07\n";
            }
            $chunks = str_split($data, 4096);
            $out = $caption."\n";
            foreach ($chunks as $index => $chunk) {
                $more = $index < count($chunks) - 1 ? 1 : 0;
                $out .= "\033_G".($index === 0 ? "a=T,f=100,t=d,q=2,c={$cols}," : '')."m={$more};{$chunk}\033\\";
            }

            return $out."\n";
        } catch (\Throwable $e) {
            return $caption.' unavailable: '.self::clean($e->getMessage())."\n";
        }
    }
}
