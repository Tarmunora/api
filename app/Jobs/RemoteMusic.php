<?php

namespace App\Jobs;

use App\Helpers\HumanReadable;
use App\Libraries\GameBridge;
use App\Models\GameRound;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use YoutubeDl\Options;
use YoutubeDl\YoutubeDl;

class RemoteMusic implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // How many times should the job be attempted
    public $tries = 3;

    private $video;

    private $roundId;

    private $gameAdminCkey;

    private $round;

    private $youtubedlPath = '/usr/local/bin/yt-dlp';

    private $storagePath = 'app/public/audio';

    private $audioExt = 'mp3';

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(string $video, int $roundId, string $gameAdminCkey)
    {
        $this->video = $video;
        $this->roundId = $roundId;
        $this->gameAdminCkey = $gameAdminCkey;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $this->round = GameRound::where('id', $this->roundId)->first();
        if (! $this->round) {
            throw new \Exception('Cannot find round.');
        }

        $url = $this->video;
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            $url = "https://www.youtube.com/watch?v=$url";
        }

        // download video
        $yt = new YoutubeDl();
        $yt->setBinPath($this->youtubedlPath);
        $collection = $yt->download(
            Options::create()
                // ->forceIpV4()
                ->extractAudio(true)
                ->audioFormat($this->audioExt)
                ->audioQuality('7') // 0-9 where 0 is best
                ->output('%(id)s.%(ext)s')
                ->maxFileSize('10m')
                ->downloadPath(storage_path($this->storagePath))
                ->url($url)
        );

        $audio = null;
        foreach ($collection->getVideos() as $video) {
            if ($video->getError() !== null) {
                throw new \Exception("Error downloading video: {$video->getError()}.");
            } else {
                $audio = $video;
                break;
            }
        }

        if (! $audio) {
            return;
        }
        $fileName = "{$audio->getId()}.{$this->audioExt}";
        $filePath = storage_path("{$this->storagePath}/$fileName");
        $fileSize = File::size($filePath);
        $publicPath = asset(Storage::url('audio/'.$fileName));

        $data = json_encode([
            'url' => $url,
            'file' => $publicPath,
            'title' => $audio->getTitle(),
            'duration' => $audio->getDuration(),
            'duration_human' => gmdate('H:i:s', $audio->getDuration()),
            'filesize' => HumanReadable::bytesToHuman($fileSize),
            'admin_ckey' => $this->gameAdminCkey,
        ]);

        GameBridge::relay($this->round->server_id, "type=youtube&data=$data");
    }

    /**
     * Handle a job failure.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        if ($this->round) {
            GameBridge::relay(
                $this->round->server_id,
                "type=youtube&error={$exception->getMessage()}"
            );
        }
    }
}
