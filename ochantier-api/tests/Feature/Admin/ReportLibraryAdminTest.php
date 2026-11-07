<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Support\Admin\ReportLibrary;
use App\Support\Auth\AdminSessions;
use App\Support\Auth\DeviceContext;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Feature\Promoter\ConfirmedSite;
use Tests\Feature\Promoter\PromoterTestCase;
use ZipArchive;

/**
 * API du Back Office, module Rapports : fichiers, bibliothèque et programmations.
 */
class ReportLibraryAdminTest extends PromoterTestCase
{
    use ConfirmedSite;

    private Admin $finance;

    private Admin $operations;

    private string $from;

    private string $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->confirmSite();
        $this->finance = Admin::factory()->role('finance')->create(['email' => 'finance@ochantier.ci']);
        $this->operations = Admin::factory()->role('operations')->create(['email' => 'operations@ochantier.ci']);
        $this->from = $this->startDate->startOfMonth()->toDateString();
        $this->to = $this->startDate->endOfMonth()->toDateString();
    }

    private function as(Admin $admin): static
    {
        return $this->withAccessToken(app(AdminSessions::class)->open($admin, new DeviceContext('Chrome', null, 'web', null, '127.0.0.1', 'phpunit'))->accessToken);
    }

    private function generate(string $format, string $kind = 'chantiers'): ReportRun
    {
        $this->as($this->finance)->postJson("/api/v1/admin/report-library/generate/{$kind}", ['from' => $this->from, 'to' => $this->to, 'format' => $format])->assertCreated();

        return ReportRun::query()->latest('generated_at')->with('mediaFile')->firstOrFail();
    }

    private function stored(ReportRun $run): string
    {
        return Storage::disk($run->mediaFile->disk)->get($run->mediaFile->path);
    }

    private function schedule(array $body = []): ReportSchedule
    {
        $id = $this->as($this->finance)->postJson('/api/v1/admin/report-library/schedules', ['kind' => 'missions', 'name' => 'Missions du lundi', 'frequency' => 'hebdomadaire', 'format' => 'csv', 'first_run_on' => now()->toDateString(), 'recipients' => ['operations@ochantier.ci'], ...$body])->assertCreated()->json('data.id');

        return ReportSchedule::query()->findOrFail($id);
    }

    public function test_a_csv_is_kept_in_the_library_and_downloaded(): void
    {
        $run = $this->generate('csv');

        $this->assertStringContainsString($this->site->label, $this->stored($run));
        $this->assertSame(1, $run->row_count);

        $this->getJson('/api/v1/admin/report-library')->assertOk()->assertJsonPath('data.0.id', $run->id)->assertJsonPath('data.0.format', 'csv');
        $this->assertStringContainsString($this->site->label, $this->get("/api/v1/admin/report-library/runs/{$run->id}/download")->assertOk()->streamedContent());
    }

    public function test_an_excel_file_opens_with_typed_cells_and_escaped_text(): void
    {
        $this->site->update(['label' => 'Résidence <A> & Cie']);
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $this->stored($this->generate('xlsx')));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        $xml = simplexml_load_string($sheet);
        $this->assertNotFalse($xml);
        $this->assertStringContainsString('Résidence &lt;A&gt; &amp; Cie', $sheet);
        $this->assertStringContainsString('<c r="D2"><v>', $sheet);
    }

    public function test_a_pdf_is_a_complete_document(): void
    {
        $content = $this->stored($this->generate('pdf'));

        $this->assertStringStartsWith('%PDF', $content);
        $this->assertStringContainsString('%%EOF', $content);
    }

    public function test_a_financial_file_needs_finance_rights(): void
    {
        $run = $this->generate('csv', 'financier');

        $this->as($this->operations)->postJson('/api/v1/admin/report-library/generate/financier', ['from' => $this->from, 'to' => $this->to, 'format' => 'csv'])->assertForbidden();
        $this->get("/api/v1/admin/report-library/runs/{$run->id}/download", ['Accept' => 'application/json'])->assertForbidden();
        $this->getJson('/api/v1/admin/report-library')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_recipients_must_be_active_admins_with_access(): void
    {
        $url = '/api/v1/admin/report-library/schedules';
        $body = ['kind' => 'financier', 'name' => 'Finance du mois', 'frequency' => 'mensuel', 'format' => 'pdf', 'first_run_on' => now()->toDateString()];

        $this->as($this->finance)->postJson($url, [...$body, 'recipients' => ['externe@gmail.com']])->assertUnprocessable()->assertJsonValidationErrors('recipients');
        $this->postJson($url, [...$body, 'recipients' => ['operations@ochantier.ci']])->assertUnprocessable()->assertJsonValidationErrors('recipients');
        $this->postJson($url, [...$body, 'recipients' => ['finance@ochantier.ci']])->assertCreated();
    }

    public function test_a_due_schedule_generates_sends_and_moves_on(): void
    {
        Event::fake([MessageSending::class]);
        $schedule = $this->schedule(['first_run_on' => now()->toDateString()]);
        $this->travelTo(CarbonImmutable::today()->setTime(7, 0));

        $this->artisan('ochantier:run-report-schedules')->expectsOutput('1 rapport(s) généré(s), 1 envoyé(s), 0 échec(s).')->assertSuccessful();

        $run = ReportRun::query()->sole();
        $this->assertSame($schedule->id, $run->schedule_id);
        $this->assertNull($run->generated_by_admin_id);
        $this->assertEquals(['from' => CarbonImmutable::today()->subDays(7)->toDateString(), 'to' => CarbonImmutable::today()->subDay()->toDateString()], $run->filters);
        $this->assertTrue($schedule->fresh()->next_run_at->isFuture());
        Event::assertDispatched(MessageSending::class, fn (MessageSending $event) => $event->message->getTo()[0]->getAddress() === 'operations@ochantier.ci' && count($event->message->getAttachments()) === 1);

        $this->artisan('ochantier:run-report-schedules')->expectsOutput('0 rapport(s) généré(s), 0 envoyé(s), 0 échec(s).')->assertSuccessful();
    }

    public function test_a_mail_failure_keeps_the_report_and_a_disabled_admin_receives_nothing(): void
    {
        $this->schedule();
        $this->travelTo(CarbonImmutable::today()->setTime(7, 0));
        Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('SMTP indisponible'));

        $this->artisan('ochantier:run-report-schedules')->expectsOutput('1 rapport(s) généré(s), 0 envoyé(s), 1 échec(s).')->assertSuccessful();
        $this->assertSame(1, ReportRun::query()->count());

        $this->operations->update(['status' => 'suspendu']);
        $this->travel(8)->days();
        Mail::shouldReceive('raw')->never();
        $this->artisan('ochantier:run-report-schedules')->assertSuccessful();
        $this->assertSame(2, ReportRun::query()->count());
    }

    public function test_a_month_schedule_covers_the_previous_month_and_a_suspended_one_waits(): void
    {
        $schedule = $this->schedule(['frequency' => 'mensuel', 'first_run_on' => now()->toDateString()]);
        $at = CarbonImmutable::parse($schedule->next_run_at);

        $run = app(ReportLibrary::class);
        $this->as($this->finance)->postJson("/api/v1/admin/report-library/schedules/{$schedule->id}/toggle")->assertOk()->assertJsonPath('data.status.value', 'suspendu');
        $this->travelTo($at->addHour());
        $this->assertSame(0, $run->runDue()['generated']);

        $this->as($this->finance)->postJson("/api/v1/admin/report-library/schedules/{$schedule->id}/toggle")->assertOk();
        Mail::fake();
        $run->runDue();
        $this->assertEquals(['from' => $at->startOfMonth()->subMonthNoOverflow()->toDateString(), 'to' => $at->startOfMonth()->subDay()->toDateString()], ReportRun::query()->sole()->filters);

        $this->deleteJson("/api/v1/admin/report-library/schedules/{$schedule->id}")->assertOk();
        $this->assertNull(ReportRun::query()->sole()->schedule_id);
    }

    public function test_generating_needs_the_right_to_create(): void
    {
        $this->as(Admin::factory()->role('direction')->create())->postJson('/api/v1/admin/report-library/generate/missions', ['from' => $this->from, 'to' => $this->to, 'format' => 'csv'])->assertForbidden();
    }
}
