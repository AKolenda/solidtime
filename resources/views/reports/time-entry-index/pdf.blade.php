@use('Brick\Math\BigDecimal')
@use('Brick\Money\Money')
@use('Carbon\CarbonInterval')
@inject('interval', 'App\Service\IntervalService')
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>Report</title>
    <style>

        html, body, div, span, applet, object, iframe,
        h1, h2, h3, h4, h5, h6, p, blockquote, pre,
        a, abbr, acronym, address, big, cite, code,
        del, dfn, em, img, ins, kbd, q, s, samp,
        small, strike, strong, sub, sup, tt, var,
        b, u, i, center,
        dl, dt, dd, ol, ul, li,
        fieldset, form, label, legend,
        table, caption, tbody, tfoot, thead, tr, th, td,
        article, aside, canvas, details, embed,
        figure, figcaption, footer, header, hgroup,
        menu, nav, output, ruby, section, summary,
        time, mark, audio, video {
            margin: 0;
            padding: 0;
            border: 0;
            font-size: 100%;
            vertical-align: baseline;
            box-sizing: border-box;
        }


        /* HTML5 display-role reset for older browsers */
        article, aside, details, figcaption, figure,
        footer, header, hgroup, menu, nav, section {
            display: block;
        }

        body {
            line-height: 1;
        }

        ol, ul {
            list-style: none;
        }

        blockquote, q {
            quotes: none;
        }

        blockquote:before, blockquote:after,
        q:before, q:after {
            content: '';
            content: none;
        }

        @font-face {
            font-family: 'Outfit';
            src: url('outfit.ttf');
        }

        body {
            font-family: 'Outfit', 'Helvetica Neue', 'Helvetica', Helvetica, Arial, sans-serif;
            color: #18181b
        }

        table {
            font-size: 10px;
        }

        table thead {
            background-color: #eee;
        }


        .table-wrapper table th {
            background-color: #fafafa;
        }

        .table-wrapper {
            border: 1px solid #d4d4d8;
            border-radius: 8px;
            overflow: hidden;
            width: calc(100% - 2px)
        }

        table {
            border-collapse: collapse;
            border-spacing: 0;
            text-align: left;
        }

        thead {
            border-bottom: 1px #d4d4d8 solid;
        }

        tfoot {
            border-top: 1px #d4d4d8 solid;
        }

        table th, table tfoot td {
            font-weight: 500;
            padding: 6px 12px;
            color: #18181b;
        }

        table td, table th {
            font-size: 12px;
        }

        table tr {
            border-bottom: 1px #e4e4e7 solid;
        }

        table tr:last-of-type {
            border-bottom: none;
        }

        table tr td {
            font-weight: 400;
            color: #3f3f46;
            padding: 6px 12px;
        }

        .shop-header { display: flex; justify-content: space-between; align-items: center; gap: 24px; margin-bottom: 20px; padding-bottom: 12px; border-bottom: 1px solid #18181b; }
        .company-name { font-size: 15px; font-weight: 700; margin-bottom: 3px; }
        .document-name { font-size: 11px; color: #52525b; margin-bottom: 8px; }
        .shop-logo-wrap { flex: 0 0 auto; padding: 3px 8px 3px 12px; }
        .shop-logo { display: block; max-width: 198px; max-height: 55px; object-fit: contain; }
        .meta-grid { background: #fff; border: 1px solid #d4d4d8; border-radius: 5px; overflow: hidden; margin-bottom: 16px; width: 100%; }
        .meta-grid thead { background: #f0f0f0; }
        .meta-grid th, .meta-grid td { border-right: 1px solid #d4d4d8; padding: 8px 12px; }
        .meta-grid th:last-child, .meta-grid td:last-child { border-right: 0; }
        .meta-grid th { color: #18181b; font-size: 10px; font-weight: 600; }
        .meta-grid td { color: #3f3f46; font-size: 10px; }
        .meta-label { color: #71717a; font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
        .meta-value { font-size: 11px; font-weight: 600; }
        .summary-sheet { border: 1px solid #d4d4d8; border-radius: 5px; overflow: hidden; margin-bottom: 16px; }
        .summary-sheet table { width: 100%; }
        .summary-sheet th, .summary-sheet td { border-right: 1px solid #d4d4d8; padding: 7px 9px; }
        .summary-sheet th:last-child, .summary-sheet td:last-child { border-right: 0; }
        .summary-sheet th { background: #f0f0f0; font-size: 10px; font-weight: 600; }
        .summary-sheet td { font-size: 10px; }
        .summary-sheet td:not(:first-child) { text-align: right; white-space: nowrap; }
        .summary-sheet .operation-name { font-size: 11px; font-weight: 700; }
        .summary-sheet .task-heading td { background: #f0f0f0; font-weight: 600; }
        .summary-sheet .task-name { text-align: left; }
        .summary-sheet .task-total { font-weight: 600; text-align: right; }
        .shop-table { table-layout: fixed; width: 100%; }
        .shop-table th, .shop-table td { border-right: 1px solid #d4d4d8; padding: 7px 9px; }
        .shop-table th:last-child, .shop-table td:last-child { border-right: 0; }
        .shop-table thead { background: #f0f0f0; }
        .shop-table thead tr:last-child th { background: #f0f0f0; color: #18181b; font-size: 10px; font-weight: 600; }
        .shop-table tbody td { color: #3f3f46; font-size: 10px; }
        .operation-section { margin-top: 14px; border: 1px solid #d4d4d8; border-radius: 5px; overflow: hidden; page-break-inside: auto; -webkit-box-decoration-break: clone; box-decoration-break: clone; }
        .operation-header-row th { padding: 9px 13px; background: #fff; border-bottom: 1px solid #18181b; }
        .operation-header { display: flex; justify-content: space-between; align-items: baseline; font-size: 14px; font-weight: 700; }
        .operation-total { font-size: 10px; font-weight: 600; }
        .operation-total span { margin-right: 6px; color: #71717a; font-size: 9px; text-transform: uppercase; letter-spacing: .035em; }
        .shop-table thead { display: table-header-group; }
        .shop-table tr { break-inside: avoid; page-break-inside: avoid; }
        .activity-label { display: block; margin-bottom: 3px; color: #475569; font-size: 9px; font-weight: 650; text-transform: uppercase; letter-spacing: .035em; }
        .user-heading { display: flex; align-items: baseline; justify-content: space-between; gap: 6px; white-space: nowrap; }
        .user-name { min-width: 0; font-weight: 500; white-space: nowrap; }
        .user-date { flex: none; color: #71717a; font-size: 8px; font-weight: 400; }
        .user-time { margin-top: 3px; color: #71717a; font-size: 9px; white-space: nowrap; }
        .machine-tags { overflow: hidden; font-size: 8px; line-height: 1.15; overflow-wrap: normal; text-overflow: ellipsis; white-space: nowrap; }
        .report-total-row { display: grid; grid-template-columns: repeat(4, 1fr); margin-top: 14px; border-top: 1px solid #18181b; border-bottom: 1px solid #d4d4d8; background: #f8f8f8; }
        .report-total-row > div { padding: 9px 11px; border-right: 1px solid #d4d4d8; }
        .report-total-row > div:last-child { border-right: 0; }
        .report-total-row span { display: block; margin-bottom: 4px; color: #71717a; font-size: 8px; font-weight: 600; text-transform: uppercase; }
        .report-total-row strong { display: block; font-size: 12px; }
        .report-total-footnote { margin: 5px 13px 0; color: #71717a; font-size: 8px; }

    </style>
</head>
<body>
@if($shopReport)
@php($quarterHours = fn (int|float $seconds): string => number_format(round(($seconds / 3600) * 4) / 4, 2).' h')
@php($quarterValue = fn (int|float $hours): string => number_format(round($hours * 4) / 4, 2).' h')
<div class="shop-header">
    <div>
        <div class="company-name">{{ $shopReportOrganizationName }}</div>
        <div class="document-name">Production time report</div>
        <div style="font-size: 12px; color: #71717a; margin-top: 5px;">
            Client {{ $timeEntries->first()?->client?->name ?? '-' }}
            @if($shopReport->purchaseOrder) &nbsp;|&nbsp; Purchase order {{ $shopReport->purchaseOrder }} @endif
            &nbsp;|&nbsp; Project {{ $shopReport->projectName }}
        </div>
    </div>
    @if($shopReportLogo)
        <div class="shop-logo-wrap"><img class="shop-logo" src="{{ $shopReportLogo }}" alt="Organization logo"></div>
    @endif
</div>
<table class="meta-grid">
    <thead><tr><th>Part number</th><th>Quantity</th><th>Quoted turning</th><th>Quoted milling</th></tr></thead>
    <tbody>
    @foreach($shopReport->parts as $part)
        <tr>
            <td class="meta-value">{{ $part['part'] }}</td>
            <td class="meta-value">{{ $part['quantity'] !== null ? $part['quantity'] + 0 : '-' }}</td>
            <td class="meta-value">{{ $part['turning'] !== null ? $quarterValue($part['turning']) : '-' }}</td>
            <td class="meta-value">{{ $part['milling'] !== null ? $quarterValue($part['milling']) : '-' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
<div class="summary-sheet">
    <table>
        <thead>
            <tr><th>Task totals</th><th>Total</th><th>Running average per piece</th></tr>
        </thead>
        <tbody>
        @foreach($shopReport->taskTotals as $task)
            <tr>
                <td class="task-name">{{ $task['name'] }}</td>
                <td class="task-total">{{ $quarterHours($task['seconds']) }}</td>
                <td class="task-total">{{ $task['seconds_per_piece'] !== null ? $quarterHours($task['seconds_per_piece']) : '-' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@else
<div>
    <p style="font-size: 32px; font-weight: 600; margin-bottom: 5px;">Detailed Report</p>
    <div style="font-size: 16px; font-weight: 600; color: #71717a;">
        <span>{{ $localization->formatDate($start->timezone($timezone)) }} - {{ $localization->formatDate($end->timezone($timezone)) }}</span><br><br>
    </div>
</div>
@endif
@if($shopReport)
    @php($operationGroups = $timeEntries->groupBy(fn ($entry) => trim(explode(' - ', $entry->task?->name ?? 'Other', 2)[0])))
    @foreach($operationGroups as $operationName => $operationEntries)
        @php($operationTotalSeconds = $operationEntries->sum(fn ($entry) => (int) $entry->getDuration()->totalSeconds))
        <div class="operation-section">
            <table class="shop-table" style="width: 100%;">
                <colgroup>
                    <col style="width: 25%;">
                    <col style="width: 9%;">
                    <col style="width: 55%;">
                    <col style="width: 11%;">
                </colgroup>
                <thead>
                    <tr class="operation-header-row">
                        <th colspan="4">
                            <div class="operation-header">
                                <div>{{ $operationName }}</div>
                                <div class="operation-total"><span>Total time</span>{{ $quarterHours($operationTotalSeconds) }}</div>
                            </div>
                        </th>
                    </tr>
                    <tr><th>User</th><th>Duration</th><th>Notes</th><th>Tags</th></tr>
                </thead>
                <tbody>
                @foreach($operationEntries as $timeEntry)
                    @php($activity = trim(explode(' - ', $timeEntry->task?->name ?? '', 2)[1] ?? ''))
                    <tr>
                        <td>
                            <div class="user-heading">
                                <span class="user-name">{{ $timeEntry->user->name }}</span>
                                <span class="user-date">{{ $timeEntry->start->timezone($timezone)->format('m-d') }}</span>
                            </div>
                            <div class="user-time">
                                {{ $localization->formatTime($timeEntry->start->timezone($timezone)) }} - {{ $localization->formatTime($timeEntry->end->timezone($timezone)) }}
                            </div>
                        </td>
                        <td>{{ $localization->formatIntervalForReporting($timeEntry->getDuration()) }}</td>
                        <td style="overflow-wrap: break-word;">
                            @if($activity !== '')<span class="activity-label">{{ $activity }}</span>@endif
                            {{ filled($timeEntry->description) ? $timeEntry->description : '-' }}
                        </td>
                        <td class="machine-tags">{{ count($timeEntry->tagsRelation) === 0 ? '-' : $timeEntry->tagsRelation->implode('name', ', ') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endforeach
@else
<div class="table-wrapper">
    <div
        style="background-color: #fafafa; padding: 5px 14px; display: flex; gap: 20px;">
        <div style="padding: 8px 12px; border-radius: 8px;">
            <div style="color: #71717a; font-weight: 600;">Duration</div>
            <div
                style="font-size: 24px; font-weight: 500; margin-top: 2px;">{{ $localization->formatIntervalForReporting(CarbonInterval::seconds($aggregatedData['seconds'])) }} </div>
        </div>
        @if($showBillableRate)
        <div style="padding: 8px 12px; border-radius: 8px;">
            <div style="color: #71717a; font-weight: 600;">Total cost</div>
            <div style="font-size: 24px; font-weight: 500; margin-top: 2px;">
                {{ $localization->formatCurrency(Money::of(BigDecimal::ofUnscaledValue($aggregatedData['cost'], 2)->__toString(), $currency)) }}
            </div>
        </div>
        @endif
    </div>
    <div>
        <table style="width: 100%;">
            <thead>
            <tr style="border-top: 1px #d4d4d8 solid;">
                <th>Project</th>
                <th>Task</th>
                <th>Client</th>
                <th>User</th>
                <th style="text-align: center;">Time</th>
                <th>Duration</th>
                <th>Tags</th>
            </tr>
            </thead>
            <tbody>
            @foreach($timeEntries as $timeEntry)
                <tr>
                    <td style="overflow-wrap: break-word; max-width: 180px;">{{ $timeEntry->project?->name ?? '-' }}</td>
                    <td style="overflow-wrap: break-word;">{{ $timeEntry->task?->name ?? '-' }}</td>
                    <td style="overflow-wrap: break-word; max-width: 140px;">{{ $timeEntry->client?->name ?? '-' }}</td>
                    <td style="overflow-wrap: break-word; min-width: 75px;">{{ $timeEntry->user->name }}</td>
                    <td style="overflow-wrap: break-word; text-align: center; white-space: nowrap;">
                        @if($timeEntry->start->timezone($timezone)->format('Y-m-d') === $timeEntry->end->timezone($timezone)->format('Y-m-d'))
                            {{ $localization->formatDate($timeEntry->start->timezone($timezone)) }}
                        @else
                            {{ $localization->formatDate($timeEntry->start->timezone($timezone)) }} - <br> {{ $localization->formatDate($timeEntry->end->timezone($timezone)) }}
                        @endif
                        <br>
                        {{ $localization->formatTime($timeEntry->start->timezone($timezone)) }} - {{ $localization->formatTime($timeEntry->end->timezone($timezone)) }}
                    </td>
                    <td style="overflow-wrap: break-word; min-width: 75px;">
                        {{ $localization->formatIntervalForReporting($timeEntry->getDuration()) }}
                    </td>
                    <td style="overflow-wrap: break-word; min-width: 75px;">{{ count($timeEntry->tagsRelation) === 0 ? '-' : $timeEntry->tagsRelation->implode('name', ', ') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

@if($shopReport && $shopReport->runningSeconds !== null && $shopReport->totalQuantity !== null)
    @php($turningOperation = collect($shopReport->operations)->firstWhere('name', 'Turning'))
    @php($millingOperation = collect($shopReport->operations)->firstWhere('name', 'Milling'))
    <div class="report-total-row">
        <div><span>Total Running</span><strong>{{ $quarterHours($shopReport->runningSeconds) }}</strong></div>
        <div><span>Turning Setup</span><strong>{{ $quarterHours($turningOperation['setup_seconds'] ?? 0) }}</strong></div>
        <div><span>Milling Setup</span><strong>{{ $quarterHours($millingOperation['setup_seconds'] ?? 0) }}</strong></div>
        <div><span>Combined Running Average Per Piece</span><strong>{{ $quarterHours($shopReport->runningSeconds / $shopReport->totalQuantity) }}</strong></div>
    </div>
    <p class="report-total-footnote">* Combined Turning + Milling running time divided by the total project quantity.</p>
@endif


</body>
</html>
