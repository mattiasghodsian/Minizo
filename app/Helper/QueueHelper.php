<?php

namespace App\Helper;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class QueueHelper
{
    public function getList(string $jobType = "DownloadJob"): \Illuminate\Support\Collection
    {
        $queues = DB::table('jobs')
            ->select(['id', 'queue', 'payload', 'attempts', 'created_at'])
            ->whereRaw("payload LIKE ?", ['%' . $jobType . '%'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($job) {
                $payload = json_decode($job->payload, true);

                $command = (array) unserialize($payload['data']['command']);
                $command = array_combine(
                    array_map(function ($key) {
                        return preg_replace('/\x00.*\x00/', '', $key);
                    }, array_keys($command)),
                    array_values($command)
                );

                return array_merge($payload, [
                    'data' => [
                        'commandName'   => $payload['data']['commandName'],
                        'command'       => $command,
                        'created_at'    => Carbon::parse($job->created_at)->diffForHumans(),
                    ]
                ]);
            });

        return $queues;
    }
}