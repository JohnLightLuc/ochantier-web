<?php

namespace App\Support\Admin;

use App\Enums\AdminStatus;
use App\Enums\ExportFormat;
use App\Enums\ReportFrequency;
use App\Enums\ReportScheduleStatus;
use App\Models\Admin;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Support\Media\MediaStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Bibliothèque des rapports générés et programmations. Une programmation génère son rapport seule et l’envoie par
 * e-mail : le fichier reste dans la bibliothèque si l’envoi échoue. Ces rapports contiennent des données personnelles
 * et financières : ils ne partent que vers des administrateurs actifs ayant le droit de les lire.
 */
class ReportLibrary
{
    public function __construct(private ReportCatalog $catalog, private ReportFiles $files, private MediaStore $media) {}

    public function generate(string $kind, CarbonImmutable $from, CarbonImmutable $to, ExportFormat $format, ?Admin $admin, ?ReportSchedule $schedule = null): ReportRun
    {
        $report = $this->catalog->build($kind, $from, $to);
        $file = $this->files->render($report, $format);
        $name = $report['title'].' · '.$from->toDateString().' au '.$to->toDateString();

        return DB::transaction(fn (): ReportRun => ReportRun::query()->create([
            'schedule_id' => $schedule?->getKey(),
            'report_kind' => $kind,
            'name' => $name,
            'format' => $format,
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'row_count' => count($report['rows']),
            'media_file_id' => $this->media->storeContent($file['content'], "rapport-{$kind}-{$from->toDateString()}.{$file['extension']}", $file['mime'], 'rapport', $admin, true)->getKey(),
            'generated_by_admin_id' => $admin?->getKey(),
            'generated_at' => now(),
        ]));
    }

    /**
     * @param  array{kind: string, name: string, frequency: string, format: string, first_run_on: string, recipients: list<string>}  $data
     */
    public function schedule(array $data, Admin $admin): ReportSchedule
    {
        $recipients = array_values(array_unique(array_map('mb_strtolower', $data['recipients'])));
        $this->ensureRecipients($data['kind'], $recipients);

        return ReportSchedule::query()->create([
            'report_kind' => $data['kind'],
            'name' => $data['name'],
            'frequency' => $data['frequency'],
            'format' => $data['format'],
            'recipients' => $recipients,
            'next_run_at' => CarbonImmutable::parse($data['first_run_on'])->setTime(6, 0),
            'status' => ReportScheduleStatus::Actif,
            'created_by_admin_id' => $admin->getKey(),
        ]);
    }

    /**
     * Programmations arrivées à échéance. Une erreur sur l’une n’empêche pas les suivantes.
     *
     * @return array{generated: int, sent: int, failed: int}
     */
    public function runDue(): array
    {
        $counts = ['generated' => 0, 'sent' => 0, 'failed' => 0];

        ReportSchedule::query()->where('status', ReportScheduleStatus::Actif)->where('next_run_at', '<=', now())->each(function (ReportSchedule $schedule) use (&$counts): void {
            $at = CarbonImmutable::parse($schedule->next_run_at);
            [$from, $to] = $this->period($schedule->frequency, $at);
            $schedule->update(['next_run_at' => $this->next($schedule->frequency, $at)]);

            try {
                $run = $this->generate($schedule->report_kind, $from, $to, $schedule->format, null, $schedule);
                $counts['generated']++;
            } catch (Throwable $e) {
                Log::error('Rapport programmé non généré', ['schedule' => $schedule->id, 'error' => $e->getMessage()]);
                $counts['failed']++;

                return;
            }

            try {
                $this->send($schedule, $run);
                $counts['sent']++;
            } catch (Throwable $e) {
                Log::error('Rapport programmé non envoyé', ['schedule' => $schedule->id, 'run' => $run->id, 'error' => $e->getMessage()]);
                $counts['failed']++;
            }
        });

        return $counts;
    }

    /**
     * Les droits sont revérifiés à chaque envoi : un administrateur désactivé ne reçoit plus rien.
     */
    private function send(ReportSchedule $schedule, ReportRun $run): void
    {
        $recipients = array_values(array_filter($schedule->recipients, fn (string $email) => $this->canReceive($email, $schedule->report_kind)));

        if ($recipients === []) {
            return;
        }

        $file = $run->mediaFile;
        $content = Storage::disk($file->disk)->get($file->path);

        Mail::raw('Veuillez trouver ci-joint : '.$run->name.'.', function ($mail) use ($recipients, $run, $content, $file): void {
            $mail->to($recipients)->subject('O’CHANTIER · '.$run->name)->attachData($content, $file->original_name, ['mime' => $file->mime_type]);
        });
    }

    /**
     * @param  list<string>  $recipients
     */
    private function ensureRecipients(string $kind, array $recipients): void
    {
        foreach ($recipients as $email) {
            if (! $this->canReceive($email, $kind)) {
                throw ValidationException::withMessages(['recipients' => $email.' n’est pas un administrateur actif ayant accès à ce rapport.']);
            }
        }
    }

    private function canReceive(string $email, string $kind): bool
    {
        $admin = Admin::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->where('status', AdminStatus::Actif)->first();

        return $admin !== null && $admin->hasPermission('reports.read') && $this->catalog->allowed($kind, $admin);
    }

    /**
     * Période couverte : la veille, les sept jours passés ou le mois précédent.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(ReportFrequency $frequency, CarbonImmutable $at): array
    {
        $yesterday = $at->subDay()->startOfDay();

        return match ($frequency) {
            ReportFrequency::Quotidien => [$yesterday, $yesterday],
            ReportFrequency::Hebdomadaire => [$yesterday->subDays(6), $yesterday],
            ReportFrequency::Mensuel => [$at->startOfMonth()->subMonthNoOverflow(), $at->startOfMonth()->subDay()],
        };
    }

    /**
     * Prochaine date future, même si des échéances ont été manquées.
     */
    private function next(ReportFrequency $frequency, CarbonImmutable $at): CarbonImmutable
    {
        do {
            $at = match ($frequency) {
                ReportFrequency::Quotidien => $at->addDay(),
                ReportFrequency::Hebdomadaire => $at->addWeek(),
                ReportFrequency::Mensuel => $at->addMonthNoOverflow(),
            };
        } while ($at->isPast());

        return $at;
    }
}
