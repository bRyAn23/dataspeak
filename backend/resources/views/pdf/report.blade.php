@php use App\Services\Reports\Pdf\ScriptSpans as S; use App\Services\Reports\Pdf\PdfWording as W; @endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<title>{{ $analysis->name }} - Data Speak</title>
<style>
  @font-face { font-family: 'Noto Sans'; font-style: normal; font-weight: normal; src: url('{{ $fontDir }}/NotoSans-Regular.ttf') format('truetype'); }
  @font-face { font-family: 'Noto Sans'; font-style: normal; font-weight: bold; src: url('{{ $fontDir }}/NotoSans-Bold.ttf') format('truetype'); }
  @font-face { font-family: 'Noto Sans Thai'; font-style: normal; font-weight: normal; src: url('{{ $fontDir }}/NotoSansThai-Regular.ttf') format('truetype'); }
  @font-face { font-family: 'Noto Sans Thai'; font-style: normal; font-weight: bold; src: url('{{ $fontDir }}/NotoSansThai-Regular.ttf') format('truetype'); }
  @font-face { font-family: 'Noto Sans Myanmar'; font-style: normal; font-weight: normal; src: url('{{ $fontDir }}/NotoSansMyanmar-Regular.ttf') format('truetype'); }
  @font-face { font-family: 'Noto Sans Myanmar'; font-style: normal; font-weight: bold; src: url('{{ $fontDir }}/NotoSansMyanmar-Regular.ttf') format('truetype'); }
  @if ($locale === 'zh')
  @font-face { font-family: 'Noto Sans SC'; font-style: normal; font-weight: normal; src: url('{{ $fontDir }}/NotoSansSC-Regular.ttf') format('truetype'); }
  @font-face { font-family: 'Noto Sans SC'; font-style: normal; font-weight: bold; src: url('{{ $fontDir }}/NotoSansSC-Regular.ttf') format('truetype'); }
  @endif
  @page { margin: 96px 40px 64px 40px; }
  body { font-family: 'Noto Sans', 'DejaVu Sans', sans-serif; font-size: 10.5pt; color: #0F172A; line-height: 1.45; }
  .th { font-family: 'Noto Sans Thai', 'Noto Sans', sans-serif; }
  .my { font-family: 'Noto Sans Myanmar', 'Noto Sans', sans-serif; }
  .zh { font-family: 'Noto Sans SC', 'Noto Sans', sans-serif; }
  header.letterhead { position: fixed; top: -72px; left: 0; right: 0; height: 48px; border-bottom: 1px solid #E2E8F0; }
  header.letterhead img { height: 34px; }
  header.letterhead .title { position: absolute; right: 0; top: 8px; font-size: 9pt; color: #64748B; text-align: right; }
  .cover { page-break-after: always; text-align: center; padding-top: 150px; }
  .cover img { height: 110px; }
  .cover h1 { font-size: 26pt; margin: 36px 0 6px; color: #03351F; }
  .cover .sub { font-size: 12pt; color: #475569; margin: 0 0 40px; }
  .cover table.meta { margin: 0 auto; border-collapse: collapse; font-size: 10.5pt; }
  .cover table.meta td { padding: 6px 14px; border-bottom: 1px solid #E2E8F0; text-align: left; }
  .cover table.meta td.k { color: #64748B; }
  .cover .rule { margin-top: 70px; font-size: 9pt; color: #64748B; }
  h2 { font-size: 15pt; color: #03351F; margin: 22px 0 8px; padding-bottom: 4px; border-bottom: 2px solid #087A43; }
  h3 { font-size: 11.5pt; margin: 14px 0 6px; color: #0F172A; }
  p { margin: 0 0 8px; }
  .kpis { width: 100%; border-collapse: separate; border-spacing: 8px 0; margin: 4px -8px 10px; }
  .kpis td { width: 25%; border: 1px solid #E2E8F0; border-radius: 6px; padding: 10px 12px; vertical-align: top; }
  .kpis .label { font-size: 8.5pt; color: #64748B; text-transform: uppercase; letter-spacing: .03em; }
  .kpis .value { font-size: 15pt; font-weight: bold; margin-top: 2px; }
  .summary { font-size: 11.5pt; line-height: 1.6; }
  .chart { page-break-inside: avoid; margin: 10px 0 16px; border: 1px solid #E2E8F0; border-radius: 6px; padding: 12px 14px; }
  .chart .ctitle { font-weight: bold; font-size: 11pt; margin-bottom: 2px; }
  .chart .csub { font-size: 9pt; color: #64748B; margin-bottom: 6px; }
  .chart img.plot { width: 100%; height: auto; display: block; margin: 4px 0; }
  table.hbars { width: 100%; border-collapse: collapse; margin: 6px 0 4px; font-size: 9pt; }
  table.hbars td { padding: 3px 4px; vertical-align: middle; border: none; }
  table.hbars td.hl { width: 44%; color: #334155; line-height: 1.35; }
  table.hbars td.hb { width: 44%; }
  table.hbars td.hb .bar { height: 11px; background: #087A43; border-radius: 3px; }
  table.hbars td.hv { width: 12%; text-align: right; color: #475569; white-space: nowrap; }
  .key { font-size: 8.5pt; color: #475569; margin: 0 0 4px; } .key span { display: inline-block; margin-right: 12px; }
  .reading { background: #F8FAFC; border-radius: 4px; padding: 8px 10px; margin-top: 8px; }
  .reading .rl { font-size: 8.5pt; color: #64748B; text-transform: uppercase; letter-spacing: .03em; margin-bottom: 3px; }
  .reading ul { margin: 0; padding-left: 16px; }
  table.data { border-collapse: collapse; width: 100%; font-size: 9.5pt; }
  table.data th, table.data td { border-bottom: 1px solid #E2E8F0; padding: 4px 6px; text-align: left; }
  table.data th { background: #F1F5F9; font-weight: bold; }
  ul.list { margin: 4px 0; padding-left: 18px; }
  .notes { background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 6px; padding: 10px 14px; }
  .muted { color: #64748B; font-size: 9pt; }
  .warn { display: inline-block; font-size: 8pt; padding: 0 5px; border-radius: 3px; background: #FFFBEB; color: #B45309; border: 1px solid #FDE68A; }
  .badge { display: inline-block; font-size: 8pt; padding: 1px 6px; border-radius: 4px; background: #ECFDF5; color: #047857; border: 1px solid #A7F3D0; }
  .appendix td { vertical-align: top; }
</style>
</head>
<body>

<header class="letterhead">
  <img src="{{ $logo }}" alt="Data Speak">
  <div class="title">{!! S::html($analysis->name) !!}<br>{!! S::html(__('report.executive_report')) !!}</div>
</header>

<section class="cover">
  <img src="{{ $logo }}" alt="Data Speak">
  <h1>{!! S::html($analysis->name) !!}</h1>
  <p class="sub">{!! S::html(__('report.executive_report')) !!}</p>
  <table class="meta">
    <tr><td class="k">{!! S::html(__('report.prepared_for')) !!}</td><td>{!! S::html($user?->name) !!} &lt;{{ $user?->email }}&gt;</td></tr>
    <tr><td class="k">{!! S::html(__('report.analysis_created')) !!}</td><td>{{ $analysis->created_at?->format('j F Y, H:i') }} UTC</td></tr>
    <tr><td class="k">{!! S::html(__('report.report_generated')) !!}</td><td>{{ $generatedAt->format('j F Y, H:i') }} UTC</td></tr>
    <tr><td class="k">{!! S::html(__('report.source_file')) !!}</td><td>{!! S::html($analysis->source_file_name ?? '—') !!}</td></tr>
    <tr><td class="k">{!! S::html(__('report.records_analysed')) !!}</td><td>{{ $analysis->row_count !== null ? number_format($analysis->row_count) : '—' }}</td></tr>
  </table>
  <p class="rule">{!! S::html(__('report.cover_rule')) !!}</p>
</section>

@if (!empty($report['summary_metrics']))
<h2>{!! S::html(__('report.key_figures')) !!}</h2>
<table class="kpis"><tr>
  @foreach (array_slice($report['summary_metrics'], 0, 4) as $m)
    <td><div class="label">{!! S::html($m['label']) !!}</div><div class="value">{!! S::html($m['display_value']) !!}@if (!empty($m['unit']) && !str_contains($m['display_value'], $m['unit'])) <span class="muted">{{ $m['unit'] }}</span>@endif</div></td>
  @endforeach
</tr></table>
@endif

<h2>{!! S::html(__('report.executive_summary')) !!}</h2>
@if (!empty($summaryClaims))
  <p class="summary">
    @foreach ($summaryClaims as $c){!! S::html($c['statement']) !!} @endforeach
  </p>
  <p class="muted">{!! S::html(__('report.verified_note')) !!}</p>
@else
  <p class="muted">{!! S::html($report['ai']['note'] ?? __('report.no_summary')) !!}</p>
@endif

<h2>{!! S::html(__('report.charts_heading')) !!}</h2>
@forelse ($charts as $chart)
  <div class="chart">
    <div class="ctitle">{!! S::html($chart['title']) !!}</div>
    <div class="csub">{!! S::html(($chart['y']['label'] ?? '').(!empty($chart['y']['unit']) ? ' ('.$chart['y']['unit'].')' : '').(!empty($chart['x']['label']) ? __('report.axis_by', ['x' => $chart['x']['label']]) : '')) !!}@if (!empty($chart['unit_missing'])) · {!! S::html(__('report.unit_not_defined')) !!} @endif</div>
    @if (!empty($chart['html']))
      {!! $chart['html'] !!}
    @elseif ($chart['svg'])
      <img class="plot" src="{{ $chart['svg'] }}" alt="">
      @if (!empty($chart['key']))
        <p class="key">@foreach ($chart['key'] as $k)<span>{{ $k['n'] }} = {!! S::html($k['label']) !!}</span>@endforeach</p>
      @endif
    @elseif (!empty($chart['series']))
      @php $xf = $chart['x']['field'] ?? null; $cols = array_keys($chart['series'][0]); usort($cols, fn ($a, $b) => ($a === $xf ? 0 : 1) <=> ($b === $xf ? 0 : 1)); @endphp
      <table class="data"><thead><tr>@foreach ($cols as $col)<th>{!! S::html($col === ($chart['x']['field'] ?? null) ? ($chart['x']['label'] ?? $col) : ($col === ($chart['y']['field'] ?? null) ? ($chart['y']['label'] ?? $col) : str_replace('_', ' ', $col))) !!}</th>@endforeach</tr></thead>
      <tbody>@foreach (array_slice($chart['series'], 0, 12) as $row)<tr>@foreach ($cols as $col)<td>{!! S::html(is_numeric($row[$col] ?? null) ? \App\Services\Reports\Pdf\ChartSvg::num((float) $row[$col]) : ($col === 'statistic' ? W::metric((string) $row[$col]) : (string) ($row[$col] ?? '—'))) !!}</td>@endforeach</tr>@endforeach</tbody></table>
    @endif
    <div class="reading">
      <div class="rl">{!! S::html(__('report.what_chart_shows')) !!}</div>
      @if (empty($chart['summary']))
        <p class="muted">{!! S::html(__('report.no_chart_summary')) !!}</p>
      @else
        <ul>@foreach ($chart['summary'] as $s)<li>{!! S::html($s['text']) !!}</li>@endforeach</ul>
      @endif
    </div>
  </div>
@empty
  <p class="muted">{!! S::html(__('report.no_charts')) !!}</p>
@endforelse

@if (!empty($otherFindings) || !empty($insights))
<h2>{!! S::html(__('report.also_worth_knowing')) !!}</h2>
<ul class="list">
  @foreach ($otherFindings as $c)<li>{!! S::html($c['statement']) !!}</li>@endforeach
  @foreach ($insights as $ins)
    @if (empty(array_filter($charts, fn ($ch) => ($ch['insight_rank'] ?? null) === $ins['rank'])))<li>{!! S::html($ins['headline']) !!}</li>@endif
  @endforeach
</ul>
@endif

@if (!empty($caveats) || !empty($report['data_notes']))
<h2>{!! S::html(__('report.data_notes')) !!}</h2>
<div class="notes"><ul class="list">
  @foreach ($caveats as $c)<li>{!! S::html($c['statement']) !!}</li>@endforeach
  @foreach ($report['data_notes'] as $n)
    @if ($n['severity'] !== 'ACCEPTABLE' || in_array($n['metric'], ['duplicate_rows', 'completeness'], true))
      <li>{!! S::html(W::dataNote(implode(', ', $n['labels'] ?? $n['columns']) ?: __('notes.the_data'), $n['metric'], $n['display_value'])) !!}</li>
    @endif
  @endforeach
</ul></div>
@endif

@php $all = array_merge($summaryClaims, $otherFindings, $caveats); @endphp
@if (!empty($all))
<h2>{!! S::html(__('report.how_we_know')) !!}</h2>
<p class="muted">{!! S::html(__('report.how_we_know_note')) !!}</p>
<table class="data appendix">
  <thead><tr><th style="width:46%">{!! S::html(__('report.statement')) !!}</th><th>{!! S::html(__('report.measured')) !!}</th><th>{!! S::html(__('report.result')) !!}</th></tr></thead>
  <tbody>
  @foreach ($all as $c)
    @foreach (($c['evidence'] ?? []) as $e)
      <tr>
        @if ($loop->first)<td rowspan="{{ max(1, count($c['evidence'])) }}">{!! S::html($c['statement']) !!}</td>@endif
        <td>{!! S::html(W::metric($e['metric'])) !!}@if (!empty($e['label'])) · {!! S::html($e['label']) !!}@endif
            @if (!empty($e['dimension'])) by {!! S::html($e['dimension']) !!} @endif</td>
        <td>{!! S::html($e['display_value'] ?? (string) ($e['value'] ?? '—')) !!}</td>
      </tr>
    @endforeach
  @endforeach
  </tbody>
</table>
@endif

</body>
</html>
