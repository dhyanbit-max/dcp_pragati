<?php

namespace App\Domain\Reports\Export;

use App\Domain\Reports\ReportResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * PDF export through Gotenberg (headless Chromium, docs/09 §4): Chromium shapes Assamese and
 * Bengali correctly, which pure-PHP PDF libraries do not. Fonts are embedded in the HTML so the
 * output does not depend on what is installed on the server.
 */
class PdfExporter
{
    public function html(ReportResult $r): string
    {
        return view('reports.pdf', ['r' => $r, 'fontCss' => $this->fontCss()])->render();
    }

    public function write(ReportResult $r, string $path): void
    {
        $url = rtrim((string) config('dcp.pdf.gotenberg_url'), '/');
        if ($url === '') {
            throw new RuntimeException('PDF export is not configured (DCP_GOTENBERG_URL).');
        }

        $response = Http::timeout(60)
            ->attach('files', $this->html($r), 'index.html')
            ->post($url.'/forms/chromium/convert/html', [
                // A4 landscape, narrow margins: report tables are wide.
                'paperWidth' => '11.69',
                'paperHeight' => '8.27',
                'marginTop' => '0.4',
                'marginBottom' => '0.5',
                'marginLeft' => '0.4',
                'marginRight' => '0.4',
                'printBackground' => 'true',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('PDF service returned HTTP '.$response->status());
        }
        file_put_contents($path, $response->body());
    }

    private function fontCss(): string
    {
        $css = '';
        foreach ([
            ['Noto Sans', 400, 'noto-sans-latin-400-normal.woff2'],
            ['Noto Sans', 700, 'noto-sans-latin-700-normal.woff2'],
            ['Noto Sans Bengali', 400, 'noto-sans-bengali-bengali-400-normal.woff2'],
            ['Noto Sans Bengali', 700, 'noto-sans-bengali-bengali-700-normal.woff2'],
        ] as [$family, $weight, $file]) {
            $data = base64_encode((string) file_get_contents(resource_path("fonts/$file")));
            $css .= "@font-face{font-family:'$family';font-weight:$weight;font-style:normal;src:url(data:font/woff2;base64,$data) format('woff2');}\n";
        }

        return $css;
    }
}
