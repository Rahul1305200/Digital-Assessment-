<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class DemoData extends Command
{
    protected $signature = 'assessment:demo {--fresh : Remove existing demo patients first}';

    protected $description = 'Create 11 demo patients (one for every step of the journey) and a sample recording. Test data only.';

    public function handle(): int
    {
        $private = storage_path('app/private');
        if (! File::exists($private.'/key')) {
            $this->error('Run "php artisan assessment:setup" first (it creates the participant-link key).');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $ids = DB::table('participants')->where('site', 'like', 'Test:%')->pluck('id');
            DB::table('assessment_history')->whereIn('participant_id', $ids)->delete();
            DB::table('assessments')->whereIn('participant_id', $ids)->delete();
            DB::table('audit')->whereIn('participant_id', $ids)->delete();
            DB::table('participants')->whereIn('id', $ids)->delete();
        } elseif (DB::table('participants')->where('site', 'like', 'Test:%')->exists()) {
            $this->error('Demo patients already exist. Use --fresh to replace them.');

            return self::FAILURE;
        }

        // A sample recording, so the music group has something to play. Replace it in the Audio library.
        $audioId = DB::table('audio_files')->orderBy('id')->value('id');
        if (! $audioId) {
            File::ensureDirectoryExists($private.'/audio');
            $name = bin2hex(random_bytes(16)).'.wav';
            File::copy(database_path('demo/demo-listening.wav'), $private.'/audio/'.$name);
            $audioId = DB::table('audio_files')->insertGetId([
                'title' => 'Demo listening · not a study recording',
                'filename' => $name,
                'mime' => 'audio/wav',
            ]);
        }

        $answers = [
            't0' => ['t0_anxiety' => 7, 't0_relaxation' => 3],
            't2audio' => ['t2_anxiety' => 4, 't2_relaxation' => 6, 't2_listening_experience' => 'Pleasant', 't2_unwanted_effects' => 'No'],
            't2usual' => ['t2_anxiety' => 6, 't2_relaxation' => 4],
            'eveningaudio' => ['evening_overall_experience' => 8, 'evening_procedure_anxiety' => 3, 'evening_procedure_comfort' => 7, 'evening_study_difficulty' => 'No', 'evening_intervention_issues' => 'No', 'evening_willingness_repeat' => 'Yes'],
            'eveningusual' => ['evening_overall_experience' => 7, 'evening_procedure_anxiety' => 5, 'evening_procedure_comfort' => 6, 'evening_study_difficulty' => 'No'],
            'sleep' => ['sleep_audio_used' => 'No', 'sleep_quality' => 6, 'sleep_total_hours' => 7, 'sleep_total_minutes' => 0, 'sleep_awakenings' => 1, 'sleep_medication_used' => 'No'],
        ];

        // code, label, group, stage, finished assessments, sleep follow-up, session state
        $rows = [
            ['YM-TEST-01', '1 New: baseline not started', 'usual', 't0', [], 0, null],
            ['YM-TEST-02', '2 Baseline done: waiting for group', 'unassigned', 'allocation', ['t0'], 0, null],
            ['YM-TEST-03', '3 With music: listening now', 'audio', 'session', ['t0'], 0, 'listening'],
            ['YM-TEST-04', '4 Without music: waiting period', 'usual', 'session', ['t0'], 0, 'listening'],
            ['YM-TEST-05', '5 With music: finished, waiting for T2', 'audio', 'wait_t2', ['t0'], 0, 'ended'],
            ['YM-TEST-06', '6 With music: T2 open', 'audio', 't2', ['t0'], 0, 'ended'],
            ['YM-TEST-07', '7 Without music: T2 done, evening not open', 'usual', 'recovery', ['t0', 't2'], 1, 'ended'],
            ['YM-TEST-08', '8 With music: evening open', 'audio', 'evening', ['t0', 't2'], 1, 'ended'],
            ['YM-TEST-09', '9 Without music: evening done, sleep not open', 'usual', 'followup', ['t0', 't2', 'evening'], 1, 'ended'],
            ['YM-TEST-10', '10 With music: sleep open', 'audio', 'sleep', ['t0', 't2', 'evening'], 1, 'ended'],
            ['YM-TEST-11', '11 Without music: all done', 'usual', 'done', ['t0', 't2', 'evening', 'sleep'], 1, 'ended'],
        ];

        foreach ($rows as [$code, $label, $group, $stage, $done, $sleep, $session]) {
            $token = bin2hex(random_bytes(32));
            $id = DB::table('participants')->insertGetId([
                'code' => $code,
                'token_hash' => hash('sha256', $token),
                'token_cipher' => seal($token),
                'site' => 'Test: '.$label,
                'study_group' => $group,
                'stage' => $stage,
                'is_test' => 1,
                'sleep_opt_in' => $sleep,
                'consent_confirmed' => 1,
                'audio_id' => $group === 'audio' ? $audioId : null,
                'sleep_audio_id' => $sleep ? $audioId : null,
                'session_started_at' => $session ? gmdate('Y-m-d H:i:s', time() - 900) : null,
                'session_ended_at' => $session === 'ended' ? gmdate('Y-m-d H:i:s', time() - 60) : null,
                'listening_seconds' => ($group === 'audio' && $session === 'ended') ? 840 : 0,
                'session_note' => $session === 'ended' ? ($group === 'audio' ? 'Audio completed' : 'Usual-care waiting interval finished') : null,
                'allocation_reference' => $group === 'unassigned' ? null : 'Manual staff selection',
            ]);
            foreach ($done as $type) {
                $a = match ($type) {
                    't0' => $answers['t0'],
                    't2' => $group === 'audio' ? $answers['t2audio'] : $answers['t2usual'],
                    'evening' => $group === 'audio' ? $answers['eveningaudio'] : $answers['eveningusual'],
                    'sleep' => $answers['sleep'],
                };
                DB::table('assessments')->insert([
                    'participant_id' => $id,
                    'type' => $type,
                    'status' => 'completed',
                    'answers' => json_encode($a),
                    'core_completed' => in_array($type, ['t0', 't2'], true) ? 1 : 0,
                    'submitted_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }
        }

        $this->info('Created '.count($rows).' demo patients. They are marked as test records and stay out of the reports.');

        return self::SUCCESS;
    }
}
