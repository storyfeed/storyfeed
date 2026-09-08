<?php

namespace Workbench\Console;

use Illuminate\Console\Command;
use Storyfeed\Facades\Storyfeed;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

class ConsoleFeedCommand extends Command
{
    protected $signature = 'storyfeed:console
        {--rung=termwind : ascii, box, 256, truecolor, termwind}
        {--images=none : none, blocks, iterm2, kitty, sixel (explicit opt-in; never guessed)}
        {--once : Render one frame}
        {--polls=0 : Stop after N frames; 0 runs until Ctrl-C}
        {--interval=2 : Seconds between head polls, minimum 0.2}
        {--limit=8 : Head page size}
        {--width=0 : Fixed width; 0 follows terminal resize}
        {--expanded : Draw all supplied group children}
        {--json : Dump the untouched head payload and exit}';

    protected $description = 'W93 experimental payload-only terminal feed (isolated demo application)';

    private bool $stopping = false;

    public function handle(): int
    {
        $rung = (string) $this->option('rung');
        $images = (string) $this->option('images');
        if (! in_array($rung, ['ascii', 'box', '256', 'truecolor', 'termwind'], true)
            || ! in_array($images, ['none', 'blocks', 'iterm2', 'kitty', 'sixel'], true)) {
            $this->error('Unknown rung or image protocol.');

            return self::FAILURE;
        }
        $tty = stream_isatty(STDOUT);
        $color = $tty && getenv('NO_COLOR') === false && ! $this->option('no-ansi') && getenv('TERM') !== 'dumb';
        if (! $color) {
            $rung = 'ascii';
            $images = 'none';
        }
        $renderer = new FeedRenderer($rung, $images, (bool) $this->option('expanded'));
        $epoch = null;
        $initialized = false;
        $frame = 0;
        $state = null;
        $live = $tty && ! $this->option('once') && getenv('TERM') !== 'dumb';
        $max = $this->option('once') || ! $tty ? 1 : max(0, (int) $this->option('polls'));
        $interval = max(0.2, (float) $this->option('interval'));
        $this->stopping = false;
        if (extension_loaded('pcntl')) {
            $this->trap([SIGINT, SIGTERM], function () {
                $this->stopping = true;
            });
        }
        if ($live) {
            $this->output->write("\033[?1049h\033[?25l", false, OutputInterface::OUTPUT_RAW);
        }
        try {
            do {
                $start = hrtime(true);
                try {
                    // The only data door. No models, resolver calls or new queries.
                    $page = Storyfeed::feed()->only(['demo.*'])->summary()->limit(max(1, min(100, (int) $this->option('limit'))))->get()->toArray();
                    if ($this->option('json')) {
                        $this->output->write(json_encode($page, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", false, OutputInterface::OUTPUT_RAW);

                        return self::SUCCESS;
                    }
                    $reset = $initialized && $page['sync_token'] !== $epoch;
                    if ($reset) {
                        // Discard epoch-derived state before a distinct fresh head read.
                        $state = null;
                        $page = Storyfeed::feed()->only(['demo.*'])->summary()->limit(max(1, min(100, (int) $this->option('limit'))))->get()->toArray();
                    }
                    $state = $page; // Nodes and opaque next_cursor replaced together every poll.
                    $width = max(16, (int) $this->option('width') ?: $this->terminalWidth());
                    $body = $renderer->render($state, $width, $color);
                    $frame++;
                    $ms = (hrtime(true) - $start) / 1e6;
                    $header = sprintf('STORYFEED | %s | frame %d | %.1f ms | %d cols', $rung, $frame, $ms, $width);
                    if ($live) {
                        $this->output->write(($images === 'kitty' ? "\033_Ga=d,d=A\033\\" : '')."\033[H\033[2J", false, OutputInterface::OUTPUT_RAW);
                    }
                    $this->output->write($header."\n".($reset ? "RESYNC: history rewritten; discarded nodes + cursor; refetched head.\n" : '').$body, false, OutputInterface::OUTPUT_RAW);
                    // Acknowledge only after the fresh frame successfully renders.
                    $epoch = $state['sync_token'];
                    $initialized = true;
                } catch (\Throwable $e) {
                    $this->output->write('READ/RENDER FAILED; epoch not acknowledged: '.FeedRenderer::clean($e->getMessage())."\n", false, OutputInterface::OUTPUT_RAW);
                    if ($max === 1) {
                        return self::FAILURE;
                    }
                }
                if ($this->stopping || ($max > 0 && $frame >= $max)) {
                    break;
                }
                usleep((int) ($interval * 1e6));
            } while (true);
        } finally {
            if ($live) {
                $this->output->write(($images === 'kitty' ? "\033_Ga=d,d=A\033\\" : '')."\033[0m\033[?25h\033[?1049l", false, OutputInterface::OUTPUT_RAW);
            }
        }

        return self::SUCCESS;
    }

    private function terminalWidth(): int
    {
        // Symfony caches dimensions; constructing another Terminal does not refresh them.
        // Query the PTY on Unix. Windows keeps Symfony's initial width in this prototype.
        if (DIRECTORY_SEPARATOR === '/' && function_exists('proc_open')) {
            $process = @proc_open(['stty', 'size'], [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (is_resource($process)) {
                $size = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                if (preg_match('/^\s*\d+\s+(\d+)/', $size, $match)) {
                    return (int) $match[1];
                }
            }
        }

        return (new Terminal)->getWidth();
    }
}
