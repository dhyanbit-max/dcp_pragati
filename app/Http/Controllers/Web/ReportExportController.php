<?php

namespace App\Http\Controllers\Web;

use App\Domain\Reports\AqAnswer;
use App\Domain\Reports\Export\PdfExporter;
use App\Domain\Reports\Export\XlsxExporter;
use App\Domain\Reports\ReportResult;
use App\Domain\Reports\ReportRunner;
use App\Http\Controllers\Controller;
use App\Models\AqRequest;
use Illuminate\Http\Request;
use RuntimeException;

/** Excel / PDF downloads for MIS reports and AQ replies. Runs are recorded for metric M4. */
class ReportExportController extends Controller
{
    public function report(Request $request, string $code, string $format, ReportRunner $runner)
    {
        abort_unless(isset(ReportRunner::REPORTS[$code]), 404);
        $filters = (array) $request->query('filters', []);
        $params = (array) $request->query('params', []);
        $result = $runner->run($code, $filters, $params, $request->user(), $format);

        return $this->download($result, $format);
    }

    public function aq(Request $request, AqRequest $aq, string $format, AqAnswer $answers, ReportRunner $runner)
    {
        abort_unless($request->user()->hasStateScope() || $aq->answered_by === $request->user()->id, 404);
        $started = hrtime(true);
        $result = $answers->rebuild($aq, $request->user());
        $runner->record('AQ', $aq->params_json ?? [], $request->user(), $format, $started, 'aq');

        return $this->download($result, $format);
    }

    private function download(ReportResult $result, string $format)
    {
        $path = tempnam(sys_get_temp_dir(), 'dcp-report');
        try {
            match ($format) {
                'xlsx' => app(XlsxExporter::class)->write($result, $path),
                'pdf' => app(PdfExporter::class)->write($result, $path),
            };
        } catch (RuntimeException $e) {
            report($e);
            @unlink($path);

            return back()->with('status', __('The PDF could not be made right now. Try Excel, or try again in a minute.'));
        }

        return response()->download($path, $result->fileName($format))->deleteFileAfterSend();
    }
}
